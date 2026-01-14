<?php
// app/Models/AuthoredPaperSection.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AuthoredPaperSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'authored_paper_id',
        'section_key',
        'section_title',
        'body_html',
        'position',
    ];

    public function paper()
    {
        return $this->belongsTo(AuthoredPaper::class, 'authored_paper_id');
    }
}
