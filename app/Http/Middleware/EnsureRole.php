<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * Usage: ->middleware('role:super_admin') or ->middleware('role:admin,super_admin')
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $roles  Comma separated allowed roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $roles = null)
    {
        // If unauthenticated, respond with 401
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Normalize roles: comma-separated string like "admin,super_admin"
        $allowed = [];
        if ($roles) {
            $allowed = array_map(function ($r) {
                return strtolower(trim(str_replace(' ', '_', $r)));
            }, explode(',', $roles));
        }

        // Normalize user's role from DB (guard against null)
        $userRole = $user->role ? strtolower(str_replace(' ', '_', $user->role)) : null;

        // If no roles passed, deny by default
        if (empty($allowed)) {
            return response()->json(['message' => 'Forbidden. No role configured for this route.'], 403);
        }

        if (!in_array($userRole, $allowed, true)) {
            return response()->json(['message' => 'Forbidden. Insufficient permissions.'], 403);
        }

        return $next($request);
    }
}
