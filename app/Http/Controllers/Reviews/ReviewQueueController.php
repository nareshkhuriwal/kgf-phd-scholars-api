<?php
// app/Http/Controllers/Reviews/ReviewQueueController.php
namespace App\Http\Controllers\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Http\Requests\Reviews\AddToQueueRequest;
use App\Http\Resources\PaperSummaryResource;
use App\Models\Paper;
use App\Models\ReviewQueue;
use Illuminate\Http\Request;

class ReviewQueueController extends Controller
{
    use OwnerAuthorizes;

    public function index(Request $request)
    {
        $userId = $request->user()->id ?? abort(401, 'Unauthenticated');

        $rows = ReviewQueue::with([
                'paper' => fn ($q) => $q->where('created_by', $userId),
                'paper.reviews' => function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                }
            ])
            ->where('user_id', $userId)
            ->whereHas('paper', fn ($q) => $q->where('created_by', $userId)) // enforce ownership
            ->orderByDesc('added_at')
            ->get()
            ->map(fn ($rq) => $rq->paper);

        return PaperSummaryResource::collection($rows);
    }

    public function store(AddToQueueRequest $request)
    {
        $userId  = $request->user()->id ?? abort(401, 'Unauthenticated');
        $paperId = (int) $request->input('paperId');

        // Ensure paper exists and is owned by current user
        $paper = Paper::findOrFail($paperId);
        $this->authorizeOwner($paper, 'created_by');

        ReviewQueue::firstOrCreate(
            ['user_id' => $userId, 'paper_id' => $paper->id],
            ['added_at' => now()]
        );

        // Return the paper summary for immediate UI prepend (with this user's review status)
        $paper->load(['reviews' => fn ($q) => $q->where('user_id', $userId)]);
        return new PaperSummaryResource($paper);
    }

    public function destroy(Request $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeOwner($paper, 'created_by');

        ReviewQueue::where('user_id', $request->user()->id)
            ->where('paper_id', $paper->id)
            ->delete();

        return response()->noContent();
    }
}
