<?php
// app/Http/Controllers/DashboardController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Support\ResolvesDashboardScope;
use App\Models\User;

class DashboardController extends Controller
{
    use OwnerAuthorizes, ResolvesDashboardScope;

    /**
     * KPIs + weekly series (last N ISO weeks).
     * Role/scope aware via ?scope=&user_id=
     */
    public function summary(Request $req)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        // resolve which user IDs to aggregate over
        $userIds = $this->resolveDashboardUserIds($req);
<<<<<<< HEAD
=======
        // if (!in_array($req->user()->id, $userIds, true)) {
        //     array_unshift($userIds, $req->user()->id); // add at front
        // }

>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
        if (empty($userIds)) {
            return response()->json([
                'data' => [
                    'totals' => [
                        'totalPapers'    => 0,
                        'reviewedPapers' => 0,
                        'inQueue'        => 0,
                        'started'        => 0,
                        'collections'    => 0,
                    ],
                    'weekly' => [
                        'labels'   => [],
                        'added'    => [],
                        'reviewed' => [],
                    ],
                ],
            ]);
        }

        // ----- Totals -----
        $totalPapers  = DB::table('papers')->whereIn('created_by', $userIds)->count();

        // Reviews table should have: user_id, status ('done'|'pending'|...)
        $reviewed     = DB::table('reviews')
            ->whereIn('user_id', $userIds)
            ->where('status', 'done')
            ->count();

        $started      = DB::table('reviews')
            ->whereIn('user_id', $userIds)
            ->where('status', '!=', 'done')
            ->count();

        // If you have a dedicated queue table, use it; otherwise "in queue" = papers with no review row
        $queueCount   = $this->inferQueueCount($userIds, $totalPapers, $reviewed, $started);

        $collections  = DB::table('collections')
            ->whereIn('user_id', $userIds)
            ->count();

        // ----- Weekly (last 8 ISO weeks, Monday start) -----
        $weeksBack = (int) $req->query('weeks', 8);
        [$labels, $added, $reviewedW] = $this->weeklyAddedVsReviewed($userIds, $weeksBack);

