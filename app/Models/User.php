<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\UserSetting;
<<<<<<< HEAD
=======
use App\Models\Paper;
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
use Carbon\Carbon;

class User extends Authenticatable
{
<<<<<<< HEAD
=======
    /** @use HasFactory<\Database\Factories\UserFactory> */
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
<<<<<<< HEAD
=======
     *
     * @var list<string>
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
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
<<<<<<< HEAD
        'subscription_status',
        'plan_key',
        'trial',
        'trial_start_date',
        'trial_end_date',
        'employee_id',
        'department',
        'specialization',
        'research_area',
=======

        // plan fields
        'plan_key',         // e.g., "researcher-free", "researcher-pro", "supervisor-pro", "admin-university"
        'plan_expires_at',  // nullable datetime for subscription expiry
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
    ];

    /**
     * The attributes that should be hidden for serialization.
<<<<<<< HEAD
=======
     *
     * @var list<string>
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
<<<<<<< HEAD
=======
     *
     * NOTE: you had a protected function casts() in the original model; preserving that
     * to avoid changing the shape of your codebase. If you prefer the standard
     * protected $casts property, you can switch to that.
     *
     * @return array<string, string>
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_agreed_at'   => 'datetime',
<<<<<<< HEAD
            'trial_start_date'  => 'datetime',
            'trial_end_date'    => 'datetime',
            'trial'             => 'boolean',
        ];
    }

    /**
     * Relationship: User has one settings record
     */
=======
            'plan_expires_at'   => 'datetime',
        ];
    }

>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
    public function settings()
    {
        return $this->hasOne(UserSetting::class);
    }

    /**
<<<<<<< HEAD
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
=======
     * Convenience: return the plan key for this user (fallback to 'researcher-free').
     *
     * @return string
     */
    public function getPlanKey(): string
    {
        return $this->plan_key ?? config('razorpay.default_plan_key', 'researcher-free');
    }

    /**
     * Return the plan config array from config('razorpay.plans') for this user.
     * Falls back to researcher-free defaults if missing.
     *
     * @return array
     */
    public function getPlanConfig(): array
    {
        $plans = config('razorpay.plans', []);
        $key = $this->getPlanKey();

        if (isset($plans[$key]) && is_array($plans[$key])) {
            return $plans[$key];
        }

        // fallback to researcher-free or first plan
        if (isset($plans['researcher-free'])) {
            return $plans['researcher-free'];
        }
        if (!empty($plans)) {
            return reset($plans);
        }

        // last resort minimal defaults
        return [
            'max_papers' => 50,
            'max_reports' => 5,
            'max_collections' => 2,
            'unlimited' => false,
        ];
    }

    /**
     * Whether the plan is effectively unlimited.
     *
     * @return bool
     */
    public function planIsUnlimited(): bool
    {
        $cfg = $this->getPlanConfig();
        if (array_key_exists('unlimited', $cfg)) {
            return (bool) $cfg['unlimited'];
        }
        // treat null max_papers as unlimited
        return !isset($cfg['max_papers']) || is_null($cfg['max_papers']);
    }

    /**
     * Return integer max papers allowed for this plan, or null for unlimited.
     *
     * @return int|null
     */
    public function planMaxPapers(): ?int
    {
        $cfg = $this->getPlanConfig();
        if (!array_key_exists('max_papers', $cfg)) {
            return null;
        }
        return is_null($cfg['max_papers']) ? null : (int) $cfg['max_papers'];
    }

    /**
     * Count how many papers this user has already created.
     *
     * @return int
     */
    public function papersCount(): int
    {
        return Paper::where('created_by', $this->id)->count();
    }

    /**
     * Remaining paper slots according to plan (int). If unlimited => PHP_INT_MAX.
     *
     * @return int
     */
    public function remainingPaperQuota(): int
    {
        if ($this->planIsUnlimited()) {
            return PHP_INT_MAX;
        }
        $max = $this->planMaxPapers() ?? 0;
        $used = $this->papersCount();
        $rem = (int)$max - (int)$used;
        return $rem < 0 ? 0 : $rem;
    }

    /**
     * Convenience check whether the user can create N more papers (default 1).
     *
     * @param int $needed
     * @return bool
     */
    public function canCreatePapers(int $needed = 1): bool
    {
        if ($this->planIsUnlimited()) return true;
        return $this->remainingPaperQuota() >= $needed;
    }

    /**
     * Optionally: If you want to check plan expiry.
     *
     * @return bool
     */
    public function planIsActive(): bool
    {
        if (!$this->plan_expires_at) return true; // treat null as active (unless you prefer otherwise)
        return Carbon::now()->lt(Carbon::parse($this->plan_expires_at));
    }
}
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
