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

$acceptanceMessage = isset($_POST['acceptance_message']) ? $_POST['acceptance_message'] : '';

if (empty($acceptanceMessage)) {
    echo json_encode(['success' => false, 'message' => 'نص الرسالة الموحدة مطلوب']);
    exit;
}

$existing = [];
$filePath = dirname(__FILE__) . '/data/whatsapp_messages.json';
if (file_exists($filePath)) {
    $existing = json_decode(file_get_contents($filePath), true) ?? [];
}

$registrationMessage = $existing['registration_message'] ?? '(معطلة) تم إيقاف رسالة التسجيل الترحيبية لمنع تكرار الرسائل';
$badgeCaption = $existing['badge_caption'] ?? "🎫 باج دخول الحلبة\n\n📱 امسح QR عند الدخول\n\n🔑 كود التسجيل: {registration_code}";
$rejectionMessage = $existing['rejection_message'] ?? '';
$activationMessage = $existing['activation_message'] ?? '';

$messages = [
    'registration_message' => $registrationMessage,
    'acceptance_message' => $acceptanceMessage,
    'badge_caption' => $badgeCaption,
    'rejection_message' => $rejectionMessage,
    'activation_message' => $activationMessage,
    'updated_at' => date('Y-m-d H:i:s')
];

if (file_put_contents($filePath, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo json_encode(['success' => true, 'message' => 'تم حفظ الرسائل بنجاح']);
} else {
    echo json_encode(['success' => false, 'message' => 'فشل في حفظ الرسائل']);
}
