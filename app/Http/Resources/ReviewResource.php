<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        /* ------------------------------------------------------
         | Normalize review sections (JSON is source of truth)
         * ------------------------------------------------------ */
        $sections = $this->review_sections ?? [];

        return [
            /* ---------------- Review ---------------- */
            'review' => [
                'id'              => $this->id,
                'paper_id'        => $this->paper_id,
                'user_id'         => $this->user_id,
                'status'          => $this->status,
                'review_sections' => $sections,
                'created_by'      => $this->whenLoaded('creator', 
                    fn() => $this->creator?->name,
                    fn() => null
                ),
                'created_at'      => $this->created_at,
                'updated_at'      => $this->updated_at,
            ],

            /* ---------------- Paper ---------------- */
            'paper' => new PaperResource($this->whenLoaded('paper')),
        ];
    }
}