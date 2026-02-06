<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FlatReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        $paper = $this->paper;

        return [
            /* ---------------- Review ---------------- */
            'review_id'       => $this->id,
            'paper_id'        => $this->paper_id,
            'user_id'         => $this->user_id,
            'status'          => $this->status,
            'review_sections' => $this->review_sections ?? [],
             // ✅ ADD THESE TWO LINES
            'problem_tags'    => $this->problem_tags ?? [],
            'solution_tags'   => $this->solution_tags ?? [],
            
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,

            /* ---------------- Paper ---------------- */
            'id'         => $paper?->id,
            'paper_code' => $paper?->paper_code,
            'title'      => $paper?->title,
            'authors'    => $paper?->authors,
            'doi'        => $paper?->doi,
            'year'       => $paper?->year,
            'category'   => $paper?->category,
            'journal'    => $paper?->journal,
            'issn_isbn'  => $paper?->issn_isbn,
            'publisher' => $paper?->publisher,
            'place'     => $paper?->place,
            'volume'    => $paper?->volume,
            'issue'     => $paper?->issue,
            'page_no'   => $paper?->page_no,
            'area'      => $paper?->area,

            /* ---------------- Files ---------------- */
            'pdf_url' => $paper?->pdf_url,

            'files' => $paper?->relationLoaded('files')
                ? $paper->files->map(fn ($f) => [
                    'id'            => $f->id,
                    'url'           => $f->url,
                    'original_name' => $f->original_name,
                    'mime'          => $f->mime,
                    'size_bytes'    => $f->size_bytes,
                ])
                : [],

            /* ---------------- Comments ---------------- */
            'comments' => $paper?->relationLoaded('comments')
                ? PaperCommentResource::collection($paper->comments)
                : [],
        ];
    }
}
