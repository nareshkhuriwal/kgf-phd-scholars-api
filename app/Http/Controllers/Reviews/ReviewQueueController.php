<?php
// app/Http/Controllers/Reviews/ReviewQueueController.php
namespace App\Http\Controllers\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Http\Requests\Reviews\AddToQueueRequest;
use App\Http\Resources\PaperSummaryResource;
use App\Models\Paper;
use App\Models\ReviewQueue;
use App\Support\ResolvesApiScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReviewQueueController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    public function index(Request $request)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('ReviewQueue index called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        // Get all accessible user IDs based on role and relationships
        $userIds = $this->resolveApiUserIds($request);

        Log::info('Accessible user IDs resolved', [
            'user_ids' => $userIds,
            'count' => count($userIds)
        ]);

        // Query review queue for all accessible users
        $rows = ReviewQueue::with([
                'paper' => fn ($q) => $q
                    ->whereIn('created_by', $userIds)
                    ->with('creator:id,name,email,role'),
                'paper.reviews' => function ($q) use ($userIds) {
                    $q->whereIn('user_id', $userIds);
                }
            ])
            ->whereIn('user_id', $userIds)
            ->whereHas('paper', fn ($q) => $q->whereIn('created_by', $userIds))
            ->get()
            ->map(function ($rq) {
                $paper = $rq->paper;
                if ($paper) {
                    // tell the resource this paper is in queue (no N+1)
                    $paper->setAttribute('in_review_queue', true);
                }
                return $paper;
            })
            ->filter()
            ->sortBy('id')
            ->values();




        Log::info('ReviewQueue rows retrieved', [
            'count' => $rows->count()
        ]);

        return PaperSummaryResource::collection($rows);
    }

    public function store(AddToQueueRequest $request)
    {
        $userId  = $request->user()->id ?? abort(401, 'Unauthenticated');
        $paperId = (int) $request->input('paperId');

        Log::info('Adding paper to review queue', [
            'user_id' => $userId,
            'paper_id' => $paperId
        ]);

        // Ensure paper exists
        $paper = Paper::findOrFail($paperId);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($request, $paper->created_by);

        Log::info('User authorized to access paper', [
            'user_id' => $userId,
            'paper_id' => $paperId,
            'paper_owner' => $paper->created_by
        ]);

        // Add to queue for the current user
        ReviewQueue::firstOrCreate(
            ['user_id' => $userId, 'paper_id' => $paper->id],
            ['added_at' => now()]
        );

        Log::info('Paper added to review queue successfully');

        // Return the paper summary with current user's review status and creator
        $paper->load([
            'reviews' => fn ($q) => $q->where('user_id', $userId),
            'creator:id,name,email,role'
        ]);
                
        $paper->setAttribute('in_review_queue', true);

        return new PaperSummaryResource($paper);
    }

    public function destroy(Request $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Removing paper from review queue', [
            'user_id' => $request->user()->id,
            'paper_id' => $paper->id
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($request, $paper->created_by);

        // Remove from queue for the current user
        ReviewQueue::where('user_id', $request->user()->id)
            ->where('paper_id', $paper->id)
            ->delete();

        Log::info('Paper removed from review queue successfully');

        return response()->noContent();
    }
}