<?php
/**
 * Download Archive - تحميل ملف الأرشيف
 * يسمح بتحميل ملفات الأرشيف للمشرفين فقط
 */
session_start();

// Only admin/root can download
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    http_response_code(403);
    die('غير مصرح');
}

$filename = $_GET['file'] ?? '';

// Validate filename (only allow archive files)
if (!preg_match('/^(data_archive|championship)_[\w\-_]+\.json$/', $filename)) {
    http_response_code(400);
    die('ملف غير صالح');
}

$archiveDir = __DIR__ . '/data/archives/';
$filepath = $archiveDir . $filename;

// Check if file exists
if (!file_exists($filepath)) {
    http_response_code(404);
    die('الملف غير موجود');
}

// Set headers for download
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));

// Output file
readfile($filepath);
exit;
