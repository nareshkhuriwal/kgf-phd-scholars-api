<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditorUploadController extends Controller
{
    /**
     * Handle CKEditor image upload (Sanctum authenticated).
     *
     * Route MUST use `auth:sanctum` middleware
     */
    public function store(Request $request): JsonResponse
    {
        /**
         * 🔐 Sanctum authentication (CORRECT)
         */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
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
         * 🔍 Extra MIME safety
         */
        $mime = $file->getMimeType();
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                'upload' => 'Invalid image type.',
            ]);
        }

        /**
         * 🧾 Deterministic filename
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
         */
        $path = $file->storeAs('editor', $fileName, 'uploads');

        /**
         * 🌍 Public URL
         */
        $url = Storage::disk('uploads')->url($path);

        /**
         * ✅ CKEditor REQUIRED response
         */
        return response()->json([
            'uploaded' => true,
            'url'      => $url,
        ], 201);
    }



    /**
     * Fetch editor image securely for DOCX export
     *
     * Sanctum protected
     */
    public function fetch(Request $request): Response
    {
        // 🔐 Auth check (Sanctum)
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        // 🧪 Validate input
        $validated = $request->validate([
            'url' => ['required', 'url'],
        ]);

        $url = $validated['url'];

        /**
         * 🔒 SECURITY: allow only your uploads domain
         * Prevent SSRF / internal fetch
         */
        $allowedHost = parse_url(
            Storage::disk('uploads')->url(''),
            PHP_URL_HOST
        );

        $imageHost = parse_url($url, PHP_URL_HOST);

        if ($allowedHost !== $imageHost) {
            throw ValidationException::withMessages([
                'url' => 'External image fetch is not allowed.',
            ]);
        }

        /**
         * 🔍 If image is stored locally on uploads disk
         * (best performance, no HTTP call)
         */
        $baseUrl = Storage::disk('uploads')->url('');
        if (str_starts_with($url, $baseUrl)) {
            $relativePath = str_replace($baseUrl, '', $url);

            if (!Storage::disk('uploads')->exists($relativePath)) {
                abort(404, 'Image not found');
            }

            $mime = Storage::disk('uploads')->mimeType($relativePath);
            $content = Storage::disk('uploads')->get($relativePath);

            return response($content, 200)
                ->header('Content-Type', $mime)
                ->header('Content-Length', strlen($content));
        }

        /**
         * 🌐 Fallback: HTTP fetch (rare)
         */
        $res = Http::timeout(10)
            ->withOptions(['verify' => false])
            ->get($url);

        if (!$res->ok()) {
            abort(404, 'Failed to fetch image');
        }

        return response($res->body(), 200)
            ->header('Content-Type', $res->header('Content-Type'));
    }



}
