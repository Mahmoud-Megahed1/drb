<?php
/**
 * Image Thumbnail Proxy
 * Resizes images on-the-fly and caches result
 * Usage: thumb.php?src=uploads/photo.jpg&w=100&h=100
 */

// Cache dir
$cacheDir = __DIR__ . '/cache/thumbs';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$src = $_GET['src'] ?? '';
$maxW = min((int)($_GET['w'] ?? 100), 400); // Max 400px
$maxH = min((int)($_GET['h'] ?? 100), 400);

if (empty($src)) {
    http_response_code(400);
    exit;
}

// Security: only allow local paths
$src = str_replace(['..', "\0"], '', $src);
$srcPath = __DIR__ . '/' . ltrim($src, '/');

// Also try with admin/ prefix
if (!file_exists($srcPath)) {
    $srcPath = __DIR__ . '/admin/' . ltrim($src, '/');
}
if (!file_exists($srcPath)) {
    // Try the raw src
    $srcPath = $src;
}
if (!file_exists($srcPath)) {
    http_response_code(404);
    exit;
}

// Cache key = hash of path + dimensions
$cacheKey = md5($srcPath . $maxW . $maxH . filemtime($srcPath));
$cachePath = $cacheDir . '/' . $cacheKey . '.jpg';

// Serve from cache if available
if (file_exists($cachePath)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=604800'); // 7 days
    header('X-Thumb-Cache: HIT');
    readfile($cachePath);
    exit;
}

// Generate thumbnail
$imgInfo = @getimagesize($srcPath);
if (!$imgInfo) {
    http_response_code(400);
    exit;
}

$origW = $imgInfo[0];
$origH = $imgInfo[1];
$type = $imgInfo[2];

// Load source image
switch ($type) {
    case IMAGETYPE_JPEG: $srcImg = @imagecreatefromjpeg($srcPath); break;
    case IMAGETYPE_PNG:  $srcImg = @imagecreatefrompng($srcPath); break;
    case IMAGETYPE_GIF:  $srcImg = @imagecreatefromgif($srcPath); break;
    case IMAGETYPE_WEBP: $srcImg = @imagecreatefromwebp($srcPath); break;
    default:
        http_response_code(400);
        exit;
}

if (!$srcImg) {
    http_response_code(500);
    exit;
}

// Calculate new dimensions maintaining aspect ratio
$ratio = min($maxW / $origW, $maxH / $origH);
if ($ratio >= 1) {
    // Image is already smaller than requested, serve original
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=604800');
    imagejpeg($srcImg, $cachePath, 85);
    readfile($cachePath);
    imagedestroy($srcImg);
    exit;
}

$newW = (int)($origW * $ratio);
$newH = (int)($origH * $ratio);

// Create thumbnail
$thumb = imagecreatetruecolor($newW, $newH);
imagecopyresampled($thumb, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

// Save to cache and serve
imagejpeg($thumb, $cachePath, 85);
imagedestroy($srcImg);
imagedestroy($thumb);

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800'); // 7 days browser cache
header('X-Thumb-Cache: MISS');
readfile($cachePath);
