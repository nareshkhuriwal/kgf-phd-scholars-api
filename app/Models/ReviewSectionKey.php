<?php
// App\Models\ReviewSectionKey.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewSectionKey extends Model
{
    protected $table = 'review_section_keys';

    protected $fillable = [
        'label',
        'key',
        'order',
        'active',
    ];

    public $timestamps = false;
}
