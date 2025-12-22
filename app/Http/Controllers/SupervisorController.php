<?php
// app/Http/Controllers/SupervisorController.php

namespace App\Http\Controllers;

use App\Http\Requests\SupervisorRequest;
use App\Http\Resources\SupervisorResource;
use App\Mail\SupervisorWelcomeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Support\ResolvesApiScope;

class SupervisorController extends Controller
{
    use ResolvesApiScope;

    protected function assertAdminOrSuperuser(Request $request): void
    {
        $role = $request->user()->role ?? null;
        if (!in_array($role, ['admin', 'superuser'], true)) {
            Log::warning('Unauthorized supervisor access attempt', [
                'user_id' => $request->user()->id,
                'role' => $role
            ]);
            abort(403, 'Only admins and superusers can access supervisors.');
        }
    }

    /**
     * Helper: ensure the current admin is the creator of the supervisor
     * Superusers bypass this check
     */
    protected function assertOwner(Request $request, User $supervisor): void
    {
        $currentUser = $request->user();
        
        // Superuser can access all supervisors
        if ($currentUser->role === 'superuser') {
            return;
        }

        // Admin can only access supervisors they created
        if ($supervisor->created_by !== $currentUser->id) {
            Log::warning('Admin attempted to access supervisor they did not create', [
                'admin_id' => $currentUser->id,
                'supervisor_id' => $supervisor->id,
                'supervisor_creator' => $supervisor->created_by
            ]);
            abort(403, 'You are not allowed to access this supervisor.');
        }
    }

    /**
     * GET /api/supervisors
     * - Admin: Returns supervisors created by the current admin
     * - Superuser: Returns ALL supervisors
     * Optional query params: q, perPage
     */
    public function index(Request $request)
    {
        $this->assertAdminOrSuperuser($request);

        Log::info('Supervisor index called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        $perPage = (int) $request->input('perPage', 25);
        $perPage = $perPage > 0 ? $perPage : 25;

        $q = trim((string) $request->input('q', ''));

        $query = User::query()->where('role', 'supervisor');

        // Superuser sees all supervisors, Admin sees only their own
        if ($request->user()->role === 'admin') {
            $query->where('created_by', $request->user()->id);
            Log::info('Admin viewing their supervisors', [
                'admin_id' => $request->user()->id
            ]);
        } else {
            Log::info('Superuser viewing all supervisors');
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('employee_id', 'like', "%{$q}%")
                    ->orWhere('department', 'like', "%{$q}%");
            });
        }

        $supervisors = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        Log::info('Supervisors retrieved', [
            'total' => $supervisors->total(),
            'per_page' => $perPage
        ]);

        return SupervisorResource::collection($supervisors);
    }

    /**
     * POST /api/supervisors
     */
    public function store(SupervisorRequest $request)
    {
        $this->assertAdminOrSuperuser($request);

        Log::info('Creating new supervisor', [
            'created_by' => $request->user()->id,
            'creator_role' => $request->user()->role
        ]);

        $data = $request->validated();
        $data['role'] = 'supervisor';

        // Set created_by to current admin/superuser
        $data['created_by'] = $request->user()->id;

        // 1️⃣ Generate a secure random password
        $plainPassword = str()->random(12);

        // 2️⃣ Hash it for database
        $data['password'] = bcrypt($plainPassword);

        // 3️⃣ Create user
        $user = User::create($data);

        Log::info('Supervisor created successfully', [
            'supervisor_id' => $user->id,
            'email' => $user->email,
            'created_by' => $request->user()->id
        ]);

        // 4️⃣ Send welcome email with username + password + login link
        try {
            Mail::to($user->email)->send(
                new SupervisorWelcomeMail($user, $plainPassword)
            );
            Log::info('Welcome email sent to supervisor', [
                'supervisor_id' => $user->id,
                'email' => $user->email
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send welcome email to supervisor', [
                'supervisor_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);
            // Don't fail the request if email fails
        }

        return new SupervisorResource($user);
    }

    /**
     * GET /api/supervisors/{supervisor}
     */
    public function show(Request $request, User $supervisor)
    {
        $this->assertAdminOrSuperuser($request);

        Log::info('Supervisor show called', [
            'supervisor_id' => $supervisor->id,
            'user_id' => $request->user()->id
        ]);

        if ($supervisor->role !== 'supervisor') {
            Log::warning('Attempted to access non-supervisor user as supervisor', [
                'user_id' => $supervisor->id,
                'actual_role' => $supervisor->role
            ]);
            abort(404, 'Supervisor not found');
        }

        // Ensure current admin created this supervisor (superuser bypasses)
        $this->assertOwner($request, $supervisor);

        Log::info('Supervisor retrieved successfully', [
            'supervisor_id' => $supervisor->id
        ]);

        return new SupervisorResource($supervisor);
    }

    /**
     * PUT/PATCH /api/supervisors/{supervisor}
     */
    public function update(SupervisorRequest $request, User $supervisor)
    {
        $this->assertAdminOrSuperuser($request);

        Log::info('Supervisor update called', [
            'supervisor_id' => $supervisor->id,
            'user_id' => $request->user()->id
        ]);

        if ($supervisor->role !== 'supervisor') {
            Log::warning('Attempted to update non-supervisor user as supervisor', [
                'user_id' => $supervisor->id,
                'actual_role' => $supervisor->role
            ]);
            abort(404, 'Supervisor not found');
        }

        // Ensure current admin created this supervisor (superuser bypasses)
        $this->assertOwner($request, $supervisor);

        $data = $request->validated();
        $data['role'] = 'supervisor'; // enforce

        // Don't accidentally wipe password, so exclude if not present:
        unset($data['password']);

        $supervisor->update($data);

        Log::info('Supervisor updated successfully', [
            'supervisor_id' => $supervisor->id
        ]);

        return new SupervisorResource($supervisor->fresh());
    }

    /**
     * DELETE /api/supervisors/{supervisor}
     */
    public function destroy(Request $request, User $supervisor)
    {
        $this->assertAdminOrSuperuser($request);

        Log::info('Supervisor destroy called', [
            'supervisor_id' => $supervisor->id,
            'user_id' => $request->user()->id
        ]);

        if ($supervisor->role !== 'supervisor') {
            Log::warning('Attempted to delete non-supervisor user as supervisor', [
                'user_id' => $supervisor->id,
                'actual_role' => $supervisor->role
            ]);
            abort(404, 'Supervisor not found');
        }

        // Ensure current admin created this supervisor (superuser bypasses)
        $this->assertOwner($request, $supervisor);

        $supervisor->delete();

        Log::info('Supervisor deleted successfully', [
            'supervisor_id' => $supervisor->id
        ]);

        return response()->json([
            'message' => 'Supervisor deleted successfully.',
        ]);
    }
}