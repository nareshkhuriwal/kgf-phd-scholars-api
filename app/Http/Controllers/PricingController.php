<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PricingController extends Controller
{
    /**
     * Return pricing for the currently authenticated user's role.
     * GET /api/pricing
     */
    public function forCurrentUser(Request $request)
    {
        $user = $request->user();

        // normalize role string (fallback to researcher)
        $role = $user?->role
            ? strtolower(str_replace(' ', '_', $user->role))
            : 'researcher';

        $rolesConfig = config('pricing.roles', []);

        // choose plans for role - fallback to researcher if not defined
        $plans = $rolesConfig[$role] ?? ($rolesConfig['researcher'] ?? []);

        // compute role label
        $roleLabel = $role === 'admin'
            ? 'Admin (University)'
            : ($plans['current']['title'] ?? ucfirst($role));

        // derive subscription/trial flags from user model (defensive)
        $isOnTrial = $user ? (bool) ($user->is_on_trial ?? $user->trial ?? false) : false;
        $isTrialExpired = $user ? (bool) ($user->is_trial_expired ?? $user->trial_expired ?? false) : false;
        $trialDaysRemaining = $user ? (int) ($user->trial_days_remaining ?? 0) : 0;
        $subscriptionStatus = $user ? ($user->subscription_status ?? null) : null;
        $planKey = $user ? ($user->plan_key ?? null) : null;
        $planExpiresAt = $user ? ($user->plan_expires_at ?? null) : null;

        // upgrade URL can be configured (e.g. config('app.upgrade_url')) or fallback to client route
        $upgradeUrl = config('app.upgrade_url', '/pricing');

        // message to show on pricing page when trial expired
        $expiredMessage = $isTrialExpired
            ? 'Your trial has expired. Please upgrade to continue using admin features.'
            : null;

        return response()->json([
            'role'                 => $role,
            'role_label'           => $roleLabel,
            'current'              => $plans['current'] ?? null,
            'upgrade'              => $plans['upgrade'] ?? null,
            // subscription/trial info for frontend
            'is_on_trial'          => $isOnTrial,
            'trial_expired'        => $isTrialExpired,
            'trial_days_remaining' => $trialDaysRemaining,
            'subscription_status'  => $subscriptionStatus,
            'plan_key'             => $planKey,
            'plan_expires_at'      => $planExpiresAt,
            'upgrade_url'          => $upgradeUrl,
            'expired_message'      => $expiredMessage,
        ]);
    }

    /**
     * Return plans for a specific role.
     * GET /api/pricing/roles/{role}
     */
    public function showByRole(string $role)
    {
        $roleNorm = strtolower(str_replace(' ', '_', $role));
        $rolesConfig = config('pricing.roles', []);

        if (! isset($rolesConfig[$roleNorm])) {
            return response()->json([
                'message' => 'Invalid role',
            ], 404);
        }

        $plans = $rolesConfig[$roleNorm];

        $roleLabel = $roleNorm === 'admin'
            ? 'Admin (University)'
            : ($plans['current']['title'] ?? ucfirst($roleNorm));

        return response()->json([
            'role'       => $roleNorm,
            'role_label' => $roleLabel,
            'current'    => $plans['current'] ?? null,
            'upgrade'    => $plans['upgrade'] ?? null,
        ]);
    }

    /**
     * Return all role plans (for admin / marketing pages if needed).
     * GET /api/pricing/all
     */
    public function all()
    {
        return response()->json([
            'roles' => config('pricing.roles', []),
        ]);
    }
}
