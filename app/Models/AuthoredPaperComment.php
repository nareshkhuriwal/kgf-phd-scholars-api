<?php
// app/Models/AuthoredPaperComment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthoredPaperComment extends Model
{
    protected $fillable = [
        'authored_paper_id',
        'user_id',
        'parent_id',
        'body',
    ];

    public function paper()
    {
        return $this->belongsTo(AuthoredPaper::class, 'authored_paper_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
