<?php
/**
 * Save WhatsApp Messages
 * حفظ رسائل الواتساب
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

$registrationMessage = isset($_POST['registration_message']) ? $_POST['registration_message'] : '';
$acceptanceMessage = isset($_POST['acceptance_message']) ? $_POST['acceptance_message'] : '';

if (empty($registrationMessage) || empty($acceptanceMessage)) {
    echo json_encode(['success' => false, 'message' => 'الرسائل مطلوبة']);
    exit;
}

$messages = [
    'registration_message' => $registrationMessage,
    'acceptance_message' => $acceptanceMessage,
    'updated_at' => date('Y-m-d H:i:s')
];

$filePath = dirname(__FILE__) . '/data/whatsapp_messages.json';

if (file_put_contents($filePath, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo json_encode(['success' => true, 'message' => 'تم حفظ الرسائل بنجاح']);
} else {
    echo json_encode(['success' => false, 'message' => 'فشل في حفظ الرسائل']);
}
