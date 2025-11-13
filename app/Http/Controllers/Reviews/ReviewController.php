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

class ReviewController extends Controller
{
    use OwnerAuthorizes;

    public function show(Request $request, Paper $paper)
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

        // Backward-compat: hydrate empty review_sections from legacy fields
        if (empty($review->review_sections)) {
            $map = [
                'Litracture Review' => $review->html,
                'Key Issue'         => $review->key_issue,
                'Remarks'           => $review->remarks,
            ];
            $review->review_sections = array_filter($map, fn ($v) => filled($v));
            $review->save();
        }

        // IMPORTANT: load paper with files so ReviewResource can expose pdf_url
        $review->load(['paper.files']);

        return new ReviewResource($review);
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

        // We deliberately ignore any incoming 'status' here; status is driven by workflow.
        $data = $request->only(['html', 'key_issue', 'remarks', 'review_sections']);

        // Update structured sections (JSON)
        if ($request->has('review_sections')) {
            $incoming = $data['review_sections'];
            if (is_array($incoming)) {
                // Replace complete sections by design
                $review->review_sections = $incoming;
            }
        }

        // Keep legacy fields for exports/back-compat
        foreach (['html', 'key_issue', 'remarks'] as $k) {
            if ($request->filled($k)) {
                $review->{$k} = $data[$k];
            }
        }

        // Any full save moves to in_progress (unless archived)
        if ($review->status !== Review::STATUS_ARCHIVED) {
            $review->status = Review::STATUS_IN_PROGRESS;
        }

        $review->save();
        $review->load(['paper.files']);

        return new ReviewResource($review);
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

        $sections = $review->review_sections ?? [];
        $sections[$request->input('section_key')] = $request->input('html') ?? '';
        $review->review_sections = $sections;

        // Any section save moves to in_progress (unless archived)
        if ($review->status !== Review::STATUS_ARCHIVED) {
            $review->status = Review::STATUS_IN_PROGRESS;
        }

        // Do not touch $review->html here (full concat stays in full update)
        $review->save();
        $review->load(['paper.files']);

        return new ReviewResource($review);
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
        $review->load(['paper.files']);

        return new ReviewResource($review);
    }
}
