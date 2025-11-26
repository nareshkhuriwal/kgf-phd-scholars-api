<?php
// app/Http/Controllers/EditorUploadController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class EditorUploadController extends Controller
{
    /**
     * Handle CKEditor image upload.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'upload'   => 'required|file|image|max:5120',  // 5 MB
            'paper_id' => 'nullable|integer',
        ]);

        $file     = $request->file('upload');
        $userId   = $request->user()->id ?? 0;
        $paperId  = $request->input('paper_id') ?: 'na';
        $ext      = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        // u<user>_p<paper>_<YYYYMMDD_HHMMSS>_<random>.ext
        $timestamp = now()->format('Ymd_His');
        $random    = Str::random(6);

        $fileName  = "u{$userId}_p{$paperId}_{$timestamp}_{$random}.{$ext}";

        // store in storage/app/public/editor/...
        $path = $file->storeAs('editor', $fileName, 'uploads');

        $url = Storage::disk('uploads')->url($path);

        return response()->json([
            'url' => $url,
        ]);
    }
}
