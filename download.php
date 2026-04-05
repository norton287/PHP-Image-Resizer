<?php

date_default_timezone_set('America/Chicago');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

if (!isset($_GET['file']) || trim($_GET['file']) === '') {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

// Restrict all downloads to the zip/ directory only
$zipDir = realpath(__DIR__ . '/zip');
if ($zipDir === false) {
    http_response_code(500);
    echo 'Server configuration error.';
    exit;
}

$requestedFile = realpath(__DIR__ . '/' . ltrim(urldecode($_GET['file']), '/\\'));

if ($requestedFile === false || !str_starts_with($requestedFile, $zipDir . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (!is_file($requestedFile)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// Determine Content-Type from extension so single image files are served correctly
$ext = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
$contentTypes = [
    'zip'  => 'application/zip',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
    'gif'  => 'image/gif',
    'tiff' => 'image/tiff',
    'tif'  => 'image/tiff',
];
$contentType = $contentTypes[$ext] ?? 'application/octet-stream';

// Strip the random-hex prefix from the filename for a clean download name
$basename    = basename($requestedFile);
$cleanName   = preg_replace('/^[0-9a-f]{16}_/i', '', $basename);

header('Content-Description: File Transfer');
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $cleanName . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($requestedFile));
ob_clean();
flush();
readfile($requestedFile);
exit;
