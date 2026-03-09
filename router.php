<?php
// Simple Router
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// Serve static files directly
if (preg_match('/\.(?:png|jpg|jpeg|gif|css|js|woff|ttf|ico)$/', $path)) {
    return false;
}

// Check if specific PHP file requested
if (strpos($path, '.php') !== false) {
    if (file_exists(__DIR__ . $path)) {
        return false;
    }
}

// Default to index.php
include 'index.php';
