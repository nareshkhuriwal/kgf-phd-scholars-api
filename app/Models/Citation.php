<?php
// app/Models/Citation.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Citation extends Model
{
    protected $fillable = [
        'citation_key',
        'citation_type_code',
        'title',
        'authors',
        'year',
        'journal',
        'publisher',
        'institution',
        'doi',
        'isbn',
        'issn',
        'patent_number',
        'url',
        'accessed_at',
        'created_from'
    ];

    public function type()
    {
        return $this->belongsTo(CitationType::class, 'citation_type_code', 'code');
    }
}
