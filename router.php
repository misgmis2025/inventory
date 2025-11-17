<?php
// Simple router for PHP built-in server to serve files from web root or nested app dir
$docRoot = __DIR__;
$nested  = __DIR__ . '/inventory/inventory/inventory';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = urldecode($path);

// Normalize path
if ($path === '' || $path === false) { $path = '/'; }

$try = [
    $docRoot . $path,
    $nested . $path,
];

foreach ($try as $candidate) {
    if (is_file($candidate)) {
        // Set basic content-type headers for common static assets
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        $types = [
            'css' => 'text/css',
            'js'  => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg'=> 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff'=> 'font/woff',
            'woff2'=>'font/woff2',
            'ttf' => 'font/ttf',
            'map' => 'application/json',
        ];
        if (isset($types[$ext])) {
            header('Content-Type: ' . $types[$ext]);
        }
        readfile($candidate);
        return true;
    }
}

// If static not found, let PHP handle the request normally
return false;
