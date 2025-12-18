<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditorUploadController extends Controller
{
    /**
     * Handle CKEditor image upload.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload'   => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'paper_id' => 'nullable|integer',
        ]);

        $file    = $validated['upload'];
        $userId  = optional($request->user())->id ?? 0;
        $paperId = $validated['paper_id'] ?? 'na';

        // Extra safety: verify real MIME
        $mime = $file->getMimeType();
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            throw ValidationException::withMessages([
                'upload' => 'Invalid image type.',
            ]);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        $timestamp = now()->format('Ymd_His');
        $random    = Str::random(6);

        $fileName = "u{$userId}_p{$paperId}_{$timestamp}_{$random}.{$ext}";

        /**
         * IMPORTANT:
         * Ensure `uploads` disk is public and symlinked
         */
        $path = $file->storeAs(
            'editor',
            $fileName,
            'uploads'
        );

        $url = Storage::disk('uploads')->url($path);

        return response()->json([
            'uploaded' => true,   // future-proof
            'url'      => $url,
        ], 201);
    }
}
