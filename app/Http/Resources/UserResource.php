<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'email'        => $this->email,
            'role'         => $this->role ?? null,      // if you store a role
            'status'       => $this->status ?? null,    // e.g., active/inactive (optional)
            'created_at'   => optional($this->created_at)->toISOString(),
            'updated_at'   => optional($this->updated_at)->toISOString(),
            // optional counts (present only if with_counts=true)
            'papers_count'  => $this->when(isset($this->papers_count), (int) $this->papers_count),
            'reviews_count' => $this->when(isset($this->reviews_count), (int) $this->reviews_count),
        ];
    }
}
