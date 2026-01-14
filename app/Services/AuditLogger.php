<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public static function log(
        Request $request,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $payload = [],
        bool $success = true,
        ?string $errorMessage = null
    ): void {
        AuditLog::create([
            'user_id'     => Auth::id(),
            'user_email' => optional(Auth::user())->email,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'payload'     => $payload,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'success'     => $success,
            'error_message' => $errorMessage,
        ]);
    }
}
