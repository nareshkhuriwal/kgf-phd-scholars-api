<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

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

