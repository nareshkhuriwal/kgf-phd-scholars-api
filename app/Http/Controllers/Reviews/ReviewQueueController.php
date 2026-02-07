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
        $user = $request->user() ?? abort(401, 'Unauthenticated');
        $userIds = $this->resolveApiUserIds($request);

        // ---- inputs ----
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        $per = max(5, min(100, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));

        $allowedSort = ['id', 'title', 'year', 'updated_at'];
        $sortBy = in_array($request->query('sort_by'), $allowedSort)
            ? $request->query('sort_by')
            : 'updated_at';

        $sortDir = strtolower($request->query('sort_dir')) === 'asc'
            ? 'asc'
            : 'desc';

        // ---- query ----
        $q = Paper::query()
            ->whereIn('created_by', $userIds)
            ->whereExists(function ($sq) use ($userIds) {
                $sq->selectRaw(1)
                    ->from('review_queue')
                    ->whereColumn('review_queue.paper_id', 'papers.id')
                    ->whereIn('review_queue.user_id', $userIds);
            })
            ->with([
                'creator:id,name,role',
                'reviews' => fn($r) => $r->whereIn('user_id', $userIds),
            ]);

        // ---- search ----
        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(
                fn($w) =>
                $w->where('title', 'like', $like)
                    ->orWhere('authors', 'like', $like)
                    ->orWhere('doi', 'like', $like)
                    ->orWhere('year', 'like', $like)
            );
        }

        // ---- status filter ----
        if ($status) {
            $q->whereHas(
                'reviews',
                fn($r) =>
                $r->where('status', $status)
                    ->whereIn('user_id', $userIds)
            );
        }

        // ---- order + paginate ----
        $q->orderBy($sortBy, $sortDir);

        $paginator = $q->paginate($per, ['*'], 'page', $page);

        return PaperSummaryResource::collection($paginator);
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
            'reviews' => fn($q) => $q->where('user_id', $userId),
            'creator:id,name,email,role'
        ]);

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
