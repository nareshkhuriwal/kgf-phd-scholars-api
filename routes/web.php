<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

// Fallback for frameworks/middleware that try redirecting to "login"
// (especially when Accept header isn't application/json, e.g. <embed> PDF requests).
Route::get('/login', function () {
    return response()->json([
        'message' => 'Unauthenticated.',
    ], 401);
})->name('login');

Route::get('/uploads/{path}', function ($path) {
    Log::info('[UPLOAD ROUTE HIT]', [
        'raw_path' => $path,
        'resolved_path' => storage_path('app/public/uploads/' . $path),
        'exists' => file_exists(storage_path('app/public/uploads/' . $path)),
        'time' => now()->toDateTimeString(),
    ]);

    $fullPath = storage_path('app/public/uploads/' . $path);

    abort_unless(file_exists($fullPath), 404);

    return response()->file($fullPath);
})->where('path', '.*');

