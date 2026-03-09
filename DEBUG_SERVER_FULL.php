<?php
header('Content-Type: text/plain; charset=utf-8');
date_default_timezone_set('Asia/Baghdad');

echo "=== SERVER TIME ===\n";
echo date('Y-m-d H:i:s') . "\n\n";

echo "=== WORKER DEBUG LOG (Last 50 lines) ===\n";
$workerLog = 'admin/data/worker_debug.log';
if (file_exists($workerLog)) {
    $lines = file($workerLog);
    echo implode("", array_slice($lines, -50));
} else {
    echo "Worker Log Not Found.\n";
}

echo "\n=== WASENDER API LOG (Last 20 lines) ===\n";
$apiLog = 'admin/data/whatsapp_log.txt';
if (file_exists($apiLog)) {
    $lines = file($apiLog);
    echo implode("", array_slice($lines, -20));
} else {
    echo "API Log Not Found.\n";
}

echo "\n=== MESSAGE QUEUE (FULL) ===\n";
$queueFile = 'admin/data/message_queue.json';
if (file_exists($queueFile)) {
    $queue = json_decode(file_get_contents($queueFile), true) ?: [];
    echo "Total in queue: " . count($queue) . "\n";
    foreach ($queue as $msg) {
        $lastRetry = $msg['last_retry'] ?? 'N/A';
        echo "ID: {$msg['id']} | Phone: {$msg['phone']} | Type: {$msg['type']} | Status: {$msg['status']} | DB_ID: " . ($msg['db_id'] ?? 'NONE') . " | Last: $lastRetry\n";
    }
} else {
    echo "Queue not found.\n";
}
