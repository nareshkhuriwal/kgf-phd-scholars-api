<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'files/*', 'uploads/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['http://localhost:5173', 'https://phd.khuriwalgroup.com', 'https://scholars.khuriwalgroup.com'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*', 'Range'],
    'max_age' => 0,
    'exposed_headers' => ['Accept-Ranges','Content-Length','Content-Range'],
    'supports_credentials' => true, // if you use cookies; harmless otherwise
];
