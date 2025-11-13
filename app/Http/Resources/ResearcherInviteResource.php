<?php
// app/Http/Resources/Researchers/ResearcherInviteResource.php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ResearcherInviteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'researcher_email' => $this->researcher_email,
            'researcher_name'  => $this->researcher_name,
            'supervisor_name'  => $this->supervisor_name,
            'role'             => $this->role,
            'allowed_domain'   => $this->allowed_domain,
            'message'          => $this->message,
            'notes'            => $this->notes,
            'status'           => $this->status,
            'expires_at'       => optional($this->expires_at)->toIso8601String(),
            'sent_at'          => optional($this->sent_at)->toIso8601String(),
            'revoked_at'       => optional($this->revoked_at)->toIso8601String(),
            'created_by'       => $this->created_by,
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
