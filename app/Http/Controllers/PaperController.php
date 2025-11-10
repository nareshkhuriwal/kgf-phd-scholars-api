<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaperRequest;
use App\Http\Requests\BulkDeletePapersRequest;
use App\Http\Resources\PaperResource;
use App\Models\Paper;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaperController extends Controller
{
    public function index(Request $req)
    {
        $q = Paper::query()->withCount('files');

        if ($s = $req->get('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('title', 'like', "%$s%")
                    ->orWhere('authors', 'like', "%$s%")
                    ->orWhere('doi', 'like', "%$s%")
                    ->orWhere('paper_code', 'like', "%$s%");
            });
        }
        if ($cat = $req->get('category')) {
            $q->where('category', $cat);
        }

        $per = (int)($req->get('per_page', 25));
        $p   = $q->latest('id')->paginate($per);

        return PaperResource::collection($p);
    }

    public function store(PaperRequest $req)
    {
        $userId = $req->user()->id ?? null;

        $paper = DB::transaction(function () use ($req, $userId) {
            $data = $req->validated();
            $data['created_by'] = $userId;

            $paper = Paper::create($data);

            // If a file came with the create request, attach it now
            if ($req->hasFile('file')) {
                $this->attachFileToPaper($paper, $req->file('file'), $userId);
            }

            return $paper->fresh('files');
        });

        return (new PaperResource($paper))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Paper $paper)
    {
        $paper->load('files');
        return new PaperResource($paper);
    }

    public function update(PaperRequest $req, Paper $paper)
    {
        $userId = $req->user()->id ?? null;

        $paper = DB::transaction(function () use ($req, $paper, $userId) {
            $paper->update($req->validated());

            // Optional: if client uploads a new file during update, attach it too
            if ($req->hasFile('file')) {
                $this->attachFileToPaper($paper, $req->file('file'), $userId);
            }

            return $paper->fresh('files');
        });

        return new PaperResource($paper);
    }

    public function destroy(Paper $paper)
    {
        $paper->delete();
        return response()->json(['ok' => true]);
    }

    public function bulkDestroy(BulkDeletePapersRequest $req)
    {
        $ids = $req->validated()['ids'];

        DB::transaction(function () use ($ids) {
            $papers = Paper::with('files')->whereIn('id', $ids)->get();

            foreach ($papers as $paper) {
                foreach ($paper->files as $file) {
                    // ignore failures if already removed
                    Storage::disk($file->disk ?: 'public')->delete($file->path);
                    $file->delete();
                }
            }

            Paper::whereIn('id', $ids)->delete();
        });

        return response()->json(['ok' => true, 'deleted' => $ids]);
    }

    /**
     * Save an uploaded file to storage and create the paper_files row.
     */
    private function attachFileToPaper(Paper $paper, UploadedFile $file, ?int $userId = null): void
    {
        $disk   = 'public';                   // matches config/filesystems.php 'public' disk
        $subdir = now()->format('Y/m');       // e.g. 2025/11
        $dir    = "library/{$subdir}";

        // Ensure target directory exists on the disk (important on shared hosting)
        Storage::disk($disk)->makeDirectory($dir);

        // Store file on the public disk; returns relative path like "library/2025/11/xxxx.pdf"
        $path = $file->store($dir, $disk);

        $paper->files()->create([
            'disk'          => $disk,
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size_bytes'    => $file->getSize(),
            'checksum'      => hash_file('sha256', $file->getRealPath()),
            'uploaded_by'   => $userId,
        ]);
    }
}
