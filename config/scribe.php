<?php
// config/scribe.php

return [

    'title' => 'KGF Scholars API',
    'description' => 'API for Papers, Collections, Reviews, Chapters, and Reports',

    // Where docs are served (static files go to public/docs)
    'routes' => [
        // Include all your API routes
        [
            'match' => [
                'domains' => ['*'],
                'prefixes' => ['api/*'], // only /api routes
                'versions' => ['v1'],    // optional
            ],
            'include' => ['*'],
            'exclude' => [],
        ],
    ],

    'auth' => [
        'enabled' => true,
        'in' => 'header',
        'name' => 'Authorization',
        // What users will paste in the UI’s “Auth” box:
        'use_value' => 'Bearer {YOUR_TOKEN}',
        'placeholder' => '{YOUR_TOKEN}',
    ],

    // Optional: Let Scribe make example calls to your app to capture example responses
    'response_calls' => [
        'enabled' => true,                // great in dev; disable in prod
        'config' => [
            'app.debug' => true,
        ],
        'base_url' => env('APP_URL', 'http://localhost:8000'),
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'apply' => [
            // Limit to your namespaces to avoid hitting vendor routes
            'response_calls' => ['App\\Http\\Controllers\\*'],
        ],
    ],

];
