<?php
require_once 'include/db.php';
$pdo = db();
$logs = $pdo->query("SELECT * FROM whatsapp_logs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "=== RECENT WHATSAPP LOGS ===\n";
foreach ($logs as $log) {
    echo "ID: {$log['id']} | Phone: {$log['phone']} | Type: {$log['message_type']} | Success: {$log['success']} | Msg: {$log['error_message']} | Time: {$log['created_at']}\n";
    echo "Details: {$log['details']}\n";
    echo "---------------------------------\n";
}
