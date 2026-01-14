<?php

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// If file exists in public, serve it directly
$publicPath = __DIR__ . '/public' . $uri;
if ($uri !== '/' && file_exists($publicPath)) {
    return false;
}

// Otherwise forward request to Laravel
require_once __DIR__ . '/public/index.php';
