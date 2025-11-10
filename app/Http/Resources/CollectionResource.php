<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CollectionResource extends JsonResource
{
    public function toArray($request): array
    {
        // ensure items_count / paper_count exists (controller loads it)
        $items = $this->whenLoaded('items', function () {
            return $this->items->map(function ($it) {
                $p = $it->paper;
                // pick first file url if you have paper_files relation preloaded
                $file = $p?->files?->first();
                return [
                    'id'         => $p->id,
                    'title'      => $p->title,
                    'authors'    => $p->authors,
                    'year'       => $p->year,
                    'doi'        => $p->doi,
                    'paper_code' => $p->paper_code,
                    'pdf_url'    => $file?->url ?? $p?->pdf_url, // fallback accessor if you use it
                    // item (pivot) fields
                    'notes_html' => $it->notes_html,
                    'position'   => $it->position,
                    'added_by'   => $it->added_by,
                    'added_at'   => optional($it->created_at)?->toIso8601String(),
                ];
            });
        });

        return [
            'id'                     => $this->id,
            'name'                   => $this->name,
            'description'            => $this->description,
            'purpose'                => $this->purpose,
            'status'                 => $this->status,
            'paper_count'            => $this->paper_count ?? $this->items_count ?? 0,
            'updated_at'             => $this->updated_at,
            'updated_at_readable'    => optional($this->updated_at)->toIso8601String(),
            'papers'                 => $items,       // <— the list your UI needs
        ];
    }
}
