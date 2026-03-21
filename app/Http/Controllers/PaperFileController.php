<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPaperUploadDisk;
use App\Models\Paper;
use App\Support\ResolvesApiScope;
use App\Models\PaperFile;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaperFileController extends Controller
{
    use ResolvesPaperUploadDisk, ResolvesApiScope;

    private const MAX_FILE_SIZE_KB = 51200; // 50 MB
    private const ALLOWED_MIMES = 'pdf,doc,docx';

    private function papersSubdir(): string
    {
        return $this->libraryBlobSubdir();
    }

    private function buildStoragePath(string $originalName): string
    {
        $originalName = trim($originalName);
        $safeName = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $originalName);
        $safeName = ltrim((string) $safeName, '.');

        if ($safeName === '') {
            $safeName = 'file_' . now()->timestamp;
        }

        return $this->papersSubdir() . '/' . Str::uuid()->toString() . '_' . $safeName;
    }

    private function isPreviewableInline(?string $mime, string $originalName): bool
    {
        $mime = strtolower((string) $mime);
        $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return $mime === 'application/pdf' || $ext === 'pdf';
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

    private function contentDispositionFilename(string $filename): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $filename);
        $fallback = $fallback ?: 'file';

        return $fallback;
    }

    /**
     * Try to open a stream from candidate disks and resolve best-effort size.
     *
     * @return array{0: string, 1: resource, 2: int|null}
     */
    private function openStreamForFile(PaperFile $file): array
    {
        $path = (string) $file->path;
        if ($path === '') {
            Log::warning('Paper file stream open aborted due to empty path', [
                'paper_file_id' => $file->id,
                'paper_id' => $file->paper_id,
                'stored_disk' => $file->disk,
            ]);
            throw new FileNotFoundException('Empty file path');
        }

        $uploadDisk = $this->uploadDisk();

        // Always try the upload disk (Azure) first.
        $candidates = [$uploadDisk];

        if ($legacy = $this->azureLegacyDisk()) {
            $candidates[] = $legacy;
        }

        // Then the disk recorded on the row (if different).
        if (is_string($file->disk) && $file->disk !== '' && $file->disk !== $uploadDisk) {
            $candidates[] = $file->disk;
        }

        // Optionally allow legacy fallbacks.
        if (!$this->strictUploadDiskOnly()) {
            array_push($candidates, 'uploads', 'public', 'local');
        }

        $candidates = array_values(array_unique(array_filter($candidates, fn ($d) => is_string($d) && $d !== '')));

        Log::info('Paper file read started', [
            'paper_file_id' => $file->id,
            'paper_id' => $file->paper_id,
            'stored_disk' => $file->disk,
            'upload_disk' => $uploadDisk,
            'strict_upload_disk_only' => $this->strictUploadDiskOnly(),
            'path' => $path,
            'candidate_disks' => $candidates,
        ]);

        $last = null;
        foreach ($candidates as $disk) {
            try {
                $diskDriver = (string) config("filesystems.disks.{$disk}.driver", 'unknown');
                $exists = null;
                try {
                    $exists = Storage::disk($disk)->exists($path);
                } catch (\Throwable $existsEx) {
                    Log::warning('Paper file exists() check failed before readStream', [
                        'paper_file_id' => $file->id,
                        'paper_id' => $file->paper_id,
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $existsEx->getMessage(),
                    ]);
                }

                Log::info('Paper file read attempt', [
                    'paper_file_id' => $file->id,
                    'paper_id' => $file->paper_id,
                    'disk' => $disk,
                    'driver' => $diskDriver,
                    'path' => $path,
                    'exists' => $exists,
                ]);

                $stream = Storage::disk($disk)->readStream($path);
                if (is_resource($stream)) {
                    $resolvedSize = null;
                    try {
                        $size = Storage::disk($disk)->size($path);
                        if (is_int($size) && $size > 0) {
                            $resolvedSize = $size;
                        }
                    } catch (\Throwable $sizeEx) {
                        Log::warning('Paper file size() check failed after readStream success', [
                            'paper_file_id' => $file->id,
                            'paper_id' => $file->paper_id,
                            'disk' => $disk,
                            'path' => $path,
                            'error' => $sizeEx->getMessage(),
                        ]);
                    }

                    Log::info('Paper file read success', [
                        'paper_file_id' => $file->id,
                        'paper_id' => $file->paper_id,
                        'resolved_disk' => $disk,
                        'path' => $path,
                        'previous_disk' => $file->disk,
                        'resolved_size' => $resolvedSize,
                    ]);

                    // Self-heal: persist the working disk when missing/incorrect.
                    if ($file->disk !== $disk) {
                        try {
                            $file->forceFill(['disk' => $disk])->save();
                            Log::info('Paper file disk self-healed', [
                                'paper_file_id' => $file->id,
                                'paper_id' => $file->paper_id,
                                'old_disk' => $file->disk,
                                'new_disk' => $disk,
                                'path' => $path,
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('Unable to persist resolved disk for paper file', [
                                'paper_file_id' => $file->id,
                                'resolved_disk' => $disk,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    return [$disk, $stream, $resolvedSize];
                }

                Log::warning('Paper file readStream returned non-resource', [
                    'paper_file_id' => $file->id,
                    'paper_id' => $file->paper_id,
                    'disk' => $disk,
                    'driver' => $diskDriver,
                    'path' => $path,
                ]);

                // Fallback for adapters where readStream() is unreliable:
                // fetch bytes with get() and wrap into a temporary stream.
                try {
                    $bytes = Storage::disk($disk)->get($path);

                    if (is_string($bytes) && $bytes !== '') {
                        $tmp = fopen('php://temp', 'w+b');
                        if (is_resource($tmp)) {
                            fwrite($tmp, $bytes);
                            rewind($tmp);

                            Log::info('Paper file read success via get() fallback', [
                                'paper_file_id' => $file->id,
                                'paper_id' => $file->paper_id,
                                'resolved_disk' => $disk,
                                'driver' => $diskDriver,
                                'path' => $path,
                                'byte_length' => strlen($bytes),
                            ]);

                            if ($file->disk !== $disk) {
                                try {
                                    $oldDisk = $file->disk;
                                    $file->forceFill(['disk' => $disk])->save();
                                    Log::info('Paper file disk self-healed (get fallback)', [
                                        'paper_file_id' => $file->id,
                                        'paper_id' => $file->paper_id,
                                        'old_disk' => $oldDisk,
                                        'new_disk' => $disk,
                                        'path' => $path,
                                    ]);
                                } catch (\Throwable $e) {
                                    Log::warning('Unable to persist resolved disk after get fallback', [
                                        'paper_file_id' => $file->id,
                                        'resolved_disk' => $disk,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }

                            return [$disk, $tmp, strlen($bytes)];
                        }
                    }

                    Log::warning('Paper file get() fallback returned empty or invalid payload', [
                        'paper_file_id' => $file->id,
                        'paper_id' => $file->paper_id,
                        'disk' => $disk,
                        'driver' => $diskDriver,
                        'path' => $path,
                        'byte_length' => is_string($bytes) ? strlen($bytes) : null,
                    ]);
                } catch (\Throwable $getEx) {
                    Log::warning('Paper file get() fallback failed', [
                        'paper_file_id' => $file->id,
                        'paper_id' => $file->paper_id,
                        'disk' => $disk,
                        'driver' => $diskDriver,
                        'path' => $path,
                        'error' => $getEx->getMessage(),
                    ]);
                }
            } catch (FileNotFoundException $e) {
                Log::warning('Paper file not found on disk candidate', [
                    'paper_file_id' => $file->id,
                    'paper_id' => $file->paper_id,
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                $last = $e;
                continue;
            } catch (\Throwable $e) {
                // keep trying other disks; capture last error for logs
                Log::warning('Paper file read failed on disk candidate', [
                    'paper_file_id' => $file->id,
                    'paper_id' => $file->paper_id,
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                $last = $e;
                continue;
            }
        }

        Log::error('Paper file read failed on all disk candidates', [
            'paper_file_id' => $file->id,
            'paper_id' => $file->paper_id,
            'stored_disk' => $file->disk,
            'path' => $path,
            'candidate_disks' => $candidates,
            'last_error' => $last?->getMessage(),
        ]);

        if ($last instanceof FileNotFoundException) {
            throw $last;
        }

        throw new FileNotFoundException('File not found on any configured disk.');
    }

    public function upload(Request $req, Paper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeUserAccess($req, (int) $paper->created_by);

        $req->validate([
            'file' => ['required', 'file', 'mimes:' . self::ALLOWED_MIMES, 'max:' . self::MAX_FILE_SIZE_KB],
        ]);

        $file = $req->file('file');
        $disk = $this->uploadDisk();
        $path = $this->buildStoragePath($file->getClientOriginalName());

        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            return response()->json([
                'message' => 'Unable to open uploaded file stream.',
            ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $uploaded = false;

        try {
            Storage::disk($disk)->writeStream($path, $stream);
            $uploaded = true;
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            Log::error('Paper file upload to storage failed', [
                'paper_id' => $paper->id,
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'File upload failed.',
                'error'   => app()->environment('production') ? null : $e->getMessage(),
            ], HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            $pf = DB::transaction(function () use ($paper, $req, $file, $disk, $path) {
                return $paper->files()->create([
                    'disk'          => $disk,
                    'path'          => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime'          => $this->normalizeMime($file->getClientMimeType(), $file->getClientOriginalName()),
                    'size_bytes'    => $file->getSize(),
                    'checksum'      => hash_file('sha256', $file->getRealPath()),
                    'uploaded_by'   => $req->user()->id ?? null,
                ]);
            });

            return response()->json([
                'id'             => $pf->id,
                'paper_id'       => $pf->paper_id,
                'disk'           => $pf->disk,
                'storage_provider' => $this->storageProviderForDisk($pf->disk),
                'original_name'  => $pf->original_name,
                'mime'           => $pf->mime,
                'size_bytes'     => $pf->size_bytes,
                'preview_url'    => route('papers.files.preview', [$paper, $pf]),
                'download_url'   => route('papers.files.download', [$paper, $pf]),
                'can_preview'    => $this->isPreviewableInline($pf->mime, $pf->original_name),
                'created_at'     => $pf->created_at,
            ], HttpResponse::HTTP_CREATED);
        } catch (\Throwable $e) {
            if ($uploaded) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $cleanupEx) {
                    Log::warning('Uploaded blob cleanup failed after DB transaction failure', [
                        'paper_id' => $paper->id,
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $cleanupEx->getMessage(),
                    ]);
                }
            }

            Log::error('Paper file DB create failed after upload', [
                'paper_id' => $paper->id,
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'File metadata save failed.',
                'error'   => app()->environment('production') ? null : $e->getMessage(),
            ], HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function preview(Request $req, Paper $paper, PaperFile $file): StreamedResponse
    {
        if ($file->paper_id !== $paper->id) {
            abort(404);
        }

        $req->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeUserAccess($req, (int) $paper->created_by);

        if (!$this->isPreviewableInline($file->mime, $file->original_name)) {
            abort(415, 'This file type is not supported for inline preview.');
        }

        try {
            [, $stream, $resolvedSize] = $this->openStreamForFile($file);
        } catch (FileNotFoundException $e) {
            abort(404, 'File not found.');
        } catch (\Throwable $e) {
            Log::error('Paper file preview failed', [
                'paper_id' => $paper->id,
                'paper_file_id' => $file->id,
                'disk' => $file->disk,
                'path' => $file->path,
                'error' => $e->getMessage(),
            ]);
            abort(500, 'Unable to open file.');
        }

        if (!is_resource($stream)) {
            abort(404, 'File stream not available.');
        }

        $mime = $this->normalizeMime($file->mime, $file->original_name);
        $name = $this->contentDispositionFilename($file->original_name);

        // PDFs can be overwritten in place (e.g. highlight apply). Do not allow browser HTTP cache
        // or reloads will show stale bytes while the blob in storage is already updated.
        $headers = [
            'Content-Type'              => $mime,
            'Content-Disposition'       => 'inline; filename="' . $name . '"',
            'X-Content-Type-Options'    => 'nosniff',
            'Accept-Ranges'             => 'bytes',
            'Cache-Control'             => 'private, no-store, must-revalidate',
            'Pragma'                    => 'no-cache',
        ];

        if (is_int($resolvedSize) && $resolvedSize > 0) {
            $headers['Content-Length'] = (string) $resolvedSize;
        }

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    public function download(Request $req, Paper $paper, PaperFile $file): StreamedResponse
    {
        if ($file->paper_id !== $paper->id) {
            abort(404);
        }

        $req->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeUserAccess($req, (int) $paper->created_by);

        try {
            [, $stream, $resolvedSize] = $this->openStreamForFile($file);
        } catch (FileNotFoundException $e) {
            abort(404, 'File not found.');
        } catch (\Throwable $e) {
            Log::error('Paper file download failed', [
                'paper_id' => $paper->id,
                'paper_file_id' => $file->id,
                'disk' => $file->disk,
                'path' => $file->path,
                'error' => $e->getMessage(),
            ]);
            abort(500, 'Unable to download file.');
        }

        if (!is_resource($stream)) {
            abort(404, 'File stream not available.');
        }

        $mime = $this->normalizeMime($file->mime, $file->original_name);
        $name = $this->contentDispositionFilename($file->original_name);

        $headers = [
            'Content-Type'           => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store, must-revalidate',
            'Pragma'                 => 'no-cache',
        ];
        if (is_int($resolvedSize) && $resolvedSize > 0) {
            $headers['Content-Length'] = (string) $resolvedSize;
        }

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, $name, $headers);
    }

    public function destroy(Request $req, Paper $paper, PaperFile $file)
    {
        if ($file->paper_id !== $paper->id) {
            abort(404);
        }

        $req->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeUserAccess($req, (int) $paper->created_by);

        $disk = $file->disk ?: $this->uploadDisk();
        $path = $file->path;

        try {
            DB::transaction(function () use ($file) {
                $file->delete();
            });

            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $e) {
                Log::warning('Paper file deleted from DB but storage delete failed', [
                    'paper_id' => $paper->id,
                    'paper_file_id' => $file->id,
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('Paper file delete failed', [
                'paper_id' => $paper->id,
                'paper_file_id' => $file->id,
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to delete file.',
                'error'   => app()->environment('production') ? null : $e->getMessage(),
            ], HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}