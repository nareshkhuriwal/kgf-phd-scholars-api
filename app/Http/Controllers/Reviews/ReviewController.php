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
            ['status' => 'pending', 'review_sections' => []]
        );

        // Backward-compat: hydrate empty review_sections from legacy fields
        if (empty($review->review_sections)) {
            $map = [
                'Litracture Review' => $review->html,
                'Key Issue'         => $review->key_issue,
                'Remarks'           => $review->remarks,
            ];
            $review->review_sections = array_filter($map, fn($v) => filled($v));
            $review->save();
        }

        // IMPORTANT: load paper with files so ReviewResource can expose pdf_url
        $review->load(['paper.files']);

        return new ReviewResource($review);
    }

    /**
     * FULL update — accepts all sections at once
     * Body may include: html, status, key_issue, remarks, review_sections (object)
     */
    public function update(SaveReviewRequest $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeOwner($paper, 'created_by');

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            ['status' => 'pending', 'review_sections' => []]
        );

        $data = $request->only(['html','status','key_issue','remarks','review_sections']);

        // Update structured sections (JSON)
        if ($request->has('review_sections')) {
            $incoming = $data['review_sections'];
            if (is_array($incoming)) {
                $review->review_sections = $incoming; // replace by design
            }
        }

        // Keep legacy fields for exports/back-compat
        foreach (['html','status','key_issue','remarks'] as $k) {
            if ($request->filled($k)) {
                $review->{$k} = $data[$k];
            }
        }

        $review->save();
        $review->load(['paper.files']);

        return new ReviewResource($review);
    }

    /**
     * PARTIAL update — update just one tab/section
     * Body: { section_key: string, html: string }
     */
    public function updateSection(SaveReviewSectionRequest $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeOwner($paper, 'created_by');

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            ['status' => 'pending', 'review_sections' => []]
        );

        $sections = $review->review_sections ?? [];
        $sections[$request->input('section_key')] = $request->input('html') ?? '';
        $review->review_sections = $sections;

        // Do not touch $review->html here (full concat stays in full update)
        $review->save();
        $review->load(['paper.files']);

        return new ReviewResource($review);
    }

    /**
     * Status toggle — mark as done/pending
     * Body: { status: 'done' | 'pending' }
     */
    public function updateStatus(SaveReviewStatusRequest $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeOwner($paper, 'created_by');

        $review = Review::firstOrCreate(
            ['paper_id' => $paper->id, 'user_id' => $request->user()->id],
            ['status' => 'pending', 'review_sections' => []]
        );

        $review->status = $request->input('status'); // 'done' or 'pending'
        $review->save();
        $review->load(['paper.files']);

        return new ReviewResource($review);
    }
}
