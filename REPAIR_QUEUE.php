<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/include/db.php';
$queueFile = __DIR__ . '/admin/data/message_queue.json';

if (!file_exists($queueFile)) {
    die("Queue file not found at: $queueFile");
}

$content = file_get_contents($queueFile);
$queue = json_decode($content, true) ?: [];
$resetCount = 0;
$migratedCount = 0;
$usedDbIds = [];

// AGGRESSIVE: Clear all existing db_id to re-verify and fix collisions
foreach ($queue as &$msg) {
    unset($msg['db_id']);
    if (isset($msg['extra'])) unset($msg['extra']['db_id']);
}

try {
    $pdo = db();
    
    foreach ($queue as &$msg) {
        $phone = $msg['phone'] ?? null;
        $type = $msg['type'] ?? null;
        if (isset($msg['extra']['type'])) $type = $msg['extra']['type'];
        
        if (!empty($phone)) {
            // Find a unique, type-appropriate DB log entry
            $sql = "SELECT id, message_type FROM whatsapp_logs 
                    WHERE phone = ? 
                    AND error_message = 'Queued for sending' 
                    AND success = 0";
            
            if (!empty($usedDbIds)) {
                $placeholders = implode(',', array_fill(0, count($usedDbIds), '?'));
                $sql .= " AND id NOT IN ($placeholders)";
            }
            
            // Prioritize matching by message_type (exact or broad)
            $stmt = $pdo->prepare($sql . " ORDER BY (CASE WHEN message_type = ? THEN 0 ELSE 1 END) ASC, id ASC LIMIT 1");
            
            $params = [$phone];
            if (!empty($usedDbIds)) $params = array_merge($params, $usedDbIds);
            $params[] = $type;
            
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                $dbId = $row['id'];
                $msg['db_id'] = $dbId;
                if (!isset($msg['extra'])) $msg['extra'] = [];
                $msg['extra']['db_id'] = $dbId;
                $usedDbIds[] = $dbId; 
                $migratedCount++;
            }
        }
        
        // Reset stuck processing to pending
        if (($msg['status'] ?? '') === 'processing' || ($msg['status'] ?? '') === 'pending') {
            $msg['status'] = 'pending';
            $resetCount++;
        }
    }
} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}

if (file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo "Successfully reset $resetCount messages to pending.\n";
    echo "Reassigned/Migrated $migratedCount unique DB IDs in queue.\n";
} else {
    echo "Failed to write to $queueFile.\n";
}

// Trigger worker
$workerUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api/whatsapp_worker.php';
echo "Triggering worker at: $workerUrl\n";
$ch = curl_init($workerUrl);
curl_setopt($ch, CURLOPT_TIMEOUT, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
@curl_exec($ch);
curl_close($ch);
echo "Worker Triggered.";
