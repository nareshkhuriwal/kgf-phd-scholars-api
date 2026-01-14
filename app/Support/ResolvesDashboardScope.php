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

        $researchersForAdminSupervisors = function (int $adminId) use ($supervisorsForAdmin, $researchersForSupervisor): array {
            $supervisorIds = $supervisorsForAdmin($adminId);
            $researcherIds = [];

            foreach ($supervisorIds as $supervisorId) {
                $researcherIds = array_merge($researcherIds, $researchersForSupervisor($supervisorId));
            }

            $researcherIds = array_values(array_unique($researcherIds));

            Log::debug("researchersForAdminSupervisors($adminId)", [
                'supervisor_count' => count($supervisorIds),
                'researcher_count' => count($researcherIds)
            ]);

            return $researcherIds;
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
                case 'all_researchers':
                    // For supervisor, both mean "their researchers"
                    $researchers = $researchersForSupervisor($user->id);
                    Log::info("Supervisor scope: {$scope}", [
                        'supervisor_id' => $user->id,
                        'researcher_ids' => $researchers,
                        'count' => count($researchers)
                    ]);
                    return $researchers;

                case 'researcher':
                    // Specific researcher selected from dropdown
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

                case 'supervisor':
                    // Specific supervisor selected from dropdown
                    if ($target) {
                        $mySupervisors = $supervisorsForAdmin($user->id);
                        $result = in_array($target, $mySupervisors, true) ? [$target] : [];
                        Log::info('Admin scope: specific supervisor', [
                            'target' => $target,
                            'my_supervisors' => $mySupervisors,
                            'result' => $result
                        ]);
                        return $result;
                    }
                    Log::warning('Admin scope: supervisor but no target specified');
                    return [];

                case 'researcher':
                    // Specific researcher selected from dropdown
                    if ($target) {
                        $myResearchers = $researchersForAdminSupervisors($user->id);
                        $result = in_array($target, $myResearchers, true) ? [$target] : [];
                        Log::info('Admin scope: specific researcher', [
                            'target' => $target,
                            'my_researchers' => $myResearchers,
                            'result' => $result
                        ]);
                        return $result;
                    }
                    Log::warning('Admin scope: researcher but no target specified');
                    return [];

                case 'all_researchers':
                    // All researchers under admin's supervisors
                    $result = $researchersForAdminSupervisors($user->id);
                    Log::info('Admin scope: all_researchers', [
                        'admin_id' => $user->id,
                        'count' => count($result)
                    ]);
                    return $result;

                case 'all_supervisors':
                case 'my_supervisors':
                    // All supervisors created by this admin
                    $result = $supervisorsForAdmin($user->id);
                    Log::info("Admin scope: {$scope}", [
                        'admin_id' => $user->id,
                        'count' => count($result)
                    ]);
                    return $result;

                case 'my_researchers':
                    // Researchers under a specific supervisor
                    if ($target) {
                        $mySupervisors = $supervisorsForAdmin($user->id);
                        if (in_array($target, $mySupervisors, true)) {
                            $result = $researchersForSupervisor($target);
                            Log::info('Admin scope: researchers for specific supervisor', [
                                'supervisor_id' => $target,
                                'researcher_ids' => $result,
                                'count' => count($result)
                            ]);
                            return $result;
                        }
                        Log::warning('Admin scope: supervisor not owned by admin', [
                            'target' => $target,
                            'admin_id' => $user->id
                        ]);
                        return [];
                    }
                    // All researchers under all admin's supervisors
                    $result = $researchersForAdminSupervisors($user->id);
                    Log::info('Admin scope: all researchers under my supervisors', [
                        'admin_id' => $user->id,
                        'count' => count($result)
                    ]);
                    return $result;

                case 'all':
                    // self + all supervisors + all researchers
                    $result = array_values(array_unique(array_merge(
                        [$user->id],
                        $supervisorsForAdmin($user->id),
                        $researchersForAdminSupervisors($user->id)
                    )));
                    Log::info('Admin scope: all (self + supervisors + researchers)', [
                        'admin_id' => $user->id,
                        'count' => count($result),
                        'breakdown' => [
                            'admin' => 1,
                            'supervisors' => count($supervisorsForAdmin($user->id)),
                            'researchers' => count($researchersForAdminSupervisors($user->id))
                        ]
                    ]);
                    return $result;

                default:
                    Log::warning('Admin scope: unknown scope, returning self', [
                        'scope' => $scope
                    ]);
                    return [$user->id];
            }
        }

        // -------- Superuser --------
        if ($role === 'superuser') {
            switch ($scope) {
                case 'self':
                    Log::info('Superuser scope: self', ['user_id' => $user->id]);
                    return [$user->id];

                case 'supervisor':
                    // Specific supervisor selected from dropdown
                    if ($target) {
                        Log::info('Superuser scope: specific supervisor', ['target' => $target]);
                        return [$target];
                    }
                    Log::warning('Superuser scope: supervisor but no target specified');
                    return [];

                case 'researcher':
                    // Specific researcher selected from dropdown
                    if ($target) {
                        Log::info('Superuser scope: specific researcher', ['target' => $target]);
                        return [$target];
                    }
                    Log::warning('Superuser scope: researcher but no target specified');
                    return [];

                case 'admin':
                    // Specific admin selected from dropdown
                    if ($target) {
                        Log::info('Superuser scope: specific admin', ['target' => $target]);
                        return [$target];
                    }
                    Log::warning('Superuser scope: admin but no target specified');
                    return [];

                case 'all_researchers':
                    $result = $allByRole('researcher');
                    Log::info('Superuser scope: all_researchers', [
                        'count' => count($result)
                    ]);
                    return $result;

                case 'all_supervisors':
                    $result = $allByRole('supervisor');
                    Log::info('Superuser scope: all_supervisors', [
                        'count' => count($result)
                    ]);
                    return $result;

                case 'all_admins':
                    $result = $allByRole('admin');
                    Log::info('Superuser scope: all_admins', [
                        'count' => count($result)
                    ]);
                    return $result;

                case 'my_researchers':
                    // Researchers under a specific supervisor
                    if ($target) {
                        $result = $researchersForSupervisor($target);
                        Log::info('Superuser scope: researchers for specific supervisor', [
                            'supervisor_id' => $target,
                            'count' => count($result)
                        ]);
                        return $result;
                    }
                    // All researchers
                    $result = $allByRole('researcher');
                    Log::info('Superuser scope: all researchers (no supervisor specified)', [
                        'count' => count($result)
                    ]);
                    return $result;

                case 'all':
                default:
                    // Everyone in the system
                    $result = array_values(array_unique(array_merge(
                        [$user->id],
                        $allByRole('researcher'),
                        $allByRole('supervisor'),
                        $allByRole('admin')
                    )));
                    Log::info('Superuser scope: all users', [
                        'count' => count($result),
                        'breakdown' => [
                            'superuser' => 1,
                            'researchers' => count($allByRole('researcher')),
                            'supervisors' => count($allByRole('supervisor')),
                            'admins' => count($allByRole('admin'))
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