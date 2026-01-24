<?php
// app/Http/Resources/PaperSummaryResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\ReviewQueue;

class PaperSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        $userId = $request->user()?->id;

        // Prefer controller-provided attribute to avoid N+1
        $inQueue = (bool) ($this->in_review_queue ?? false);

        // If not set, you can optionally compute (commented to avoid accidental N+1)
        // if (!$inQueue && $userId) {
        //     $inQueue = ReviewQueue::where('user_id', $userId)
        //         ->where('paper_id', $this->id)
        //         ->exists();
        // }

        $hasReview = $this->relationLoaded('reviews')
            ? $this->reviews->isNotEmpty()
            : false; // (avoid N+1; load reviews in controller when needed)

        $isAddedForReview = $inQueue || $hasReview;

        $reviewStatus = $isAddedForReview
            ? (optional($this->reviews->first())->status ?? 'pending')
            : 'not_added';

        return [
            'id'        => $this->id,
            'title'     => $this->Title ?? $this->title,
            'authors'   => $this->{'Author(s)'} ?? $this->authors,
            'year'      => $this->Year ?? $this->year,
            'doi'       => $this->DOI ?? $this->doi,

            'created_by' => $this->whenLoaded(
                'creator',
                fn () => $this->creator?->name,
                fn () => null
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // ✅ new fields for UI
            'in_review_queue'    => $inQueue,
            'has_review'         => $hasReview,
            'is_added_for_review'=> $isAddedForReview,

            // ✅ now accurate
            'review_status' => $reviewStatus,

            'pdf_url'   => $this->pdf_path ? asset('storage/' . $this->pdf_path) : null,
        ];
    }
}
