<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\ResolvesDashboardScope;
use Illuminate\Http\JsonResponse;
use Throwable;

class AnalyticsReportController extends Controller
{
    use ResolvesDashboardScope;

    /**
     * GET /api/reports/analytics/overview
     * Returns all fixed analytics blocks for dashboard reports
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user() ?? abort(401, 'Unauthenticated');

        try {
            // Resolve scoped users (admin / supervisor / researcher safe)
            $userIds = $this->normalizeUserIds(
                $this->resolveDashboardUserIds($request)
            );

            Log::info('Analytics overview requested', [
                'requested_by' => $user->id,
                'user_ids'     => $userIds,
            ]);

            return response()->json([
                'cooccurrence'        => $this->problemSolutionCooccurrence($userIds),
                'cooccurrence_matrix' => $this->cooccurrenceMatrix($userIds),   // ✅ NEW
                'aggregated_matrix'   => $this->aggregatedMatrix($userIds),     // ✅ NEW

                'problem_counts'     => $this->problemCounts($userIds),
                'solution_counts'    => $this->solutionCounts($userIds),
                'row_percentages'    => $this->rowWisePercentages($userIds),
                'dominant_solutions' => $this->dominantSolutions($userIds),
                'underexplored_gaps' => $this->underexploredGaps($userIds),
            ]);
        } catch (Throwable $e) {
            Log::error('Analytics overview failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            abort(500, 'Failed to generate analytics report');
        }
    }

    /* ============================================================
     | Core Builders
     ============================================================ */

    /**
     * Problem × Solution co-occurrence matrix
     */
    private function problemSolutionCooccurrence(array $userIds): array
    {
        return DB::table('review_tags as rp')
            ->join('review_tags as rs', function ($j) {
                $j->on('rp.review_id', '=', 'rs.review_id')
                    ->where('rs.tag_type', '=', 'solution');
            })
            ->join('tags as p', 'p.id', '=', 'rp.tag_id')
            ->join('tags as s', 's.id', '=', 'rs.tag_id')
            ->join('reviews as r', 'r.id', '=', 'rp.review_id')
            ->where('rp.tag_type', 'problem')
            ->whereIn('r.user_id', $userIds)
            ->where('r.status', '!=', 'archived')
            ->select([
                'p.name as problem',
                's.name as solution',
                DB::raw('COUNT(DISTINCT r.id) as count'),
            ])
            ->groupBy('p.name', 's.name')
            ->orderByDesc('count')
            ->get()
            ->values()
            ->toArray();
    }

    /**
     * Total count per problem tag
     */
    private function problemCounts(array $userIds): array
    {
        return DB::table('review_tags as rt')
            ->join('tags as t', 't.id', '=', 'rt.tag_id')
            ->join('reviews as r', 'r.id', '=', 'rt.review_id')
            ->where('rt.tag_type', 'problem')
            ->whereIn('r.user_id', $userIds)
            ->where('r.status', '!=', 'archived')
            ->select([
                't.name as problem',
                DB::raw('COUNT(DISTINCT r.id) as count'),
            ])
            ->groupBy('t.name')
            ->orderByDesc('count')
            ->get()
            ->values()
            ->toArray();
    }

    /**
     * Total count per solution tag
     */
    private function solutionCounts(array $userIds): array
    {
        return DB::table('review_tags as rt')
            ->join('tags as t', 't.id', '=', 'rt.tag_id')
            ->join('reviews as r', 'r.id', '=', 'rt.review_id')
            ->where('rt.tag_type', 'solution')
            ->whereIn('r.user_id', $userIds)
            ->where('r.status', '!=', 'archived')
            ->select([
                't.name as solution',
                DB::raw('COUNT(DISTINCT r.id) as count'),
            ])
            ->groupBy('t.name')
            ->orderByDesc('count')
            ->get()
            ->values()
            ->toArray();
    }

    /**
     * Row-wise percentage distribution per problem
     */
    private function rowWisePercentages(array $userIds): array
    {
        $rows = collect($this->problemSolutionCooccurrence($userIds))
            ->groupBy('problem');

        return $rows->map(function ($items) {
            $total = $items->sum('count');

            return $items->map(fn($r) => [
                'solution'   => $r->solution,
                'percentage' => $total > 0
                    ? round(($r->count / $total) * 100, 2)
                    : 0.0,
            ])->values();
        })->toArray();
    }

    /**
     * Dominant solution per problem
     */
    private function dominantSolutions(array $userIds): array
    {
        return collect($this->problemSolutionCooccurrence($userIds))
            ->groupBy('problem')
            ->map(fn($rows) => $rows->sortByDesc('count')->first())
            ->values()
            ->toArray();
    }

    /**
     * Underexplored problem–solution gaps
     */
    private function underexploredGaps(array $userIds): array
    {
        return collect($this->problemSolutionCooccurrence($userIds))
            ->filter(fn($r) => (int) $r->count <= 2)
            ->values()
            ->toArray();
    }

    /* ============================================================
     | Utilities
     ============================================================ */

    /**
     * Normalize and sanitize dashboard user IDs
     */
    private function normalizeUserIds(array $userIds): array
    {
        return array_values(
            array_unique(
                array_map(
                    'intval',
                    array_filter($userIds)
                )
            )
        );
    }

    private function cooccurrenceMatrix(array $userIds): array
    {
        $rows = $this->problemSolutionCooccurrence($userIds);

        $problems = collect($rows)->pluck('problem')->unique()->values();
        $solutions = collect($rows)->pluck('solution')->unique()->values();

        $matrix = [];

        foreach ($problems as $p) {
            $row = ['problem' => $p];
            foreach ($solutions as $s) {
                $row[$s] = collect($rows)
                    ->first(fn($r) => $r->problem === $p && $r->solution === $s)
                    ->count ?? 0;
            }
            $matrix[] = $row;
        }

        return [
            'problems'  => $problems,
            'solutions' => $solutions,
            'matrix'    => $matrix
        ];
    }


    private function aggregatedMatrix(array $userIds): array
    {
        $rows = $this->problemSolutionCooccurrence($userIds);

        $problems = collect($rows)->pluck('problem')->unique()->values();
        $solutions = collect($rows)->pluck('solution')->unique()->values();

        $matrix = [];

        foreach ($problems as $p) {
            $row = ['problem' => $p];
            foreach ($solutions as $s) {
                $row[$s] = collect($rows)
                    ->where('problem', $p)
                    ->where('solution', $s)
                    ->sum('count');
            }
            $matrix[] = $row;
        }

        return [
            'problems'  => $problems,
            'solutions' => $solutions,
            'matrix'    => $matrix
        ];
    }
}
