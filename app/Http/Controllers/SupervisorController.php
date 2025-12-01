<?php
// app/Http/Controllers/SupervisorController.php

namespace App\Http\Controllers;

use App\Http\Requests\SupervisorRequest;
use App\Http\Resources\SupervisorResource;
use App\Mail\SupervisorWelcomeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SupervisorController extends Controller
{
    protected function assertAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'admin') {
            abort(403, 'Only admins can manage supervisors.');
        }
    }

    /**
     * Helper: ensure the current admin is the creator of the supervisor
     */
    protected function assertOwner(Request $request, User $supervisor): void
    {
        $currentId = $request->user()?->id;
        // If supervisor doesn't have created_by or not created by current admin, forbid
        if ($supervisor->created_by !== $currentId) {
            abort(403, 'You are not allowed to access this supervisor.');
        }
    }

    /**
     * GET /api/supervisors
     * Only returns supervisors created by the current admin.
     * Optional query params: q, perPage
     */
    public function index(Request $request)
    {
        $this->assertAdmin($request);

        $perPage = (int) $request->input('perPage', 25);
        $perPage = $perPage > 0 ? $perPage : 25;

        $q = trim((string) $request->input('q', ''));

        $query = User::query()
            ->where('role', 'supervisor')
            ->where('created_by', $request->user()->id); // <- only supervisors created by current admin

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

        return SupervisorResource::collection($supervisors);
    }

    /**
     * POST /api/supervisors
     */
    public function store(SupervisorRequest $request)
    {
        $this->assertAdmin($request);

        $data = $request->validated();
        $data['role'] = 'supervisor';

        // Ensure created_by is set to current admin
        $data['created_by'] = $request->user()->id;

        // 1️⃣ Generate a secure random password
        $plainPassword = str()->random(12); // you can tweak length/rules

        // 2️⃣ Hash it for database
        $data['password'] = bcrypt($plainPassword);

        // 3️⃣ Create user
        $user = User::create($data);

        // 4️⃣ Send welcome email with username + password + login link
        Mail::to($user->email)->send(
            new SupervisorWelcomeMail($user, $plainPassword)
        );

        return new SupervisorResource($user);
    }


    /**
     * GET /api/supervisors/{supervisor}
     */
    public function show(Request $request, User $supervisor)
    {
        $this->assertAdmin($request);

        if ($supervisor->role !== 'supervisor') {
            abort(404);
        }

        // Ensure current admin created this supervisor
        $this->assertOwner($request, $supervisor);

        return new SupervisorResource($supervisor);
    }

    /**
     * PUT/PATCH /api/supervisors/{supervisor}
     */
    public function update(SupervisorRequest $request, User $supervisor)
    {
        $this->assertAdmin($request);

        if ($supervisor->role !== 'supervisor') {
            abort(404);
        }

        // Ensure current admin created this supervisor
        $this->assertOwner($request, $supervisor);

        $data = $request->validated();
        $data['role'] = 'supervisor'; // enforce

        // Don’t accidentally wipe password, so exclude if not present:
        unset($data['password']);

        $supervisor->update($data);

        return new SupervisorResource($supervisor->fresh());
    }

    /**
     * DELETE /api/supervisors/{supervisor}
     */
    public function destroy(Request $request, User $supervisor)
    {
        $this->assertAdmin($request);

        if ($supervisor->role !== 'supervisor') {
            abort(404);
        }

        // Ensure current admin created this supervisor
        $this->assertOwner($request, $supervisor);

        $supervisor->delete();

        return response()->json([
            'message' => 'Supervisor deleted successfully.',
        ]);
    }
}
