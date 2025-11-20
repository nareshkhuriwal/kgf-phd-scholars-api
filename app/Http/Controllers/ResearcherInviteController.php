<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResearcherInviteRequest;
use App\Http\Resources\ResearcherInviteResource;
use App\Models\ResearcherInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\User;


class ResearcherInviteController extends Controller
{
    // Supervisor / creator: list invites they have created
    public function index(Request $request)
    {
        $user = $request->user();

        $perPage = (int) $request->input('perPage', 10);
        $perPage = $perPage > 0 ? $perPage : 10;

        $query = ResearcherInvite::ownedBy($user->id)
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage)->appends($request->query());

        return ResearcherInviteResource::collection($paginator);
    }

    // Supervisor / creator: create an invite
    public function store(StoreResearcherInviteRequest $request)
    {
        $user = $request->user();

        // We only receive: researcher_email (+ optional expires_in_days/message/notes)
        $researcherEmail = $request->input('researcher_email');

        // Supervisor details & ownership come from auth user
        $supervisorName = $user->name
            ?? ($user->name ?? null); // adjust to your User model

        // Expiry logic (optional)
        $expiresInDays = method_exists($request, 'integer')
            ? $request->integer('expires_in_days')
            : (int) $request->input('expires_in_days');

        $expiresAt = $expiresInDays
            ? now()->addDays($expiresInDays)
            : null;

        // Domain can be derived from researcher email
        $allowedDomain = null;
        if ($researcherEmail) {
            $parts = explode('@', $researcherEmail);
            if (count($parts) === 2) {
                $allowedDomain = $parts[1];
            }
        }

        // Try to find existing user with that email
        $existingUser = User::where('email', $researcherEmail)->first();
        $researcherName = $existingUser?->name;

        $invite = ResearcherInvite::create([
            'researcher_email' => $researcherEmail,
            'researcher_name'  => $researcherName, // will be filled after signup
            'supervisor_name'  => $supervisorName,
            'message'          => $request->input('message'),
            'role'             => 'researcher',
            'allowed_domain'   => $allowedDomain,
            'notes'            => $request->input('notes'),

            'invite_token'     => Str::uuid()->toString(),
            'status'           => 'pending',
            'expires_at'       => $expiresAt,
            'sent_at'          => now(),
            'created_by'       => $user->id,
        ]);


        return new ResearcherInviteResource($invite);
    }

    // Supervisor / creator: revoke invite
    public function destroy(Request $request, ResearcherInvite $invite)
    {
        $user = $request->user();

        if ($invite->created_by !== $user->id) {
            abort(403, 'You do not have permission to revoke this invite.');
        }

        $invite->update([
            'status'     => 'revoked',
            'revoked_at' => now(),
        ]);

        return response()->json([
            'message' => 'Invite revoked successfully.',
        ]);
    }

    // Supervisor / creator: resend invite email
    public function resend(Request $request, ResearcherInvite $invite)
    {
        $user = $request->user();

        if ($invite->created_by !== $user->id) {
            abort(403, 'You do not have permission to resend this invite.');
        }

        if ($invite->isRevoked()) {
            return response()->json([
                'message' => 'Cannot resend a revoked invite.',
            ], 422);
        }

        if ($invite->isExpired()) {
            // Optionally refresh expiry
            $invite->expires_at = now()->addDays(7);
        }

        $invite->sent_at = now();
        $invite->status  = 'pending';
        $invite->save();

        // dispatch(new SendResearcherInviteMail($invite));

        return new ResearcherInviteResource($invite);
    }

    // Researcher (invited user): list invites sent to their email (for notification bell)
    public function myInvites(Request $request)
    {
        $user = $request->user();
        $email = $user->email;

        $perPage = (int) $request->input('perPage', 10);
        $perPage = $perPage > 0 ? $perPage : 10;

        $query = ResearcherInvite::where('researcher_email', $email)
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage)->appends($request->query());

        return ResearcherInviteResource::collection($paginator);
    }

    // Researcher: accept invite
    public function accept(Request $request, ResearcherInvite $invite)
    {
        $user = $request->user();

        // Only the invited email can accept this invite
        if (strcasecmp($invite->researcher_email, $user->email) !== 0) {
            abort(403, 'You are not allowed to accept this invite.');
        }

        if ($invite->isRevoked()) {
            return response()->json([
                'message' => 'This invite has been revoked.',
            ], 422);
        }

        if ($invite->isExpired()) {
            return response()->json([
                'message' => 'This invite has expired.',
            ], 422);
        }

        if ($invite->isAccepted()) {
            // Already accepted – you can treat as success
            return new ResearcherInviteResource($invite);
        }
        
        // pick researcher name from users table (or from current user)
        if (empty($invite->researcher_name)) {
            // You can use the current auth user directly:
            $invite->researcher_name = $user->name;
        }

        $invite->accepted_at = now();
        $invite->status      = 'accepted';
        $invite->save();

        // Here you can link this user as a "researcher" in another table, if needed.

        return new ResearcherInviteResource($invite);
    }

    // Researcher: reject/decline invite
    public function reject(Request $request, ResearcherInvite $invite)
    {
        $user = $request->user();

        // Only the invited email can reject this invite
        if (strcasecmp($invite->researcher_email, $user->email) !== 0) {
            abort(403, 'You are not allowed to reject this invite.');
        }

        if ($invite->isRevoked()) {
            return response()->json([
                'message' => 'This invite has already been revoked.',
            ], 422);
        }

        if ($invite->isAccepted()) {
            return response()->json([
                'message' => 'This invite has already been accepted.',
            ], 422);
        }

        // We’ll mark it as revoked when the researcher declines
        $invite->status     = 'revoked';
        $invite->revoked_at = now();
        $invite->save();

        return new ResearcherInviteResource($invite);
    }
}
