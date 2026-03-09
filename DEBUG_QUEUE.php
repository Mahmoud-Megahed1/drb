<?php
$queueFile = 'admin/data/message_queue.json';
if (file_exists($queueFile)) {
    $queue = json_decode(file_get_contents($queueFile), true);
    echo "=== MESSAGE QUEUE (First 20) ===\n";
    $count = 0;
    foreach ($queue as $msg) {
        if ($count++ > 20) break;
        echo "ID: {$msg['id']} | Phone: {$msg['phone']} | Type: {$msg['type']} | Status: {$msg['status']} | Last Retry: " . ($msg['last_retry'] ?? 'N/A') . "\n";
    }
    echo "Total in queue: " . count($queue) . "\n";
} else {
    echo "Queue file not found!\n";
}
