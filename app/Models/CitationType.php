<?php
// app/Models/CitationType.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CitationType extends Model
{
    protected $table = 'citation_types';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'label',
        'description',
        'is_active'
    ];
}
