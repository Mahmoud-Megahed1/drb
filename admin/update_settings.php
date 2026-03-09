<?php
/**
 * Update Site Settings - Simplified Version
 */

session_start();

header('Content-Type: application/json; charset=utf-8');

// Check if logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صالحة']);
    exit;
}

$settingType = isset($_POST['setting_type']) ? $_POST['setting_type'] : '';

if ($settingType !== 'banner' && $settingType !== 'frame') {
    echo json_encode(['success' => false, 'message' => 'نوع الإعداد غير صالح']);
    exit;
}

// Check file upload
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== 0) {
    echo json_encode(['success' => false, 'message' => 'لم يتم اختيار ملف']);
    exit;
}

$file = $_FILES['image'];

// Simple validation
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExt)) {
    echo json_encode(['success' => false, 'message' => 'نوع الملف غير مدعوم']);
    exit;
}

// Create upload directory
$baseDir = dirname(dirname(__FILE__));
$uploadDir = $baseDir . '/images/settings/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate filename and move
$filename = $settingType . '_' . time() . '.' . $ext;
$filepath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'message' => 'فشل في حفظ الملف']);
    exit;
}

// Update settings file
$settingsFile = dirname(__FILE__) . '/data/site_settings.json';

$settings = [];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    if (!$settings) $settings = [];
}

$relativePath = 'images/settings/' . $filename;

if ($settingType === 'banner') {
    $settings['banner_url'] = $relativePath;
} else {
    $settings['frame_url'] = $relativePath;
    
    // Also update frame_settings.json to keep synced
    $frameSettingsFile = dirname(__FILE__) . '/data/frame_settings.json';
    $frameSettings = [];
    if (file_exists($frameSettingsFile)) {
        $frameSettings = json_decode(file_get_contents($frameSettingsFile), true);
        if (!$frameSettings) $frameSettings = [];
    }
    $frameSettings['frame_image'] = $relativePath;
    file_put_contents($frameSettingsFile, json_encode($frameSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$settings['updated_at'] = date('Y-m-d H:i:s');

if (file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo json_encode(['success' => true, 'message' => 'تم التحديث بنجاح', 'path' => $relativePath]);
} else {
    echo json_encode(['success' => false, 'message' => 'فشل في حفظ الإعدادات']);
}
