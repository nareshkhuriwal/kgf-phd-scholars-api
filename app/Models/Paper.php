<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
class Paper extends Model
{

    protected $fillable = [
        'paper_code',
        'title',
        'authors',
        'doi',
        'year',
        'category',
        'journal',
        'issn_isbn',
        'publisher',
        'place',
        'volume',
        'issue',
        'page_no',
        'area',
        'citation_type_code', // ✅ Add this

        // 'key_issue',
        // 'review_html',
        // 'solution_method_html',
        // 'related_work_html',
        // 'input_params_html',
        // 'hw_sw_html',
        // 'results_html',
        // 'advantages_html',
        // 'limitations_html',
        // 'remarks_html',
        'meta',
        'created_by',
    ];


    protected $casts = ['meta' => 'array'];

    public function files(): HasMany
    {
        return $this->hasMany(PaperFile::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ✅ Add citation relationship
    public function citation(): HasOne
    {
        return $this->hasOne(Citation::class, 'citation_key', 'paper_code');
    }

    // ✅ Add citation type relationship
    public function citationType()
    {
        return $this->belongsTo(CitationType::class, 'citation_type_code', 'code');
    }

    public function getPdfUrlAttribute(): ?string
    {
        // Legacy column: still expose a public URL if present
        if (!empty($this->pdf_path)) {
            return asset('storage/' . $this->pdf_path);
        }

        // Primary PDF file — use authenticated API download route (Azure / remote-safe).
        // Do not use Storage::disk(...)->url() here: that produced /uploads/... for local disks
        // and breaks Review / PDF.js when files live only on Data Lake.
        $file = $this->relationLoaded('files')
            ? $this->resolveLibraryPdfFromCollection($this->files)
            : $this->files()
                ->where(function ($q) {
                    $q->where('is_review_copy', false)->orWhereNull('is_review_copy');
                })
                ->orderByRaw("CASE WHEN mime='application/pdf' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();

        if (!$file || !$this->id) {
            return null;
        }

        try {
            return route('papers.files.download', [
                'paper' => $this->id,
                'file'  => $file->id,
            ], true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function comments()
    {
        return $this->hasMany(PaperComment::class);
    }

    /**
     * @param \Illuminate\Support\Collection<int, PaperFile> $files
     */
    private function resolveLibraryPdfFromCollection($files): ?PaperFile
    {
        $library = $files->filter(fn (PaperFile $f) => !($f->is_review_copy ?? false));

        return $library->firstWhere('mime', 'application/pdf') ?? $library->first();
    }
}
