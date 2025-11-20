<?php

// app/Models/CollectionItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionItem extends Model
{
    protected $fillable = ['collection_id','paper_id','notes_html','added_by','position'];

    public function collection() { return $this->belongsTo(Collection::class); }
    public function paper() { return $this->belongsTo(Paper::class); }
    public function adder() { return $this->belongsTo(User::class, 'added_by'); }
}
