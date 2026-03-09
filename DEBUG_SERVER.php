<?php
header('Content-Type: text/plain');
echo "=== WORKER LOG ===\n";
$logFile = 'admin/data/worker_debug.log';
if (file_exists($logFile)) echo file_get_contents($logFile);
else echo "Worker Log Not Found.\n";

echo "\n=== QUEUE (FULL) ===\n";
$queueFile = 'admin/data/message_queue.json';
if (file_exists($queueFile)) {
    $queue = json_decode(file_get_contents($queueFile), true);
    foreach ($queue as $msg) {
        echo "ID: {$msg['id']} | Phone: {$msg['phone']} | Type: {$msg['type']} | Status: {$msg['status']} | DB_ID: " . ($msg['db_id'] ?? 'NONE') . "\n";
    }
} else {
    echo "Queue not found.\n";
}
