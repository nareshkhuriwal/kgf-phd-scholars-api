<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /** POST /auth/login */
    public function login(Request $request): JsonResponse
    {
        $cred = $request->validate([
            'email'    => ['required','email'],
            'password' => ['required'],
        ]);

        $email = strtolower(trim($cred['email']));

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($cred['password'], $user->password)) {
            // generic message avoids leaking whether the email exists
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ]);
    }

    /**
     * POST /auth/register
     * Body: name, email, password, password_confirmation, phone?, organization?, terms:true
     * Response: { token, user }
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name'            => $data['name'],
            'email'           => strtolower(trim($data['email'])),
            'password'        => Hash::make($data['password']),
            'phone'           => $data['phone'] ?? null,
            'organization'    => $data['organization'] ?? null,
            'status'          => 'active',
            'role'            => 'user',
            'terms_agreed_at' => now(),
        ]);

        // default prefs (works if you added $user->settings() hasOne())
        if (method_exists($user, 'settings')) {
            $user->settings()->create([
                'citation_style'     => 'chicago-note-bibliography-short',
                'note_format'        => 'markdown+richtext',
                'language'           => 'en-US',
                'quick_copy_as_html' => false,
                'include_urls'       => false,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ], 201);
    }

    /** GET /me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()));
    }

    /** POST /auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return response()->json(['ok' => true]);
    }
}
