<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Http\Requests\BulkDeletePapersRequest;
use App\Http\Requests\PaperRequest;
use App\Http\Resources\PaperResource;
use App\Models\Citation;
use App\Models\Paper;
use App\Models\ReviewQueue;
use App\Support\ResolvesApiScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PaperController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    public function index(Request $req)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Paper index called', [
            'user_id' => $user->id,
            'role'    => $user->role,
        ]);

        $userIds = $this->resolveApiUserIds($req);

        Log::info('Accessible user IDs for papers', [
            'count' => count($userIds),
        ]);

        $search   = trim((string) $req->query('search', ''));
        $category = $req->query('category');

        $perRaw = $req->query('per_page', $req->query('perPage', $req->query('limit', 10)));
        $per    = max(5, min(200, (int) $perRaw));

        $page = max(1, (int) $req->query('page', 1));

        $allowedSort = ['id', 'title', 'authors', 'year', 'doi', 'created_at', 'updated_at'];
        $sortBy      = $req->query('sort_by', 'id');
        $sortBy      = in_array($sortBy, $allowedSort, true) ? $sortBy : 'title';

        $sortDir = Str::lower((string) $req->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $q = Paper::query()
            ->whereIn('created_by', $userIds)
            ->with('creator:id,name,email,role')
            ->withCount('files')
            ->addSelect([
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

        $p = $q->paginate($per, ['*'], 'page', $page);

        $lastPage = $p->lastPage();
        if ($lastPage > 0 && $page > $lastPage) {
            $page = $lastPage;
            $p = $q->paginate($per, ['*'], 'page', $page);
        }

        Log::info('Papers retrieved', [
            'total'           => $p->total(),
            'per_page'        => $per,
            'requested_page'  => (int) $req->query('page', 1),
            'current_page'    => $p->currentPage(),
            'last_page'       => $p->lastPage(),
            'sort_by'         => $sortBy,
            'sort_dir'        => $sortDir,
            'has_search'      => $search !== '',
            'has_category'    => !is_null($category) && $category !== '',
        ]);

        return PaperResource::collection($p);
    }

    public function show(Request $req, Paper $paper)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Paper show called', [
            'paper_id' => $paper->id,
            'user_id'  => $user->id,
        ]);

        $this->authorizeUserAccess($req, $paper->created_by);

        $paper->load('files');

        $paper->setAttribute(
            'in_review_queue',
            ReviewQueue::where('user_id', $user->id)
                ->where('paper_id', $paper->id)
                ->exists()
        );

        $paper->setAttribute(
            'in_review_table',
            DB::table('reviews')
                ->where('user_id', $user->id)
                ->where('paper_id', $paper->id)
                ->exists()
        );

        return new PaperResource($paper);
    }

    public function store(PaperRequest $req)
    {
        $userId = $req->user()->id ?? abort(401, 'Unauthenticated');

        Log::info('Creating new paper', [
            'user_id' => $userId,
        ]);

        $paper = DB::transaction(function () use ($req, $userId) {
            $data = $req->validated();
            $data['created_by'] = $userId;

            $paper = Paper::create($data);

            $this->createCitationFromPaper($paper);

            if ($req->hasFile('file')) {
                $this->attachFileToPaper($paper, $req->file('file'), $userId);

                Log::info('File attached to new paper', [
                    'paper_id' => $paper->id,
                    'filename' => $req->file('file')->getClientOriginalName(),
                ]);
            }

            return $paper->fresh('files');
        }, 3);

        Log::info('Paper created successfully', [
            'paper_id' => $paper->id,
            'has_file' => $paper->files->isNotEmpty(),
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
            'user_id'  => $req->user()->id,
        ]);

        $this->authorizeUserAccess($req, $paper->created_by);

        $userId = $req->user()->id;

        $paper = DB::transaction(function () use ($req, $paper, $userId) {
            $data = $req->validated();
            unset($data['file']);

            $paper->update($data);

            $this->updateOrCreateCitationFromPaper($paper);

            if ($req->hasFile('file')) {
                $this->attachFileToPaper($paper, $req->file('file'), $userId);

                Log::info('File attached to updated paper', [
                    'paper_id' => $paper->id,
                    'filename' => $req->file('file')->getClientOriginalName(),
                ]);
            }

            return $paper->fresh('files');
        }, 3);

        Log::info('Paper updated successfully', [
            'paper_id' => $paper->id,
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
            'user_id'  => $req->user()->id,
        ]);

        $this->authorizeUserAccess($req, $paper->created_by);

        $userId = $req->user()->id;

        $req->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        $diskForFallback = $this->uploadDisk();

        DB::transaction(function () use ($paper, $req, $userId, $diskForFallback) {
            $paper->load('files');
            $oldFiles = $paper->files->map(function ($f) {
                return [
                    'id'   => $f->id,
                    'disk' => $f->disk,
                    'path' => $f->path,
                ];
            })->values()->all();

            $oldFileCount = count($oldFiles);

            $this->attachFileToPaper($paper, $req->file('file'), $userId);

            foreach ($paper->files as $old) {
                if (in_array($old->id, array_column($oldFiles, 'id'), true)) {
                    $old->delete();
                }
            }

            DB::afterCommit(function () use ($oldFiles, $paper, $diskForFallback) {
                foreach ($oldFiles as $old) {
                    try {
                        $disk = $old['disk'] ?: $diskForFallback;
                        Storage::disk($disk)->delete($old['path']);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to delete old file from storage after replace', [
                            'paper_id' => $paper->id,
                            'file_id'  => $old['id'],
                            'disk'     => $old['disk'] ?: $diskForFallback,
                            'path'     => $old['path'],
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            });

            Log::info('Paper file replaced', [
                'paper_id'       => $paper->id,
                'old_file_count' => $oldFileCount,
                'new_filename'   => $req->file('file')->getClientOriginalName(),
            ]);
        }, 3);

        return new PaperResource($paper->fresh('files'));
    }

    public function destroy(Request $req, Paper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Paper destroy called', [
            'paper_id' => $paper->id,
            'user_id'  => $req->user()->id,
        ]);

        $this->authorizeUserAccess($req, $paper->created_by);

        $attempts = 0;
        $max      = 3;

        while (true) {
            try {
                $filesToDelete = [];

                DB::transaction(function () use ($paper, &$filesToDelete) {
                    $paper->load('files');

                    foreach ($paper->files as $file) {
                        $filesToDelete[] = [
                            'paper_id' => $paper->id,
                            'file_id'  => $file->id,
                            'disk'     => $file->disk ?: $this->uploadDisk(),
                            'path'     => $file->path,
                        ];

                        $file->delete();
                    }

                    $paper->delete();

                    Log::info('Paper deleted successfully', [
                        'paper_id'      => $paper->id,
                        'files_deleted' => count($filesToDelete),
                    ]);
                }, 3);

                foreach ($filesToDelete as $file) {
                    try {
                        Storage::disk($file['disk'])->delete($file['path']);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to delete paper file from storage', [
                            'paper_id' => $file['paper_id'],
                            'file_id'  => $file['file_id'],
                            'disk'     => $file['disk'],
                            'path'     => $file['path'],
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }

                return response()->json(['ok' => true]);
            } catch (QueryException $e) {
                $attempts++;

                if ($attempts < $max && str_contains($e->getMessage(), '1615')) {
                    Log::warning('Retry paper deletion due to MySQL 1615 error', [
                        'paper_id' => $paper->id,
                        'attempt'  => $attempts,
                    ]);
                    usleep(150000);
                    continue;
                }

                Log::error('Failed to delete paper', [
                    'paper_id' => $paper->id,
                    'error'    => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    public function bulkDestroy(BulkDeletePapersRequest $req)
    {
        $userId = $req->user()->id ?? abort(401, 'Unauthenticated');
        $ids    = $req->validated()['ids'];

        Log::info('Bulk paper destroy called', [
            'user_id'       => $userId,
            'requested_ids' => $ids,
            'count'         => count($ids),
        ]);

        $userIds = $this->resolveApiUserIds($req);

        $ownedIds = Paper::whereIn('id', $ids)
            ->whereIn('created_by', $userIds)
            ->pluck('id')
            ->all();

        Log::info('Papers filtered by accessible users', [
            'requested_count'  => count($ids),
            'accessible_count' => count($ownedIds),
            'accessible_ids'   => $ownedIds,
        ]);

        if (empty($ownedIds)) {
            Log::warning('No accessible papers found for bulk deletion');
            return response()->json(['ok' => true, 'deleted' => []]);
        }

        $attempts = 0;
        $max      = 3;

        while (true) {
            try {
                $filesToDelete = [];
                $paperCount    = 0;

                DB::transaction(function () use ($ownedIds, &$filesToDelete, &$paperCount) {
                    $papers = Paper::with('files')->whereIn('id', $ownedIds)->get();
                    $paperCount = $papers->count();

                    foreach ($papers as $paper) {
                        foreach ($paper->files as $file) {
                            $filesToDelete[] = [
                                'paper_id' => $paper->id,
                                'file_id'  => $file->id,
                                'disk'     => $file->disk ?: $this->uploadDisk(),
                                'path'     => $file->path,
                            ];

                            $file->delete();
                        }
                    }

                    Paper::whereIn('id', $ownedIds)->delete();

                    Log::info('Bulk paper deletion DB phase completed', [
                        'papers_deleted' => $paperCount,
                        'files_marked'   => count($filesToDelete),
                    ]);
                }, 3);

                $deletedFiles = 0;
                foreach ($filesToDelete as $file) {
                    try {
                        Storage::disk($file['disk'])->delete($file['path']);
                        $deletedFiles++;
                    } catch (\Throwable $e) {
                        Log::warning('Failed to delete file during bulk deletion', [
                            'paper_id' => $file['paper_id'],
                            'file_id'  => $file['file_id'],
                            'disk'     => $file['disk'],
                            'path'     => $file['path'],
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }

                Log::info('Bulk paper deletion completed', [
                    'papers_deleted' => $paperCount,
                    'files_deleted'  => $deletedFiles,
                ]);

                return response()->json(['ok' => true, 'deleted' => $ownedIds]);
            } catch (QueryException $e) {
                $attempts++;

                if ($attempts < $max && str_contains($e->getMessage(), '1615')) {
                    Log::warning('Retry bulk deletion due to MySQL 1615 error', [
                        'attempt' => $attempts,
                    ]);
                    usleep(150000);
                    continue;
                }

                Log::error('Failed bulk paper deletion', [
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    private function createCitationFromPaper(Paper $paper): Citation
    {
        Log::info('Creating citation from paper', [
            'paper_id'   => $paper->id,
            'paper_code' => $paper->paper_code,
        ]);

        $citation = Citation::create([
            'paper_id'            => $paper->id,
            'citation_key'        => $paper->paper_code ?: 'PAPER-' . $paper->id,
            'citation_type_code'  => $paper->citation_type_code,
            'title'               => $paper->title,
            'authors'             => $paper->authors,
            'year'                => $paper->year,
            'journal'             => $paper->journal,
            'volume'              => $paper->volume,
            'issue'               => $paper->issue,
            'pages'               => $paper->page_no,
            'publisher'           => $paper->publisher,
            'doi'                 => $paper->doi,
            'isbn'                => $paper->issn_isbn,
            'created_from'        => 'paper',
        ]);

        Log::info('Citation created from paper', [
            'citation_id'  => $citation->id,
            'citation_key' => $citation->citation_key,
        ]);

        return $citation;
    }

    private function updateOrCreateCitationFromPaper(Paper $paper): Citation
    {
        Log::info('Updating or creating citation from paper', [
            'paper_id'   => $paper->id,
            'paper_code' => $paper->paper_code,
        ]);

        $citation = Citation::where('paper_id', $paper->id)
            ->orWhere('citation_key', $paper->paper_code)
            ->first();

        $citationData = [
            'paper_id'           => $paper->id,
            'citation_key'       => $paper->paper_code ?: 'PAPER-' . $paper->id,
            'citation_type_code' => $paper->citation_type_code,
            'title'              => $paper->title,
            'authors'            => $paper->authors,
            'year'               => $paper->year,
            'journal'            => $paper->journal,
            'volume'             => $paper->volume,
            'issue'              => $paper->issue,
            'pages'              => $paper->page_no,
            'publisher'          => $paper->publisher,
            'doi'                => $paper->doi,
            'isbn'               => $paper->issn_isbn,
        ];

        if ($citation) {
            $citation->update($citationData);

            Log::info('Citation updated from paper', [
                'citation_id'  => $citation->id,
                'citation_key' => $citation->citation_key,
            ]);
        } else {
            $citationData['created_from'] = 'paper';
            $citation = Citation::create($citationData);

            Log::info('New citation created from paper', [
                'citation_id'  => $citation->id,
                'citation_key' => $citation->citation_key,
            ]);
        }

        return $citation;
    }

    /**
     * Save an uploaded file to storage and create the paper_files row.
     */
    private function attachFileToPaper(Paper $paper, UploadedFile $file, ?int $userId = null): void
    {
        $disk         = $this->uploadDisk();
        $originalName = $file->getClientOriginalName();
        $mime         = $this->normalizeMime($file->getClientMimeType(), $originalName);
        $sizeBytes    = $file->getSize();
        $checksum     = hash_file('sha256', $file->getRealPath());
        $path         = $this->buildStoragePath($originalName);

        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open uploaded file stream.');
        }

        $uploaded = false;

        try {
            Storage::disk($disk)->writeStream($path, $stream);
            $uploaded = true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            $paper->files()->create([
                'disk'          => $disk,
                'path'          => $path,
                'original_name' => $originalName,
                'mime'          => $mime,
                'size_bytes'    => $sizeBytes,
                'checksum'      => $checksum,
                'uploaded_by'   => $userId,
            ]);
        } catch (\Throwable $e) {
            if ($uploaded) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $cleanupEx) {
                    Log::warning('Failed to cleanup uploaded file after DB insert failure', [
                        'paper_id' => $paper->id,
                        'disk'     => $disk,
                        'path'     => $path,
                        'error'    => $cleanupEx->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }

    private function uploadDisk(): string
    {
        return config('filesystems.default_upload_disk', env('AZURE_STORAGE_DISK', 'azure'));
    }

    private function paperSubdir(): string
    {
        return 'library/' . now()->format('Y/m');
    }

    private function buildStoragePath(string $originalName): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', trim($originalName));
        $safeName = ltrim((string) $safeName, '.');

        if ($safeName === '') {
            $safeName = 'file_' . now()->timestamp;
        }

        return $this->paperSubdir() . '/' . Str::uuid()->toString() . '_' . $safeName;
    }

    private function normalizeMime(?string $mime, string $originalName): string
    {
        $mime = strtolower(trim((string) $mime));

        if ($mime !== '') {
            $mime = explode(';', $mime)[0];
        }

        if ($mime !== '') {
            return $mime;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }
}