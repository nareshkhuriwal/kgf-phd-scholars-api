<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raises PHP max_execution_time for API routes (Azure + PDF work can exceed default 30s).
 */
final class ExtendApiExecutionTime
{
    public function handle(Request $request, Closure $next): Response
    {
        $seconds = (int) config('app.api_max_execution_seconds', 0);
        if ($seconds > 0) {
            @set_time_limit($seconds);
            @ini_set('max_execution_time', (string) $seconds);
        }

        return $next($request);
    }
}
