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
        $role = $user?->role ?? 'researcher';

        $rolesConfig = config('pricing.roles');
        $plans = $rolesConfig[$role] ?? $rolesConfig['researcher'];

        $roleLabel = $role === 'admin'
            ? 'Admin (University)'
            : $plans['current']['title'];

        return response()->json([
            'role'       => $role,
            'role_label' => $roleLabel,
            'current'    => $plans['current'],
            'upgrade'    => $plans['upgrade'] ?? null,
        ]);
    }

    /**
     * Return plans for a specific role.
     * GET /api/pricing/roles/{role}
     */
    public function showByRole(string $role)
    {
        $rolesConfig = config('pricing.roles');
        if (! isset($rolesConfig[$role])) {
            return response()->json([
                'message' => 'Invalid role',
            ], 404);
        }

        $plans = $rolesConfig[$role];

        $roleLabel = $role === 'admin'
            ? 'Admin (University)'
            : $plans['current']['title'];

        return response()->json([
            'role'       => $role,
            'role_label' => $roleLabel,
            'current'    => $plans['current'],
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
            'roles' => config('pricing.roles'),
        ]);
    }
}
