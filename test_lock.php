<?php
header('Content-Type: application/json; charset=utf-8');

$result = ['time' => date('Y-m-d H:i:s')];

// Check queue file lock
$queueFile = __DIR__ . '/admin/data/whatsapp_queue.json';
if (file_exists($queueFile)) {
    $fp = @fopen($queueFile, 'c');
    if ($fp) {
        $locked = flock($fp, LOCK_EX | LOCK_NB);
        if ($locked) {
            $result['queue_lock'] = 'FREE';
            flock($fp, LOCK_UN);
        } else {
            $result['queue_lock'] = '⚠️ LOCKED by another process!';
        }
        fclose($fp);
    }
    $result['queue_size'] = filesize($queueFile);
    $q = json_decode(file_get_contents($queueFile), true) ?: [];
    $pending = array_filter($q, fn($m) => ($m['status'] ?? '') === 'pending');
    $sent = array_filter($q, fn($m) => ($m['status'] ?? '') === 'sent');
    $failed = array_filter($q, fn($m) => ($m['status'] ?? '') === 'failed');
    $result['queue_pending'] = count($pending);
    $result['queue_sent'] = count($sent);
    $result['queue_failed'] = count($failed);
    $result['queue_total'] = count($q);
} else {
    $result['queue'] = 'File not found';
}

// Check data.json lock
$dataFile = 'admin/data/data.json';
$fp2 = @fopen($dataFile, 'c');
if ($fp2) {
    $locked2 = flock($fp2, LOCK_EX | LOCK_NB);
    if ($locked2) {
        $result['data_lock'] = 'FREE';
        flock($fp2, LOCK_UN);
    } else {
        $result['data_lock'] = '⚠️ LOCKED!';
    }
    fclose($fp2);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
