<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_email',
        'action',
        'entity_type',
        'entity_id',
        'payload',
        'ip_address',
        'user_agent',
        'success',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'success' => 'boolean',
    ];
}
