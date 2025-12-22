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
use App\Http\Resources\FlatReviewResource;

class ReviewController extends Controller
{
    use OwnerAuthorizes;

    public function show(Request $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeOwner($paper, 'created_by');

        $review = Review::firstOrCreate(
            [
                'paper_id' => $paper->id,
                'user_id'  => $request->user()->id,
            ],
            [
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]
        );

        // IMPORTANT: load paper with files so ReviewResource can expose pdf_url
        // $review->load(['paper.files']);
        // return new ReviewResource($review);
        $review->load(['paper.files', 'paper.comments.user', 'paper.comments.children.user']);
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
        $this->authorizeOwner($paper, 'created_by');

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            [
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]
        );

        // ---- Decode main HTML (CRITICAL) ----
        if ($request->filled('html')) {
            $decodedHtml = base64_decode($request->input('html'), true);

            if ($decodedHtml === false) {
                abort(422, 'Invalid HTML encoding');
            }

            $review->html = $decodedHtml;
        }

        // ---- Structured sections (already decoded client-side or separate flow) ----
        if ($request->has('review_sections')) {
            $incoming = $request->input('review_sections');
            if (is_array($incoming)) {
                $review->review_sections = $incoming;
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
            $review->status = Review::STATUS_IN_PROGRESS;
        }

        $review->save();
        // $review->load(['paper.files']);
        // return new ReviewResource($review);
        $review->load(['paper.files', 'paper.comments.user', 'paper.comments.children.user']);
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
        $this->authorizeOwner($paper, 'created_by');

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            [
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]
        );

        // ✅ DECODE HTML (CRITICAL)
        $decodedHtml = base64_decode($request->input('html'), true);

        if ($decodedHtml === false) {
            abort(422, 'Invalid HTML encoding');
        }

        $sections = $review->review_sections ?? [];
        $sections[$request->input('section_key')] = $decodedHtml;
        $review->review_sections = $sections;

        if ($review->status !== Review::STATUS_ARCHIVED) {
            $review->status = Review::STATUS_IN_PROGRESS;
        }

        $review->save();
        // $review->load(['paper.files']);
        // return new ReviewResource($review);

        $review->load(['paper.files', 'paper.comments.user', 'paper.comments.children.user']);
        return new FlatReviewResource($review);

    }


    /**
     * Status toggle — explicit change (MARK COMPLETE, Archive, etc.)
     * Body: { status: 'draft' | 'in_progress' | 'done' | 'archived' }
     */
    public function updateStatus(SaveReviewStatusRequest $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeOwner($paper, 'created_by');

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            [
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]
        );

        $review->status = $request->input('status'); // draft | in_progress | done | archived
        $review->save();
        // $review->load(['paper.files']);
        // return new ReviewResource($review);

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
        $this->authorizeOwner($paper, 'created_by');

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            [
                'status'          => Review::STATUS_DRAFT,
                'review_sections' => [],
            ]
        );

        // return response()->json([
        //     'review_sections' => $review->review_sections ?? [],
        //     'status'          => $review->status,
        // ]);

        $review->load(['paper.files', 'paper.comments.user', 'paper.comments.children.user']);
        return new FlatReviewResource($review);

    }
}
