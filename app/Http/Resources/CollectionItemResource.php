<?php
// app/Http/Resources/CollectionItemResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CollectionItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $p = $this->paper;
        return [
            'id'       => $p->id,
            'title'    => $p->title,
            'authors'  => $p->authors,
            'year'     => $p->year,
            'doi'      => $p->doi,
            'paper_code' => $p->paper_code,
            'position' => $this->position,
            'notes_html' => $this->notes_html,
        ];
    }
}
