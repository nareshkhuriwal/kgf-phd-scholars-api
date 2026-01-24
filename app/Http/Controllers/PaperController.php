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
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Support\ResolvesApiScope;
use App\Models\Citation; // ✅ Add this import
use App\Models\ReviewQueue;
use Illuminate\Support\Str;

class PaperController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    public function index(Request $req)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Paper index called', ['user_id' => $user->id, 'role' => $user->role]);

        $userIds = $this->resolveApiUserIds($req);
        Log::info('Accessible user IDs for papers', ['count' => count($userIds)]);

        // ---------- Inputs (sanitized) ----------
        $search   = trim((string) $req->query('search', ''));
        $category = $req->query('category');

        $perRaw = $req->query('per_page', $req->query('perPage', $req->query('limit', 10)));
        $per    = max(5, min(200, (int) $perRaw));

        $page = max(1, (int) $req->query('page', 1));

        $allowedSort = ['id', 'title', 'authors', 'year', 'doi', 'created_at', 'updated_at'];
        $sortBy = $req->query('sort_by', 'id');
        $sortBy = in_array($sortBy, $allowedSort, true) ? $sortBy : 'title';

        $sortDir = Str::lower((string) $req->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---------- Query ----------
        $q = Paper::query()
            ->whereIn('created_by', $userIds)
            ->with('creator:id,name,email,role')
            ->withCount('files')
            ->addSelect([
                // ✅ ONE status column: 1 if paper is in review_queue for ANY accessible user
                'is_added_for_review' => ReviewQueue::selectRaw('1')
                    ->whereColumn('paper_id', 'papers.id')
                    ->whereIn('user_id', $userIds)
                    ->limit(1),
            ]);


        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $like = "%{$search}%";
                $w->where('title', 'like', $like)
                    ->orWhere('authors', 'like', $like)
                    ->orWhere('doi', 'like', $like)
                    ->orWhere('paper_code', 'like', $like);
            });
        }

        if (!is_null($category) && $category !== '') {
            $q->where('category', $category);
        }

        $q->orderBy($sortBy, $sortDir);

        // ✅ First paginate using requested page
        $p = $q->paginate($per, ['*'], 'page', $page);

        // ✅ Clamp out-of-range page numbers (prevents empty data when page > last_page)
        $lastPage = $p->lastPage(); // 0 when no records
        if ($lastPage > 0 && $page > $lastPage) {
            $page = $lastPage;
            $p = $q->paginate($per, ['*'], 'page', $page);
        }

        Log::info('Papers retrieved', [
            'total' => $p->total(),
            'per_page' => $per,
            'requested_page' => (int) $req->query('page', 1),
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
            'has_search' => $search !== '',
            'has_category' => !is_null($category) && $category !== '',
        ]);

        return PaperResource::collection($p);
    }


    public function show(Request $req, Paper $paper)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Paper show called', ['paper_id' => $paper->id, 'user_id' => $user->id]);

        $this->authorizeUserAccess($req, $paper->created_by);

        // ✅ include flags + files in one go (no extra resource logic)
        $paper->load('files');

        $paper->setAttribute(
            'in_review_queue',
            ReviewQueue::where('user_id', $user->id)->where('paper_id', $paper->id)->exists()
        );

        $paper->setAttribute(
            'in_review_table',
            DB::table('reviews')->where('user_id', $user->id)->where('paper_id', $paper->id)->exists()
        );

        return new PaperResource($paper);
    }


    public function store(PaperRequest $req)
    {
        $userId = $req->user()->id ?? abort(401, 'Unauthenticated');

        Log::info('Creating new paper', [
            'user_id' => $userId
        ]);

        $paper = DB::transaction(function () use ($req, $userId) {
            $data = $req->validated();
            $data['created_by'] = $userId;

            $paper = Paper::create($data);

            // ✅ Automatically create citation from paper
            $this->createCitationFromPaper($paper);

            // If a file came with the create request, attach it now
            if ($req->hasFile('file')) {
                $this->attachFileToPaper($paper, $req->file('file'), $userId);
                Log::info('File attached to new paper', [
                    'paper_id' => $paper->id,
                    'filename' => $req->file('file')->getClientOriginalName()
                ]);
            }

            return $paper->fresh('files');
        });

        Log::info('Paper created successfully', [
            'paper_id' => $paper->id,
            'has_file' => $paper->files->isNotEmpty()
        ]);

        return (new PaperResource($paper))
            ->response()
            ->setStatusCode(201);
    }

    public function update(PaperRequest $req, Paper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Paper update called', [
            'paper_id' => $paper->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($req, $paper->created_by);

        $userId = $req->user()->id;

        $paper = DB::transaction(function () use ($req, $paper, $userId) {
            $data = $req->validated();
            unset($data['file']);

            $paper->update($data);

            // ✅ Update or create citation when paper is updated
            $this->updateOrCreateCitationFromPaper($paper);

            if ($req->hasFile('file')) {
                $this->attachFileToPaper($paper, $req->file('file'), $userId);
                Log::info('File attached to updated paper', [
                    'paper_id' => $paper->id,
                    'filename' => $req->file('file')->getClientOriginalName()
                ]);
            }

            return $paper->fresh('files');
        });

        Log::info('Paper updated successfully', [
            'paper_id' => $paper->id
        ]);

        return new PaperResource($paper);
    }

    /**
     * Upload / replace PDF file for an existing paper.
     * POST /api/papers/{paper}/file
     */
    public function updateFile(Request $req, Paper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Paper file update called', [
            'paper_id' => $paper->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($req, $paper->created_by);

        $userId = $req->user()->id;

        $req->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:51200'], // 50MB
        ]);

        DB::transaction(function () use ($paper, $req, $userId) {
            $oldFileCount = $paper->files->count();

            // OPTIONAL: remove old files (recommended = replace behavior)
            foreach ($paper->files as $old) {
                try {
                    Storage::disk($old->disk)->delete($old->path);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete old file from storage', [
                        'paper_id' => $paper->id,
                        'file_id' => $old->id,
                        'error' => $e->getMessage()
                    ]);
                }
                $old->delete();
            }

            // Attach new file
            $this->attachFileToPaper(
                $paper,
                $req->file('file'),
                $userId
            );

            Log::info('Paper file replaced', [
                'paper_id' => $paper->id,
                'old_file_count' => $oldFileCount,
                'new_filename' => $req->file('file')->getClientOriginalName()
            ]);
        });

        return new PaperResource($paper->fresh('files'));
    }

    public function destroy(Request $req, Paper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Paper destroy called', [
            'paper_id' => $paper->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($req, $paper->created_by);

        $attempts = 0;
        $max = 3;

        while (true) {
            try {
                DB::transaction(function () use ($paper) {
                    $paper->load('files');
                    $fileCount = $paper->files->count();

                    // Delete blobs first (ignore if already gone)
                    foreach ($paper->files as $file) {
                        $disk = $file->disk ?: 'public';
                        $path = $file->path;
                        try {
                            Storage::disk($disk)->delete($path);
                        } catch (\Throwable $e) {
                            Log::warning('Failed to delete paper file from storage', [
                                'paper_id' => $paper->id,
                                'file_id' => $file->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                        $file->delete();
                    }

                    // Finally delete the paper row
                    $paper->delete();

                    Log::info('Paper deleted successfully', [
                        'paper_id' => $paper->id,
                        'files_deleted' => $fileCount
                    ]);
                });

                return response()->json(['ok' => true]);
            } catch (QueryException $e) {
                $attempts++;
                // Handle MySQL "Prepared statement needs to be re-prepared" (HY000/1615) on shared hosting
                if ($attempts < $max && str_contains($e->getMessage(), '1615')) {
                    Log::warning('Retry paper deletion due to MySQL 1615 error', [
                        'paper_id' => $paper->id,
                        'attempt' => $attempts
                    ]);
                    usleep(150000); // 150ms backoff
                    continue;
                }
                Log::error('Failed to delete paper', [
                    'paper_id' => $paper->id,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }
    }

    public function bulkDestroy(BulkDeletePapersRequest $req)
    {
        $userId = $req->user()->id ?? abort(401, 'Unauthenticated');
        $ids = $req->validated()['ids'];

        Log::info('Bulk paper destroy called', [
            'user_id' => $userId,
            'requested_ids' => $ids,
            'count' => count($ids)
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($req);

        // Restrict to accessible user-owned IDs only (silently ignore others)
        $ownedIds = Paper::whereIn('id', $ids)
            ->whereIn('created_by', $userIds)
            ->pluck('id')
            ->all();

        Log::info('Papers filtered by accessible users', [
            'requested_count' => count($ids),
            'accessible_count' => count($ownedIds),
            'accessible_ids' => $ownedIds
        ]);

        if (empty($ownedIds)) {
            Log::warning('No accessible papers found for bulk deletion');
            return response()->json(['ok' => true, 'deleted' => []]);
        }

        $attempts = 0;
        $max = 3;

        while (true) {
            try {
                DB::transaction(function () use ($ownedIds) {
                    $papers = Paper::with('files')->whereIn('id', $ownedIds)->get();
                    $totalFiles = 0;

                    foreach ($papers as $paper) {
                        foreach ($paper->files as $file) {
                            $disk = $file->disk ?: 'public';
                            $path = $file->path;
                            try {
                                Storage::disk($disk)->delete($path);
                                $totalFiles++;
                            } catch (\Throwable $e) {
                                Log::warning('Failed to delete file during bulk deletion', [
                                    'paper_id' => $paper->id,
                                    'file_id' => $file->id,
                                    'error' => $e->getMessage()
                                ]);
                            }
                            $file->delete();
                        }
                    }

                    // Delete papers at the end (FKs may cascade other relations)
                    Paper::whereIn('id', $ownedIds)->delete();

                    Log::info('Bulk paper deletion completed', [
                        'papers_deleted' => count($ownedIds),
                        'files_deleted' => $totalFiles
                    ]);
                });

                return response()->json(['ok' => true, 'deleted' => $ownedIds]);
            } catch (QueryException $e) {
                $attempts++;
                if ($attempts < $max && str_contains($e->getMessage(), '1615')) {
                    Log::warning('Retry bulk deletion due to MySQL 1615 error', [
                        'attempt' => $attempts
                    ]);
                    usleep(150000);
                    continue;
                }
                Log::error('Failed bulk paper deletion', [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }
    }

    /**
     * ✅ Create a citation from paper data
     */
    private function createCitationFromPaper(Paper $paper): Citation
    {
        Log::info('Creating citation from paper', [
            'paper_id' => $paper->id,
            'paper_code' => $paper->paper_code
        ]);

        $citation = Citation::create([
            'paper_id' => $paper->id,
            'citation_key' => $paper->paper_code ?: 'PAPER-' . $paper->id,
            'citation_type_code' => $paper->citation_type_code,
            'title' => $paper->title,
            'authors' => $paper->authors,
            'year' => $paper->year,
            'journal' => $paper->journal,
            'volume' => $paper->volume,
            'issue' => $paper->issue,
            'pages' => $paper->page_no,
            'publisher' => $paper->publisher,
            'doi' => $paper->doi,
            'isbn' => $paper->issn_isbn,
            'created_from' => 'paper',
        ]);

        Log::info('Citation created from paper', [
            'citation_id' => $citation->id,
            'citation_key' => $citation->citation_key
        ]);

        return $citation;
    }

    /**
     * ✅ Update existing citation or create new one
     */
    private function updateOrCreateCitationFromPaper(Paper $paper): Citation
    {
        Log::info('Updating or creating citation from paper', [
            'paper_id' => $paper->id,
            'paper_code' => $paper->paper_code
        ]);

        // Find existing citation by paper_id or citation_key
        $citation = Citation::where('paper_id', $paper->id)
            ->orWhere('citation_key', $paper->paper_code)
            ->first();

        $citationData = [
            'paper_id' => $paper->id,
            'citation_key' => $paper->paper_code ?: 'PAPER-' . $paper->id,
            'citation_type_code' => $paper->citation_type_code,
            'title' => $paper->title,
            'authors' => $paper->authors,
            'year' => $paper->year,
            'journal' => $paper->journal,
            'volume' => $paper->volume,
            'issue' => $paper->issue,
            'pages' => $paper->page_no,
            'publisher' => $paper->publisher,
            'doi' => $paper->doi,
            'isbn' => $paper->issn_isbn,
        ];

        if ($citation) {
            // Update existing citation
            $citation->update($citationData);
            Log::info('Citation updated from paper', [
                'citation_id' => $citation->id,
                'citation_key' => $citation->citation_key
            ]);
        } else {
            // Create new citation
            $citationData['created_from'] = 'paper';
            $citation = Citation::create($citationData);
            Log::info('New citation created from paper', [
                'citation_id' => $citation->id,
                'citation_key' => $citation->citation_key
            ]);
        }

        return $citation;
    }

    /**
     * Save an uploaded file to storage and create the paper_files row.
     */
    private function attachFileToPaper(Paper $paper, UploadedFile $file, ?int $userId = null): void
    {
        $disk   = 'uploads';                   // matches config/filesystems.php 'public' disk
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
