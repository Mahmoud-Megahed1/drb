<?php
/**
 * Send Activation API
 * Activates imported member account and sends WhatsApp message
 */

session_start();
require_once '../../include/db.php';
require_once '../../wasender.php';

header('Content-Type: application/json; charset=utf-8');

// Auth check
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

// Input
$wasel = trim($_POST['wasel'] ?? '');

if (empty($wasel)) {
    echo json_encode(['success' => false, 'error' => 'رقم الواصل مطلوب']);
    exit;
}

try {
    // Load data.json
    $dataFile = __DIR__ . '/../data/data.json';
    if (!file_exists($dataFile)) {
        throw new Exception('ملف البيانات غير موجود');
    }
    
    $data = json_decode(file_get_contents($dataFile), true);
    if (!is_array($data)) {
        throw new Exception('خطأ في قراءة البيانات');
    }
    
    // Find member
    $found = false;
    $memberData = null;
    
    foreach ($data as $index => $item) {
        if ((string)($item['wasel'] ?? '') === (string)$wasel) {
            $memberData = $item;
            $memberIndex = $index;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        throw new Exception('العضو غير موجود');
    }
    
    // Check if already activated
    if (!empty($memberData['account_activated'])) {
        echo json_encode(['success' => true, 'message' => 'الحساب مفعل مسبقاً']);
        exit;
    }
    
    // Get permanent code
    $permanentCode = $memberData['permanent_code'] ?? $memberData['registration_code'] ?? '';
    if (empty($permanentCode)) {
        throw new Exception('كود العضو غير موجود');
    }
    
    // Send WhatsApp
    $wasender = new WaSender();
    $waResult = $wasender->sendAccountActivation([
        'name' => $memberData['full_name'] ?? $memberData['name'] ?? 'عضو',
        'phone' => $memberData['phone'] ?? '',
        'permanent_code' => $permanentCode,
        'country_code' => '+964'
    ]);
    
    // Update data.json
    $data[$memberIndex]['account_activated'] = true;
    $data[$memberIndex]['activation_date'] = date('Y-m-d H:i:s');
    $data[$memberIndex]['activation_message_sent'] = $waResult['success'] ?? false;
    
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Also update members.json if exists
    $membersFile = __DIR__ . '/../data/members.json';
    if (file_exists($membersFile)) {
        $members = json_decode(file_get_contents($membersFile), true) ?? [];
        if (isset($members[$permanentCode])) {
            $members[$permanentCode]['account_activated'] = true;
            $members[$permanentCode]['activation_date'] = date('Y-m-d H:i:s');
            $members[$permanentCode]['activation_message_sent'] = $waResult['success'] ?? false;
            file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
    
    if ($waResult['success'] ?? false) {
        echo json_encode([
            'success' => true,
            'message' => 'تم تفعيل الحساب وإرسال رسالة التفعيل بنجاح!'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'تم تفعيل الحساب، لكن فشل إرسال الرسالة',
            'wa_error' => $waResult['error'] ?? 'Unknown'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
