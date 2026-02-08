<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Citation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\OwnerAuthorizes;

class ReviewCitationController extends Controller
{
    use OwnerAuthorizes;

    /**
     * Sync citations used in a review (assign order on first appearance)
     */
    public function sync(Request $req, $paperId)
    {
        $req->validate([
            'citation_keys' => ['array'],
            'citation_keys.*' => ['integer', 'exists:citations,id'],
        ]);


        $review = Review::where('paper_id', $paperId)->firstOrFail();

        // 🔒 Owner-based authorization (project standard)
        $this->authorizeOwner($review, 'created_by');

        $ids = collect($req->citation_keys ?? [])
            ->filter(fn ($v) => is_numeric($v))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json([
                'ok' => true,
                'message' => 'No citations to sync',
            ]);
        }

        $citations = Citation::whereIn('id', $ids)->get();

        DB::transaction(function () use ($citations, $review) {

            // 🔐 Lock rows to avoid race conditions
            $currentMaxOrder = DB::table('review_citations')
                ->where('review_id', $review->id)
                ->lockForUpdate()
                ->max('first_used_order') ?? 0;

            foreach ($citations as $citation) {

                $exists = DB::table('review_citations')
                    ->where('review_id', $review->id)
                    ->where('citation_id', $citation->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('review_citations')->insert([
                    'review_id'        => $review->id,
                    'citation_id'      => $citation->id,
                    'first_used_order' => ++$currentMaxOrder,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * List citations for a review in first-used order
     */
    public function list($reviewId)
    {
        $review = Review::with([
            'citations' => function ($q) {
                $q->with('type')
                  ->orderBy('review_citations.first_used_order', 'asc');
            }
        ])->findOrFail($reviewId);

        // 🔒 Owner-based authorization
        $this->authorizeOwner($review, 'created_by');

        return response()->json([
            'review_id' => $reviewId,
            'count'     => $review->citations->count(),
            'citations' => $review->citations->map(fn ($c) => [
                'citation_id' => $c->id,
                'order'       => $c->pivot->first_used_order,
                'title'       => $c->title,
                'authors'     => $c->authors,
                'year'        => $c->year,
                'type'        => optional($c->type)->code,
            ]),
        ]);
    }
}
