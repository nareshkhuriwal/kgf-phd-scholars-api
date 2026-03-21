<?php

namespace App\Http\Controllers\Concerns;

use App\Models\PaperFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Paper;


trait ResolvesPublicUploads
{
    /** URL → (disk, relPath). Supports /uploads/... (both disks). */
    protected function resolveFromUrl(string $url): array
    {
        $parts = parse_url($url);
        $path  = urldecode($parts['path'] ?? '');

        // Authenticated API download URL from review / PDF viewer
        if (preg_match('#/api/papers/(\d+)/files/(\d+)/(?:download|preview)#', $path, $m)) {
            $paperId = (int) $m[1];
            $fileId  = (int) $m[2];
            $pf = PaperFile::query()
                ->where('id', $fileId)
                ->where('paper_id', $paperId)
                ->first();
            if ($pf && $pf->path) {
                $disk = $pf->disk ?: (string) config('filesystems.default_upload_disk', 'azure');

                return [$disk, $pf->path];
            }

            return [null, null];
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
