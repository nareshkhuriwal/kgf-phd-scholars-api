<?php

namespace App\Http\Resources;

use App\Models\PaperFile;
use Illuminate\Http\Resources\Json\JsonResource;

class FlatReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        $paper = $this->paper;
        $libraryPdfUrl = $paper?->pdf_url;
        $reviewPdfUrl = $this->resolveReviewPdfUrl($libraryPdfUrl);

        return [
            /* ---------------- Review ---------------- */
            'review_id'       => $this->id,
            'paper_id'        => $this->paper_id,
            'user_id'         => $this->user_id,
            /** Present when a per-review PDF copy exists (only this file may be overwritten by highlight save). */
            'review_working_copy_file_id' => $this->review_working_copy_file_id,
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
            // Review UI: annotated PDF is the per-review working copy; library PDF stays unchanged.
            'pdf_url' => $reviewPdfUrl,
            'library_pdf_url' => $libraryPdfUrl,

            'files' => $paper?->relationLoaded('files')
                ? $paper->files
                    ->filter(fn ($f) => !($f->is_review_copy ?? false))
                    ->values()
                    ->map(fn ($f) => [
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

    private function resolveReviewPdfUrl(?string $libraryPdfUrl): ?string
    {
        /** @var PaperFile|null $wc */
        $wc = $this->resource->workingCopyFile;

        if ($wc && $wc->paper_id && $wc->id) {
            try {
                return route('papers.files.download', [
                    'paper' => $wc->paper_id,
                    'file'  => $wc->id,
                ], true);
            } catch (\Throwable $e) {
                return $libraryPdfUrl;
            }
        }

        return $libraryPdfUrl;
    }
}
