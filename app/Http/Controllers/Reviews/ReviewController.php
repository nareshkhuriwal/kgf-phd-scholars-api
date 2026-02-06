<?php
// app/Http/Controllers/Reviews/ReviewController.php
namespace App\Http\Controllers\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Http\Requests\Reviews\SaveReviewRequest;
use App\Http\Requests\Reviews\SaveReviewSectionRequest;
use App\Http\Requests\Reviews\SaveReviewStatusRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Paper;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\FlatReviewResource;
use App\Support\ResolvesApiScope;
use App\Models\ReviewTag;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;


class ReviewController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    public function show(Request $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Review show method called', [
            'paper_id' => $paper->id,
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($request, $paper->created_by);

        Log::info('User authorized to access paper', [
            'paper_id' => $paper->id,
            'paper_owner' => $paper->created_by
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($request);

        // Get review for any of the accessible user IDs
        $review = Review::where('paper_id', $paper->id)
            ->whereIn('user_id', $userIds)
            ->first();

        Log::info('Review lookup result', [
            'found' => $review ? true : false,
            'review_id' => $review?->id,
            'searched_user_ids' => $userIds
        ]);

        // If no review exists, create one for the current user
        if (!$review) {
            $review = Review::create([
                'paper_id'        => $paper->id,
                'user_id'         => $request->user()->id,
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]);

            Log::info('Created new review', [
                'review_id' => $review->id,
                'paper_id' => $paper->id,
                'user_id' => $request->user()->id
            ]);
        }

        // Load paper with files and comments
        $review->load(['paper.files', 'paper.comments.user', 'paper.comments.children.user']);


        $review->problem_tags = ReviewTag::where('review_id', $review->id)
            ->where('tag_type', 'problem')
            ->pluck('tag_id')
            ->values()
            ->toArray();

        $review->solution_tags = ReviewTag::where('review_id', $review->id)
            ->where('tag_type', 'solution')
            ->pluck('tag_id')
            ->values()
            ->toArray();



        Log::info('Returning review resource', [
            'review_id' => $review->id
        ]);

        return new FlatReviewResource($review);
    }

    /**
     * FULL update — accepts all sections at once
     * Body may include: html, key_issue, remarks, review_sections (object)
     * Any save => status becomes in_progress (unless archived)
     */
    public function update(SaveReviewRequest $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Review update method called', [
            'paper_id' => $paper->id,
            'user_id' => $request->user()->id
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($request, $paper->created_by);

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            [
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]
        );

        Log::info('Review found or created', [
            'review_id' => $review->id,
            'status' => $review->status
        ]);


        // ---- Decode main HTML (CRITICAL) ----
        if ($request->filled('html')) {
            $decodedHtml = base64_decode($request->input('html'), true);

            if ($decodedHtml === false) {
                Log::error('Invalid HTML encoding', [
                    'review_id' => $review->id
                ]);
                abort(422, 'Invalid HTML encoding');
            }

            $review->html = $decodedHtml;
        }

        // ---- Structured sections (already decoded client-side or separate flow) ----
        if ($request->has('review_sections')) {
            $incoming = $request->input('review_sections');
            if (is_array($incoming)) {
                $review->review_sections = $incoming;
                Log::info('Review sections updated', [
                    'review_id' => $review->id,
                    'section_count' => count($incoming)
                ]);
            }
        }

        // ---- Legacy fields (DO NOT touch html again) ----
        foreach (['key_issue', 'remarks'] as $field) {
            if ($request->filled($field)) {
                $review->{$field} = $request->input($field);
            }
        }

        // ---- Status transition ----
        if ($review->status !== Review::STATUS_ARCHIVED) {
            $oldStatus = $review->status;
            $review->status = Review::STATUS_IN_PROGRESS;
            Log::info('Review status updated', [
                'review_id' => $review->id,
                'old_status' => $oldStatus,
                'new_status' => $review->status
            ]);
        }

        $review->save();
        $review->load(['paper.files', 'paper.comments.user', 'paper.comments.children.user']);

        Log::info('Review updated successfully', [
            'review_id' => $review->id
        ]);

        return new FlatReviewResource($review);
    }

    /**
     * PARTIAL update — update just one tab/section
     * Body: { section_key: string, html: string }
     * Any save => status becomes in_progress (unless archived)
     */
    public function updateSection(SaveReviewSectionRequest $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Review section update called', [
            'paper_id' => $paper->id,
            'user_id' => $request->user()->id,
            'section_key' => $request->input('section_key')
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($request, $paper->created_by);

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            [
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]
        );

        // ---------------------------------------------
        // ✅ TAGS SAVE (SPECIAL CASE)
        // ---------------------------------------------
        if ($request->input('section_key') === 'tags') {

            $data = $request->validate([
                'problem_tags'  => 'array',
                'problem_tags.*' => 'integer|exists:tags,id',
                'solution_tags' => 'array',
                'solution_tags.*' => 'integer|exists:tags,id',
            ]);

            DB::transaction(function () use ($paper, $request, $data) {

                $review = Review::firstOrCreate(
                    ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
                    [
                        'status' => Review::STATUS_DRAFT,
                        'review_sections' => [],
                    ]
                );

                // 🔥 Remove old tags
                ReviewTag::where('review_id', $review->id)->delete();

                // 🔥 Insert problem tags
                foreach ($data['problem_tags'] ?? [] as $tagId) {
                    ReviewTag::create([
                        'review_id' => $review->id,
                        'tag_id'    => $tagId,
                        'tag_type'  => 'problem',
                    ]);
                }

                // 🔥 Insert solution tags
                foreach ($data['solution_tags'] ?? [] as $tagId) {
                    ReviewTag::create([
                        'review_id' => $review->id,
                        'tag_id'    => $tagId,
                        'tag_type'  => 'solution',
                    ]);
                }

                if ($review->status !== Review::STATUS_ARCHIVED) {
                    $review->status = Review::STATUS_IN_PROGRESS;
                    $review->save();
                }
            });

            return response()->json([
                'message' => 'Tags saved successfully'
            ]);
        }


        // ✅ DECODE HTML (CRITICAL)
        $decodedHtml = base64_decode($request->input('html'), true);

        if ($decodedHtml === false) {
            Log::error('Invalid HTML encoding in section update', [
                'review_id' => $review->id,
                'section_key' => $request->input('section_key')
            ]);
            abort(422, 'Invalid HTML encoding');
        }

        $sections = $review->review_sections ?? [];
        $sectionKey = $request->input('section_key');
        $sections[$sectionKey] = $decodedHtml;
        $review->review_sections = $sections;

        Log::info('Review section updated', [
            'review_id' => $review->id,
            'section_key' => $sectionKey,
            'html_length' => strlen($decodedHtml)
        ]);

        if ($review->status !== Review::STATUS_ARCHIVED) {
            $review->status = Review::STATUS_IN_PROGRESS;
        }

        $review->save();
        $review->load(['paper.files', 'paper.comments.user', 'paper.comments.children.user']);

        Log::info('Section update completed', [
            'review_id' => $review->id
        ]);

        return new FlatReviewResource($review);
    }

    /**
     * Status toggle — explicit change (MARK COMPLETE, Archive, etc.)
     * Body: { status: 'draft' | 'in_progress' | 'done' | 'archived' }
     */
    public function updateStatus(SaveReviewStatusRequest $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Review status update called', [
            'paper_id' => $paper->id,
            'user_id' => $request->user()->id,
            'requested_status' => $request->input('status')
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($request, $paper->created_by);

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            [
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]
        );

        $oldStatus = $review->status;
        $review->status = $request->input('status'); // draft | in_progress | done | archived
        $review->save();

        Log::info('Review status updated', [
            'review_id' => $review->id,
            'old_status' => $oldStatus,
            'new_status' => $review->status
        ]);

        $review->load(['paper.files', 'paper.comments.user', 'paper.comments.children.user']);

        return new FlatReviewResource($review);
    }

    /**
     * READ sections only
     * GET /reviews/{paper}/sections
     */
    public function sections(Request $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Review sections read called', [
            'paper_id' => $paper->id,
            'user_id' => $request->user()->id
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($request, $paper->created_by);

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            [
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]
        );

        Log::info('Review sections retrieved', [
            'review_id' => $review->id,
            'section_count' => count($review->review_sections ?? [])
        ]);

        $review->load(['paper.files', 'paper.comments.user', 'paper.comments.children.user']);

        return new FlatReviewResource($review);
    }
}
