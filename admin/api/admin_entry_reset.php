<?php
/**
 * Admin Entry Reset API
 * ????? ????? ?????? - ??????? ???
 * 
 * ??? ????? ??????? ???? ????????
 * ?? ????? Reset ???? ?? ???????
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// ?????? ?? ????????? - Root ?? Admin ???
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
    $isAdmin = ($user->username ?? '') === 'root' || 
               in_array($user->role ?? '', ['root', 'admin']);
    $operatorName = $user->full_name ?? $user->username ?? '??? ?????';
    $operatorRole = $user->role ?? '';
    $operatorDepartment = $user->department ?? '???????';
} elseif (is_array($user)) {
    $isAdmin = ($user['username'] ?? '') === 'root' || 
               in_array($user['role'] ?? '', ['root', 'admin']);
    $operatorName = $user['full_name'] ?? $user['username'] ?? '??? ?????';
    $operatorRole = $user['role'] ?? '';
    $operatorDepartment = $user['department'] ?? '???????';
}

if (!$isAdmin) {
    echo json_encode([
        'success' => false, 
        'error' => '? ??????? ??????? ?????? ?????? ???????'
    ]);
    exit;
}

$dataFile = __DIR__ . '/data/data.json';
$logsFile = __DIR__ . '/data/entry_logs.json';

$data = json_decode(file_get_contents($dataFile), true) ?? [];
$logs = json_decode(file_get_contents($logsFile), true) ?? [];

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$wasel = $_POST['wasel'] ?? $_GET['wasel'] ?? '';
$badgeId = $_POST['badge_id'] ?? $_GET['badge_id'] ?? '';
$reason = $_POST['reason'] ?? '?? ??? ????? ?????';

// ????? ?? ???????
$targetIndex = -1;
$registration = null;

foreach ($data as $index => $reg) {
    if ((!empty($wasel) && (string)$reg['wasel'] === (string)$wasel) ||
        (!empty($badgeId) && (($reg['badge_id'] ?? '') === $badgeId || ($reg['badge_token'] ?? '') === $badgeId))) {
        $registration = $reg;
        $targetIndex = $index;
        break;
    }
}

if (!$registration) {
    echo json_encode(['success' => false, 'error' => '??????? ??? ?????']);
    exit;
}

switch ($action) {
    case 'reset_entry':
        // ????? ????? ??????
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
        
        // ????? ??? ????? ???????
        $data[$targetIndex]['last_reset_time'] = date('Y-m-d H:i:s');
        $data[$targetIndex]['last_reset_by'] = $operatorName;
        $data[$targetIndex]['last_reset_reason'] = $reason;
        
        // ??? ?? ??? ???????
        $logEntry = [
            'log_id' => uniqid('RESET_'),
            'action' => 'entry_reset',
            'timestamp' => date('Y-m-d H:i:s'),
            'member_wasel' => $registration['wasel'],
            'member_name' => $registration['full_name'],
            'operator_name' => $operatorName,
            'operator_role' => $operatorRole,
            'operator_department' => $operatorDepartment,
            'reason' => $reason,
            'previous_status' => $oldStatus,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        $logs[] = $logEntry;
        
        // ??? ???????
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        echo json_encode([
            'success' => true,
            'message' => '? ?? ????? ????? ?????? ?????',
            'member' => [
                'wasel' => $registration['wasel'],
                'name' => $registration['full_name']
            ],
            'reset_by' => $operatorName,
            'reset_time' => date('Y-m-d H:i:s'),
            'log_id' => $logEntry['log_id']
        ]);
        break;
        
    case 'reset_all':
        // ????? ????? ???? ?????? (????!)
        if ($operatorRole !== 'root' && ($user->username ?? $user['username'] ?? '') !== 'root') {
            echo json_encode(['success' => false, 'error' => '?????? Root ??????']);
            exit;
        }
        
        $resetCount = 0;
        foreach ($data as $index => $reg) {
            if ($reg['has_entered'] ?? false) {
                $data[$index]['has_entered'] = false;
                $data[$index]['entry_time'] = null;
                $data[$index]['gate1_scanned'] = false;
                $data[$index]['gate2_scanned'] = false;
                $resetCount++;
            }
        }
        
        // ??? ???????
        $logs[] = [
            'log_id' => uniqid('RESET_ALL_'),
            'action' => 'reset_all_entries',
            'timestamp' => date('Y-m-d H:i:s'),
            'operator_name' => $operatorName,
            'operator_role' => $operatorRole,
            'entries_reset' => $resetCount,
            'reason' => $reason,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        echo json_encode([
            'success' => true,
            'message' => "? ?? ????? ????? $resetCount ????",
            'reset_by' => $operatorName
        ]);
        break;
        
    case 'view_logs':
        // ??? ????? ??????
        $memberWasel = $_GET['member_wasel'] ?? null;
        $limit = intval($_GET['limit'] ?? 100);
        
        $filteredLogs = $logs;
        
        if ($memberWasel) {
            $filteredLogs = array_filter($logs, function($log) use ($memberWasel) {
                return ($log['member_wasel'] ?? '') === $memberWasel;
            });
        }
        
        // ??? ??????? ?????
        $filteredLogs = array_slice(array_reverse($filteredLogs), 0, $limit);
        
        echo json_encode([
            'success' => true,
            'total_logs' => count($logs),
            'returned_logs' => count($filteredLogs),
            'logs' => array_values($filteredLogs)
        ]);
        break;
        
    default:
        echo json_encode([
            'success' => false,
            'error' => '????? ??? ?????',
            'valid_actions' => ['reset_entry', 'reset_all', 'view_logs']
        ]);
}
