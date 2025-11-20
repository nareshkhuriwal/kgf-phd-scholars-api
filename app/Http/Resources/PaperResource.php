<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'id'          => $this->id,
            'Paper ID'    => $this->paper_code,
            'Title'       => $this->title,
            'Author(s)'   => $this->authors,
            'DOI'         => $this->doi,
            'Year'        => $this->year,
            'Category of Paper' => $this->category,
            'Name of Journal/Conference' => $this->journal,
            'ISSN / ISBN' => $this->issn_isbn,
            'Name of Publisher / Organization' => $this->publisher,
            'Place of Conference' => $this->place,
            'Volume'      => $this->volume,
            'Issue'       => $this->issue,
            'Page No'     => $this->page_no,
            'Area / Sub Area' => $this->area,
            'Key Issue'   => $this->key_issue,

            // HTML fields
            'Litracture Review' => $this->review_html,
            'Solution Approach / Methodology used' => $this->solution_method_html,
            'Related Work'      => $this->related_work_html,
            'Input Parameters used' => $this->input_params_html,
            'Hardware / Software / Technology Used' => $this->hw_sw_html,
            'Results'       => $this->results_html,
            'Key advantages'=> $this->advantages_html,
            'Limitations'   => $this->limitations_html,
            'Remarks'       => $this->remarks_html,

            // optional convenience for the viewer
            'pdf_url' => $primaryUrl,

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
