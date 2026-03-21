<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaperFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'paper_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'checksum',
        'uploaded_by',
        'is_review_copy',
    ];

    protected $casts = [
        'is_review_copy' => 'boolean',
    ];

    protected $appends = [
        'preview_url',
        'download_url',
        'can_preview',
    ];

    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getCanPreviewAttribute(): bool
    {
        $mime = strtolower((string) $this->mime);
        $ext  = strtolower(pathinfo((string) $this->original_name, PATHINFO_EXTENSION));

        return $mime === 'application/pdf' || $ext === 'pdf';
    }

    public function getPreviewUrlAttribute(): ?string
    {
        if (!$this->paper_id || !$this->id || !$this->can_preview) {
            return null;
        }

        return route('papers.files.preview', [
            'paper' => $this->paper_id,
            'file'  => $this->id,
        ]);
    }

    public function getDownloadUrlAttribute(): ?string
    {
        if (!$this->paper_id || !$this->id) {
            return null;
        }

        return route('papers.files.download', [
            'paper' => $this->paper_id,
            'file'  => $this->id,
        ]);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->download_url;
    }
}