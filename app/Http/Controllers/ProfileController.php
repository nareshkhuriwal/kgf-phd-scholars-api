<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    /** GET /me (optional, handy for bootstrapping) */
    public function me(Request $request): UserResource
    {
        $user = $request->user() ?? abort(401, 'Unauthenticated');
        return new UserResource($user);
    }

    /** PUT/PATCH /me */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user() ?? abort(401, 'Unauthenticated');
        $data = $request->validated();

        // Pull password fields (handle specially)
        $password         = $data['password'] ?? null;
        $currentPassword  = $data['current_password'] ?? null;

        unset($data['password'], $data['password_confirmation'], $data['current_password']);

        // Mass-assign permitted fields from the request DTO
        $user->fill($data);

        // If password change requested, require current password to match
        if ($password) {
            if (!$currentPassword || !Hash::check($currentPassword, $user->password)) {
                return response()->json([
                    'message' => 'Current password is incorrect.'
                ], 422);
            }
            $user->password = Hash::make($password);
        }

        $user->save();

        return response()->json([
            'user' => new UserResource($user->fresh())
        ]);
    }
}
