<?php
// app/Models/Review.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'paper_id','user_id',
        'html','status','key_issue','remarks',
        'review_sections',   // NEW
    ];

    protected $casts = [
        'review_sections' => 'array',   // NEW – returns PHP array
        'updated_at'      => 'datetime',
    ];

    public function paper(): BelongsTo { return $this->belongsTo(Paper::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
