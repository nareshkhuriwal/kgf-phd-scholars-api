<?php
// app/Models/Review.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Review extends Model
{
    public const STATUS_DRAFT       = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE        = 'done';
    public const STATUS_ARCHIVED    = 'archived';
    
    protected $fillable = [
        'paper_id','user_id',
        'html','status','key_issue','remarks',
        'review_sections',   // NEW
    ];

    protected $casts = [
        'review_sections' => 'array',   // NEW – returns PHP array
        'updated_at'      => 'datetime',
    ];

/**
     * Get citations for this review
     * ✅ Specify the pivot table and foreign keys explicitly
     */
    public function citations(): BelongsToMany
    {
        return $this->belongsToMany(
            Citation::class,           // Related model
            'review_citations',        // Pivot table name
            'review_id',              // Foreign key on pivot table for this model
            'citation_id'             // Foreign key on pivot table for related model
        );
    }

    public function paper(): BelongsTo { return $this->belongsTo(Paper::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
