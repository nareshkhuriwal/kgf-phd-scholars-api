<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChapterItem extends Model
{
    protected $fillable = ['chapter_id','paper_id','source_field','content_html','citation_style','order_index'];

    public function chapter() { return $this->belongsTo(Chapter::class); }
    public function paper() { return $this->belongsTo(Paper::class); }
}
