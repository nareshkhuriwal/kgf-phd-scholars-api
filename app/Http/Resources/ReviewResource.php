<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        // Defensive: paper is loaded by controller
        $paper = $this->whenLoaded('paper');

        /*
        |--------------------------------------------------------------------------
        | Normalize review sections
        |--------------------------------------------------------------------------
        | Priority:
        | 1) review_sections JSON (new system)
        | 2) Legacy individual HTML columns (fallback)
        */
        $sections = $this->review_sections;

        if (!is_array($sections) || empty($sections)) {
            $sections = array_filter([
                'Literature Review'                     => $this->html,
                'Key Issue'                             => $this->key_issue,
                'Solution Approach / Methodology used'  => $this->solution_method_html ?? null,
                'Related Work'                          => $this->related_work_html ?? null,
                'Input Parameters used'                 => $this->input_params_html ?? null,
                'Hardware / Software / Technology Used' => $this->hw_sw_html ?? null,
                'Results'                               => $this->results_html ?? null,
                'Key advantages'                        => $this->advantages_html ?? null,
                'Limitations'                           => $this->limitations_html ?? null,
                'Remarks'                               => $this->remarks ?? null,
            ], fn ($v) => filled($v));
        }

        return [
            /* ------------------------------------------------------------------
             | Review (core – REQUIRED by UI)
             * ------------------------------------------------------------------ */
            'review_id'       => $this->id,
            'paper_id'        => $this->paper_id,
            'user_id'         => $this->user_id,
            'status'          => $this->status,
            'review_sections' => $sections,

            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,

            /* ------------------------------------------------------------------
             | Paper (flattened – ROL / DOCX / PDF ready)
             * ------------------------------------------------------------------ */
            'title'               => optional($paper)->title,
            'authors'             => optional($paper)->authors,
            'year'                => optional($paper)->year,
            'doi'                 => optional($paper)->doi,
            'journal'             => optional($paper)->journal,
            'issn_isbn'           => optional($paper)->issn_isbn,
            'publisher'           => optional($paper)->publisher,
            'volume'              => optional($paper)->volume,
            'issue'               => optional($paper)->issue,
            'page_no'             => optional($paper)->page_no,
            'category'            => optional($paper)->category,
            'area'                => optional($paper)->area_sub_area ?? optional($paper)->area,
            'place'               => optional($paper)->place_of_conference ?? optional($paper)->place,

            /* ------------------------------------------------------------------
             | File access (critical for Review UI)
             * ------------------------------------------------------------------ */
            'pdf_url' => optional(
                optional($paper)->files?->firstWhere('type', 'pdf')
            )->pdf_url,
        ];
    }
}
