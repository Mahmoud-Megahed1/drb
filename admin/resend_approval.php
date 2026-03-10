<?php
/**
 * Resend Approval Messages - إعادة إرسال رسائل القبول
 * يرسل صورة القبول وباج الدخول للمسجلين المقبولين
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

$wasel = $_POST['wasel'] ?? $_GET['wasel'] ?? '';

if (empty($wasel)) {
    echo json_encode(['success' => false, 'error' => 'رقم التسجيل مطلوب']);
    exit;
}

// Load registration data
$dataFile = 'data/data.json';
if (!file_exists($dataFile)) {
    echo json_encode(['success' => false, 'error' => 'ملف البيانات غير موجود']);
    exit;
}

$registrations = json_decode(file_get_contents($dataFile), true);
$registration = null;
$regIndex = -1;

foreach ($registrations as $idx => $reg) {
    if ($reg['wasel'] == $wasel) {
        $registration = $reg;
        $regIndex = $idx;
        break;
    }
}

if (!$registration) {
    echo json_encode(['success' => false, 'error' => 'التسجيل غير موجود']);
    exit;
}

if (($registration['status'] ?? '') !== 'approved') {
    echo json_encode(['success' => false, 'error' => 'التسجيل غير مقبول بعد']);
    exit;
}

// Include WaSender
require_once '../wasender.php';
$wasender = new WaSender();

$results = ['acceptance' => null, 'badge' => null];

try {
    // Get protocol and host
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host;
    
    // --- USE EXISTING TOKEN - DON'T GENERATE NEW ONE ---
    $badgeToken = $registration['badge_token'] ?? $registration['session_badge_token'] ?? null;
    
    // Only generate if absolutely missing
    $needsSave = false;
    if (empty($badgeToken)) {
        $badgeToken = bin2hex(random_bytes(16));
        $registrations[$regIndex]['badge_token'] = $badgeToken;
        $registrations[$regIndex]['session_badge_token'] = $badgeToken;
        $needsSave = true;
    }
    
    // Badge ID for QR (use existing)
    $badgeId = $registration['badge_id'] ?? $badgeToken;
    if (empty($registration['badge_id'])) {
        $registrations[$regIndex]['badge_id'] = $badgeId;
        $needsSave = true;
    }

    if ($needsSave) {
        $saveResult = file_put_contents($dataFile, json_encode($registrations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($saveResult === false) {
             error_log("CRITICAL: Failed to write data.json in resend_approval.php for ID $wasel");
             echo json_encode(['success' => false, 'error' => 'فشل في حفظ توكن البادج (System Write Error)']);
             exit;
        }
    }
    
    // Load message templates from settings
    $messagesFile = __DIR__ . '/data/whatsapp_messages.json';
    $messageTemplates = [
        'acceptance_message' => "🎉 *مبروك! تم قبول طلبك!*\n━━━━━━━━━━━━━━━\n\n👤 *الاسم:* {name}\n🔢 *رقم التسجيل:* #{wasel}\n🚗 *السيارة:* {car_type}\n\n✅ احتفظ بهذه الصورة وأظهرها عند دخول الحلبة\n\n🏆 نراك في الحلبة!\n━━━━━━━━━━━━━━━",
        'badge_caption' => "🎫 باج دخول الحلبة\n\n👤 الاسم: {name}\n🔑 رقم القبول: #{wasel}\n\n📱 امسح QR Code عند الدخول\n\n⚠️ يجب إبراز هذا الباج عند الدخول"
    ];
    
    if (file_exists($messagesFile)) {
        $savedMessages = json_decode(file_get_contents($messagesFile), true);
        if ($savedMessages) {
            $messageTemplates = array_merge($messageTemplates, $savedMessages);
        }
    }
    
    $countryCode = $registration['country_code'] ?? '+964';
    
    // --- 1. DETERMINE ACCEPTANCE IMAGE URL ---
            // DIRECT MATCH WITH test_acceptance_logic.php
            // Fixed Protocol & Host logic
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $path = dirname($_SERVER['PHP_SELF']); 
            // Fix path if it points to admin
            $path = str_replace('/admin', '', $path);
            $path = rtrim($path, '/');
            
            $baseUrl = "$protocol://$host$path";
            
            // 1. Acceptance Image (Dynamic or Edited) - IGNORED FOR SENDING, USED FOR OG ONLY
            // Default to dynamic generator
            $acceptanceImageUrl = $baseUrl . "/generate_acceptance.php?wasel=" . $registration['wasel'] . "&t=" . time();

            // If user manually uploaded an edited image, use that instead
            if (!empty($registration['edited_image']) && file_exists(__DIR__ . '/../' . $registration['edited_image'])) {
                 $acceptanceImageUrl = $baseUrl . '/' . $registration['edited_image'];
            }
            
            // 2. Acceptance Page Link
            $acceptanceLink = $baseUrl . "/acceptance.php?token=" . $badgeToken;
            
            $acceptCaption = $messageTemplates['acceptance_message'];
            $acceptCaption = str_replace('{name}', $registration['full_name'] ?? 'مشترك', $acceptCaption);
            $acceptCaption = str_replace('{wasel}', $registration['wasel'], $acceptCaption);
            $acceptCaption = str_replace('{car_type}', $registration['car_type'] ?? '', $acceptCaption);
            $acceptCaption = str_replace('{plate}', $registration['plate_full'] ?? '', $acceptCaption);
            $acceptCaption = str_replace('{registration_code}', $registration['registration_code'] ?? '', $acceptCaption);
            
            $acceptCaption .= "\n\n🌐 *رابط بطاقة القبول:* \n" . $acceptanceLink;
    
    // Extract name safely
    $personName = $registration['full_name'] ?? $registration['name'] ?? 'مشترك';
    
    // Send TEXT MESSAGE ONLY (No Image) to prevent encoding issues
    $results['acceptance'] = $wasender->sendMessage($registration['phone'], $acceptCaption, $countryCode, [
        'type' => 'acceptance',
        'name' => $personName,
        'wasel' => $registration['wasel']
    ]);
    
    // No need to sleep here! The background worker automatically enforces a 7-second delay between queued messages.
    // Sleeping here just delays the AJAX response to the browser and risks a timeout.
    
    // --- 2. SEND QR BADGE ---
    $badgeLink = $baseUrl . '/badge.php?token=' . urlencode($badgeToken);
    $verifyUrl = $baseUrl . '/verify_entry.php?badge_id=' . urlencode($badgeId) . '&action=checkin';
    $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($verifyUrl);
    
    $badgeCaption = $messageTemplates['badge_caption'] ?? "🎫 باج دخول الحلبة";
    $badgeCaption = str_replace('{name}', $personName, $badgeCaption);
    $badgeCaption = str_replace('{wasel}', $registration['wasel'] ?? '', $badgeCaption);
    $badgeCaption = str_replace('{registration_code}', $registration['registration_code'] ?? '', $badgeCaption);
    $badgeCaption .= "\n\n🎫 افتح الباج الكامل:\n" . $badgeLink;
    
    // Send QR code image using WaSender class
    $results['badge'] = $wasender->sendImage($registration['phone'], $qrCodeUrl, $badgeCaption, $countryCode, [
        'type' => 'badge',
        'name' => $personName,
        'wasel' => $registration['wasel']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'تم إعادة وضع الرسائل في الطابور لإرسالها',
        'results' => $results,
        'badge_url' => $badgeLink,
        'image_url' => $acceptanceImageUrl,
        'token_used' => $badgeToken
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
