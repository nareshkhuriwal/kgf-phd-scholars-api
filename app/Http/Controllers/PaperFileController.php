<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Models\Paper;
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
    use OwnerAuthorizes;

    private const MAX_FILE_SIZE_KB = 51200; // 50 MB
    private const ALLOWED_MIMES = 'pdf,doc,docx';

    private function uploadDisk(): string
    {
        return config('filesystems.default_upload_disk', env('AZURE_STORAGE_DISK', 'azure'));
    }

    private function papersSubdir(): string
    {
        return 'papers/' . now()->format('Y/m');
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

    public function upload(Request $req, Paper $paper)
    {
        $this->authorizeOwner($paper, 'created_by');

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

        $this->authorizeOwner($paper, 'created_by');

        if (!$this->isPreviewableInline($file->mime, $file->original_name)) {
            abort(415, 'This file type is not supported for inline preview.');
        }

        try {
            $stream = Storage::disk($file->disk ?: $this->uploadDisk())->readStream($file->path);
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

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type'              => $mime,
            'Content-Length'            => (string) ($file->size_bytes ?? ''),
            'Content-Disposition'       => 'inline; filename="' . $name . '"',
            'X-Content-Type-Options'    => 'nosniff',
            'Accept-Ranges'             => 'bytes',
            'Cache-Control'             => 'private, max-age=300',
        ]);
    }

    public function download(Request $req, Paper $paper, PaperFile $file): StreamedResponse
    {
        if ($file->paper_id !== $paper->id) {
            abort(404);
        }

        $this->authorizeOwner($paper, 'created_by');

        try {
            $stream = Storage::disk($file->disk ?: $this->uploadDisk())->readStream($file->path);
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

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, $name, [
            'Content-Type'           => $mime,
            'Content-Length'         => (string) ($file->size_bytes ?? ''),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=300',
        ]);
    }

    public function destroy(Paper $paper, PaperFile $file)
    {
        if ($file->paper_id !== $paper->id) {
            abort(404);
        }

        $this->authorizeOwner($paper, 'created_by');

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