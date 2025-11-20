<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Paper extends Model
{
    protected $fillable = [
        'paper_code','title','authors','doi','year','category','journal','issn_isbn','publisher','place',
        'volume','issue','page_no','area','key_issue',
        'review_html','solution_method_html','related_work_html','input_params_html','hw_sw_html',
        'results_html','advantages_html','limitations_html','remarks_html','meta','created_by'
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
        return $this->belongsTo(User::class,'created_by');
    }



public function getPdfUrlAttribute(): ?string
{
    // Prefer an explicit pdf_path if you still use it anywhere
    if (!empty($this->pdf_path)) {
        return asset('storage/'.$this->pdf_path);
    }

    // Pick the first PDF if present, otherwise the first file
    $file = $this->relationLoaded('files')
        ? ($this->files->firstWhere('mime', 'application/pdf') ?? $this->files->first())
        : ($this->files()->orderByRaw("CASE WHEN mime='application/pdf' THEN 0 ELSE 1 END")
                         ->orderBy('id')
                         ->first());

    if (!$file) return null;

    try {
        return Storage::disk($file->disk)->url($file->path);
    } catch (\Throwable $e) {
        // If disk url fails (bad disk or no symlink), return null gracefully
        return null;
    }
}

public function comments() { return $this->hasMany(PaperComment::class); }


}
