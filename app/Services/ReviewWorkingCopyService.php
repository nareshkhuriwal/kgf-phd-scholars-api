<?php

namespace App\Services;

use App\Http\Controllers\Concerns\ResolvesPaperUploadDisk;
use App\Models\Paper;
use App\Models\PaperFile;
use App\Models\Review;
use App\Models\User;
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
                    $disk = $existing->disk ?: (string) config('filesystems.default_upload_disk', 'azure');
                    try {
                        if (Storage::disk($disk)->exists($existing->path)) {
                            return;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Review working copy existence check failed; will recreate', [
                            'review_id' => $review->id,
                            'paper_file_id' => $existing->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $existing->delete();
                }

                $review->forceFill(['review_working_copy_file_id' => null])->save();
            }

            $source = $this->resolvePrimaryLibraryPdf($paper);
            if (!$source || !$source->path) {
                Log::info('No library PDF to clone for review working copy', [
                    'review_id' => $review->id,
                    'paper_id' => $paper->id,
                ]);

                return;
            }

            $uploadDisk = (string) config('filesystems.default_upload_disk', 'azure');
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
                'original_name'  => $source->original_name,
                'mime'           => $source->mime ?: 'application/pdf',
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

    private function resolvePrimaryLibraryPdf(Paper $paper): ?PaperFile
    {
        return $paper->files()
            ->where(function ($q) {
                $q->where('is_review_copy', false)->orWhereNull('is_review_copy');
            })
            ->orderByRaw("CASE WHEN mime = 'application/pdf' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }

    private function safeFilename(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $name) ?: 'file.pdf';
        $name = ltrim($name, '.');

        return $name !== '' ? $name : 'file.pdf';
    }
}