        return response()->json([
            'data' => [
                'totals' => [
                    'totalPapers'    => $totalPapers,
                    'reviewedPapers' => $reviewed,
                    'inQueue'        => $queueCount,
                    'started'        => $started,
                    'collections'    => $collections,
                ],
                'weekly' => [
                    'labels'   => $labels,
                    'added'    => $added,
                    'reviewed' => $reviewedW,
                ],
                // optional: category distribution if you keep a category field on papers
                // 'byCategory' => $this->byCategoryForUsers($userIds),
            ]
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

        return response()->json([
            'data' => compact('labels', 'added', 'reviewed', 'started')
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

        return response()->json([
            'data' => $this->weeklyAddedVsReviewed($userIds, $weeks, true)
        ]);
    }

    /**
     * Filters endpoint for dropdowns:
     *  - supervisors
     *  - researchers
     *  - supervisorResearcherMap (supervisor_id => [researcher_ids])
     *
     * Behaviour:
     *  - Admin:
     *      supervisors = all supervisors
     *      researchers = all researchers
     *  - Supervisor:
     *      supervisors = [self]
     *      researchers = mapped researchers (via invites)
     *  - Researcher:
     *      supervisors = supervisors who invited them (accepted)
     *      researchers = [self]
     */
    public function filters(Request $req)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');
        $role = $user->role ?? 'researcher';

        // ---------- Supervisors list ----------
        if ($role === 'admin') {
            $supervisors = User::where('role', 'supervisor')
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get();
        } elseif ($role === 'supervisor') {
            // only self
            $supervisors = User::where('id', $user->id)
                ->select('id', 'name', 'email')
                ->get();
        } else { // researcher → supervisors that invited them
            $supervisors = DB::table('researcher_invites')
                ->join('users', 'users.id', '=', 'researcher_invites.created_by')
                ->where('researcher_invites.researcher_email', $user->email)
                ->where('researcher_invites.status', 'accepted')
                ->select('users.id', 'users.name', 'users.email')
                ->distinct()
                ->get();
        }

        // ---------- Researchers list ----------
        if ($role === 'admin') {
            $researchers = User::where('role', 'researcher')
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get();
        } elseif ($role === 'supervisor') {
            $researchers = $this->fetchResearchersForSupervisor($user->id);
        } else { // researcher: just self
            $researchers = User::where('id', $user->id)
                ->select('id', 'name', 'email')
                ->get();
        }

        // ---------- Supervisor → researcher mapping ----------
        $map = $this->buildSupervisorResearcherMap($supervisors->pluck('id')->all());

        return response()->json([
            'data' => [
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

        if (!$supervisorId) {
            return response()->json(['data' => []]);
        }

        // Only admin or that supervisor can query directly
        if ($current->role !== 'admin' && $current->id !== $supervisorId) {
            abort(403, 'Not allowed');
        }

        $researchers = $this->fetchResearchersForSupervisor($supervisorId);

        return response()->json(['data' => $researchers]);
    }

    // ==================== Helpers ====================

    private function inferQueueCount(array $userIds, int $totalPapers, int $reviewed, int $started): int
    {
        if (empty($userIds)) {
            return 0;
        }

        // If you have a queue table (e.g., review_queue with user_id), switch to:
        // return (int) DB::table('review_queue')->whereIn('user_id', $userIds)->count();

        // Fallback inference: papers with no finished/started review
        $inReviews = DB::table('reviews')
            ->whereIn('user_id', $userIds)
            ->distinct('paper_id')
            ->count('paper_id');

        return max($totalPapers - $inReviews, 0);
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
    private function weeklyAddedVsReviewed(array $userIds, int $weeksBack, bool $asAssoc = false): array
    {
        if (empty($userIds)) {
            $empty = ['labels' => [], 'added' => [], 'reviewed' => []];
            return $asAssoc ? $empty : [[], [], []];
        }

        $end   = Carbon::now()->startOfWeek(Carbon::MONDAY)->endOfWeek(Carbon::SUNDAY);
        $start = (clone $end)->subWeeks($weeksBack - 1)->startOfWeek(Carbon::MONDAY);

        // papers created per ISO week
        $addedRows = DB::table('papers')
            ->selectRaw("YEARWEEK(created_at, 3) as yw, COUNT(*) as c") // ISO week
            ->whereIn('created_by', $userIds)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('yw')
            ->pluck('c', 'yw')
            ->all();

        // reviews completed per ISO week (use updated_at when status flips to 'done')
        $revRows = DB::table('reviews')
            ->selectRaw("YEARWEEK(updated_at, 3) as yw, COUNT(*) as c")
            ->whereIn('user_id', $userIds)
            ->where('status', 'done')
            ->whereBetween('updated_at', [$start, $end])
            ->groupBy('yw')
            ->pluck('c', 'yw')
            ->all();

        $labels = [];
        $added  = [];
        $review = [];

        $cursor = $start->copy();
        while ($cursor <= $end) {
            $isoY  = $cursor->isoWeekYear();
            $isoW  = str_pad($cursor->isoWeek(), 2, '0', STR_PAD_LEFT);
            $label = "{$isoY}-W{$isoW}";
            $labels[] = $label;

            // YEARWEEK(...,3) format matches "YYYYWW" numeric; rebuild the key
            $ywKey   = (int) ($isoY . $isoW);
            $added[]  = (int) ($addedRows[$ywKey] ?? 0);
            $review[] = (int) ($revRows[$ywKey] ?? 0);

            $cursor->addWeek();
        }

        return $asAssoc
            ? ['labels' => $labels, 'added' => $added, 'reviewed' => $review]
            : [$labels, $added, $review];
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
            ->selectRaw('COALESCE(category,"Uncategorized") as name, COUNT(*) as value')
            ->whereIn('created_by', $userIds)
            ->groupBy('name')
            ->orderByDesc('value')
            ->limit(10)
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
}
