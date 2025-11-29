<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

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


    /**
     * POST /profile/avatar
     * Accepts multipart/form-data with `avatar` file input.
     * Stores file under public/uploads/avatars/{user_id}/ and saves the public URL in user->avatar
     */
    public function avatar(Request $request): JsonResponse
    {
        $user = $request->user() ?? abort(401, 'Unauthenticated');

        // validate request
        $rules = [
            'avatar' => 'required|file|image|mimes:jpeg,jpg,png,webp|max:5120', // max 5MB
        ];

        $validator = \Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid avatar upload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('avatar');

        // Build filename: slug-originalname-timestamp.ext
        $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $ext = $file->getClientOriginalExtension();
        $timestamp = now()->timestamp;
        $newFilename = sprintf('%s-%s.%s', $filename, $timestamp, $ext);

        // Destination path under public/
        $relativeDir = "uploads/avatars/{$user->id}";
        $destinationDir = public_path($relativeDir);

        try {
            // make sure destination directory exists
            if (!file_exists($destinationDir)) {
                if (!mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $destinationDir));
                }
            }

            // Move uploaded file to public/uploads/avatars/{user_id}
            $moved = $file->move($destinationDir, $newFilename);
            if (!$moved) {
                throw new \RuntimeException('Failed to move uploaded avatar to public folder.');
            }

            // Build public URL (scheme + host + /uploads/...)
            $publicUrl = url($relativeDir . '/' . $newFilename); // uses app URL, works for localhost and production

            // Delete old avatar if it's stored under /uploads/...
            if ($user->avatar) {
                try {
                    // If user->avatar contains a full URL, try to extract the path after host (e.g. /uploads/...)
                    $existing = $user->avatar;
                    $existingPath = null;

                    // If it's a URL belonging to this app, transform into file path
                    $appUrl = rtrim(config('app.url') ?: request()->getSchemeAndHttpHost(), '/');
                    if (Str::startsWith($existing, $appUrl)) {
                        $relative = substr($existing, strlen($appUrl));
                        $existingPath = public_path(ltrim($relative, '/'));
                    } elseif (Str::startsWith($existing, '/uploads/') || Str::contains($existing, 'uploads/avatars')) {
                        // handle cases like "/uploads/..." or "http://.../uploads/..."
                        // try to find "/uploads/..." inside string
                        $pos = strpos($existing, '/uploads/');
                        if ($pos !== false) {
                            $maybe = substr($existing, $pos + 1); // remove leading slash
                            $existingPath = public_path($maybe);
                        }
                    }

                    if ($existingPath && file_exists($existingPath)) {
                        @unlink($existingPath);
                    }
                } catch (\Throwable $e) {
                    // deletion failure shouldn't block upload — log and continue
                    Log::warning('Failed deleting previous avatar for user '.$user->id.': '.$e->getMessage());
                }
            }

            // Save new avatar URL on user
            $user->avatar = $publicUrl;
            $user->save();

            return response()->json([
                'user' => new UserResource($user->fresh()),
                'message' => 'Avatar uploaded successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Avatar upload failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Failed to upload avatar. Please try again later.',
            ], 500);
        }
    }

    /**
     * DELETE /profile/avatar
     * Remove avatar from public/uploads and clear user->avatar
     */
    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $request->user() ?? abort(401, 'Unauthenticated');

        if ($user->avatar) {
            try {
                $existing = $user->avatar;
                $existingPath = null;

                $appUrl = rtrim(config('app.url') ?: request()->getSchemeAndHttpHost(), '/');
                if (Str::startsWith($existing, $appUrl)) {
                    $relative = substr($existing, strlen($appUrl));
                    $existingPath = public_path(ltrim($relative, '/'));
                } elseif (Str::startsWith($existing, '/uploads/') || Str::contains($existing, 'uploads/avatars')) {
                    $pos = strpos($existing, '/uploads/');
                    if ($pos !== false) {
                        $maybe = substr($existing, $pos + 1);
                        $existingPath = public_path($maybe);
                    }
                }

                if ($existingPath && file_exists($existingPath)) {
                    @unlink($existingPath);
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to delete user avatar', ['user_id' => $user->id, 'exception' => $e->getMessage()]);
            }
        }

        $user->avatar = null;
        $user->save();

        return response()->json([
            'user' => new UserResource($user->fresh()),
            'message' => 'Avatar removed.'
        ]);
    }

}
