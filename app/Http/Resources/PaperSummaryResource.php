<?php
// app/Http/Resources/PaperSummaryResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaperSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        // Normalize keys for your UI
        return [
            'id'        => $this->id,
            'title'     => $this->Title ?? $this->title,
            'authors'   => $this->{'Author(s)'} ?? $this->authors,
            'year'      => $this->Year ?? $this->year,
            'doi'       => $this->DOI ?? $this->doi,
            'review_status' => optional($this->reviews->first())->status ?? 'pending',
            'pdf_url'   => $this->pdf_path ? asset('storage/'.$this->pdf_path) : null,
        ];
    }
}
