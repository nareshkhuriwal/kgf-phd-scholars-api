<?php

namespace App\Http\Resources;

<<<<<<< HEAD
use Illuminate\Http\Request;
=======
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
<<<<<<< HEAD
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'email'                => $this->email,
            'phone'                => $this->phone,
            'organization'         => $this->organization,
            'role'                 => $this->role,
            'status'               => $this->status,
            'email_verified_at'    => $this->email_verified_at,
            'terms_agreed_at'      => $this->terms_agreed_at,
            
            // Subscription & Trial fields
            'subscription_status'  => $this->subscription_status,
            'plan_key'             => $this->plan_key,
            'trial'                => (int) $this->trial, // Convert boolean to 1/0 for frontend
            'trial_start_date'     => $this->trial_start_date,
            'trial_end_date'       => $this->trial_end_date,
            
            // Role-specific fields
            'employee_id'          => $this->employee_id,
            'department'           => $this->department,
            'specialization'       => $this->specialization,
            'research_area'        => $this->research_area,
            
            // Computed fields
            'is_on_trial'          => $this->isOnTrial(),
            'is_trial_expired'     => $this->isTrialExpired(),
            'trial_days_remaining' => $this->trialDaysRemaining(),
            
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        ];
    }
}
=======
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
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
