<?php

namespace App\Services;

use App\Http\Controllers\Concerns\ResolvesPaperUploadDisk;
use App\Models\Paper;
use App\Models\PaperFile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReviewWorkingCopyService
{
    use ResolvesPaperUploadDisk;

    /**
     * Ensure this review has a dedicated PDF copy under
     * {review_working_copy_prefix}/{paper_id}/r{review_id}/... (default: review/... under scholars).
     * Library / paper view continues to use primary (non-review) files only.
     */
    public function ensure(Review $review, Paper $paper, User $user): void
    {
        DB::transaction(function () use ($review, $paper, $user) {
            $review->refresh();

            if ($review->review_working_copy_file_id) {
                $existing = PaperFile::query()->find($review->review_working_copy_file_id);
                if ($existing) {
                    $presence = $this->verifyReviewWorkingCopyBlobPresent($existing, $paper, $review);
                    if ($presence === true) {
                        return;
                    }
                    if ($presence === null) {
                        Log::warning('Review working copy presence uncertain; keeping DB row (no delete)', [
                            'review_id' => $review->id,
                            'paper_file_id' => $existing->id,
                            'path' => $existing->path,
                        ]);

                        return;
                    }

                    // Blob may exist (e.g. Azure) while exists() was false — reuse folder PDF instead of cloning again.
                    if ($this->tryRepointWorkingCopyToExistingBlob($existing, $review, $paper, $user)) {
                        return;
                    }

                    $existing->delete();
                }

                $review->forceFill(['review_working_copy_file_id' => null])->save();
            }

            $review->refresh();
            if (! $review->review_working_copy_file_id
                && $this->tryReattachOrphanReviewCopyFromAzure($review, $paper, $user)) {
                return;
            }

            $source = $this->resolveLibrarySourceFile($paper);
            $uploadDisk = (string) config('filesystems.default_upload_disk', 'azure');
            $bytes = null;
            $safeName = 'document.pdf';
            $sourceMime = 'application/pdf';

            if ($source && $source->path) {
                $srcDisk = $source->disk ?: $uploadDisk;
                $bytes = $this->readSourcePdfBytes($srcDisk, (string) $source->path);
                if (! is_string($bytes) || $bytes === '') {
                    Log::error('Review working copy: could not read source PDF from any disk', [
                        'review_id' => $review->id,
                        'paper_id' => $paper->id,
                        'paper_file_id' => $source->id,
                        'tried_disks' => $this->sourcePdfReadDisks($srcDisk),
                        'path' => $source->path,
                    ]);
                    throw new \RuntimeException('Could not read source PDF for review working copy.');
                }
                $safeName = $this->safeFilename($source->original_name ?: 'document.pdf');
                $sourceMime = $source->mime ?: 'application/pdf';
            } else {
                $legacy = $this->readLegacyPaperPdfWithLocation($paper)
                    ?? $this->tryReadLibraryPdfFromPaperMeta($paper);
                if ($legacy === null) {
                    Log::info('No library PDF to clone for review working copy', [
                        'review_id' => $review->id,
                        'paper_id' => $paper->id,
                    ]);

                    return;
                }
                [$bytes, $safeName, $sourceMime, $legacyDisk, $legacyPath] = $legacy;
                $source = $this->ensureLibraryPaperFileRow(
                    $paper,
                    $user,
                    $bytes,
                    $legacyDisk,
                    $legacyPath,
                    $safeName,
                    $sourceMime
                );
            }

            if (! is_string($bytes) || $bytes === '') {
                Log::info('No library PDF to clone for review working copy', [
                    'review_id' => $review->id,
                    'paper_id' => $paper->id,
                ]);

                return;
            }

            // Last chance: PDFs already in reviews/{paper}/r{id}/ (orphan blobs) — never upload another UUID copy.
            if ($this->tryReattachOrphanReviewCopyFromAzure($review, $paper, $user)) {
                return;
            }

            $safeName = $this->safeFilename($safeName);
            $copyKey = Str::uuid()->toString();
            $prefix = rtrim((string) config('filesystems.review_working_copy_prefix', 'reviews'), '/');
            $relPath = sprintf(
                '%s/%d/r%d/%s_%s',
                $prefix,
                $paper->id,
                $review->id,
                $copyKey,
                $safeName
            );

            Storage::disk($uploadDisk)->put($relPath, $bytes);

            // Must be unique per created copy because paper_files has unique(paper_id, checksum),
            // and soft-deleted rows can still reserve the previous checksum value.
            $copyChecksum = hash('sha256', $bytes."\0review-working-copy:{$review->id}:{$copyKey}");

            $copy = PaperFile::query()->create([
                'paper_id'       => $paper->id,
                'disk'           => $uploadDisk,
                'path'           => $relPath,
                'original_name'  => ($source && $source->original_name)
                    ? $source->original_name
                    : $safeName,
                'mime'           => $sourceMime,
                'size_bytes'     => strlen($bytes),
                'checksum'       => $copyChecksum,
                'uploaded_by'    => $user->id,
                'is_review_copy' => true,
            ]);

            $review->forceFill(['review_working_copy_file_id' => $copy->id])->save();

            Log::info('Review working PDF copy created', [
                'review_id' => $review->id,
                'paper_id' => $paper->id,
                'paper_file_id' => $copy->id,
                'path' => $relPath,
            ]);
        }, 3);
    }

    /**
     * Confirm the review working-copy blob is reachable. Only considers the upload / Azure disks
     * where review copies are stored (container path like reviews/{paper_id}/r{review_id}/...),
     * not local public/uploads.
     *
     * @return bool|null true = found, false = not found on any tried disk, null = errors while checking (caller must not delete the row)
     */
    private function verifyReviewWorkingCopyBlobPresent(PaperFile $file, Paper $paper, Review $review): ?bool
    {
        $path = (string) $file->path;
        if ($path === '') {
            return false;
        }

        $uploadDisk = (string) config('filesystems.default_upload_disk', 'azure');
        $disks = $this->disksForReviewWorkingCopyLookup($file, $uploadDisk);

        $hadThrowable = false;
        foreach ($disks as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return true;
                }
                // Some Azure / Flysystem setups report exists=false while the blob is still readable.
                $probe = Storage::disk($disk)->get($path);
                if (is_string($probe) && $probe !== '') {
                    Log::info('Review working copy reachable via get() though exists() was false', [
                        'disk' => $disk,
                        'path' => $path,
                        'paper_id' => $paper->id,
                        'review_id' => $review->id,
                    ]);

                    return true;
                }
            } catch (\Throwable $e) {
                $hadThrowable = true;
                Log::warning('Review working copy exists() check failed', [
                    'disk' => $disk,
                    'path' => $path,
                    'paper_id' => $paper->id,
                    'review_id' => $review->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $hadThrowable ? null : false;
    }

    /**
     * List PDF blobs under reviews/{paper_id}/r{review_id}/ on upload disks.
     *
     * @return array{disk: string|null, paths: list<string>}
     */
    private function discoverReviewFolderPdfs(Paper $paper, Review $review): array
    {
        $uploadDisk = (string) config('filesystems.default_upload_disk', 'azure');
        $prefix = rtrim((string) config('filesystems.review_working_copy_prefix', 'reviews'), '/');
        $dir = sprintf('%s/%d/r%d', $prefix, $paper->id, $review->id);

        foreach ($this->disksForReviewWorkingCopyLookup(
            new PaperFile(['disk' => $uploadDisk]),
            $uploadDisk
        ) as $tryDisk) {
            try {
                $flat = Storage::disk($tryDisk)->files($dir);
                $deep = Storage::disk($tryDisk)->allFiles($dir);
                $merged = array_values(array_unique(array_merge(
                    is_array($flat) ? $flat : [],
                    is_array($deep) ? $deep : []
                )));
                $pdfs = [];
                foreach ($merged as $p) {
                    $p = (string) $p;
                    if ($p !== '' && str_ends_with(strtolower($p), '.pdf')) {
                        $pdfs[] = $p;
                    }
                }
                if ($pdfs !== []) {
                    rsort($pdfs, SORT_STRING);

                    return ['disk' => $tryDisk, 'paths' => $pdfs];
                }
            } catch (\Throwable $e) {
                Log::warning('Review working copy: list review folder failed', [
                    'disk' => $tryDisk,
                    'dir' => $dir,
                    'paper_id' => $paper->id,
                    'review_id' => $review->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['disk' => null, 'paths' => []];
    }

    /**
     * Keep the same paper_files row id but point it at an existing PDF in the review folder
     * (avoids piling up duplicate UUID blobs when storage checks flake).
     */
    private function tryRepointWorkingCopyToExistingBlob(
        PaperFile $existing,
        Review $review,
        Paper $paper,
        User $user
    ): bool {
        $found = $this->discoverReviewFolderPdfs($paper, $review);
        if ($found['paths'] === [] || $found['disk'] === null) {
            return false;
        }

        $disk = $found['disk'];
        $pick = $found['paths'][0];

        try {
            $bytes = Storage::disk($disk)->get($pick);
        } catch (\Throwable $e) {
            Log::warning('Repoint working copy: read picked blob failed', [
                'path' => $pick,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! is_string($bytes) || $bytes === '') {
            return false;
        }

        $safeName = $this->safeFilename(basename($pick) ?: 'document.pdf');
        $checksum = hash('sha256', $bytes."\0review-working-copy-repoint:{$review->id}:pf{$existing->id}");

        $existing->forceFill([
            'disk'           => $disk,
            'path'           => $pick,
            'original_name'  => $safeName,
            'mime'           => 'application/pdf',
            'size_bytes'     => strlen($bytes),
            'checksum'       => $checksum,
            'is_review_copy' => true,
            'uploaded_by'    => $user->id,
        ])->save();

        Log::info('Repointed review working copy row to existing blob (no new upload)', [
            'review_id' => $review->id,
            'paper_file_id' => $existing->id,
            'path' => $pick,
        ]);

        return true;
    }

    /**
     * Disks to probe for a review working copy (Azure scholars/reviews/...), in order.
     *
     * @return list<string>
     */
    private function disksForReviewWorkingCopyLookup(PaperFile $file, string $uploadDisk): array
    {
        $disks = [];
        $rowDisk = $file->disk ? (string) $file->disk : '';
        if ($rowDisk !== '') {
            $disks[] = $rowDisk;
        }
        if ($uploadDisk !== '' && ! in_array($uploadDisk, $disks, true)) {
            $disks[] = $uploadDisk;
        }
        if ($uploadDisk === 'azure' && ($legacy = $this->azureLegacyDisk()) && ! in_array($legacy, $disks, true)) {
            $disks[] = $legacy;
        }

        return array_values(array_filter(array_unique($disks)));
    }

    /**
     * @return list<string>
     */
    private function sourcePdfReadDisks(string $primaryDisk): array
    {
        $disks = [$primaryDisk];
        if ($primaryDisk === 'azure' && ($legacy = $this->azureLegacyDisk())) {
            $disks[] = $legacy;
        }

        return array_values(array_unique(array_filter($disks)));
    }

    private function readSourcePdfBytes(string $primaryDisk, string $path): ?string
    {
        foreach ($this->sourcePdfReadDisks($primaryDisk) as $disk) {
            try {
                $bytes = Storage::disk($disk)->get($path);
                if (is_string($bytes) && $bytes !== '') {
                    if ($disk !== $primaryDisk) {
                        Log::info('Review working copy: read source PDF from legacy Azure container', [
                            'disk' => $disk,
                            'path' => $path,
                        ]);
                    }

                    return $bytes;
                }
            } catch (\Throwable $e) {
                Log::warning('Review working copy: source PDF read failed', [
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Optional: `papers.meta` may declare a blob path when no paper_files row exists yet.
     * Keys: library_file_path, primary_pdf_path, azure_library_path (relative path in upload container).
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null
     */
    private function tryReadLibraryPdfFromPaperMeta(Paper $paper): ?array
    {
        $meta = $paper->meta;
        if (! is_array($meta)) {
            return null;
        }

        $rel = $meta['library_file_path'] ?? $meta['primary_pdf_path'] ?? $meta['azure_library_path'] ?? null;
        if (! is_string($rel) || trim($rel) === '') {
            return null;
        }

        $rel = ltrim($rel, '/');
        $uploadDisk = (string) config('filesystems.default_upload_disk', 'azure');

        foreach ($this->sourcePdfReadDisks($uploadDisk) as $diskName) {
            try {
                if (! Storage::disk($diskName)->exists($rel)) {
                    continue;
                }
                $bytes = Storage::disk($diskName)->get($rel);
                if (! is_string($bytes) || $bytes === '') {
                    continue;
                }
                $name = basename($rel) ?: 'document.pdf';
                Log::info('Review working copy: using meta-declared library blob path', [
                    'paper_id' => $paper->id,
                    'disk' => $diskName,
                    'path' => $rel,
                ]);

                return [$bytes, $name, 'application/pdf', $diskName, $rel];
            } catch (\Throwable $e) {
                Log::warning('Review working copy: meta library path read failed', [
                    'paper_id' => $paper->id,
                    'disk' => $diskName,
                    'path' => $rel,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Legacy papers may use `papers.pdf_path` (public / uploads disk) without a `paper_files` row.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null bytes, displayName, mime, disk, relativePath
     */
    private function readLegacyPaperPdfWithLocation(Paper $paper): ?array
    {
        $rel = ltrim((string) data_get($paper->getAttributes(), 'pdf_path', ''), '/');
        if ($rel === '') {
            return null;
        }

        foreach (['public', 'uploads'] as $diskName) {
            try {
                if (! Storage::disk($diskName)->exists($rel)) {
                    continue;
                }
                $bytes = Storage::disk($diskName)->get($rel);
                if (! is_string($bytes) || $bytes === '') {
                    continue;
                }
                $name = basename($rel) ?: 'document.pdf';

                return [$bytes, $name, 'application/pdf', $diskName, $rel];
            } catch (\Throwable $e) {
                Log::warning('Review working copy: legacy pdf_path read failed', [
                    'paper_id' => $paper->id,
                    'disk' => $diskName,
                    'path' => $rel,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Register a library (non-review) paper_files row when the PDF exists in storage but
     * was never recorded (e.g. legacy pdf_path only). Reuses an existing row for the same disk+path.
     */
    private function ensureLibraryPaperFileRow(
        Paper $paper,
        User $user,
        string $bytes,
        string $disk,
        string $relPath,
        string $originalName,
        string $mime
    ): PaperFile {
        $existing = PaperFile::withTrashed()
            ->where('paper_id', $paper->id)
            ->where('disk', $disk)
            ->where('path', $relPath)
            ->where(function ($q) {
                $q->where('is_review_copy', false)->orWhereNull('is_review_copy');
            })
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $originalName = $this->safeFilename($originalName);
        $size = strlen($bytes);
        $checksum = hash('sha256', $bytes);

        try {
            return PaperFile::query()->create([
                'paper_id'       => $paper->id,
                'disk'           => $disk,
                'path'           => $relPath,
                'original_name'  => $originalName,
                'mime'           => $mime ?: 'application/pdf',
                'size_bytes'     => $size,
                'checksum'       => $checksum,
                'uploaded_by'    => $user->id,
                'is_review_copy' => false,
            ]);
        } catch (QueryException $e) {
            // unique(paper_id, checksum) can collide (e.g. soft-deleted row still reserving checksum)
            if (! $this->looksLikeDuplicateKeyError($e)) {
                throw $e;
            }

            $salted = hash('sha256', $bytes."\0library-ingest:{$paper->id}:{$disk}:{$relPath}");

            return PaperFile::query()->create([
                'paper_id'       => $paper->id,
                'disk'           => $disk,
                'path'           => $relPath,
                'original_name'  => $originalName,
                'mime'           => $mime ?: 'application/pdf',
                'size_bytes'     => $size,
                'checksum'       => $salted,
                'uploaded_by'    => $user->id,
                'is_review_copy' => false,
            ]);
        }
    }

    private function looksLikeDuplicateKeyError(QueryException $e): bool
    {
        if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'duplicate');
    }

    /**
     * Blob already exists at reviews/{paper_id}/r{review_id}/... (e.g. Azure) but paper_files
     * row was deleted or never written — recreate the row and link reviews.review_working_copy_file_id.
     */
    private function tryReattachOrphanReviewCopyFromAzure(Review $review, Paper $paper, User $user): bool
    {
        $discovered = $this->discoverReviewFolderPdfs($paper, $review);
        $paths = $discovered['paths'];
        $listDisk = $discovered['disk'];

        if ($paths === [] || $listDisk === null) {
            return false;
        }

        $pick = $paths[0];

        try {
            $bytes = Storage::disk($listDisk)->get($pick);
        } catch (\Throwable $e) {
            Log::warning('Orphan review copy: read failed', [
                'path' => $pick,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! is_string($bytes) || $bytes === '') {
            return false;
        }

        $dup = PaperFile::withTrashed()
            ->where('paper_id', $paper->id)
            ->where('disk', $listDisk)
            ->where('path', $pick)
            ->first();

        if ($dup) {
            if ($dup->trashed()) {
                $dup->restore();
            }
            $dup->forceFill([
                'is_review_copy' => true,
                'mime' => $dup->mime ?: 'application/pdf',
                'size_bytes' => $dup->size_bytes ?: strlen($bytes),
            ])->save();
            $review->forceFill(['review_working_copy_file_id' => $dup->id])->save();

            Log::info('Re-linked existing paper_files row to review working copy path', [
                'review_id' => $review->id,
                'paper_file_id' => $dup->id,
                'path' => $pick,
            ]);

            return true;
        }

        $safeName = $this->safeFilename(basename($pick) ?: 'document.pdf');
        $reattachKey = Str::uuid()->toString();
        $checksum = hash('sha256', $bytes."\0review-working-copy-reattach:{$review->id}:{$reattachKey}");

        $copy = PaperFile::query()->create([
            'paper_id'       => $paper->id,
            'disk'           => $listDisk,
            'path'           => $pick,
            'original_name'  => $safeName,
            'mime'           => 'application/pdf',
            'size_bytes'     => strlen($bytes),
            'checksum'       => $checksum,
            'uploaded_by'    => $user->id,
            'is_review_copy' => true,
        ]);

        $review->forceFill(['review_working_copy_file_id' => $copy->id])->save();

        Log::info('Created paper_files row for orphan review working copy blob', [
            'review_id' => $review->id,
            'paper_file_id' => $copy->id,
            'path' => $pick,
        ]);

        return true;
    }

    /**
     * Prefer real library blobs under library_upload_prefix; include soft-deleted rows; ignore
     * files under this paper's reviews/{paper_id}/... working-copy area. Fixes rows wrongly
     * flagged is_review_copy=true while living under library/.
     */
    private function resolveLibrarySourceFile(Paper $paper): ?PaperFile
    {
        $libraryPrefix = rtrim((string) config('filesystems.library_upload_prefix', 'library'), '/');
        $reviewArea = $this->reviewWorkingCopyAreaPrefix($paper);

        $row = PaperFile::withTrashed()
            ->where('paper_id', $paper->id)
            ->where('path', 'like', $libraryPrefix.'/%')
            ->orderByRaw("CASE WHEN mime = 'application/pdf' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if (! $row) {
            $row = PaperFile::withTrashed()
                ->where('paper_id', $paper->id)
                ->where('path', 'not like', $reviewArea.'%')
                ->where(function ($q) {
                    $q->where('is_review_copy', false)->orWhereNull('is_review_copy');
                })
                ->orderByRaw("CASE WHEN mime = 'application/pdf' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();
        }

        if (! $row) {
            $row = PaperFile::withTrashed()
                ->where('paper_id', $paper->id)
                ->where('path', 'not like', $reviewArea.'%')
                ->orderByRaw("CASE WHEN mime = 'application/pdf' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();
        }

        if (! $row) {
            return null;
        }

        return $this->normalizeLibrarySourceRow($row, $paper);
    }

    private function reviewWorkingCopyAreaPrefix(Paper $paper): string
    {
        $reviewPrefix = rtrim((string) config('filesystems.review_working_copy_prefix', 'reviews'), '/');

        return sprintf('%s/%d/', $reviewPrefix, $paper->id);
    }

    private function normalizeLibrarySourceRow(PaperFile $row, Paper $paper): PaperFile
    {
        if ($row->trashed()) {
            $row->restore();
        }

        $path = (string) $row->path;
        if (! str_starts_with($path, $this->reviewWorkingCopyAreaPrefix($paper)) && $row->is_review_copy) {
            $row->forceFill(['is_review_copy' => false])->save();
        }

        return $row->fresh() ?? $row;
    }

    private function safeFilename(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $name) ?: 'file.pdf';
        $name = ltrim($name, '.');

        return $name !== '' ? $name : 'file.pdf';
    }
}
