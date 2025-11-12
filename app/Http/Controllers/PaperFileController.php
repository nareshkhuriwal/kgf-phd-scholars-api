<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use App\Models\PaperFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Concerns\OwnerAuthorizes;

class PaperFileController extends Controller
{
    use OwnerAuthorizes;

    public function upload(Request $req, Paper $paper)
    {
        // Only the paper owner can upload files
        $this->authorizeOwner($paper, 'created_by');

        $req->validate([
            'file' => ['required','file','mimes:pdf,doc,docx','max:51200']
        ]);

        $file = $req->file('file');

        $disk = 'uploads'; // cPanel-safe public/uploads
        $sub  = now()->format('Y/m');
        $path = $file->store("papers/{$sub}", $disk);

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
            'url'           => \Storage::disk($disk)->url($path),
            'original_name' => $pf->original_name,
        ], 201);
    }

    public function destroy(Paper $paper, PaperFile $file)
    {
        // Ensure file belongs to this paper and only owner can remove
        if ($file->paper_id !== $paper->id) abort(404);
        $this->authorizeOwner($paper, 'created_by');

        Storage::disk($file->disk ?: 'uploads')->delete($file->path);
        $file->delete();

        return response()->json(['ok' => true]);
    }
}
