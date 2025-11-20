<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        // ensure paper is loaded (controller does it, but keep defensive)
        $paper = $this->whenLoaded('paper');

        // Normalize review sections (if you want structured sections)
        $sections = $this->review_sections ?? null; // if you store JSON
        if (!is_array($sections)) {
            // fall back mapping from legacy columns to a sections object
            $sections = [
                'Litracture Review'                    => $this->html ?? null,
                'Key Issue'                            => $this->key_issue ?? null,
                'Solution Approach / Methodology used' => $this->solution_method_html ?? null,
                'Related Work'                         => $this->related_work_html ?? null,
                'Input Parameters used'                => $this->input_params_html ?? null,
                'Hardware / Software / Technology Used'=> $this->hw_sw_html ?? null,
                'Results'                              => $this->results_html ?? null,
                'Key advantages'                       => $this->advantages_html ?? null,
                'Limitations'                          => $this->limitations_html ?? null,
                'Remarks'                              => $this->remarks_html ?? null,
            ];
        }

        return [
            'paper_id'        => $this->paper_id,
            'user_id'         => $this->user_id,
            'status'          => $this->status,

            // expose structured review and legacy html
            'review_sections' => $sections,
            'html'            => $this->html,

            // FLATTEN paper meta so Review UI can read it directly
            'title'           => optional($paper)->title,
            'authors'         => optional($paper)->authors,
            'year'            => optional($paper)->year,
            'doi'             => optional($paper)->doi,

            // CRITICAL: pdf_url at root
            'pdf_url' => optional($paper)->pdf_url,

            // still include nested paper summary if you like
            'paper'           => new PaperSummaryResource($paper),

            'updated_at'      => $this->updated_at,
        ];
    }
}
