<?php
/**
 * WhatsApp Messages Log Viewer
 * عرض سجل رسائل الواتساب مع خيارات إعادة الإرسال
 */
session_start();
require_once '../include/auth.php';
require_once '../include/WhatsAppLogger.php';
require_once '../wasender.php';

requireAuth('../dashboard.php');

$currentUser = getCurrentUser();
$isRoot = ($currentUser['username'] ?? '') === 'root';
$canSendWhatsapp = hasPermission('whatsapp');

if (!$isRoot && !$canSendWhatsapp) {
    header('Location: ../dashboard.php');
    exit;
}

$logger = new WhatsAppLogger();

$message = '';
$messageType = '';

// Handle Clear Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all_logs']) && $isRoot) {
    try {
        $pdo = \db();
        $pdo->exec("DELETE FROM whatsapp_logs");
        $dataDir = __DIR__ . '/data';
        @file_put_contents($dataDir . '/whatsapp_log.json', json_encode([], JSON_PRETTY_PRINT));
        @file_put_contents($dataDir . '/whatsapp_failed_queue.json', json_encode([], JSON_PRETTY_PRINT));
        @file_put_contents($dataDir . '/message_logs.json', json_encode([], JSON_PRETTY_PRINT));
        
        // Log this action
        require_once '../include/AdminLogger.php';
        $adminLogger = new AdminLogger();
        $adminLogger->log('settings_change', $currentUser['username'] ?? 'root', 'قام بمسح سجلات الواتساب كاملة', []);
        
        $message = "تم مسح سجلات الواتساب بنجاح!";
        $messageType = "success";
    } catch (\Exception $e) {
        $message = "حدث خطأ أثناء مسح السجلات: " . $e->getMessage();
        $messageType = "error";
    }
}

