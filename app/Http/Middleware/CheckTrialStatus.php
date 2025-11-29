<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTrialStatus
{
    /**
     * Handle an incoming request.
     *
     * Only enforces trial checks for users with role 'admin'.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $role = strtolower(str_replace(' ', '_', (string) $user->role));

            // Only check for admin
            if ($role === 'admin') {
                // make methods optional (defensive)
                $isExpired = method_exists($user, 'isTrialExpired')
                    ? $user->isTrialExpired()
                    : (bool) ($user->is_trial_expired ?? false);

                if ($isExpired) {
                    // Try to call expireTrial if present to update DB, but don't throw if missing
                    if (method_exists($user, 'expireTrial')) {
                        try {
                            $user->expireTrial();
                        } catch (\Throwable $e) {
                            // ignore - we still block access below
                        }
                    }

                    return response()->json([
                        'message' => 'Your trial has expired. Please upgrade to continue using admin features.',
                        'trial_expired' => true,
                        'upgrade_url' => '/pricing',
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
