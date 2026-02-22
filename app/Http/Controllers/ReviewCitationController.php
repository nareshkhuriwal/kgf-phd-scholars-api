<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Citation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewCitationController extends Controller
{
    /**
     * Insert citations into a review safely (NO sync)
     */
    // public function sync(Request $req, $paperId)
    // {
    //     Log::info('🔵 ReviewCitation sync started', [
    //         'paper_id' => $paperId,
    //         'citation_keys' => $req->citation_keys ?? []
    //     ]);

    //     $req->validate([
    //         'citation_keys' => 'array',
    //         'citation_keys.*' => 'string'
    //     ]);

    //     $review = Review::where('paper_id', $paperId)->firstOrFail();
    //     Log::info('✅ Review found', ['review_id' => $review->id]);

    //     $citations = Citation::whereIn(
    //         'id',
    //         $req->citation_keys ?? []
    //     )->get();

    //     Log::info('📚 Citations fetched', [
    //         'count' => $citations->count(),
    //         'citation_ids' => $citations->pluck('id')->toArray(),
    //         'citation_keys' => $citations->pluck('citation_key')->toArray()
    //     ]);

    //     DB::transaction(function () use ($citations, $review) {
    //         Log::info('🔄 Transaction started for review', ['review_id' => $review->id]);

    //         foreach ($citations as $citation) {
    //             Log::info('🔍 Processing citation', [
    //                 'citation_id' => $citation->id,
    //                 'citation_key' => $citation->citation_key
    //             ]);

    //             // 1️⃣ Check if citation already has a global order
    //             $existingOrder = DB::table('review_citations')
    //                 ->where('citation_id', $citation->id)
    //                 ->whereNotNull('first_used_order')
    //                 ->value('first_used_order');

    //             Log::info('🔎 Existing order check', [
    //                 'citation_id' => $citation->id,
    //                 'existing_order' => $existingOrder,
    //                 'has_order' => !is_null($existingOrder)
    //             ]);

    //             // 2️⃣ Assign new order ONLY if first time ever
    //             $maxOrder = DB::table('review_citations')->max('first_used_order');
    //             $order = $existingOrder ?? ($maxOrder + 1);

    //             Log::info('🔢 Order calculation', [
    //                 'citation_id' => $citation->id,
    //                 'max_order_in_db' => $maxOrder,
    //                 'assigned_order' => $order,
    //                 'is_new_order' => is_null($existingOrder)
    //             ]);

    //             // 3️⃣ Insert link without destroying others
    //             $result = DB::table('review_citations')->updateOrInsert(
    //                 [
    //                     'review_id'   => $review->id,
    //                     'citation_id' => $citation->id,
    //                 ],
    //                 [
    //                     'first_used_order' => $existingOrder ? null : $order,
    //                     'updated_at' => now(),
    //                     'created_at' => now(),
    //                 ]
    //             );

    //             Log::info('💾 Database operation completed', [
    //                 'citation_id' => $citation->id,
    //                 'review_id' => $review->id,
    //                 'operation' => $result ? 'inserted' : 'updated',
    //                 'first_used_order' => $existingOrder ? null : $order
    //             ]);
    //         }

    //         Log::info('✅ Transaction completed successfully');
    //     });

    //     Log::info('🎉 Sync operation completed', ['paper_id' => $paperId]);

    //     return response()->json(['ok' => true]);
    // }

    public function sync(Request $req, $paperId)
    {
        Log::info('🔵 ReviewCitation sync started', [
            'paper_id' => $paperId,
            'citation_ids' => $req->citation_ids ?? []
        ]);

        $req->validate([
            'citation_ids' => 'required|array',
            'citation_ids.*' => 'integer'
        ]);

        $review = Review::where('paper_id', $paperId)->firstOrFail();

        $citations = Citation::with('type')
            ->whereIn('id', $req->citation_ids)
            ->get();

        $attached = [];

        DB::transaction(function () use ($citations, $review, &$attached) {

            // ✅ per-review max order + lock
            $maxOrder = DB::table('review_citations')
                ->where('review_id', $review->id)
                ->lockForUpdate()
                ->max('first_used_order');

            $nextOrder = ($maxOrder ?? 0) + 1;

            foreach ($citations as $citation) {

                // ✅ check existing link in THIS review
                $existing = DB::table('review_citations')
                    ->where('review_id', $review->id)
                    ->where('citation_id', $citation->id)
                    ->first();

                if ($existing) {
                    $order = $existing->first_used_order;

                    DB::table('review_citations')
                        ->where('review_id', $review->id)
                        ->where('citation_id', $citation->id)
                        ->update(['updated_at' => now()]);
                } else {
                    $order = $nextOrder++;

                    DB::table('review_citations')->insert([
                        'review_id' => $review->id,
                        'citation_id' => $citation->id,
                        'first_used_order' => $order,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // ✅ return details so frontend can insert [order]
                $attached[] = [
                    'id' => $citation->id,
                    'title' => $citation->title,
                    'authors' => $citation->authors,
                    'year' => $citation->year,
                    'doi' => $citation->doi,
                    'type' => optional($citation->type)->code,
                    'order' => (int) $order,
                ];
            }
        });

        usort($attached, fn($a, $b) => $a['order'] <=> $b['order']);

        return response()->json([
            'ok' => true,
            'review_id' => $review->id,
            'citations' => $attached,
        ]);
    }

    /**
     * List citations for a review (ordered for UI)
     */
    public function list($reviewId)
    {
        Log::info('📋 Listing citations for review', ['review_id' => $reviewId]);

        $review = Review::with([
            'citations' => function ($q) {
                $q->with('type')
                    ->orderBy('review_citations.first_used_order', 'asc');
            }
        ])->findOrFail($reviewId);

        $citations = $review->citations;

        Log::info('✅ Citations retrieved', [
            'review_id' => $reviewId,
            'citation_count' => $citations->count(),
            'citation_ids' => $citations->pluck('id')->toArray(),
            'orders' => $citations->pluck('pivot.first_used_order')->toArray()
        ]);

        return $citations;
    }
}
