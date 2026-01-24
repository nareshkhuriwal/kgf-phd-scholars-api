<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\PaperCommentResource;

class PaperResource extends JsonResource
{
    public function toArray($request): array {
        // Derive a "primary" PDF url (first pdf-like file) for viewer convenience.
        $primaryUrl = null;
        if ($this->relationLoaded('files') && $this->files->count()) {
            $firstPdf = $this->files->first(fn($f) => str_contains($f->mime ?? '', 'pdf') || str_ends_with(strtolower($f->original_name ?? ''), '.pdf'));
            $primaryUrl = optional($firstPdf)->url ?? optional($this->files->first())->url;
        }

        return [
            'id'            => $this->id,
            'paper_code'    => $this->paper_code,
            'title'         => $this->title,
            'authors'       => $this->authors,
            'doi'           => $this->doi,
            'year'          => $this->year,
            'category'      => $this->category,
            'journal'       => $this->journal,
            'issn_isbn'     => $this->issn_isbn,
            'publisher'     => $this->publisher,
            'place'         => $this->place,
            'volume'        => $this->volume,
            'issue'         => $this->issue,
            'page_no'       => $this->page_no,
            'area'          => $this->area,
            'citation_type_code' => $this->citation_type_code,

            // Review pick status (computed, not stored in papers table)
            'is_added_for_review' => (bool) ($this->is_added_for_review ?? false),
            'status' => (bool) ($this->is_added_for_review ?? false) ? 'added' : 'not_added',

            // optional convenience for the viewer
            'pdf_url' => $primaryUrl,

            // Creator name
            'created_by' => $this->whenLoaded('creator', 
                fn() => $this->creator?->name,
                fn() => null
            ),

            // PAPER COMMENTS (NEW)
            'comments' => PaperCommentResource::collection(
                $this->whenLoaded('comments')
            ),

            // Related files (true relation)
            'files' => $this->whenLoaded('files', fn() =>
                $this->files->map(fn($f) => [
                    'id'            => $f->id,
                    'url'           => $f->url, // accessor builds Storage::disk(...)->url()
                    'original_name' => $f->original_name,
                    'mime'          => $f->mime,
                    'size_bytes'    => $f->size_bytes,
                ])
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}