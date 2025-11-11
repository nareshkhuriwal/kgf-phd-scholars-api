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
        return new UserResource($request->user());
    }

    /** PUT/PATCH /me */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // remove fields we handle specially
        $password = $data['password'] ?? null;
        unset($data['password'], $data['password_confirmation'], $data['current_password']);

        // simple mass-assign permitted fields
        $user->fill($data);

        if ($password) {
            $user->password = Hash::make($password);
        }

        $user->save();

        return response()->json([
            'user' => new UserResource($user->fresh())
        ]);
    }
}
