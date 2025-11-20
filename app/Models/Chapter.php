<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chapter extends Model
{
    protected $fillable = ['user_id','collection_id','title','order_index','body_html'];

    public function user() { return $this->belongsTo(User::class); }
    public function collection() { return $this->belongsTo(Collection::class); }
    public function items() { return $this->hasMany(ChapterItem::class); }
}
