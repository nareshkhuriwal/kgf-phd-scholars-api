<?php
// app/Http/Controllers/DashboardController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Support\ResolvesDashboardScope;
use App\Support\ResolvesApiScope;
use App\Models\User;
use App\Models\Paper;

class DashboardController extends Controller
{
    use OwnerAuthorizes, ResolvesDashboardScope, ResolvesApiScope;

    /**
     * Summary statistics for dashboard.
     */
    public function summary(Request $req)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Dashboard summary called', [
            'user_id' => $req->user()->id,
            'role'    => $req->user()->role,
            'scope'   => $req->query('scope', 'self'),
        ]);

        // Resolve user scope
        $userIds = $this->resolveDashboardUserIds($req);

        Log::info('Dashboard user IDs resolved', [
            'user_ids' => $userIds,
            'count'    => count($userIds),
        ]);

        if (empty($userIds)) {
            return response()->json([
                'data' => [
                    'totals' => [
                        'totalPapers'    => 0,
                        'reviewedPapers' => 0,
                        'inQueue'        => 0,
                        'started'        => 0,
                        'archived'       => 0,
                        'collections'    => 0,
                    ],
                    'weekly'  => [],
                    'yearly'  => [],
                    'derived' => [
                        'reviewCompletionRate' => 0,
                        'queuePressure'        => 0,
                    ],
                ],
            ]);
        }

        /* =======================
     * TOTALS (ARCHIVED INCLUDED)
     * ======================= */

        $totalPapers = Paper::withoutGlobalScopes()
            ->whereIn('created_by', $userIds)
            ->count();

        $archivedPapers = Paper::withoutGlobalScopes()
            ->whereIn('created_by', $userIds)
            ->where('review_status', 'archived')
            ->count();

        $reviewed = DB::table('reviews')
            ->whereIn('user_id', $userIds)
            ->where('status', 'done')
            ->count();

        $started = DB::table('reviews')
            ->whereIn('user_id', $userIds)
            ->where('status', '!=', 'done')
            ->count();

        // In queue = papers without completed or started review
        $inQueue = max($totalPapers - $reviewed - $started, 0);

        $collections = DB::table('collections')
            ->whereIn('user_id', $userIds)
            ->count();

        /* =======================
     * DERIVED KPIs
     * ======================= */

        $reviewCompletionRate = $totalPapers > 0
            ? round(($reviewed / $totalPapers) * 100, 1)
            : 0;

        $queuePressure = $totalPapers > 0
            ? round(($inQueue / $totalPapers) * 100, 1)
            : 0;

        /* =======================
     * YEAR-WISE (ARCHIVED INCLUDED)
     * ======================= */

        $yearStatsRaw = Paper::withoutGlobalScopes()
            ->select('year', DB::raw('COUNT(*) as total'))
            ->whereIn('created_by', $userIds)
            ->whereNotNull('year')
            ->whereBetween('year', [1900, now()->year])
            ->groupBy('year')
            ->orderBy('year')
            ->get();

        $yearLabels   = [];
        $yearCounts   = [];
        $yearPercents = [];

        foreach ($yearStatsRaw as $row) {
            $yearLabels[]   = (string) $row->year;
            $yearCounts[]   = (int) $row->total;
            $yearPercents[] = $totalPapers > 0
                ? round(($row->total / $totalPapers) * 100, 1)
                : 0;
        }

        /* =======================
     * WEEKLY / MONTHLY
     * ======================= */

        $weeksBack = (int) $req->query('weeks', 8);

        // These helper methods MUST also use Paper::withoutGlobalScopes()
        $weekly = $this->weeklyAddedVsReviewed($userIds, $weeksBack, true);

        $hasWeeklyEfficiency = collect($weekly['efficiency'] ?? [])->sum() > 0;

        if (!$hasWeeklyEfficiency) {
            $monthly = $this->monthlyReviewEfficiency($userIds);

            $weekly = [
                'mode'       => 'monthly',
                'labels'     => $monthly['labels'],
                'added'      => $monthly['added'],
                'reviewed'   => $monthly['reviewed'],
                'efficiency' => $monthly['efficiency'],
            ];
        } else {
            $weekly['mode'] = 'weekly';
        }

        Log::info('Dashboard summary generated', [
            'total_papers' => $totalPapers,
            'archived'     => $archivedPapers,
            'reviewed'     => $reviewed,
            'collections'  => $collections,
        ]);

        return response()->json([
            'data' => [
                'totals' => [
                    'totalPapers'    => $totalPapers,
                    'reviewedPapers' => $reviewed,
                    'inQueue'        => $inQueue,
                    'started'        => $started,
                    'archived'       => $archivedPapers,
                    'collections'    => $collections,
                ],
                'yearly' => [
                    'labels'   => $yearLabels,
                    'counts'   => $yearCounts,
                    'percents' => $yearPercents,
                ],
                'derived' => [
                    'reviewCompletionRate' => $reviewCompletionRate,
                    'queuePressure'        => $queuePressure,
                ],
                'weekly' => $weekly,
                'byCreatedBy' => $this->byCategoryForPaperCategory($userIds),
            ],
        ]);
    }


    /**
     * Daily series for the last N days (default 30).
     * Returns zero-filled arrays: labels[], added[], reviewed[], started[]
     * Aggregates across userIds from scope.
     */
    public function dailySeries(Request $req)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Dashboard daily series called', [
            'user_id' => $req->user()->id,
            'days' => $req->integer('days', 30)
        ]);

        $userIds = $this->resolveDashboardUserIds($req);

        if (empty($userIds)) {
            return response()->json([
                'data' => [
                    'labels'   => [],
                    'added'    => [],
                    'reviewed' => [],
                    'started'  => [],
                ],
            ]);
        }

        $days = max(1, min((int) $req->integer('days', 30), 180));

        $to   = Carbon::today();
        $from = (clone $to)->subDays($days - 1);

        [$labels, $added]    = $this->dailyCount($userIds, 'papers', 'created_at', $from, $to, 'created_by');
        [$_,      $reviewed] = $this->dailyCount($userIds, 'reviews', 'updated_at', $from, $to, 'user_id', ['status' => 'done']);
        [$__,     $started]  = $this->dailyCount($userIds, 'reviews', 'created_at', $from, $to, 'user_id', ['status' => ['!=', 'done']]);

        Log::info('Daily series generated', [
            'days' => $days,
            'data_points' => count($labels)
        ]);

        // cumulative reviewed (NEW)
        $cumulativeReviewed = [];
        $sum = 0;
        foreach ($reviewed as $v) {
            $sum += $v;
            $cumulativeReviewed[] = $sum;
        }

        return response()->json([
            'data' => compact('labels', 'added', 'reviewed', 'started', 'cumulativeReviewed'),
        ]);
    }

    /**
     * Weekly series for the last N ISO weeks (default 12).
     * Returns: labels (YYYY-Www), added[], reviewed[]
     * Aggregates across userIds from scope.
     */
    public function weeklySeries(Request $req)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Dashboard weekly series called', [
            'user_id' => $req->user()->id,
            'weeks' => $req->integer('weeks', 12)
        ]);

        $userIds = $this->resolveDashboardUserIds($req);

        if (empty($userIds)) {
            return response()->json([
                'data' => [
                    'labels'   => [],
                    'added'    => [],
                    'reviewed' => [],
                ],
            ]);
        }

        $weeks = max(1, min((int) $req->integer('weeks', 12), 52));
        $result = $this->weeklyAddedVsReviewed($userIds, $weeks, true);

        Log::info('Weekly series generated', [
            'weeks' => $weeks,
            'data_points' => count($result['labels'])
        ]);

        return response()->json([
            'data' => $result
        ]);
    }

    /**
     * Filters endpoint for dashboard dropdowns:
     *  - supervisors
     *  - researchers
     *  - admins (superuser only)
     *  - supervisorResearcherMap
     *
     * Rules:
     *  - superuser: sees ALL supervisors, researchers & admins
     *  - admin: sees ONLY their supervisors & researchers under those supervisors
     *  - supervisor: sees self + invited researchers
     *  - researcher: sees self + supervisors who invited them
     */
    public function filters(Request $req)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');
        $role = $user->role;
        $uid  = $user->id;

        Log::info('Dashboard filters called', [
            'user_id' => $uid,
            'role' => $role
        ]);

        /* ============================================================
         * ADMINS (superuser only)
         * ============================================================ */
        if ($role === 'superuser') {
            // Superuser → all admins
            $admins = User::where('role', 'admin')
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get();

            Log::info('Superuser: loaded all admins', [
                'count' => $admins->count()
            ]);
        } else {
            // Other roles don't see admins list
            $admins = collect([]);
        }

        /* ============================================================
         * SUPERVISORS
         * ============================================================ */
        if ($role === 'superuser') {
            // Superuser → all supervisors
            $supervisors = User::where('role', 'supervisor')
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get();

            Log::info('Superuser: loaded all supervisors', [
                'count' => $supervisors->count()
            ]);
        } elseif ($role === 'admin') {
            // Admin → only supervisors created by them
            $supervisors = User::where('role', 'supervisor')
                ->where('created_by', $uid)
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get();

            Log::info('Admin: loaded their supervisors', [
                'count' => $supervisors->count()
            ]);
        } elseif ($role === 'supervisor') {
            // Supervisor → only self
            $supervisors = User::where('id', $uid)
                ->select('id', 'name', 'email')
                ->get();
        } else {
            // Researcher → supervisors who invited them
            $supervisors = DB::table('researcher_invites')
                ->join('users', 'users.id', '=', 'researcher_invites.created_by')
                ->where('researcher_invites.researcher_email', $user->email)
                ->where('researcher_invites.status', 'accepted')
                ->whereNull('researcher_invites.revoked_at')
                ->select('users.id', 'users.name', 'users.email')
                ->distinct()
                ->orderBy('users.name')
                ->get();
        }

        /* ============================================================
         * RESEARCHERS
         * ============================================================ */
        if ($role === 'superuser') {
            // Superuser → all researchers
            $researchers = User::where('role', 'researcher')
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get();

            Log::info('Superuser: loaded all researchers', [
                'count' => $researchers->count()
            ]);
        } elseif ($role === 'admin') {
            // Admin → researchers under their supervisors
            $supervisorIds = User::where('role', 'supervisor')
                ->where('created_by', $uid)
                ->pluck('id')
                ->all();

            if (empty($supervisorIds)) {
                $researchers = collect([]);
            } else {
                $researchers = User::query()
                    ->select('users.id', 'users.name', 'users.email')
                    ->join('researcher_invites', 'users.email', '=', 'researcher_invites.researcher_email')
                    ->whereIn('researcher_invites.created_by', $supervisorIds)
                    ->where('researcher_invites.status', 'accepted')
                    ->whereNull('researcher_invites.revoked_at')
                    ->distinct()
                    ->orderBy('users.name')
                    ->get();
            }

            Log::info('Admin: loaded researchers under their supervisors', [
                'supervisor_count' => count($supervisorIds),
                'researcher_count' => $researchers->count()
            ]);
        } elseif ($role === 'supervisor') {
            // Supervisor → invited researchers
            $researchers = $this->fetchResearchersForSupervisor($uid);

            Log::info('Supervisor: loaded their researchers', [
                'count' => $researchers->count()
            ]);
        } else {
            // Researcher → only self
            $researchers = User::where('id', $uid)
                ->select('id', 'name', 'email')
                ->get();
        }

        /* ============================================================
         * SUPERVISOR → RESEARCHER MAP
         * (only for visible supervisors)
         * ============================================================ */
        $supervisorIds = collect($supervisors)->pluck('id')->all();
        $map = $this->buildSupervisorResearcherMap($supervisorIds);

        Log::info('Dashboard filters generated', [
            'admin_count' => count($admins),
            'supervisor_count' => count($supervisors),
            'researcher_count' => count($researchers),
            'map_entries' => count($map)
        ]);

        return response()->json([
            'data' => [
                'admins'                  => $admins,
                'supervisors'             => $supervisors,
                'researchers'             => $researchers,
                'supervisorResearcherMap' => $map,
            ],
        ]);
    }

    /**
     * Optional: dedicated endpoint to fetch researchers for a supervisor
     * ?supervisor_id=...
     * Used if you want to lazy-load researchers when a supervisor is selected.
     */
    public function researchersBySupervisor(Request $req)
    {
        $current      = $req->user() ?? abort(401, 'Unauthenticated');
        $supervisorId = (int) $req->query('supervisor_id');

        Log::info('Researchers by supervisor called', [
            'current_user_id' => $current->id,
            'supervisor_id' => $supervisorId
        ]);

        if (!$supervisorId) {
            return response()->json(['data' => []]);
        }

        // Superuser can query any supervisor
        if ($current->role === 'superuser') {
            $researchers = $this->fetchResearchersForSupervisor($supervisorId);
            return response()->json(['data' => $researchers]);
        }

        // Admin can query their supervisors
        if ($current->role === 'admin') {
            $isMySupervisor = User::where('id', $supervisorId)
                ->where('role', 'supervisor')
                ->where('created_by', $current->id)
                ->exists();

            if (!$isMySupervisor) {
                Log::warning('Admin attempted to access supervisor they did not create', [
                    'admin_id' => $current->id,
                    'supervisor_id' => $supervisorId
                ]);
                abort(403, 'Not allowed to access this supervisor');
            }

            $researchers = $this->fetchResearchersForSupervisor($supervisorId);
            return response()->json(['data' => $researchers]);
        }

        // Supervisor can only query themselves
        if ($current->role === 'supervisor' && $current->id === $supervisorId) {
            $researchers = $this->fetchResearchersForSupervisor($supervisorId);
            return response()->json(['data' => $researchers]);
        }

        Log::warning('Unauthorized access to researcher list', [
            'current_user_id' => $current->id,
            'current_role' => $current->role,
            'requested_supervisor_id' => $supervisorId
        ]);

        abort(403, 'Not allowed');
    }

    // ==================== Helpers ====================

    private function inferQueueCount(array $userIds, int $totalPapers, int $reviewed, int $started): int
    {
        if (empty($userIds)) {
            return 0;
        }

        // If you have a queue table (e.g., review_queue with user_id), use:
        return (int) DB::table('review_queue')
            ->whereIn('user_id', $userIds)
            ->count();

        // Fallback inference: papers with no finished/started review
        // $inReviews = DB::table('reviews')
        //     ->whereIn('user_id', $userIds)
        //     ->distinct('paper_id')
        //     ->count('paper_id');

        // return max($totalPapers - $inReviews, 0);
    }

    /**
     * Counts per day within [from..to] for a table, filtered by multiple user IDs + optional status.
     * @return array [$labels, $series]
     */
    private function dailyCount(
        array $userIds,
        string $table,
        string $dateColumn,
        Carbon $from,
        Carbon $to,
        string $userColumn,
        array $extraFilter = []
    ): array {
        if (empty($userIds)) {
            return [[], []];
        }

        $q = DB::table($table)
            ->selectRaw('DATE(' . $dateColumn . ') as d, COUNT(*) as c')
            ->whereBetween($dateColumn, [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereIn($userColumn, $userIds);

        // extra filter supports equality or ['!=','done'] style
        foreach ($extraFilter as $col => $val) {
            if (is_array($val) && count($val) === 2) {
                [$op, $v] = $val;
                $q->where($col, $op, $v);
            } else {
                $q->where($col, $val);
            }
        }

        $rows = $q->groupBy('d')->orderBy('d')->pluck('c', 'd')->all();

        // zero-fill
        $labels = [];
        $series = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $key      = $day->toDateString();
            $labels[] = $key;
            $series[] = (int) ($rows[$key] ?? 0);
        }

        return [$labels, $series];
    }

    /**
     * Weekly counts (ISO week, Monday start) for "papers added" and "reviews done".
     * Aggregates for multiple user IDs.
     *
     * @return array [$labels, $added, $reviewed] or assoc if $asAssoc=true
     */
    /**
     * Weekly added vs reviewed (+ efficiency)
     */
    private function weeklyAddedVsReviewed(array $userIds, int $weeksBack, bool $asAssoc = false): array
    {
        if (empty($userIds)) {
            $empty = [
                'labels' => [],
                'added' => [],
                'reviewed' => [],
                'started' => [],
                'efficiency' => [],
            ];
            return $asAssoc ? $empty : [[], [], []];
        }

        $end   = Carbon::now()->startOfWeek(Carbon::MONDAY)->endOfWeek(Carbon::SUNDAY);
        $start = (clone $end)->subWeeks($weeksBack - 1)->startOfWeek(Carbon::MONDAY);

        /* -------------------------------
     * Papers added per ISO week
     * ------------------------------- */
        $addedRows = DB::table('papers')
            ->selectRaw("YEARWEEK(created_at, 3) as yw, COUNT(*) as c")
            ->whereIn('created_by', $userIds)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('yw')
            ->pluck('c', 'yw')
            ->all();

        /* -------------------------------
     * Reviews started per ISO week
     * ------------------------------- */
        $startedRows = DB::table('reviews')
            ->selectRaw("YEARWEEK(created_at, 3) as yw, COUNT(*) as c")
            ->whereIn('user_id', $userIds)
            ->where('status', '!=', 'done')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('yw')
            ->pluck('c', 'yw')
            ->all();

        /* -------------------------------
     * Reviews completed per ISO week
     * ------------------------------- */
        $reviewRows = DB::table('reviews')
            ->selectRaw("YEARWEEK(updated_at, 3) as yw, COUNT(*) as c")
            ->whereIn('user_id', $userIds)
            ->where('status', 'done')
            ->whereBetween('updated_at', [$start, $end])
            ->groupBy('yw')
            ->pluck('c', 'yw')
            ->all();

        /* -------------------------------
     * Backlog at START of first week
     * ------------------------------- */
        $initialBacklog = DB::table('papers')
            ->whereIn('created_by', $userIds)
            ->where('created_at', '<', $start)
            ->count()
            -
            DB::table('reviews')
            ->whereIn('user_id', $userIds)
            ->where('status', 'done')
            ->where('updated_at', '<', $start)
            ->count();

        $backlog = max(0, $initialBacklog);

        $labels = $added = $reviewed = $started = $efficiency = [];

        $cursor = $start->copy();

        while ($cursor <= $end) {
            $isoY = $cursor->isoWeekYear();
            $isoW = str_pad($cursor->isoWeek(), 2, '0', STR_PAD_LEFT);
            $key  = (int) ($isoY . $isoW);

            $a = (int) ($addedRows[$key] ?? 0);
            $r = (int) ($reviewRows[$key] ?? 0);
            $s = (int) ($startedRows[$key] ?? 0);

            $labels[]   = "{$isoY}-W{$isoW}";
            $added[]    = $a;
            $reviewed[] = $r;
            $started[]  = $s;

            // ✅ Backlog-based efficiency
            $efficiency[] = $backlog > 0
                ? round(($r / $backlog) * 100, 1)
                : 0;

            // backlog evolves week-by-week
            $backlog = max(0, $backlog + $a - $r);

            $cursor->addWeek();
        }

        return $asAssoc
            ? compact('labels', 'added', 'reviewed', 'started', 'efficiency')
            : [$labels, $added, $reviewed];
    }


    /**
     * Monthly review efficiency (fallback)
     * Returns last N months
     */
    private function monthlyReviewEfficiency(array $userIds, int $monthsBack = 6): array
    {
        if (empty($userIds)) {
            return [
                'labels' => [],
                'added' => [],
                'reviewed' => [],
                'efficiency' => [],
            ];
        }

        $end   = Carbon::now()->endOfMonth();
        $start = (clone $end)->subMonths($monthsBack - 1)->startOfMonth();

        /* -------------------------------
     * Added per month
     * ------------------------------- */
        $addedRows = DB::table('papers')
            ->selectRaw("DATE_FORMAT(created_at,'%Y-%m') as ym, COUNT(*) as c")
            ->whereIn('created_by', $userIds)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('ym')
            ->pluck('c', 'ym')
            ->all();

        /* -------------------------------
     * Reviewed per month
     * ------------------------------- */
        $reviewedRows = DB::table('reviews')
            ->selectRaw("DATE_FORMAT(updated_at,'%Y-%m') as ym, COUNT(*) as c")
            ->whereIn('user_id', $userIds)
            ->where('status', 'done')
            ->whereBetween('updated_at', [$start, $end])
            ->groupBy('ym')
            ->pluck('c', 'ym')
            ->all();

        /* -------------------------------
     * Backlog at START of first month
     * ------------------------------- */
        $initialBacklog = DB::table('papers')
            ->whereIn('created_by', $userIds)
            ->where('created_at', '<', $start)
            ->count()
            -
            DB::table('reviews')
            ->whereIn('user_id', $userIds)
            ->where('status', 'done')
            ->where('updated_at', '<', $start)
            ->count();

        $backlog = max(0, $initialBacklog);

        $labels = $added = $reviewed = $efficiency = [];

        $cursor = $start->copy();

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');

            $a = (int) ($addedRows[$key] ?? 0);
            $r = (int) ($reviewedRows[$key] ?? 0);

            $labels[]   = $cursor->format('M Y');
            $added[]    = $a;
            $reviewed[] = $r;

            // ✅ backlog-based efficiency
            $efficiency[] = $backlog > 0
                ? round(($r / $backlog) * 100, 1)
                : 0;

            $backlog = max(0, $backlog + $a - $r);

            $cursor->addMonth();
        }

        return compact('labels', 'added', 'reviewed', 'efficiency');
    }



    /**
     * Optional: distribution by paper category for given users.
     */
    private function byCategoryForUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = DB::table('papers')
            ->selectRaw('COALESCE(publisher,"Uncategorized") as name, COUNT(*) as value')
            ->whereIn('created_by', $userIds)
            ->groupBy('name')
            ->orderByDesc('value')
            ->get();

        return $rows->toArray();
    }

    /**
     * Helper: researchers for one supervisor.
     */
    private function fetchResearchersForSupervisor(int $supervisorId)
    {
        return User::query()
            ->select('users.id', 'users.name', 'users.email')
            ->join('researcher_invites', 'users.email', '=', 'researcher_invites.researcher_email')
            ->where('researcher_invites.created_by', $supervisorId)
            ->where('researcher_invites.status', 'accepted')
            ->whereNull('researcher_invites.revoked_at')
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Helper: map supervisor_id => [researcher_ids]
     */
    private function buildSupervisorResearcherMap(array $supervisorIds): array
    {
        if (empty($supervisorIds)) {
            return [];
        }

        $rows = DB::table('researcher_invites')
            ->join('users', 'users.email', '=', 'researcher_invites.researcher_email')
            ->whereIn('researcher_invites.created_by', $supervisorIds)
            ->where('researcher_invites.status', 'accepted')
            ->whereNull('researcher_invites.revoked_at')
            ->select('researcher_invites.created_by as supervisor_id', 'users.id as researcher_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $sid = (int) $row->supervisor_id;
            $rid = (int) $row->researcher_id;
            $map[$sid][] = $rid;
        }

        return $map;
    }


    /**
     * Distribution of papers by creator (user).
     * Returns [{ name, value }]
     */
    private function byCreatedByForUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = DB::table('papers')
            ->join('users', 'users.id', '=', 'papers.created_by')
            ->whereIn('papers.created_by', $userIds)
            ->selectRaw('users.id as user_id, COUNT(*) as value')
            ->groupBy('users.id')
            ->orderByDesc('value')
            ->get();

        // Map ID → display name safely in PHP
        return $rows->map(function ($row) {
            $user = User::find($row->user_id);
            return [
                'name'  => $user?->name ?: $user?->email ?: "User #{$row->user_id}",
                'value' => (int) $row->value,
            ];
        })->values()->toArray();
    }



    private function byCategoryForPaperCategory(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = DB::table('papers')
            ->selectRaw('COALESCE(publisher, "Uncategorized") as name, COUNT(*) as value')
            ->whereIn('created_by', $userIds)
            ->groupBy('name')
            ->orderByDesc('value')
            ->get();

        return $rows->toArray();
    }
}
