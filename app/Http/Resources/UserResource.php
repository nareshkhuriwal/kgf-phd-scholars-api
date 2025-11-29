<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserResource extends JsonResource
{
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
            'avatar' => $this->when($this->avatar, function () {
                $disk = config('filesystems.avatar_disk', 'public');
                // If avatar is already a full URL, return as-is
                if (Str::startsWith($this->avatar, ['http://', 'https://'])) {
                    return $this->avatar;
                }
                // Otherwise return Storage URL
                return Storage::disk($disk)->url($this->avatar);
            }, null),

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
            'papers_count'  => $this->when(isset($this->papers_count), (int) $this->papers_count),
            'reviews_count' => $this->when(isset($this->reviews_count), (int) $this->reviews_count),
        ];
    }
}
