<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait ResolvesApiScope
{
    /**
     * Resolve user IDs for API access based on role and relationships
     * 
     * @param Request $req
     * @param bool $includeSelf - Whether to include the current user in results
     * @return array Array of user IDs the current user can access
     */
    protected function resolveApiUserIds(Request $req, bool $includeSelf = true): array
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');
        $role = $user->role ?? 'researcher';

        Log::info('ResolvesApiScope called', [
            'user_id' => $user->id,
            'role' => $role,
            'include_self' => $includeSelf
        ]);

        // Helper: Get all users by role
        $allByRole = function (string $roleName): array {
            $ids = DB::table('users')
                ->where('role', $roleName)
                ->pluck('id')
                ->all();
            
            Log::debug("allByRole($roleName)", ['count' => count($ids)]);
            return $ids;
        };

        // Helper: Get researchers for a supervisor
        $researchersForSupervisor = function (int $supervisorId): array {
            $ids = DB::table('researcher_invites')
                ->join('users', 'users.email', '=', 'researcher_invites.researcher_email')
                ->where('researcher_invites.created_by', $supervisorId)
                ->where('researcher_invites.status', 'accepted')
                ->whereNull('researcher_invites.revoked_at')
                ->pluck('users.id')
                ->all();
            
            Log::debug("researchersForSupervisor($supervisorId)", [
                'count' => count($ids),
                'ids' => $ids
            ]);
            return $ids;
        };

        // Helper: Get supervisor for a researcher
        $supervisorForResearcher = function (int $researcherId): ?int {
            $email = DB::table('users')
                ->where('id', $researcherId)
                ->value('email');

            if (!$email) {
                return null;
            }

            $supervisorId = DB::table('researcher_invites')
                ->where('researcher_email', $email)
                ->where('status', 'accepted')
                ->whereNull('revoked_at')
                ->value('created_by');

            Log::debug("supervisorForResearcher($researcherId)", [
                'supervisor_id' => $supervisorId
            ]);

            return $supervisorId;
        };

        // Helper: Get supervisors created by admin
        $supervisorsForAdmin = function (int $adminId): array {
            $ids = DB::table('users')
                ->where('role', 'supervisor')
                ->where('created_by', $adminId)
                ->pluck('id')
                ->all();
            
            Log::debug("supervisorsForAdmin($adminId)", [
                'count' => count($ids),
                'ids' => $ids
            ]);
            return $ids;
        };

        // Helper: Get all researchers for admin's supervisors
        $researchersForAdminSupervisors = function (int $adminId) use ($supervisorsForAdmin, $researchersForSupervisor): array {
            $supervisorIds = $supervisorsForAdmin($adminId);
            $researcherIds = [];

            foreach ($supervisorIds as $supervisorId) {
                $researcherIds = array_merge($researcherIds, $researchersForSupervisor($supervisorId));
            }

            $researcherIds = array_values(array_unique($researcherIds));

            Log::debug("researchersForAdminSupervisors($adminId)", [
                'supervisor_count' => count($supervisorIds),
                'researcher_count' => count($researcherIds),
                'researcher_ids' => $researcherIds
            ]);

            return $researcherIds;
        };

        $result = [];

        // -------- SUPERUSER: Full Access to Everything --------
        if ($role === 'superuser') {
            $result = array_values(array_unique(array_merge(
                $allByRole('researcher'),
                $allByRole('supervisor'),
                $allByRole('admin'),
                $allByRole('superuser')
            )));

            Log::info('Superuser scope: all users (no restrictions)', [
                'total_count' => count($result),
                'breakdown' => [
                    'researchers' => count($allByRole('researcher')),
                    'supervisors' => count($allByRole('supervisor')),
                    'admins' => count($allByRole('admin')),
                    'superusers' => count($allByRole('superuser'))
                ]
            ]);

            return $result;
        }

        // -------- ADMIN: Limited to Their Supervisors + Researchers --------
        if ($role === 'admin') {
            $supervisors = $supervisorsForAdmin($user->id);
            $researchers = $researchersForAdminSupervisors($user->id);

            $result = $includeSelf
                ? array_values(array_unique(array_merge([$user->id], $supervisors, $researchers)))
                : array_values(array_unique(array_merge($supervisors, $researchers)));

            Log::info('Admin scope: self + their supervisors + researchers', [
                'admin_id' => $user->id,
                'supervisor_count' => count($supervisors),
                'researcher_count' => count($researchers),
                'total_count' => count($result),
                'include_self' => $includeSelf
            ]);

            return $result;
        }

        // -------- SUPERVISOR: Self + Their Researchers --------
        if ($role === 'supervisor') {
            $researchers = $researchersForSupervisor($user->id);
            
            $result = $includeSelf 
                ? array_values(array_unique(array_merge([$user->id], $researchers)))
                : $researchers;

            Log::info('Supervisor scope: self + researchers', [
                'supervisor_id' => $user->id,
                'researcher_count' => count($researchers),
                'total_count' => count($result),
                'include_self' => $includeSelf
            ]);

            return $result;
        }

        // -------- RESEARCHER: Self + Their Supervisor (optional) --------
        if ($role === 'researcher') {
            $supervisorId = $supervisorForResearcher($user->id);
            
            if ($supervisorId && $includeSelf) {
                $result = [$user->id, $supervisorId];
            } elseif ($supervisorId) {
                $result = [$supervisorId];
            } else {
                $result = [$user->id];
            }

            Log::info('Researcher scope: self + supervisor', [
                'researcher_id' => $user->id,
                'supervisor_id' => $supervisorId,
                'result' => $result,
                'include_self' => $includeSelf
            ]);

            return $result;
        }

        // -------- FALLBACK: Self Only --------
        Log::warning('Fallback scope: unknown role, returning self', [
            'user_id' => $user->id,
            'role' => $role
        ]);

        return [$user->id];
    }

    /**
     * Check if current user can access a specific user's data
     * 
     * @param Request $req
     * @param int $targetUserId
     * @return bool
     */
    protected function canAccessUser(Request $req, int $targetUserId): bool
    {
        $accessibleIds = $this->resolveApiUserIds($req);
        $canAccess = in_array($targetUserId, $accessibleIds, true);

        Log::info('canAccessUser check', [
            'current_user_id' => $req->user()->id,
            'target_user_id' => $targetUserId,
            'can_access' => $canAccess
        ]);

        return $canAccess;
    }

    /**
     * Get query constraint for filtering by accessible user IDs
     * Use this in Eloquent queries: ->whereIn('created_by', $this->getAccessibleUserIdsConstraint($request))
     * 
     * @param Request $req
     * @param string $column - Column name to filter (default: 'user_id')
     * @return array
     */
    protected function getAccessibleUserIdsConstraint(Request $req, string $column = 'user_id'): array
    {
        return $this->resolveApiUserIds($req);
    }

    /**
     * Authorize that current user can access target user's data, or abort
     * 
     * @param Request $req
     * @param int $targetUserId
     * @return void
     */
    protected function authorizeUserAccess(Request $req, int $targetUserId): void
    {
        if (!$this->canAccessUser($req, $targetUserId)) {
            Log::warning('Unauthorized access attempt', [
                'current_user_id' => $req->user()->id,
                'current_user_role' => $req->user()->role,
                'target_user_id' => $targetUserId
            ]);

            abort(403, 'You do not have permission to access this resource');
        }
    }

    /**
     * Get researchers accessible to current user
     * 
     * @param Request $req
     * @return array
     */
    protected function getAccessibleResearchers(Request $req): array
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');
        $role = $user->role ?? 'researcher';

        // Superuser: All researchers
        if ($role === 'superuser') {
            return DB::table('users')
                ->where('role', 'researcher')
                ->pluck('id')
                ->all();
        }

        // Admin: Only researchers under their supervisors
        if ($role === 'admin') {
            $supervisorIds = DB::table('users')
                ->where('role', 'supervisor')
                ->where('created_by', $user->id)
                ->pluck('id')
                ->all();

            if (empty($supervisorIds)) {
                return [];
            }

            return DB::table('researcher_invites')
                ->join('users', 'users.email', '=', 'researcher_invites.researcher_email')
                ->whereIn('researcher_invites.created_by', $supervisorIds)
                ->where('researcher_invites.status', 'accepted')
                ->whereNull('researcher_invites.revoked_at')
                ->pluck('users.id')
                ->all();
        }

        // Supervisor: Their researchers
        if ($role === 'supervisor') {
            return DB::table('researcher_invites')
                ->join('users', 'users.email', '=', 'researcher_invites.researcher_email')
                ->where('researcher_invites.created_by', $user->id)
                ->where('researcher_invites.status', 'accepted')
                ->whereNull('researcher_invites.revoked_at')
                ->pluck('users.id')
                ->all();
        }

        // Researcher: Self only
        if ($role === 'researcher') {
            return [$user->id];
        }

        return [];
    }

    /**
     * Get supervisors accessible to current user
     * 
     * @param Request $req
     * @return array
     */
    protected function getAccessibleSupervisors(Request $req): array
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');
        $role = $user->role ?? 'researcher';

        // Superuser: All supervisors
        if ($role === 'superuser') {
            return DB::table('users')
                ->where('role', 'supervisor')
                ->pluck('id')
                ->all();
        }

        // Admin: Only supervisors they created
        if ($role === 'admin') {
            return DB::table('users')
                ->where('role', 'supervisor')
                ->where('created_by', $user->id)
                ->pluck('id')
                ->all();
        }

        // Supervisor: Self only
        if ($role === 'supervisor') {
            return [$user->id];
        }

        // Researcher: Their supervisor (if any)
        if ($role === 'researcher') {
            $email = $user->email;
            $supervisorId = DB::table('researcher_invites')
                ->where('researcher_email', $email)
                ->where('status', 'accepted')
                ->whereNull('revoked_at')
                ->value('created_by');

            return $supervisorId ? [$supervisorId] : [];
        }

        return [];
    }
}