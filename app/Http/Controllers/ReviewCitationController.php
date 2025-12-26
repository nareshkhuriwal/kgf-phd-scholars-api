<?php
namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Citation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewCitationController extends Controller
{
    /**
     * Insert citations into a review safely (NO sync)
     */
    public function sync(Request $req, $paperId)
    {
        $req->validate([
            'citation_keys' => 'array',
            'citation_keys.*' => 'string'
        ]);

        $review = Review::where('paper_id', $paperId)->firstOrFail();

        $citations = Citation::whereIn(
            'citation_key',
            $req->citation_keys ?? []
        )->get();

        DB::transaction(function () use ($citations, $review) {

            foreach ($citations as $citation) {

                // 1️⃣ Check if citation already has a global order
                $existingOrder = DB::table('review_citations')
                    ->where('citation_id', $citation->id)
                    ->whereNotNull('first_used_order')
                    ->value('first_used_order');

                // 2️⃣ Assign new order ONLY if first time ever
                $order = $existingOrder ?? (
                    DB::table('review_citations')->max('first_used_order') + 1
                );

                // 3️⃣ Insert link without destroying others
                DB::table('review_citations')->updateOrInsert(
                    [
                        'review_id'   => $review->id,
                        'citation_id' => $citation->id,
                    ],
                    [
                        'first_used_order' => $existingOrder ? null : $order,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * List citations for a review (ordered for UI)
     */
    public function list($reviewId)
    {
        return Review::with([
            'citations' => function ($q) {
                $q->with('type')
                  ->orderBy('review_citations.first_used_order', 'asc');
            }
        ])->findOrFail($reviewId)->citations;
    }
}
