<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Services\CitationFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Concerns\OwnerAuthorizes;

class CitationRenderController extends Controller
{
    use OwnerAuthorizes;

    /**
     * Get citations for a paper in a given style
     */
    public function index($paperId, Request $request)
    {
        Log::info('📘 Citation render request started', [
            'paper_id' => $paperId,
            'query' => $request->query(),
            'user_id' => optional($request->user())->id,
        ]);

        $style = strtolower($request->query('style', 'mla'));

        // ----------------------------
        // Validate citation style
        // ----------------------------
        $availableStyles = array_keys(CitationFormatter::getAvailableStyles());

        if (!in_array($style, $availableStyles, true)) {
            Log::warning('❌ Invalid citation style requested', [
                'paper_id' => $paperId,
                'requested_style' => $style,
                'available_styles' => $availableStyles,
            ]);

            return response()->json([
                'error' => 'Invalid citation style',
                'available_styles' => $availableStyles,
            ], 400);
        }

        Log::info('✅ Citation style validated', [
            'paper_id' => $paperId,
            'style' => $style,
        ]);

        // ----------------------------
        // Load review with ordered citations
        // ----------------------------
        $review = Review::where('paper_id', $paperId)
            ->with([
                'citations' => function ($q) {
                    $q->with('type')
                      ->orderBy('review_citations.first_used_order', 'asc');
                }
            ])
            ->first();

        if (!$review) {
            Log::warning('⚠️ Review not found for citation render', [
                'paper_id' => $paperId,
            ]);

            return response()->json([
                'style' => $style,
                'count' => 0,
                'citations' => [],
                'message' => 'No review found for this paper',
            ], 404);
        }

        Log::info('📄 Review loaded for citation render', [
            'review_id' => $review->id,
            'paper_id' => $review->paper_id,
            'citation_count' => $review->citations->count(),
        ]);

        // ----------------------------
        // Authorization (owner-based)
        // ----------------------------
        $this->authorizeOwner($review, 'created_by');

        Log::info('🔒 Authorization passed for citation render', [
            'review_id' => $review->id,
            'user_id' => optional($request->user())->id,
        ]);

        if ($review->citations->isEmpty()) {
            Log::info('ℹ️ No citations to render', [
                'review_id' => $review->id,
                'paper_id' => $paperId,
            ]);

            return response()->json([
                'style' => $style,
                'count' => 0,
                'citations' => [],
            ]);
        }

        // ----------------------------
        // Format citations
        // ----------------------------
        Log::info('🧾 Formatting citations', [
            'review_id' => $review->id,
            'style' => $style,
            'orders' => $review->citations->pluck('pivot.first_used_order')->toArray(),
        ]);

        $citations = $review->citations->map(function ($c) use ($style, $review) {
            try {
                return [
                    'citation_id' => $c->id,
                    'order'       => $c->pivot->first_used_order,
                    'text'        => CitationFormatter::format(
                        $c,
                        $style,
                        $c->pivot->first_used_order
                    ),
                    'title'   => $c->title,
                    'authors' => $c->authors,
                    'year'    => $c->year,
                    'type'    => optional($c->type)->code,
                ];
            } catch (\Throwable $e) {
                Log::error('🔥 Citation formatting failed', [
                    'review_id' => $review->id,
                    'citation_id' => $c->id,
                    'order' => $c->pivot->first_used_order,
                    'style' => $style,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'citation_id' => $c->id,
                    'order' => $c->pivot->first_used_order,
                    'text' => '[Formatting error]',
                    'error' => true,
                ];
            }
        })->values();

        Log::info('✅ Citation render completed', [
            'paper_id' => $paperId,
            'review_id' => $review->id,
            'style' => $style,
            'rendered_count' => $citations->count(),
        ]);

        return response()->json([
            'style'     => $style,
            'count'     => $citations->count(),
            'citations' => $citations,
        ]);
    }

    /**
     * Available citation styles
     */
    public function styles()
    {
        Log::info('📚 Citation styles requested');

        return response()->json([
            'styles' => CitationFormatter::getAvailableStyles(),
        ]);
    }
}
