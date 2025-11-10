<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use App\Models\PaperFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaperFileController extends Controller
{
    public function upload(Request $req, Paper $paper) {
        $req->validate([
            'file' => ['required','file','mimes:pdf,doc,docx','max:51200'] // 50MB
        ]);

        $file = $req->file('file');
        $disk = 'public'; // ensure public disk for web access
        $subdir = now()->format('Y/m');
        $path = $file->store("library/{$subdir}", $disk);

        $pf = $paper->files()->create([
            'disk'          => $disk,
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size_bytes'    => $file->getSize(),
            'checksum'      => hash_file('sha256', $file->getRealPath()),
            'uploaded_by'   => $req->user()->id ?? null,
        ]);

        return response()->json([
            'id'            => $pf->id,
            'url'           => Storage::disk($pf->disk)->url($pf->path),
            'original_name' => $pf->original_name,
            'mime'          => $pf->mime,
            'size_bytes'    => $pf->size_bytes,
        ], 201);
    }

    public function destroy(Paper $paper, PaperFile $file) {
        if ($file->paper_id !== $paper->id) abort(404);
        Storage::disk($file->disk ?: 'public')->delete($file->path);
        $file->delete();
        return response()->json(['ok' => true]);
    }
}
