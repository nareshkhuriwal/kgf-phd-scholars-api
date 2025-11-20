<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaperFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'paper_id','disk','path','original_name','mime','size_bytes','checksum','uploaded_by'
    ];

    public function paper(): BelongsTo { return $this->belongsTo(Paper::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class,'uploaded_by'); }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? \Storage::disk($this->disk ?: 'public')->url($this->path) : null;
    }
}
