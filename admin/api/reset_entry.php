<?php
/**
 * Reset Entry API - ????? ????? ??????
 * ??????? ??? - ??? ????????
 * 
 * ?????: ????? ????? ????? ?????? ?????? ????????
 */

session_start();
require_once '../../include/db.php';

header('Content-Type: application/json; charset=utf-8');

// ?????? ?? ????????? - Root ?? Admin ??? (??? Gate)
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => '??? ????']);
    exit;
}

$user = $_SESSION['user'];
$isAdmin = false;
$operatorName = '';
$operatorRole = '';
$operatorDepartment = '';

if (is_object($user)) {
    $isAdmin = ($user->username ?? '') === 'root' || in_array($user->role ?? '', ['root', 'admin']);
    $operatorName = $user->full_name ?? $user->username ?? '??? ?????';
    $operatorRole = $user->role ?? '';
    $operatorDepartment = $user->department ?? '???????';
} elseif (is_array($user)) {
    $isAdmin = ($user['username'] ?? '') === 'root' || in_array($user['role'] ?? '', ['root', 'admin']);
    $operatorName = $user['full_name'] ?? $user['username'] ?? '??? ?????';
    $operatorRole = $user['role'] ?? '';
    $operatorDepartment = $user['department'] ?? '???????';
}

// ? ???????? ?? ?????? ????? ???????
if (!$isAdmin) {
    echo json_encode([
        'success' => false, 
        'error' => '? ????? ??????? ????? ??????? ??? - ??? ?? ????????'
    ]);
    exit;
}

$id = $_POST['id'] ?? $_GET['id'] ?? '';
$reason = $_POST['reason'] ?? '?? ??? ????? ?????';

if (empty($id)) {
    echo json_encode(['success' => false, 'error' => '???? ??? ????']);
    exit;
}

$dataFile = '../data/data.json';
$logsFile = '../data/entry_logs.json';

$data = json_decode(file_get_contents($dataFile), true) ?? [];
$logs = file_exists($logsFile) ? (json_decode(file_get_contents($logsFile), true) ?? []) : [];

// ????? ?? ???????
$targetIndex = -1;
$registration = null;

foreach ($data as $index => $item) {
    if (
        (isset($item['badge_id']) && $item['badge_id'] === $id) ||
        (isset($item['badge_token']) && $item['badge_token'] === $id) ||
        (isset($item['session_badge_token']) && $item['session_badge_token'] === $id) ||
        (isset($item['registration_code']) && strcasecmp($item['registration_code'], $id) === 0) ||
        (isset($item['wasel']) && (string)$item['wasel'] === (string)$id)
    ) {
        $registration = $item;
        $targetIndex = $index;
        break;
    }
}

if ($targetIndex === -1) {
    echo json_encode(['success' => false, 'error' => '??????? ??? ?????']);
    exit;
}

// ??? ?????? ??????? ?????
$oldStatus = [
    'was_entered' => $registration['has_entered'] ?? false,
    'gate1_scanned' => $registration['gate1_scanned'] ?? false,
    'gate2_scanned' => $registration['gate2_scanned'] ?? false,
    'entry_time' => $registration['entry_time'] ?? null,
    'entered_by' => $registration['entered_by'] ?? null
];

// ????? ????? ?? ???? ??????
$data[$targetIndex]['has_entered'] = false;
$data[$targetIndex]['entry_time'] = null;
$data[$targetIndex]['entered_by'] = null;
$data[$targetIndex]['gate1_scanned'] = false;
$data[$targetIndex]['gate1_time'] = null;
$data[$targetIndex]['gate1_operator'] = null;
$data[$targetIndex]['gate2_scanned'] = false;
$data[$targetIndex]['gate2_time'] = null;
$data[$targetIndex]['gate2_operator'] = null;

// ????? ??????? ????? ???????
$data[$targetIndex]['last_reset_time'] = date('Y-m-d H:i:s');
$data[$targetIndex]['last_reset_by'] = $operatorName;
$data[$targetIndex]['last_reset_reason'] = $reason;

// ??? ?? ?????
$logEntry = [
    'log_id' => uniqid('RESET_'),
    'action' => 'entry_reset',
    'timestamp' => date('Y-m-d H:i:s'),
    'member_wasel' => $registration['wasel'] ?? '',
    'member_name' => $registration['full_name'] ?? '',
    'operator_name' => $operatorName,
    'operator_role' => $operatorRole,
    'operator_department' => $operatorDepartment,
    'reason' => $reason,
    'previous_status' => $oldStatus,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
];
$logs[] = $logEntry;

// ??? JSON
file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ????? ????? ???????? SQL ?????
try {
    $pdo = db();
    
    // Try to find participant - use wasel or registration_code as they are more reliable
    $stmt = $pdo->prepare("SELECT id FROM participants WHERE registration_code = ? OR wasel = ?");
    $stmt->execute([$id, $id]);
    $pId = $stmt->fetchColumn();
    
    $deletedLogs = 0;
    if ($pId) {
        $stmt = $pdo->prepare("DELETE FROM round_logs WHERE participant_id = ?");
        $stmt->execute([$pId]);
        $deletedLogs = $stmt->rowCount();
    }
    
    // ????? ???? ?????????
    $stmt = $pdo->prepare("UPDATE registrations SET has_entered = 0, entry_time = NULL WHERE session_badge_token = ? OR wasel = ?");
    $stmt->execute([$id, $id]);
    
} catch (Exception $e) {
    // DB error - JSON already saved
}

// Log to AdminLogger
try {
    require_once __DIR__ . '/../../include/AdminLogger.php';
    $adminLogger = new AdminLogger();
    $adminLogger->log(
        AdminLogger::ACTION_SETTINGS_CHANGE,
        $operatorName,
        'إلغاء دخول عضو: ' . ($registration['full_name'] ?? '') . ' - واصل: ' . ($registration['wasel'] ?? ''),
        ['wasel' => $registration['wasel'] ?? '', 'reason' => $reason, 'source' => 'reset_entry']
    );
} catch (Exception $e) {}

echo json_encode([
    'success' => true,
    'message' => 'تم إعادة تعيين الدخول بنجاح',
    'member' => [
        'wasel' => $registration['wasel'] ?? '',
        'name' => $registration['full_name'] ?? ''
    ],
    'reset_by' => $operatorName,
    'log_id' => $logEntry['log_id']
]);
