<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ResolvesDashboardScope
{
<<<<<<< HEAD
    /**
     * Core: resolve which user IDs to aggregate over, based on:
     *  - authenticated user's role
     *  - ?scope=
     *  - ?user_id=
     *
     * Supported scopes:
     *   self
     *   all
     *   researcher
     *   supervisor
     *   my_researchers
     *   all_researchers
     *   all_supervisors
     */
=======
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
    protected function resolveDashboardUserIds(Request $req): array
    {
        $user   = $req->user() ?? abort(401, 'Unauthenticated');
        $role   = $user->role ?? 'researcher';
        $scope  = $req->query('scope', 'self');
        $target = (int) $req->query('user_id');

        // helpers
        $allByRole = function (string $roleName): array {
            return DB::table('users')
                ->where('role', $roleName)
                ->pluck('id')
                ->all();
        };

        $researchersForSupervisor = function (int $supervisorId): array {
            return DB::table('researcher_invites')
                ->join('users', 'users.email', '=', 'researcher_invites.researcher_email')
                ->where('researcher_invites.created_by', $supervisorId)
                ->where('researcher_invites.status', 'accepted')
                ->pluck('users.id')
                ->all();
        };

        // -------- Researcher: always self --------
        if ($role === 'researcher') {
            return [$user->id];
        }

        // -------- Supervisor --------
        if ($role === 'supervisor') {
            switch ($scope) {
                case 'self':
                    return [$user->id];

                case 'my_researchers':
                case 'all_researchers':
                    return $researchersForSupervisor($user->id);

                case 'researcher':
                    if ($target) {
                        $allowed = $researchersForSupervisor($user->id);
                        return in_array($target, $allowed, true) ? [$target] : [];
                    }
                    return [];

                case 'all':
                    // self + all my researchers
                    return array_values(array_unique(array_merge(
                        [$user->id],
                        $researchersForSupervisor($user->id)
                    )));

                default:
                    return [$user->id];
            }
        }

        // -------- Admin --------
        if ($role === 'admin') {
            switch ($scope) {
                case 'self':
                    return [$user->id];

                case 'researcher':
                    return $target ? [$target] : [];

                case 'supervisor':
                    return $target ? [$target] : [];

                case 'all_researchers':
                    return $allByRole('researcher');

                case 'all_supervisors':
                    return $allByRole('supervisor');

                case 'my_researchers':
                    if ($target) {
                        return $researchersForSupervisor($target);
                    }
                    // union for all supervisors
                    return DB::table('researcher_invites')
                        ->join('users', 'users.email', '=', 'researcher_invites.researcher_email')
                        ->where('researcher_invites.status', 'accepted')
                        ->distinct()
                        ->pluck('users.id')
                        ->all();

                case 'all':
                default:
                    // all supervisors + all researchers
<<<<<<< HEAD
                    return array_values(array_unique(array_merge(
=======
                    // ===> include current user when scope == 'all'
                    return array_values(array_unique(array_merge(
                        [$user->id], // <-- ensure current user included for all
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
                        $allByRole('researcher'),
                        $allByRole('supervisor')
                    )));
            }
        }

        // fallback
        return [$user->id];
    }
}
