<?php
// app/Models/ResearcherInvite.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResearcherInvite extends Model
{
    use HasFactory;

    protected $fillable = [
        'researcher_email',
        'researcher_name',
        'supervisor_name',
        'message',
        'role',
        'allowed_domain',
        'notes',
        'invite_token',
        'status',
        'expires_at',
        'sent_at',
        'accepted_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'sent_at'    => 'datetime',
        'accepted_at'=> 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOwnedBy($query, $userId)
    {
        return $query->where('created_by', $userId);
    }

    public function isRevoked(): bool
    {
        return ! is_null($this->revoked_at);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return ! is_null($this->accepted_at) || $this->status === 'accepted';
    }
}
