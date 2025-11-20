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
            'phone'       => $this->phone,
            'organization' => $this->organization,
            'role'         => $this->role ?? null,      // if you store a role
            'status'       => $this->status ?? null,    // e.g., active/inactive (optional)
            'plan_key'     => $this->plan_key ?? null, 
            'plan_expires_at'     => $this->plan_expires_at ?? null, 
            'created_at'   => optional($this->created_at)->toISOString(),
            'updated_at'   => optional($this->updated_at)->toISOString(),
            // optional counts (present only if with_counts=true)
            'papers_count'  => $this->when(isset($this->papers_count), (int) $this->papers_count),
            'reviews_count' => $this->when(isset($this->reviews_count), (int) $this->reviews_count),
        ];
    }
}
