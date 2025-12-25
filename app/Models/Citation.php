<?php
// app/Models/Citation.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Citation extends Model
{
    protected $fillable = [
        'citation_key',
        'citation_type_code',
        'title',
        'authors',
        'year',
        'journal',
        'conference',      // ADD
        'volume',          // ADD
        'issue',           // ADD
        'pages',           // ADD
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

    protected $casts = [
        'accessed_at' => 'datetime',
    ];

    public function type()
    {
        return $this->belongsTo(CitationType::class, 'citation_type_code', 'code');
    }


    /**
     * Get reviews that use this citation
     * ✅ Define inverse relationship
     */
    public function reviews(): BelongsToMany
    {
        return $this->belongsToMany(
            Review::class,
            'review_citations',
            'citation_id',
            'review_id'
        );
    }
}