<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperComment extends Model
{
    protected $fillable = ['paper_id','user_id','parent_id','body'];

    public function paper(): BelongsTo { return $this->belongsTo(Paper::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class,'parent_id'); }
}
