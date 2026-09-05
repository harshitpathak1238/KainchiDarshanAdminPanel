<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/admin/assets/')) {
    $file = __DIR__ . '/public/assets/' . basename($path);
    if (is_file($file)) {
        $mime = mime_content_type($file) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($file);
        return true;
    }
}

if (str_starts_with($path, '/admin/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

if ($path === '/admin' || $path === '/admin/') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    require __DIR__ . '/public/index.html';
    return true;
}

if (is_file(__DIR__ . $path)) {
    return false;
}

http_response_code(404);
echo 'Not found';
