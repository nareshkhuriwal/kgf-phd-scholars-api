<?php
// app/Models/UserSetting.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'citation_style',
        'note_format',
        'language',
        'quick_copy_as_html',
        'include_urls',
    ];

    protected $casts = [
        'quick_copy_as_html' => 'boolean',
        'include_urls'       => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
