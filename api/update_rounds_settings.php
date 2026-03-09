<?php
require_once '../include/auth.php';
require_once '../include/errors.php';

// Buffer output
ob_start();
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    if (ob_get_length()) ob_end_clean();
    jsonError('UNAUTHORIZED', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('INVALID_INPUT', ['method' => 'Only POST allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$total = intval($input['total_rounds'] ?? 0);

if ($total < 1 || $total > 10) {
    jsonError('INVALID_INPUT', ['message' => 'عدد الجولات يجب أن يكون بين 1 و 10']);
}

$configFile = __DIR__ . '/../admin/data/rounds_config.json';
$data = ['total_rounds' => $total];

// Create directory if not exists
if (!is_dir(dirname($configFile))) {
    mkdir(dirname($configFile), 0755, true);
}

if (file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT))) {
    if (ob_get_length()) ob_end_clean();
    echo json_encode(['success' => true, 'total_rounds' => $total, 'message' => 'تم تحديث عدد الجولات بنجاح']);
} else {
    jsonError('DB_ERROR', ['message' => 'فشل في حفظ ملف الإعدادات'], 500);
}
