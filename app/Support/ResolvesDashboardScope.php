<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait ResolvesDashboardScope
{
    protected function resolveDashboardUserIds(Request $req): array
    {
        $user   = $req->user() ?? abort(401, 'Unauthenticated');
        $role   = $user->role ?? 'researcher';
        $scope  = $req->query('scope', 'self');
        $target = (int) $req->query('user_id');

        Log::info('ResolvesDashboardScope called', [
            'user_id' => $user->id,
            'role' => $role,
            'scope' => $scope,
            'target' => $target
        ]);

        // helpers
        $allByRole = function (string $roleName): array {
            $ids = DB::table('users')
                ->where('role', $roleName)
                ->pluck('id')
                ->all();
            
            Log::debug("allByRole($roleName)", ['count' => count($ids), 'ids' => $ids]);
            return $ids;
        };

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

        // -------- Researcher: always self --------
        if ($role === 'researcher') {
            Log::info('Researcher scope: returning self', ['user_id' => $user->id]);
            return [$user->id];
        }

        // -------- Supervisor --------
        if ($role === 'supervisor') {
            switch ($scope) {
                case 'self':
                    Log::info('Supervisor scope: self', ['user_id' => $user->id]);
                    return [$user->id];

                case 'my_researchers':
                    $researchers = $researchersForSupervisor($user->id);
                    Log::info('Supervisor scope: my_researchers', [
                        'supervisor_id' => $user->id,
                        'researcher_ids' => $researchers,
                        'count' => count($researchers)
                    ]);
                    return $researchers;

                case 'all_researchers':
                    // Same as my_researchers for supervisor
                    $researchers = $researchersForSupervisor($user->id);
                    Log::info('Supervisor scope: all_researchers', [
                        'supervisor_id' => $user->id,
                        'researcher_ids' => $researchers,
                        'count' => count($researchers)
                    ]);
                    return $researchers;

                case 'researcher':
                    if ($target) {
                        $allowed = $researchersForSupervisor($user->id);
                        $result = in_array($target, $allowed, true) ? [$target] : [];
                        Log::info('Supervisor scope: specific researcher', [
                            'target' => $target,
                            'allowed' => $allowed,
                            'result' => $result
                        ]);
                        return $result;
                    }
                    Log::warning('Supervisor scope: researcher but no target specified');
                    return [];

                case 'all':
                    // self + all my researchers
                    $result = array_values(array_unique(array_merge(
                        [$user->id],
                        $researchersForSupervisor($user->id)
                    )));
                    Log::info('Supervisor scope: all (self + researchers)', [
                        'supervisor_id' => $user->id,
                        'all_ids' => $result,
                        'count' => count($result)
                    ]);
                    return $result;

                default:
                    Log::warning('Supervisor scope: unknown scope, returning self', [
                        'scope' => $scope
                    ]);
                    return [$user->id];
            }
        }

        // -------- Admin --------
        if ($role === 'admin') {
            switch ($scope) {
                case 'self':
                    Log::info('Admin scope: self', ['user_id' => $user->id]);
                    return [$user->id];

                case 'researcher':
                    $result = $target ? [$target] : [];
                    Log::info('Admin scope: specific researcher', [
                        'target' => $target,
                        'result' => $result
                    ]);
                    return $result;

                case 'supervisor':
                    $result = $target ? [$target] : [];
                    Log::info('Admin scope: specific supervisor', [
                        'target' => $target,
                        'result' => $result
                    ]);
                    return $result;

                case 'all_researchers':
                    $result = $allByRole('researcher');
                    Log::info('Admin scope: all_researchers', [
                        'count' => count($result)
                    ]);
                    return $result;

                case 'all_supervisors':
                    $result = $allByRole('supervisor');
                    Log::info('Admin scope: all_supervisors', [
                        'count' => count($result)
                    ]);
                    return $result;

                case 'my_researchers':
                    if ($target) {
                        $result = $researchersForSupervisor($target);
                        Log::info('Admin scope: my_researchers for specific supervisor', [
                            'supervisor_id' => $target,
                            'researcher_ids' => $result,
                            'count' => count($result)
                        ]);
                        return $result;
                    }
                    // union for all supervisors
                    $result = DB::table('researcher_invites')
                        ->join('users', 'users.email', '=', 'researcher_invites.researcher_email')
                        ->where('researcher_invites.status', 'accepted')
                        ->whereNull('researcher_invites.revoked_at')
                        ->distinct()
                        ->pluck('users.id')
                        ->all();
                    Log::info('Admin scope: my_researchers for all supervisors', [
                        'count' => count($result)
                    ]);
                    return $result;

                case 'all':
                default:
                    // all supervisors + all researchers + admin
                    $result = array_values(array_unique(array_merge(
                        [$user->id],
                        $allByRole('researcher'),
                        $allByRole('supervisor')
                    )));
                    Log::info('Admin scope: all users', [
                        'count' => count($result),
                        'breakdown' => [
                            'admin' => 1,
                            'researchers' => count($allByRole('researcher')),
                            'supervisors' => count($allByRole('supervisor'))
                        ]
                    ]);
                    return $result;
            }
        }

        // fallback
        Log::warning('Fallback: unknown role, returning self', [
            'user_id' => $user->id,
            'role' => $role
        ]);
        return [$user->id];
    }
}