<?php
/**
 * Migration Script: JSON Queue → SQLite messages table
 * =====================================================
 * Run this ONCE after deploying the v2.0 messaging system.
 * It imports pending/failed messages from old JSON files into the new `messages` table.
 * 
 * Usage: access via browser (authenticated admin) or CLI:
 *   php migrate_messages.php
 */

// CLI or web?
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    session_start();
    if (!isset($_SESSION['user'])) {
        die('Unauthorized. Please log in first.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/include/WhatsAppLogger.php';
require_once __DIR__ . '/include/db.php';

echo "=== Message System Migration ===\n\n";

$logger = new WhatsAppLogger();
$pdo = db();
$migrated = 0;
$skipped = 0;
$errors = 0;

// 1. Migrate from message_queue.json (main queue)
$queueFile = __DIR__ . '/admin/data/message_queue.json';
if (file_exists($queueFile)) {
    $queue = json_decode(file_get_contents($queueFile), true) ?? [];
    echo "📋 Found " . count($queue) . " messages in message_queue.json\n";
    
    foreach ($queue as $msg) {
        try {
            $phone = $msg['phone'] ?? '';
            if (empty($phone)) { $skipped++; continue; }
            
            // Map old status
            $oldStatus = $msg['status'] ?? 'pending';
            $newStatus = 'queued';
            if ($oldStatus === 'sent') $newStatus = 'sent';
            elseif ($oldStatus === 'failed_permanent') $newStatus = 'failed_permanent';
            elseif ($oldStatus === 'processing') $newStatus = 'queued';
            
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO messages 
                (phone, recipient_name, wasel, message_type, content_type, message_preview, api_payload, 
                 status, error_message, attempts, max_attempts, next_retry_at, created_at, sent_at, country_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $apiPayload = $msg['data'] ?? [];
            $preview = mb_substr($apiPayload['text'] ?? $apiPayload['caption'] ?? '', 0, 200);
            $name = $msg['extra']['name'] ?? $msg['name'] ?? null;
            $wasel = $msg['extra']['wasel'] ?? $msg['wasel'] ?? null;
            
            $stmt->execute([
                $phone,
                $name,
                $wasel,
                $msg['type'] ?? $msg['extra']['type'] ?? 'text',
                (isset($apiPayload['imageUrl'])) ? 'image' : ((isset($apiPayload['document'])) ? 'document' : 'text'),
                $preview,
                json_encode($apiPayload, JSON_UNESCAPED_UNICODE),
                $newStatus,
                $msg['error'] ?? null,
                $msg['attempts'] ?? 0,
                $msg['max_attempts'] ?? 5,
                ($newStatus === 'queued') ? $now : null,
                $msg['created_at'] ?? $msg['queued_at'] ?? $now,
                $msg['sent_at'] ?? null,
                '+964'
            ]);
            
            $migrated++;
            echo "  ✅ Migrated: {$phone} ({$oldStatus} → {$newStatus})\n";
        } catch (Exception $e) {
            $errors++;
            echo "  ❌ Error: {$phone} - " . $e->getMessage() . "\n";
        }
    }
    
    // Rename old file (backup, don't delete)
    @rename($queueFile, $queueFile . '.bak.' . date('Ymd_His'));
    echo "  📁 Old queue file backed up\n\n";
} else {
    echo "ℹ️ No message_queue.json found (skipped)\n\n";
}

// 2. Migrate from whatsapp_failed_queue.json
$failedFile = __DIR__ . '/admin/data/whatsapp_failed_queue.json';
if (file_exists($failedFile)) {
    $failed = json_decode(file_get_contents($failedFile), true) ?? [];
    echo "📋 Found " . count($failed) . " messages in whatsapp_failed_queue.json\n";
    
    foreach ($failed as $msg) {
        try {
            $phone = $msg['phone'] ?? '';
            if (empty($phone)) { $skipped++; continue; }
            
            // Check if already migrated (by phone + type + timestamp match)
            $check = $pdo->prepare("SELECT id FROM messages WHERE phone = ? AND message_type = ? AND created_at = ? LIMIT 1");
            $check->execute([$phone, $msg['message_type'] ?? 'text', $msg['timestamp'] ?? $msg['queued_at'] ?? '']);
            if ($check->fetchColumn()) { $skipped++; continue; }
            
            $oldStatus = $msg['status'] ?? 'pending';
            $newStatus = 'failed_permanent';
            if ($oldStatus === 'pending') $newStatus = 'queued';
            
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO messages 
                (phone, recipient_name, wasel, message_type, content_type, message_preview, 
                 status, error_message, attempts, max_attempts, next_retry_at, created_at, country_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $phone,
                $msg['recipient_name'] ?? null,
                $msg['wasel'] ?? null,
                $msg['message_type'] ?? 'text',
                'text',
                null,
                $newStatus,
                $msg['error'] ?? $msg['failed_reason'] ?? 'Migrated from failed queue',
                $msg['retry_count'] ?? 0,
                5,
                ($newStatus === 'queued') ? $now : null,
                $msg['timestamp'] ?? $msg['queued_at'] ?? $now,
                $msg['country_code'] ?? '+964'
            ]);
            
            $migrated++;
            echo "  ✅ Migrated failed: {$phone}\n";
        } catch (Exception $e) {
            $errors++;
            echo "  ❌ Error: {$phone} - " . $e->getMessage() . "\n";
        }
    }
    
    @rename($failedFile, $failedFile . '.bak.' . date('Ymd_His'));
    echo "  📁 Old failed queue file backed up\n\n";
} else {
    echo "ℹ️ No whatsapp_failed_queue.json found (skipped)\n\n";
}

// 3. Summary
echo "=== Migration Complete ===\n";
echo "✅ Migrated: {$migrated}\n";
echo "⏭️ Skipped: {$skipped}\n";
echo "❌ Errors: {$errors}\n";

// Verify
$count = $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
echo "\n📊 Total messages in DB: {$count}\n";

$pending = $pdo->query("SELECT COUNT(*) FROM messages WHERE status = 'queued'")->fetchColumn();
echo "📨 Pending (queued): {$pending}\n";

echo "\n✅ Migration done. Old JSON files have been backed up with .bak extension.\n";
echo "🔒 You can safely delete the .bak files after verifying everything works.\n";