// Handle retry request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'retry') {
        $messageId = $_POST['message_id'] ?? '';
        $message = $logger->retryMessage($messageId);
        
        if ($message) {
            // Attempt to resend
            try {
                $wasender = new WaSender();
                $result = false;
                
                switch ($message['message_type']) {
                    case WHATSAPP_MSG_REGISTRATION:
                        $result = $wasender->sendMessage(
                            $message['phone'],
                            "تم استلام طلبك بنجاح", // Simplified message
                            $message['country_code']
                        );
                        break;
                    
                    case WHATSAPP_MSG_ACCEPTANCE:
                    case WHATSAPP_MSG_BADGE:
                    case WHATSAPP_MSG_QR_ONLY:
                        // Would need full registration data - mark for manual review
                        $result = ['success' => false, 'error' => 'رسالة معقدة جداً - يفضل إرسالها من "القبول والرفض" في لوحة التحكم'];
                        break;
                    case 'text':
                    case 'image':
                    case 'document':
                    case 'broadcast':
                    case 'reminder':
                        $result = ['success' => false, 'error' => 'هذه الرسالة تحتوي على محتوى مخصص لا يمكن إعادة إرساله من هذه الشاشة'];
                        break;
                    default:
                        $result = ['success' => false, 'error' => 'نوع الرسالة غير مدعوم لاعادة الإرسال من هذه الشاشة'];
                        break;
                }
                
                if ($result && $result['success']) {
                    $logger->removeFromQueue($messageId);
                    $logger->log(
                        $message['phone'],
                        $message['message_type'],
                        true,
                        null,
                        [
                            'name' => $message['recipient_name'],
                            'wasel' => $message['wasel'],
                            'retry_count' => ($message['retry_count'] ?? 0) + 1
                        ]
                    );
                    echo json_encode(['success' => true, 'message' => '✅ تم إعادة الإرسال بنجاح']);
                } else {
                    $error = $result['error'] ?? 'فشل غير معروف';
                    $logger->log(
                        $message['phone'],
                        $message['message_type'],
                        false,
                        $error,
                        [
                            'name' => $message['recipient_name'],
                            'wasel' => $message['wasel'],
                            'retry_count' => ($message['retry_count'] ?? 0) + 1
                        ]
                    );
                    echo json_encode(['success' => false, 'message' => '❌ ' . $error]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => '❌ خطأ: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'الرسالة غير موجودة']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'mark_failed') {
        $messageId = $_POST['message_id'] ?? '';
        $reason = $_POST['reason'] ?? 'سبب يدوي';
        $logger->markAsPermanentlyFailed($messageId, $reason);
        echo json_encode(['success' => true, 'message' => '✅ تم تحديد الرسالة كفاشلة نهائياً']);
        exit;
    }
}

// Get filters
$status = $_GET['status'] ?? 'all'; // all, success, failed
$messageType = $_GET['message_type'] ?? '';
$phone = $_GET['phone'] ?? '';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

// Helper to normalize phone for lookup
function normalizeForLookup($p) {
    if (empty($p)) return '';
    return preg_replace('/[^0-9]/', '', $p);
}

// Helper to index data into phoneMap
function indexMemberData(&$map, $data) {
    $ph = $data['phone'] ?? '';
    $raw = normalizeForLookup($ph);
    if (!$raw) return;
    
    $info = [
        'name' => $data['name'] ?? '',
        'wasel' => $data['wasel'] ?? ''
    ];
    
    // Index multiple variations to Ensure match
    $variations = [];
    $variations[] = $raw; // Original normalized
    $variations[] = ltrim($raw, '0'); // Without leading zero
    
    $noZero = ltrim($raw, '0');
    // If it's effectively an Iraqi local number (e.g. 77...)
    if (strlen($noZero) === 10 && (strpos($noZero, '7') === 0)) {
        $variations[] = '964' . $noZero; // Add Iraq code
        $variations[] = '0' . $noZero;   // Add leading zero
    }
    // If it HAS Iraq code (964...)
    if (substr($noZero, 0, 3) === '964') {
        $variations[] = substr($noZero, 3); // Remove Iraq code
        $variations[] = '0' . substr($noZero, 3); // Local with 0
    }
    // If it HAS Egypt code (20...)
    if (substr($noZero, 0, 2) === '20') {
        $variations[] = substr($noZero, 2); // Remove Egypt code
        $variations[] = '0' . substr($noZero, 2); // Local with 0
    }

    foreach ($variations as $v) {
        if ($v) $map[$v] = $info;
    }
}

// Load members for name resolution
$phoneMap = [];

// 1. Load from members.json
$membersFile = __DIR__ . '/data/members.json';
if (file_exists($membersFile)) {
    $membersData = json_decode(file_get_contents($membersFile), true) ?? [];
    foreach ($membersData as $m) {
        indexMemberData($phoneMap, [
            'phone' => $m['phone'] ?? '',
            'name' => $m['full_name'] ?? '',
            'wasel' => $m['registration_code'] ?? ''
        ]);
    }
}

// 2. Load from data.json (often contains more recent registrations)
$dataFile = __DIR__ . '/data/data.json';
if (file_exists($dataFile)) {
    $dataJson = json_decode(file_get_contents($dataFile), true) ?? [];
    foreach ($dataJson as $d) {
        indexMemberData($phoneMap, [
            'phone' => $d['phone'] ?? '',
            'name' => $d['full_name'] ?? '',
            'wasel' => $d['wasel'] ?? ($d['registration_code'] ?? '')
        ]);
    }
}

// Get logs from WhatsAppLogger
$filters = array_filter([
    'success' => ($status === 'all' || $status === 'queued') ? null : ($status === 'success'),
    'message_type' => $messageType,
    'phone' => $phone,
    'from_date' => $fromDate,
    'to_date' => $toDate
]);

$logs = $logger->getLogs($filters);

// Filter for queued-only status (messages with 'Queued for sending' error)
if ($status === 'queued') {
    $logs = array_filter($logs, function($log) {
        $err = $log['error_message'] ?? $log['error'] ?? '';
        return !$log['success'] && stripos($err, 'Queued') !== false;
    });
    $logs = array_values($logs);
}
$failedQueue = $logger->getFailedQueue();
$stats = $logger->getStats();

// Helper to find member with smart matching
$findMember = function($ph) use ($phoneMap) {
    if (empty($ph)) return null;
    $norm = normalizeForLookup($ph);
    
    // 1. Exact match
    if (isset($phoneMap[$norm])) return $phoneMap[$norm];
    
    // 2. Try without leading zero
    $noZero = ltrim($norm, '0');
    if (isset($phoneMap[$noZero])) return $phoneMap[$noZero];
    
    // 3. Try removing Iraq code (964)
    if (strpos($norm, '964') === 0) {
        $local = substr($norm, 3);
        if (isset($phoneMap[$local])) return $phoneMap[$local];
        if (isset($phoneMap[ltrim($local, '0')])) return $phoneMap[ltrim($local, '0')];
    }
    
    // 4. Try removing Egypt code (20)
    if (strpos($norm, '20') === 0) {
        $local = substr($norm, 2); // e.g. 2010123... -> 10123...
        if (isset($phoneMap[$local])) return $phoneMap[$local];
        if (isset($phoneMap[ltrim($local, '0')])) return $phoneMap[ltrim($local, '0')];
    }
    
    return null;
};

// Enrich logs with names if missing
foreach ($logs as &$log) {
    if (empty($log['recipient_name']) || $log['recipient_name'] === 'غير معرف') {
        $member = $findMember($log['phone'] ?? '');
        if ($member) {
            $log['recipient_name'] = $member['name'];
            if (empty($log['wasel']) && !empty($member['wasel'])) {
                $details = !empty($log['details']) ? (is_string($log['details']) ? json_decode($log['details'], true) : $log['details']) : [];
                $details['wasel'] = $member['wasel'];
                $log['details'] = json_encode($details);
            }
        }
    }
}
unset($log); // Break reference

// Merge legacy message_logs.json data (for messages sent before WhatsAppLogger was integrated)
$legacyFile = __DIR__ . '/data/message_logs.json';
if (file_exists($legacyFile)) {
    $legacyLogs = json_decode(file_get_contents($legacyFile), true) ?? [];
    $existingPhones = [];
    foreach ($logs as $log) {
        $existingPhones[$log['phone'] . '_' . ($log['created_at'] ?? $log['timestamp'] ?? '')] = true;
    }
    
    foreach ($legacyLogs as $ll) {
        $key = ($ll['phone'] ?? '') . '_' . ($ll['timestamp'] ?? '');
        if (isset($existingPhones[$key])) continue; // Skip duplicates
        
        // Apply filters
        if ($status === 'success' && !$ll['success']) continue;
        if ($status === 'failed' && $ll['success']) continue;
        if ($phone && strpos($ll['phone'] ?? '', $phone) === false) continue;
        if ($fromDate && ($ll['timestamp'] ?? '') < $fromDate) continue;
        if ($toDate && ($ll['timestamp'] ?? '') > $toDate . ' 23:59:59') continue;
        
        // Resolve name if missing
        $recName = $ll['name'] ?? '';
        $recWasel = $ll['wasel'] ?? '';
        
        if (empty($recName) || $recName === 'غير معرف') {
            $member = $findMember($ll['phone'] ?? '');
            if ($member) {
                $recName = $member['name'];
                if (empty($recWasel)) $recWasel = $member['wasel'];
            }
        }

        $logs[] = [
            'id' => 'legacy_' . md5($key),
            'phone' => $ll['phone'] ?? '',
            'message_type' => $ll['type'] ?? 'text',
            'success' => $ll['success'] ? 1 : 0,
            'error_message' => $ll['error'] ?? null,
            'recipient_name' => $recName,
            'created_at' => $ll['timestamp'] ?? '',
            'details' => json_encode(['wasel' => $recWasel, 'source' => 'legacy']),
            'legacy' => true
        ];
        
        // Update stats for legacy data
        if (!isset($stats['total'])) $stats['total'] = 0;
        $stats['total']++;
        if ($ll['success']) {
            if (!isset($stats['success'])) $stats['success'] = 0;
            $stats['success']++;
        } else {
            if (!isset($stats['failed'])) $stats['failed'] = 0;
            $stats['failed']++;
        }
    }
    
    // Sort by timestamp descending
    usort($logs, function($a, $b) {
        $ta = $a['created_at'] ?? $a['timestamp'] ?? '';
        $tb = $b['created_at'] ?? $b['timestamp'] ?? '';
        return strcmp($tb, $ta);
    });
}
// Enrich failed queue with names
foreach ($failedQueue as &$fq) {
    if (empty($fq['recipient_name']) || $fq['recipient_name'] === 'غير معرف') {
        $member = $findMember($fq['phone'] ?? '');
        if ($member) {
            $fq['recipient_name'] = $member['name'];
        }
    }
}
unset($fq);

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="whatsapp_log_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Arabic
    $out = fopen('php://output', 'w');
    fputcsv($out, ['الحالة', 'اسم المستلم', 'رقم الهاتف', 'نوع الرسالة', 'التاريخ', 'الخطأ']);
    foreach ($logs as $l) {
        fputcsv($out, [
            $l['success'] ? 'ناجح' : 'فشل',
            $l['recipient_name'] ?? '',
            $l['phone'] ?? '',
            $l['message_type'] ?? '',
            $l['created_at'] ?? $l['timestamp'] ?? '',
            $l['error_message'] ?? $l['error'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Message type labels
$messageTypeLabels = [
    'registration_received' => '📝 استلام التسجيل',
    'acceptance' => '✅ قبول مبدئي',
    'badge' => '📛 كود الباج',
    'qr_only' => '📷 QR Code',
    'rejection' => '❌ رفض',
    'broadcast' => '📢 إشعار جماعي',
    'reminder' => '⏰ تذكير',
    'text' => '💬 نصية',
    'image' => '🖼️ صورة',
    'document' => '📎 مرفق'
];

// Calculate success rate safely
$successRate = ($stats['total'] ?? 0) > 0 
    ? round((($stats['success'] ?? 0) / $stats['total']) * 100, 1) 
    : 0;

// Pagination
$perPage = 50;
$totalLogs = count($logs);
$totalPages = max(1, ceil($totalLogs / $perPage));
$currentPage = max(1, min(intval($_GET['page'] ?? 1), $totalPages));
$offset = ($currentPage - 1) * $perPage;
$pagedLogs = array_slice($logs, $offset, $perPage);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجل الرسائل</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #0f0f1a; color: #e0e0e0; min-height: 100vh; }
        
        .page-container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        
        /* Header */
        .page-header { 
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 28px; padding: 20px 24px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-radius: 16px; border: 1px solid rgba(255,255,255,0.06);
        }
        .page-header h1 { font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .page-header h1 .wa-icon { color: #25D366; font-size: 28px; }
        .btn-back { 
            background: rgba(255,255,255,0.08); color: #ccc; border: 1px solid rgba(255,255,255,0.1);
            padding: 8px 18px; border-radius: 8px; text-decoration: none; font-size: 14px;
            transition: all 0.2s; display: flex; align-items: center; gap: 6px;
        }
        .btn-back:hover { background: rgba(255,255,255,0.14); color: #fff; }

        /* Stats Grid */
        .stats-grid { 
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px; margin-bottom: 24px; 
        }
        .stat-card { 
            background: rgba(255,255,255,0.04); padding: 20px; border-radius: 14px;
            text-align: center; border: 1px solid rgba(255,255,255,0.06);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.3); }
        .stat-card .stat-number { font-size: 32px; font-weight: 700; margin-bottom: 4px; }
        .stat-card .stat-label { font-size: 13px; color: #888; }
        .stat-card.total .stat-number { color: #7c8dff; }
        .stat-card.success .stat-number { color: #25D366; }
        .stat-card.failed .stat-number { color: #ff5252; }
        .stat-card.rate .stat-number { color: #ffab40; }

        /* Failed Queue Alert */
        .failed-alert {
            background: linear-gradient(135deg, #3a1c1c, #2a1515);
            border: 1px solid rgba(255,82,82,0.3); border-radius: 12px;
            padding: 16px 20px; margin-bottom: 20px; color: #ff8a80;
            display: flex; justify-content: space-between; align-items: center;
        }
        .failed-alert i { margin-left: 8px; }
        .failed-alert .btn-show { 
            background: rgba(255,82,82,0.2); color: #ff8a80; border: 1px solid rgba(255,82,82,0.3);
            padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: inherit;
        }
        .failed-queue-panel { 
            display: none; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,82,82,0.15);
            border-radius: 12px; padding: 16px; margin-bottom: 20px; max-height: 300px; overflow-y: auto;
        }
        .failed-queue-panel.show { display: block; }
        .fq-item { 
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 14px; background: rgba(0,0,0,0.2); border-radius: 8px; margin-bottom: 8px;
        }
        .fq-item:last-child { margin-bottom: 0; }
        .fq-item .fq-info { flex: 1; }
        .fq-item .fq-info small { color: #999; }
        .fq-actions { display: flex; gap: 8px; }
        .fq-actions button { 
            border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;
            font-family: inherit; transition: all 0.2s;
        }
        .btn-retry-sm { background: #ffab40; color: #000; }
        .btn-retry-sm:hover { background: #ffc107; }
        .btn-fail-sm { background: rgba(255,82,82,0.2); color: #ff5252; border: 1px solid rgba(255,82,82,0.3); }
        .btn-fail-sm:hover { background: rgba(255,82,82,0.35); }

        /* Filters */
        .filter-bar {
            background: rgba(255,255,255,0.04); padding: 16px 20px; border-radius: 12px;
            margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 11px; color: #888; font-weight: 600; }
        .filter-group select, .filter-group input {
            background: rgba(0,0,0,0.3); color: #e0e0e0; border: 1px solid rgba(255,255,255,0.1);
            padding: 8px 12px; border-radius: 8px; font-family: inherit; font-size: 13px;
            min-width: 130px;
        }
        .filter-group select:focus, .filter-group input:focus { 
            border-color: #7c8dff; outline: none; box-shadow: 0 0 0 2px rgba(124,141,255,0.2); 
        }
        .filter-actions { display: flex; gap: 8px; align-self: flex-end; }
        .btn-filter { 
            background: #7c8dff; color: #fff; border: none; padding: 8px 18px;
            border-radius: 8px; cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 600;
            display: flex; align-items: center; gap: 6px; transition: all 0.2s;
        }
        .btn-filter:hover { background: #6b7cee; }
        .btn-reset { 
            background: rgba(255,255,255,0.06); color: #ccc; border: 1px solid rgba(255,255,255,0.1);
            padding: 8px 14px; border-radius: 8px; text-decoration: none; font-size: 13px;
            display: flex; align-items: center; gap: 6px; transition: all 0.2s;
        }
        .btn-reset:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .btn-csv {
            background: rgba(32,201,151,0.15); color: #20c997; border: 1px solid rgba(32,201,151,0.3);
            padding: 8px 14px; border-radius: 8px; text-decoration: none; font-size: 13px;
            display: flex; align-items: center; gap: 6px; transition: all 0.2s;
        }
        .btn-csv:hover { background: rgba(32,201,151,0.25); color: #3dd5a7; }

        /* Table */
        .table-wrapper {
            background: rgba(255,255,255,0.03); border-radius: 14px; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .table-header-bar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .table-header-bar h3 { font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .table-header-bar .count { color: #7c8dff; }
        
        table { width: 100%; border-collapse: collapse; }
        thead th { 
            background: rgba(0,0,0,0.3); padding: 12px 16px; text-align: right;
            font-weight: 600; font-size: 13px; color: #888; white-space: nowrap;
            border-bottom: 2px solid rgba(255,255,255,0.06);
        }
        tbody tr { 
            border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; 
        }
        tbody tr:hover { background: rgba(255,255,255,0.04); }
        tbody td { padding: 14px 16px; font-size: 14px; vertical-align: middle; }
        
        /* Status badge */
        .status-badge { 
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
        }
        .status-badge.success { background: rgba(37,211,102,0.15); color: #25D366; }
        .status-badge.failed { background: rgba(255,82,82,0.15); color: #ff5252; }
        .status-badge.queued { background: rgba(255,193,7,0.15); color: #ffc107; }
        
        /* Message type chip */
        .type-chip { 
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 6px; font-size: 12px;
            background: rgba(124,141,255,0.12); color: #a0adff;
        }
        
        /* Recipient info */
        .recipient-info .name { font-weight: 600; color: #fff; margin-bottom: 2px; }
        .recipient-info .phone-num { font-size: 12px; color: #888; direction: ltr; text-align: right; }
        .recipient-info .wasel-tag { 
            display: inline-block; font-size: 11px; background: rgba(255,193,7,0.12);
            color: #ffc107; padding: 1px 6px; border-radius: 4px; margin-top: 2px;
        }
        
        /* Error row */
        .error-detail { 
            font-size: 12px; color: #ff8a80; background: rgba(255,82,82,0.08);
            padding: 4px 10px; border-radius: 4px; max-width: 300px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        /* Legacy tag */
        .legacy-tag {
            display: inline-block; font-size: 10px; background: rgba(108,117,125,0.2);
            color: #aaa; padding: 1px 6px; border-radius: 3px;
        }
        
        /* Time */
        .time-cell { font-size: 13px; color: #999; direction: ltr; text-align: right; white-space: nowrap; }

        /* Pagination */
        .pagination-bar { 
            display: flex; justify-content: center; align-items: center; gap: 8px;
            padding: 16px; border-top: 1px solid rgba(255,255,255,0.06);
        }
        .pagination-bar a, .pagination-bar span {
            padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; transition: all 0.2s;
        }
        .pagination-bar a { background: rgba(255,255,255,0.06); color: #ccc; }
        .pagination-bar a:hover { background: rgba(255,255,255,0.12); color: #fff; }
        .pagination-bar .active-page { background: #7c8dff; color: #fff; font-weight: 600; }
        .pagination-bar .dots { color: #666; }

        /* Empty state */
        .empty-state { text-align: center; padding: 60px 20px; color: #666; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.3; }
        .empty-state p { font-size: 16px; }

        /* Responsive */
        @media (max-width: 768px) {
            .page-container { padding: 12px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            .filter-group select, .filter-group input { width: 100%; }
            .table-wrapper { overflow-x: auto; }
            table { min-width: 700px; }
        }
    </style>


</head>
<body>
    <?php include '../include/navbar-custom.php'; ?>
<div class="page-container">
    <!-- Header -->
    <div class="page-header">
        <h1><i class="fa-brands fa-whatsapp wa-icon"></i> سجل الرسائل</h1>
        <div style="display: flex; gap: 10px;">
            <?php if ($isRoot): ?>
            <form method="POST" onsubmit="return confirm('تأكيد نهائي: هل أنت متأكد من مسح جميع السجلات؟ هذا الإجراء لا يمكن التراجع عنه!');" style="display:inline;">
                <input type="hidden" name="clear_all_logs" value="1">
                <button type="submit" class="btn-back" style="background: rgba(255,82,82,0.2); color: #ff8a80; border-color: rgba(255,82,82,0.3); border-radius: 8px; padding: 8px 18px; cursor: pointer; font-size: 14px;"><i class="fa-solid fa-trash-can"></i> مسح جميع السجلات</button>
            </form>
            <?php endif; ?>
            
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="padding: 15px; margin-bottom: 20px; border-radius: 8px; background: <?= $messageType === 'success' ? 'rgba(37,211,102,0.15)' : 'rgba(255,82,82,0.15)' ?>; color: <?= $messageType === 'success' ? '#25D366' : '#ff5252' ?>; border: 1px solid <?= $messageType === 'success' ? '#25D366' : '#ff5252' ?>;">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-number"><?= number_format($stats['total'] ?? 0) ?></div>
            <div class="stat-label">إجمالي الرسائل</div>
        </div>
        <div class="stat-card success">
            <div class="stat-number"><?= number_format($stats['success'] ?? 0) ?></div>
            <div class="stat-label">ناجحة</div>
        </div>
        <div class="stat-card failed">
            <div class="stat-number"><?= number_format($stats['failed'] ?? 0) ?></div>
            <div class="stat-label">فاشلة</div>
        </div>
        <div class="stat-card rate">
            <div class="stat-number"><?= $successRate ?>%</div>
            <div class="stat-label">نسبة النجاح</div>
        </div>
    </div>

    <!-- Failed Queue -->
    <?php if (count($failedQueue) > 0): ?>
    <div class="failed-alert">
        <span><i class="fa-solid fa-triangle-exclamation"></i> <?= count($failedQueue) ?> رسالة فشلت وبانتظار الإجراء</span>
        <button class="btn-show" onclick="toggleFailedQueue()">
            <i class="fa-solid fa-eye"></i> عرض
        </button>
    </div>
    <div id="failedPanel" class="failed-queue-panel">
        <?php foreach ($failedQueue as $failed): ?>
        <?php if (($failed['status'] ?? '') !== 'failed_permanent'): ?>
        <div class="fq-item">
            <div class="fq-info">
                <strong><?= htmlspecialchars($failed['recipient_name'] ?? 'غير معرف') ?></strong>
                — <?= $messageTypeLabels[$failed['message_type']] ?? $failed['message_type'] ?>
                <br><small><?= $failed['phone'] ?> | المحاولات: <?= $failed['retry_count'] ?? 0 ?> | <?= $failed['queued_at'] ?? '' ?></small>
                <?php if (!empty($failed['error'])): ?>
                <br><small style="color:#ff8a80">❌ <?= htmlspecialchars($failed['error']) ?></small>
                <?php endif; ?>
            </div>
            <div class="fq-actions">
                <button class="btn-retry-sm" onclick="retryMessage('<?= $failed['id'] ?>')">
                    <i class="fa-solid fa-rotate-right"></i> إعادة
                </button>
                <button class="btn-fail-sm" onclick="markFailed('<?= $failed['id'] ?>')">
                    <i class="fa-solid fa-ban"></i> فشل نهائي
                </button>
            </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <label>الحالة</label>
            <select name="status">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>الكل</option>
                <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>✅ ناجحة</option>
                <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>❌ فاشلة</option>
                <option value="queued" <?= $status === 'queued' ? 'selected' : '' ?>>⏳ قيد الانتظار</option>
            </select>
        </div>
        <div class="filter-group">
            <label>نوع الرسالة</label>
            <select name="message_type">
                <option value="">الكل</option>
                <?php foreach ($messageTypeLabels as $key => $label): ?>
                <option value="<?= $key ?>" <?= $messageType === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>رقم الهاتف</label>
            <input type="text" name="phone" placeholder="07xxxxxxxx" value="<?= htmlspecialchars($phone) ?>">
        </div>
        <div class="filter-group">
            <label>من تاريخ</label>
            <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
        </div>
        <div class="filter-group">
            <label>إلى تاريخ</label>
            <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-filter"><i class="fa-solid fa-search"></i> بحث</button>
            <a href="whatsapp_log.php" class="btn-reset"><i class="fa-solid fa-times"></i></a>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-csv">
                <i class="fa-solid fa-file-csv"></i> CSV
            </a>
        </div>
    </form>

    <!-- Table -->
    <div class="table-wrapper">
        <div class="table-header-bar">
            <h3><i class="fa-solid fa-list"></i> السجلات <span class="count">(<?= number_format($totalLogs) ?>)</span></h3>
            <span style="font-size: 13px; color: #888;">صفحة <?= $currentPage ?> من <?= $totalPages ?></span>
        </div>

        <?php if (empty($pagedLogs)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-inbox"></i>
            <p>لا توجد رسائل مطابقة للبحث</p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 40px">#</th>
                    <th>الحالة</th>
                    <th>المُستلم</th>
                    <th>نوع الرسالة</th>
                    <th>التاريخ والوقت</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagedLogs as $idx => $log): 
                    $logTime = $log['created_at'] ?? $log['timestamp'] ?? '';
                    $recipientName = $log['recipient_name'] ?? '';
                    $logPhone = $log['phone'] ?? '';
                    $logType = $log['message_type'] ?? 'text';
                    $isSuccess = !empty($log['success']);
                    $errorMsg = $log['error_message'] ?? $log['error'] ?? '';
                    $isLegacy = !empty($log['legacy']);
                    
                    // Extract wasel from details
                    $wasel = '';
                    if (!empty($log['details'])) {
                        $details = is_string($log['details']) ? json_decode($log['details'], true) : $log['details'];
                        $wasel = $details['wasel'] ?? '';
                    }
                ?>
                <tr>
                    <td style="color: #555; font-size: 12px;"><?= $offset + $idx + 1 ?></td>
                    <td>
                        <?php if (!$isSuccess && strpos($errorMsg, 'Queued') !== false): ?>
                            <span class="status-badge queued">
                                <i class="fa-solid fa-clock"></i> قيد الانتظار
                            </span>
                        <?php elseif ($isSuccess): ?>
                            <span class="status-badge success">
                                <i class="fa-solid fa-check"></i> ناجح
                            </span>
                        <?php else: ?>
                            <span class="status-badge failed">
                                <i class="fa-solid fa-xmark"></i> فشل
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="recipient-info">
                            <div class="name"><?= htmlspecialchars($recipientName ?: 'غير معرف') ?></div>
                            <div class="phone-num"><?= htmlspecialchars($logPhone) ?></div>
                            <?php if ($wasel): ?>
                            <span class="wasel-tag">وصل #<?= htmlspecialchars($wasel) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="type-chip"><?= $messageTypeLabels[$logType] ?? $logType ?></span>
                        <?php if ($isLegacy): ?>
                        <span class="legacy-tag">legacy</span>
                        <?php endif; ?>
                    </td>
                    <td class="time-cell"><?= htmlspecialchars($logTime) ?></td>
                    <td>
                        <?php if (!$isSuccess && $errorMsg): ?>
                        <div class="error-detail" title="<?= htmlspecialchars($errorMsg) ?>">
                            <?= htmlspecialchars($errorMsg) ?>
                        </div>
                        <?php else: ?>
                        <span style="color: #444;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-bar">
            <?php 
            $queryParams = $_GET;
            unset($queryParams['page']);
            $baseUrl = 'whatsapp_log.php?' . http_build_query($queryParams);
            
            if ($currentPage > 1): ?>
                <a href="<?= $baseUrl ?>&page=<?= $currentPage - 1 ?>"><i class="fa-solid fa-chevron-right"></i></a>
            <?php endif;
            
            $startPage = max(1, $currentPage - 3);
            $endPage = min($totalPages, $currentPage + 3);
            
            if ($startPage > 1): ?>
                <a href="<?= $baseUrl ?>&page=1">1</a>
                <?php if ($startPage > 2): ?><span class="dots">...</span><?php endif;
            endif;
            
            for ($p = $startPage; $p <= $endPage; $p++): ?>
                <?php if ($p == $currentPage): ?>
                    <span class="active-page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= $baseUrl ?>&page=<?= $p ?>"><?= $p ?></a>
                <?php endif;
            endfor;
            
            if ($endPage < $totalPages): 
                if ($endPage < $totalPages - 1): ?><span class="dots">...</span><?php endif; ?>
                <a href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a>
            <?php endif;
            
            if ($currentPage < $totalPages): ?>
                <a href="<?= $baseUrl ?>&page=<?= $currentPage + 1 ?>"><i class="fa-solid fa-chevron-left"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function toggleFailedQueue() {
    var el = document.getElementById('failedPanel');
    if (el.style.display === 'block') {
        el.style.display = 'none';
    } else {
        el.style.display = 'block';
    }
}

function retryMessage(messageId) {
    if (!confirm('هل تريد إعادة إرسال هذه الرسالة؟')) return;
    
    $.post('whatsapp_log.php', {
        action: 'retry',
        message_id: messageId
    }, function(response) {
        alert(response.message);
        location.reload();
    }, 'json');
}

function markFailed(messageId) {
    const reason = prompt('سبب الفشل النهائي:');
    if (!reason) return;
    
    $.post('whatsapp_log.php', {
        action: 'mark_failed',
        message_id: messageId,
        reason: reason
    }, function(response) {
        alert(response.message);
        location.reload();
    }, 'json');
}
</script>
</body>
</html>






