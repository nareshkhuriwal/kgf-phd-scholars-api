<?php
// app/Http/Controllers/ReviewCitationController.php
namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Citation;
use Illuminate\Http\Request;

class ReviewCitationController extends Controller
{
    public function sync(Request $req, $paperId)
    {
        $review = Review::where('paper_id', $paperId)->firstOrFail();

        $citationIds = Citation::whereIn(
            'citation_key',
            $req->citation_keys ?? []
        )->pluck('id');

        $review->citations()->sync($citationIds);

        return response()->json(['ok' => true]);
    }


    public function list($reviewId)
    {
        $review = Review::with('citations.type')->findOrFail($reviewId);
        return $review->citations;
    }
}
