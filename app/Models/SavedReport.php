<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedReport extends Model
{
    protected $table = 'saved_reports';

    protected $fillable = [
        'name',
        'template',
        'presentation_theme', 
        'format',
        'filename',
        'filters',
        'selections',
        'headerFooter',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'filters'      => 'array',
        'selections'   => 'array',
        'headerFooter' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}