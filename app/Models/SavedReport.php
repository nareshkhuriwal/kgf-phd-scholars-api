<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedReport extends Model
{
    protected $table = 'saved_reports';

    protected $fillable = [
        'name','template','format','filename','filters','selections',
        'created_by','updated_by'
    ];

    protected $casts = [
        'filters'    => 'array',
        'selections' => 'array',
    ];
}
