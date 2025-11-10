<?php
// app/Models/ReviewQueue.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewQueue extends Model
{
    public $timestamps = false;
    protected $table = 'review_queue';
    protected $fillable = ['user_id','paper_id','added_at'];
    protected $casts = ['added_at' => 'datetime'];

    public function paper(): BelongsTo { return $this->belongsTo(Paper::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
