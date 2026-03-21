<?php

namespace App\Http\Controllers\Concerns;

use App\Models\PaperFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Paper;


trait ResolvesPublicUploads
{
    /**
     * Resolve paper_files row from an authenticated download/preview URL.
     * Accepts /api/papers/... and /papers/... (reverse-proxy stripped /api).
     */
    protected function paperFileFromDownloadUrl(string $url): ?PaperFile
    {
        $parts = parse_url($url);
        $path = urldecode($parts['path'] ?? '');
        if ($path === '') {
            return null;
        }

        if (! preg_match('#/(?:api/)?papers/(\d+)/files/(\d+)/(?:download|preview)\b#', $path, $m)) {
            return null;
        }

        $paperId = (int) $m[1];
        $fileId = (int) $m[2];

        return PaperFile::query()
            ->where('id', $fileId)
            ->where('paper_id', $paperId)
            ->first();
    }

    /**
     * Highlight save may only touch per-review blobs (is_review_copy or path under reviews/{paper_id}/r{n}/).
     */
    protected function paperFileQualifiesAsReviewWorkingCopy(?PaperFile $row, Paper $paper): bool
    {
        if (! $row || (int) $row->paper_id !== (int) $paper->id) {
            return false;
        }

        $attrs = $row->getAttributes();
        $rawFlag = $attrs['is_review_copy'] ?? null;
        if ($rawFlag === true || $rawFlag === 1 || $rawFlag === '1') {
            return true;
        }

        $prefix = rtrim((string) config('filesystems.review_working_copy_prefix', 'reviews'), '/');
        $path = (string) ($attrs['path'] ?? '');
        if ($path === '') {
            return false;
        }

        $quotedPrefix = preg_quote($prefix, '#');
        $pid = (int) $paper->id;
        if (preg_match("#^{$quotedPrefix}/{$pid}/r\\d+/#", $path)) {
            if ($rawFlag === null || $rawFlag === false || $rawFlag === 0 || $rawFlag === '0' || $rawFlag === '') {
                try {
                    $row->forceFill(['is_review_copy' => true])->save();
                } catch (\Throwable) {
                    // still allow this request if the column cannot be updated
                }
            }

            return true;
        }

        return false;
    }

    /** URL → (disk, relPath). Supports /uploads/... (both disks). */
    protected function resolveFromUrl(string $url): array
    {
        $parts = parse_url($url);
        $path  = urldecode($parts['path'] ?? '');

        $pf = $this->paperFileFromDownloadUrl($url);
        if ($pf && $pf->path) {
            $disk = $pf->disk ?: (string) config('filesystems.default_upload_disk', 'azure');

            return [$disk, $pf->path];
        }

        if (!Str::startsWith($path, '/uploads/')) {
            return [null, null];
        }

        $rel = ltrim(Str::after($path, '/uploads/'), '/'); // "library/2025/11/x.pdf"
        if (!$this->isSafeRelative($rel)) return [null, null];

        if (Storage::disk('uploads')->exists($rel)) return ['uploads', $rel];
        // if (Storage::disk('public')->exists($rel))  return ['public',  $rel];

        $active = config('filesystems.default', 'public');
        return [$active, $rel];
    }

    /** Path string → (disk, relPath). Accepts "uploads/...", "storage/...", or plain "library/...". */
protected function resolveFromPath(string $input): array
{
    $p = ltrim(trim($input), '/');

    if (Str::startsWith($p, 'uploads/')) {
        $rel = ltrim(Str::after($p, 'uploads/'), '/');
        if (!$this->isSafeRelative($rel)) return [null, null];

        if (Storage::disk('uploads')->exists($rel)) return ['uploads', $rel];
        return [config('filesystems.default', 'public'), $rel];
    }

    if (Str::startsWith($p, 'storage/')) {                   // <-- fix
        $rel = ltrim(Str::after($p, 'storage/'), '/');
        if (!$this->isSafeRelative($rel)) return [null, null];
        return ['public', $rel];
    }

    $rel = $p;
    if (!$this->isSafeRelative($rel)) return [null, null];

    if (Storage::disk('uploads')->exists($rel)) return ['uploads', $rel];
    return [config('filesystems.default', 'public'), $rel];
}


    /** Guard: clean relative path only (no traversal). */
    protected function isSafeRelative(string $rel): bool
    {
        if ($rel === '' || str_contains($rel, '..')) return false;
        return !Str::startsWith($rel, ['/','\\']) && !preg_match('/^[A-Za-z]:/', $rel);
    }



    /** Utils */

    protected function clamp01($v): ?float
    {
        if (!is_numeric($v)) return null;
        $v = (float) $v;
        if ($v < 0) $v = 0;
        if ($v > 1) $v = 1;
        return $v;
    }

    protected function clamp($v, float $min, float $max): float
    {
        $v = (float) $v;
        return max($min, min($max, $v));
    }

    protected function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            // fallback to yellow
            return [255, 235, 59];
        }
        return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    }

    protected function resolvePdfDiskAndPath(Paper $paper): array
    {
        $file = $paper->relationLoaded('files')
            ? $this->firstLibraryPdfFromCollection($paper->files)
            : $paper->files()
                ->where(function ($q) {
                    $q->where('is_review_copy', false)->orWhereNull('is_review_copy');
                })
                ->orderByRaw("CASE WHEN mime='application/pdf' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();

        if ($file && $file->path) {
            $disk = $file->disk ?: (string) config('filesystems.default_upload_disk', 'azure');

            return [$disk, $file->path];
        }

        if (!empty($paper->pdf_path)) {
            return ['public', ltrim($paper->pdf_path, '/')];
        }

        if (!empty($paper->pdf_url)) {
            return $this->resolveFromUrl($paper->pdf_url);
        }

        return [null, null];
    }

    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\PaperFile> $files
     */
    private function firstLibraryPdfFromCollection($files): ?PaperFile
    {
        $library = $files->filter(fn (PaperFile $f) => !($f->is_review_copy ?? false));

        return $library->firstWhere('mime', 'application/pdf') ?? $library->first();
    }
}
