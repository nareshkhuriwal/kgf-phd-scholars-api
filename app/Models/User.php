<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\UserSetting;
use Carbon\Carbon;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'organization',
        'role',
        'status',
        'terms_agreed_at',
        'subscription_status',
        'plan_key',
        'trial',
        'trial_start_date',
        'trial_end_date',
        'employee_id',
        'department',
        'specialization',
        'research_area',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_agreed_at'   => 'datetime',
            'trial_start_date'  => 'datetime',
            'trial_end_date'    => 'datetime',
            'trial'             => 'boolean',
        ];
    }

    /**
     * Relationship: User has one settings record
     */
    public function settings()
    {
        return $this->hasOne(UserSetting::class);
    }

    /**
     * Check if user is on an active trial
     */
    public function isOnTrial(): bool
    {
        return $this->trial 
            && $this->subscription_status === 'trial'
            && $this->trial_end_date 
            && Carbon::parse($this->trial_end_date)->isFuture();
    }

    /**
     * Check if user's trial has expired
     */
    public function isTrialExpired(): bool
    {
        return $this->trial 
            && $this->trial_end_date 
            && Carbon::parse($this->trial_end_date)->isPast();
    }

    /**
     * Get days remaining in trial
     */
    public function trialDaysRemaining(): int
    {
        if (!$this->isOnTrial()) {
            return 0;
        }
        
        $now = Carbon::now();
        $end = Carbon::parse($this->trial_end_date);
        return (int) $now->diffInDays($end, false);
    }

    /**
     * Activate trial for admin user (30 days)
     */
    public function activateTrial(int $days = 30): void
    {
        $this->update([
            'trial' => true,
            'subscription_status' => 'trial',
            'trial_start_date' => Carbon::now(),
            'trial_end_date' => Carbon::now()->addDays($days),
        ]);
    }

    /**
     * Upgrade user from trial to paid
     */
    public function upgradeToPaid(string $planKey): void
    {
        $this->update([
            'trial' => false,
            'subscription_status' => 'active',
            'plan_key' => $planKey,
            // Keep trial dates for record keeping
        ]);
    }

    /**
     * Mark trial as expired
     */
    public function expireTrial(): void
    {
        $this->update([
            'subscription_status' => 'expired',
        ]);
    }
}