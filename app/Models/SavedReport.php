<?php
// app/Models/SavedReport.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedReport extends Model
{
    protected $fillable = [
        'name','template','format','filename','filters','selections','created_by','updated_by'
    ];

    protected $casts = [
        'filters'    => 'array',   // ensures arrays are JSON-encoded/decoded
        'selections' => 'array',
    ];
}
