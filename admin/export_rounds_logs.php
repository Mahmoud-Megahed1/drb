<?php
/**
 * Export Rounds Logs - ????? ????? ???????
 */

require_once '../include/db.php';
require_once '../include/auth.php';

requireAuth();
if (!hasPermission('admin') && !hasPermission('rounds') && !hasPermission('root')) {
    die('Unauthorized');
}

// Load rounds logs
$logsFile = __DIR__ . '/data/round_logs.json';
$logs = file_exists($logsFile) ? (json_decode(file_get_contents($logsFile), true) ?? []) : [];

// Load data.json for participant names
$dataFile = __DIR__ . '/data/data.json';
$participants = file_exists($dataFile) ? (json_decode(file_get_contents($dataFile), true) ?? []) : [];

// Build lookup
$participantNames = [];
foreach ($participants as $p) {
    $wasel = $p['wasel'] ?? '';
    if (!empty($wasel)) {
        $participantNames[$wasel] = $p['full_name'] ?? $p['name'] ?? '??? ?????';
    }
}

// Sort by timestamp desc
usort($logs, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));

// Output CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="rounds_logs_' . date('Y-m-d') . '.csv"');

// BOM for Excel Arabic
echo "\xEF\xBB\xBF";

// Headers
echo "#,?????,???????,??? ???????,??????,???????,??????,??????\n";

$count = 0;
foreach ($logs as $log) {
    $count++;
    $pid = $log['participant_id'] ?? '';
    $pName = $participantNames[$pid] ?? '??? ?????';
    $timestamp = $log['timestamp'] ?? 0;
    $time = $timestamp ? date('Y-m-d H:i:s', $timestamp) : '-';
    $action = ($log['action'] ?? '') === 'enter' ? '????' : '????';
    
    echo implode(',', [
        $count,
        '"' . $time . '"',
        '"' . $pName . '"',
        $pid,
        $log['round_id'] ?? '-',
        $action,
        '"' . ($log['device'] ?? '-') . '"',
        '"' . ($log['scanned_by'] ?? '-') . '"'
    ]) . "\n";
}
