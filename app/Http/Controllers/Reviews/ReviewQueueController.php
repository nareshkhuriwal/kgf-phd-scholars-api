<?php
// app/Http/Controllers/Reviews/ReviewQueueController.php
namespace App\Http\Controllers\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\AddToQueueRequest;
use App\Http\Resources\PaperSummaryResource;
use App\Models\Paper;
use App\Models\ReviewQueue;
use Illuminate\Http\Request;

class ReviewQueueController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $rows = ReviewQueue::with(['paper.reviews' => function ($q) use ($userId) {
            $q->where('user_id', $userId);
        }])
        ->where('user_id', $userId)
        ->orderByDesc('added_at')
        ->get()
        ->map(fn ($rq) => $rq->paper);

        return PaperSummaryResource::collection($rows);
    }

    public function store(AddToQueueRequest $request)
    {
        $userId  = $request->user()->id;
        $paperId = (int) $request->input('paperId');

        // Ensure paper exists
        $paper = Paper::findOrFail($paperId);

        ReviewQueue::firstOrCreate([
            'user_id'  => $userId,
            'paper_id' => $paper->id,
        ], [
            'added_at' => now(),
        ]);

        // Return the paper summary for immediate UI prepend
        // eager load user's review for status
        $paper->load(['reviews' => fn($q) => $q->where('user_id', $userId)]);
        return new PaperSummaryResource($paper);
    }

    public function destroy(Request $request, Paper $paper)
    {
        ReviewQueue::where('user_id', $request->user()->id)
            ->where('paper_id', $paper->id)
            ->delete();

        return response()->noContent();
    }
}
