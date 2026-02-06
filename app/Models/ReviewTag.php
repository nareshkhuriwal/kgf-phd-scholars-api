<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewTag extends Model
{
    protected $table = 'review_tags';

    protected $fillable = [
        'review_id',
        'tag_id',
        'tag_type',
    ];

    public $timestamps = true;
}
