<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = ['name', 'type', 'created_by'];

    // ✅ NEW: reviews using this tag
    public function reviews()
    {
        return $this->belongsToMany(
            Review::class,
            'review_tags',
            'tag_id',
            'review_id'
        );
    }
}
