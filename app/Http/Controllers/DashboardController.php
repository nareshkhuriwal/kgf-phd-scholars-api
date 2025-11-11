<?php
// app/Http/Controllers/DashboardController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use App\Http\Controllers\Concerns\OwnerAuthorizes;

class DashboardController extends Controller
{
    use OwnerAuthorizes;

    /**
     * KPIs + weekly series (last 8 ISO weeks).
     * Output matches the Redux slice you already wired.
     */
    public function summary(Request $req)
    {
        $uid = $req->user()->id ?? abort(401, 'Unauthenticated');

        // ----- Totals -----
        $totalPapers  = DB::table('papers')->where('created_by', $uid)->count();

        // Reviews table should have: user_id, status ('done'|'pending'|...)
        $reviewed     = DB::table('reviews')->where('user_id', $uid)->where('status', 'done')->count();
        $started      = DB::table('reviews')->where('user_id', $uid)->where('status', '!=', 'done')->count();

        // If you have a dedicated queue table, use it; otherwise "in queue" = papers with no review row
        $queueCount   = $this->inferQueueCount($uid, $totalPapers, $reviewed, $started);

        $collections  = DB::table('collections')->where('user_id', $uid)->count();

        // ----- Weekly (last 8 ISO weeks, Monday start) -----
        $weeksBack = (int) request('weeks', 8);
        [$labels, $added, $reviewedW] = $this->weeklyAddedVsReviewed($uid, $weeksBack);

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
                // 'byCategory' => $this->byCategory($uid),
            ]
        ]);
    }

    /**
     * Daily series for the last N days (default 30).
     * Returns zero-filled arrays: labels[], added[], reviewed[], started[]
     */
    public function dailySeries(Request $req)
    {
        $uid  = $req->user()->id ?? abort(401, 'Unauthenticated');
        $days = max(1, min((int) $req->integer('days', 30), 180));

        $to   = Carbon::today();
        $from = (clone $to)->subDays($days - 1);

        [$labels, $added]    = $this->dailyCount($uid, 'papers', 'created_at', $from, $to, 'created_by');
        [$_,      $reviewed] = $this->dailyCount($uid, 'reviews', 'updated_at', $from, $to, 'user_id', ['status' => 'done']);
        [$__,     $started]  = $this->dailyCount($uid, 'reviews', 'created_at', $from, $to, 'user_id', ['status' => ['!=', 'done']]);

        return response()->json([
            'data' => compact('labels', 'added', 'reviewed', 'started')
        ]);
    }

    /**
     * Weekly series for the last N ISO weeks (default 12).
     * Returns: labels (YYYY-Www), added[], reviewed[]
     */
    public function weeklySeries(Request $req)
    {
        $uid   = $req->user()->id ?? abort(401, 'Unauthenticated');
        $weeks = max(1, min((int) $req->integer('weeks', 12), 52));

        return response()->json([
            'data' => $this->weeklyAddedVsReviewed($uid, $weeks, true) // same aggregator, explicit return format
        ]);
    }

    // -------------------- Helpers --------------------

    private function inferQueueCount(int $uid, int $totalPapers, int $reviewed, int $started): int
    {
        // If you have a queue table (e.g., review_queue with user_id), switch to:
        // return (int) DB::table('review_queue')->where('user_id', $uid)->count();

        // Fallback inference: papers with no finished/started review
        $inReviews = DB::table('reviews')->where('user_id', $uid)->distinct('paper_id')->count('paper_id');
        return max($totalPapers - $inReviews, 0);
    }

    /**
     * Counts per day within [from..to] for a table, filtered by user column + optional status.
     * @return array [$labels, $series]
     */
    private function dailyCount(
        int $uid,
        string $table,
        string $dateColumn,
        Carbon $from,
        Carbon $to,
        string $userColumn,
        array $extraFilter = []
    ): array {
        // Build base
        $q = DB::table($table)->selectRaw('DATE('.$dateColumn.') as d, COUNT(*) as c')
            ->whereBetween($dateColumn, [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where($userColumn, $uid);

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
            $key = $day->toDateString();
            $labels[] = $key;
            $series[] = (int) ($rows[$key] ?? 0);
        }
        return [$labels, $series];
    }

    /**
     * Weekly counts (ISO week, Monday start) for "papers added" and "reviews done".
     * @return array [$labels, $added, $reviewed]
     */
    private function weeklyAddedVsReviewed(int $uid, int $weeksBack, bool $asAssoc = false): array
    {
        $end   = Carbon::now()->startOfWeek(Carbon::MONDAY)->endOfWeek(Carbon::SUNDAY);
        $start = (clone $end)->subWeeks($weeksBack - 1)->startOfWeek(Carbon::MONDAY);

        // papers created per ISO week
        $addedRows = DB::table('papers')
            ->selectRaw("YEARWEEK(created_at, 3) as yw, COUNT(*) as c") // mode=3 => ISO week (MySQL/MariaDB)
            ->where('created_by', $uid)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('yw')
            ->pluck('c', 'yw')
            ->all();

        // reviews completed per ISO week (use updated_at when status flips to 'done')
        $revRows = DB::table('reviews')
            ->selectRaw("YEARWEEK(updated_at, 3) as yw, COUNT(*) as c")
            ->where('user_id', $uid)
            ->where('status', 'done')
            ->whereBetween('updated_at', [$start, $end])
            ->groupBy('yw')
            ->pluck('c', 'yw')
            ->all();

        // Zero-fill by iterating weeks
        $labels = [];
        $added  = [];
        $review = [];

        $cursor = $start->copy();
        while ($cursor <= $end) {
            $isoY = $cursor->isoWeekYear();
            $isoW = str_pad($cursor->isoWeek(), 2, '0', STR_PAD_LEFT);
            $label = "{$isoY}-W{$isoW}";
            $labels[] = $label;

            // YEARWEEK(...,3) format matches "YYYYWW" numeric; rebuild the key to look it up
            $ywKey = (int) ($isoY . $isoW);
            $added[]  = (int) ($addedRows[$ywKey] ?? 0);
            $review[] = (int) ($revRows[$ywKey] ?? 0);

            $cursor->addWeek();
        }

        return $asAssoc ? ['labels'=>$labels,'added'=>$added,'reviewed'=>$review] : [$labels, $added, $review];
    }

    /**
     * Optional: distribution by paper category (if you keep a 'category' column).
     */
    private function byCategory(int $uid): array
    {
        $rows = DB::table('papers')
            ->selectRaw('COALESCE(category,"Uncategorized") as name, COUNT(*) as value')
            ->where('created_by', $uid)
            ->groupBy('name')
            ->orderByDesc('value')
            ->limit(10)
            ->get();

        return $rows->toArray();
    }
}
