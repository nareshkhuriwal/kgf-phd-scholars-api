<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditorUploadController extends Controller
{
    /**
     * Handle CKEditor image upload (JWT authenticated).
     *
     * Route MUST use auth:api middleware
     */
    public function store(Request $request): JsonResponse
    {
        /**
         * 🔐 JWT authentication (explicit, predictable)
         */
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        /**
         * 🧪 Validate input
         */
        $validated = $request->validate([
            'upload'   => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'paper_id' => 'nullable|integer',
        ]);

        $file    = $validated['upload'];
        $paperId = $validated['paper_id'] ?? 'na';

        /**
         * 🔍 Extra MIME safety (defense-in-depth)
         */
        $mime = $file->getMimeType();
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                'upload' => 'Invalid image type.',
            ]);
        }

        /**
         * 🧾 Generate deterministic filename
         * u<user>_p<paper>_<timestamp>_<rand>.ext
         */
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        $fileName = sprintf(
            'u%d_p%s_%s_%s.%s',
            $user->id,
            $paperId,
            now()->format('Ymd_His'),
            Str::random(6),
            $ext
        );

        /**
         * 💾 Store file
         * uploads disk must be public + symlinked
         */
        $path = $file->storeAs(
            'editor',
            $fileName,
            'uploads'
        );

        /**
         * 🌍 Public URL
         */
        $url = Storage::disk('uploads')->url($path);

        /**
         * ✅ CKEditor REQUIRED RESPONSE FORMAT
         */
        return response()->json([
            'uploaded' => true,
            'url'      => $url,
        ], 201);
    }
}
