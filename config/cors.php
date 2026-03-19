<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'files/*', 'uploads/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,https://localhost:5173,http://127.0.0.1:5173,https://127.0.0.1:5173,https://phd.khuriwalgroup.com,https://scholars.khuriwalgroup.com'
    ))))),
    'allowed_origins_patterns' => [
        '^https:\/\/([a-z0-9-]+\.)?khuriwalgroup\.com$',
    ],
    'allowed_headers' => ['*', 'Range'],
    'max_age' => 0,
    'exposed_headers' => ['Accept-Ranges','Content-Length','Content-Range'],
    'supports_credentials' => true, // if you use cookies; harmless otherwise
];
