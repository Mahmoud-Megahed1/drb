<?php
/**
 * WhatsApp Message Logger v2.0
 * ============================
 * SINGLE SOURCE OF TRUTH for all message state.
 * Replaces: whatsapp_logs table, whatsapp_log.json, whatsapp_failed_queue.json, message_queue.json
 * 
 * Message Lifecycle: queued → sending → sent/failed → (retry) → sent/failed_permanent
 */
date_default_timezone_set('Asia/Baghdad');

class WhatsAppLogger {
    private $pdo;

    // Status constants
    const STATUS_QUEUED    = 'queued';
    const STATUS_SENDING   = 'sending';
    const STATUS_SENT      = 'sent';
    const STATUS_FAILED    = 'failed';
    const STATUS_FAILED_PERMANENT = 'failed_permanent';

    // Retry backoff schedule (seconds): 1min, 5min, 15min, 30min, 60min
    const BACKOFF_SCHEDULE = [60, 300, 900, 1800, 3600];
    const DEFAULT_MAX_ATTEMPTS = 5;

    public function __construct() {
        require_once __DIR__ . '/db.php';
        try {
            $this->pdo = db();
            $this->initTable();
        } catch (Exception $e) {
            error_log("WhatsAppLogger: DB init failed: " . $e->getMessage());
            throw $e; // Can't work without DB
        }
    }

    /**
     * Create/upgrade the messages table
     */
    private function initTable() {
        // Create new unified messages table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            
            -- Identity
            phone TEXT NOT NULL,
            recipient_name TEXT,
            wasel TEXT,
            registration_code TEXT,
            
            -- Content
            message_type TEXT NOT NULL,
            content_type TEXT DEFAULT 'text',
            message_preview TEXT,
            api_payload TEXT,
            
            -- Status Lifecycle
            status TEXT NOT NULL DEFAULT 'queued',
            error_message TEXT,
            is_manual INTEGER DEFAULT 0,
            
            -- Retry
            attempts INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 5,
            next_retry_at DATETIME,
            
            -- External
            api_response TEXT,
            external_id TEXT,
            api_key_used TEXT,
            
            -- Bulk tracking
            batch_id TEXT,
            
            -- Idempotency
            idempotency_key TEXT UNIQUE,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sending_at DATETIME,
            sent_at DATETIME,
            failed_at DATETIME,
            
            -- Admin
            confirmed_by TEXT,
            country_code TEXT DEFAULT '+964'
        )");

        // Create indexes
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_status ON messages(status)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_phone ON messages(phone)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_batch ON messages(batch_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_created ON messages(created_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_next_retry ON messages(next_retry_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_idempotency ON messages(idempotency_key)");
    }

    // ==================== CORE OPERATIONS ====================

    /**
     * Queue a new message for sending.
     * This is the ONLY way to create a message. Returns the message ID.
     */
    public function queueMessage($phone, $messageType, $contentType, $apiPayload, $extras = [], $batchId = null) {
        $preview = mb_substr($apiPayload['text'] ?? $apiPayload['caption'] ?? '', 0, 200);
        $name = $extras['name'] ?? $extras['full_name'] ?? null;
        $wasel = $extras['wasel'] ?? null;
        $regCode = $extras['registration_code'] ?? null;
        $countryCode = $extras['country_code'] ?? '+964';
        
        // Idempotency key: prevent duplicate sends for same phone + type within 5 seconds
        $idempotencyKey = md5($phone . '|' . $messageType . '|' . substr($preview, 0, 50) . '|' . floor(time() / 5));
        
        // Check for duplicate
        $stmt = $this->pdo->prepare("SELECT id FROM messages WHERE idempotency_key = ?");
        $stmt->execute([$idempotencyKey]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int)$existing; // Return existing ID, don't create duplicate
        }
        
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("INSERT INTO messages 
            (phone, recipient_name, wasel, registration_code, message_type, content_type, message_preview, api_payload, status, attempts, max_attempts, next_retry_at, batch_id, idempotency_key, created_at, country_code)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $phone,
            $name,
            $wasel,
            $regCode,
            $messageType,
            $contentType,
            $preview,
            json_encode($apiPayload, JSON_UNESCAPED_UNICODE),
            self::STATUS_QUEUED,
            self::DEFAULT_MAX_ATTEMPTS,
            $now,  // next_retry_at = now (ready immediately)
            $batchId,
            $idempotencyKey,
            $now,
            $countryCode
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Pick the next queued message for processing.
     * Uses status transition guard for concurrency safety.
     * Returns the message array or null if none available.
     */
    public function pickNextMessage() {
        $now = date('Y-m-d H:i:s');
        
        // Recovery: reset stale 'sending' messages (stuck > 3 minutes)
        $staleTime = date('Y-m-d H:i:s', time() - 180);
        $this->pdo->prepare("UPDATE messages SET status = 'queued', error_message = 'Worker timeout recovery' WHERE status = 'sending' AND sending_at < ?")->execute([$staleTime]);
        
        // Auto-fail messages that exceeded max_attempts while still queued
        $this->pdo->exec("UPDATE messages SET status = 'failed_permanent', error_message = 'Max attempts exceeded', failed_at = '$now' WHERE status = 'queued' AND attempts >= max_attempts");
        
        // Pick next ready message (oldest first)
        // STATUS TRANSITION GUARD: Only update if still 'queued' (prevents double-pick)
        $stmt = $this->pdo->prepare("SELECT id FROM messages WHERE status = 'queued' AND next_retry_at <= ? ORDER BY created_at ASC LIMIT 1");
        $stmt->execute([$now]);
        $id = $stmt->fetchColumn();
        
        if (!$id) return null;
        
        // Atomic status transition: queued → sending (only if still queued)
        $stmt = $this->pdo->prepare("UPDATE messages SET status = 'sending', sending_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'queued'");
        $stmt->execute([$now, $id]);
        
        if ($stmt->rowCount() === 0) {
            return null; // Another worker grabbed it
        }
        
        // Fetch full message
        $stmt = $this->pdo->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Mark message as successfully sent
     */
    public function markSent($id, $apiResponse = null, $apiKeyUsed = null) {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("UPDATE messages SET 
            status = 'sent', 
            sent_at = ?, 
            error_message = NULL, 
            api_response = ?,
            api_key_used = ?
            WHERE id = ?");
        $stmt->execute([$now, $apiResponse ? json_encode($apiResponse) : null, $apiKeyUsed, $id]);
    }

    /**
     * Mark message as failed (will retry if attempts < max)
     */
    public function markFailed($id, $error, $currentAttempts, $maxAttempts = null) {
        $now = date('Y-m-d H:i:s');
        $max = $maxAttempts ?? self::DEFAULT_MAX_ATTEMPTS;
        
        if ($currentAttempts >= $max) {
            // Permanent failure
            $stmt = $this->pdo->prepare("UPDATE messages SET 
                status = 'failed_permanent', 
                error_message = ?, 
                failed_at = ?
                WHERE id = ?");
            $stmt->execute([$error, $now, $id]);
        } else {
            // Schedule retry with exponential backoff
            $backoffIndex = min($currentAttempts - 1, count(self::BACKOFF_SCHEDULE) - 1);
            $delay = self::BACKOFF_SCHEDULE[max(0, $backoffIndex)];
            $nextRetry = date('Y-m-d H:i:s', time() + $delay);
            
            $stmt = $this->pdo->prepare("UPDATE messages SET 
                status = 'queued', 
                error_message = ?, 
                failed_at = ?,
                next_retry_at = ?
                WHERE id = ?");
            $stmt->execute([$error, $now, $nextRetry, $id]);
        }
    }

    /**
     * Mark message as rate-limited (special retry)
     */
    public function markRateLimited($id, $retryAfter = 10) {
        $nextRetry = date('Y-m-d H:i:s', time() + $retryAfter);
        $stmt = $this->pdo->prepare("UPDATE messages SET 
            status = 'queued', 
            error_message = 'Rate limit (429)',
            attempts = MAX(0, attempts - 1),
            next_retry_at = ?
            WHERE id = ?");
        $stmt->execute([$nextRetry, $id]);
    }

    /**
     * Manual confirmation: Admin clicks "Mark as Sent"
     */
    public function markAsSentManual($id, $adminUsername) {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("UPDATE messages SET 
            status = 'sent', 
            is_manual = 1,
            sent_at = ?, 
            confirmed_by = ?,
            error_message = NULL
            WHERE id = ? AND status IN ('queued', 'failed', 'failed_permanent')");
        $stmt->execute([$now, $adminUsername, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reset a failed message back to queued for retry
     */
    public function resetToQueued($id) {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("UPDATE messages SET 
            status = 'queued', 
            next_retry_at = ?,
            error_message = 'Manual retry'
            WHERE id = ? AND status IN ('failed', 'failed_permanent')");
        $stmt->execute([$now, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a message from queue
     */
    public function deleteMessage($id) {
        $stmt = $this->pdo->prepare("DELETE FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // ==================== QUERIES ====================

    /**
     * Get messages by status filter
     */
    public function getMessages($filter = 'all', $limit = 200) {
        $sql = "SELECT * FROM messages";
        $params = [];
        
        if ($filter === 'pending') {
            $sql .= " WHERE status IN ('queued', 'sending', 'failed')";
        } elseif ($filter !== 'all') {
            $sql .= " WHERE status = ?";
            $params[] = $filter;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get count by status
     */
    public function getStatusCounts() {
        $stmt = $this->pdo->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
            SUM(CASE WHEN status = 'sending' THEN 1 ELSE 0 END) as sending,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'failed_permanent' THEN 1 ELSE 0 END) as failed_permanent,
            SUM(CASE WHEN is_manual = 1 THEN 1 ELSE 0 END) as manual_sent
        FROM messages");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get pending count (queued + sending + failed-waiting-retry)
     */
    public function getPendingCount() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM messages WHERE status IN ('queued', 'sending', 'failed')");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get statistics
     */
    public function getStats() {
        $counts = $this->getStatusCounts();
        
        // By type
        $stmt = $this->pdo->query("SELECT message_type, COUNT(*) as cnt, 
            SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as success_cnt 
            FROM messages GROUP BY message_type");
        $byType = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byType[$r['message_type']] = [
                'total' => $r['cnt'],
                'success' => $r['success_cnt'],
                'failed' => $r['cnt'] - $r['success_cnt']
            ];
        }
        
        // Common errors
        $stmt = $this->pdo->query("SELECT error_message, COUNT(*) as cnt FROM messages 
            WHERE error_message IS NOT NULL AND error_message != '' 
            GROUP BY error_message ORDER BY cnt DESC LIMIT 10");
        $errors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $errors[$r['error_message']] = $r['cnt'];
        }
        
        // Last success
        $stmt = $this->pdo->query("SELECT sent_at FROM messages WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1");
        $lastSuccess = $stmt->fetchColumn() ?: null;
        
        $sentCount = (int)($counts['sent'] ?? 0);
        $failedRetryable = (int)($counts['failed'] ?? 0);
        $failedPermanent = (int)($counts['failed_permanent'] ?? 0);
        $totalCount = (int)($counts['total'] ?? 0);
        
        return [
            'total' => $totalCount,
            'queued' => (int)($counts['queued'] ?? 0),
            'sending' => (int)($counts['sending'] ?? 0),
            'sent' => $sentCount,
            'success' => $sentCount,  // backward-compat alias for whatsapp_log.php
            'failed' => $failedRetryable + $failedPermanent,  // combined for backward-compat
            'failed_retryable' => $failedRetryable,
            'failed_permanent' => $failedPermanent,
            'manual_sent' => (int)($counts['manual_sent'] ?? 0),
            'success_rate' => ($totalCount > 0) ? round(($sentCount / $totalCount) * 100, 1) : 0,
            'by_type' => $byType,
            'common_errors' => $errors,
            'last_success' => $lastSuccess,
            'pending_count' => (int)($counts['queued'] ?? 0) + (int)($counts['sending'] ?? 0)
        ];
    }

    /**
     * Get message lifecycle trace for debugging
     */
    public function getMessageTrace($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msg) return null;
        
        return [
            'message' => $msg,
            'timeline' => [
                'created' => $msg['created_at'],
                'last_sending' => $msg['sending_at'],
                'sent' => $msg['sent_at'],
                'failed' => $msg['failed_at'],
                'next_retry' => $msg['next_retry_at']
            ],
            'retry_info' => [
                'attempts' => $msg['attempts'],
                'max_attempts' => $msg['max_attempts'],
                'is_manual' => (bool)$msg['is_manual'],
                'confirmed_by' => $msg['confirmed_by']
            ]
        ];
    }

    /**
     * Get logs for display (backward-compatible with old getLogs format)
     */
    public function getLogs($filters = []) {
        $sql = "SELECT * FROM messages WHERE 1=1";
        $params = [];
        
        if (isset($filters['success'])) {
            if ($filters['success']) {
                $sql .= " AND status = 'sent'";
            } else {
                $sql .= " AND status IN ('failed', 'failed_permanent', 'queued')";
            }
        }
        if (!empty($filters['message_type'])) {
            $sql .= " AND message_type = ?";
            $params[] = $filters['message_type'];
        }
        if (!empty($filters['phone'])) {
            $sql .= " AND phone LIKE ?";
            $params[] = '%' . $filters['phone'] . '%';
        }
        if (!empty($filters['from_date'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }
        if (!empty($filters['to_date'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT 500";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map to backward-compatible format
        $logs = [];
        foreach ($rows as $row) {
            $logs[] = [
                'id' => $row['id'],
                'timestamp' => $row['created_at'],
                'created_at' => $row['created_at'],
                'phone' => $row['phone'],
                'message_type' => $row['message_type'],
                'success' => ($row['status'] === 'sent'),
                'error' => $row['error_message'],
                'error_message' => $row['error_message'],  // backward-compat alias
                'recipient_name' => $row['recipient_name'],
                'wasel' => $row['wasel'],
                'registration_code' => $row['registration_code'],
                'status' => $row['status']
            ];
        }
        return $logs;
    }

    /**
     * Clear sent messages older than N days
     */
    public function clearOldMessages($days = 30) {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt = $this->pdo->prepare("DELETE FROM messages WHERE status = 'sent' AND sent_at < ?");
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Clear all sent and permanently failed messages
     */
    public function clearCompleted() {
        $stmt = $this->pdo->prepare("DELETE FROM messages WHERE status IN ('sent', 'failed_permanent')");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Get failed queue (backward-compatible)
     */
    public function getFailedQueue($status = null) {
        if ($status === 'pending') {
            return $this->getMessages('queued');
        }
        return $this->getMessages($status ?? 'failed_permanent');
    }

    // ==================== LEGACY BACKWARD-COMPATIBLE METHODS ====================
    // Used by admin/whatsapp_log.php

    /**
     * Legacy: log() - used by whatsapp_log.php retry flow
     * In v2, logging is handled by queueMessage/markSent/markFailed.
     * This shim exists for code that still calls log() directly.
     */
    public function log($phone, $messageType, $success, $errorMessage = null, $details = []) {
        // If successful, this is probably a manual success update
        if ($success) {
            // Try to find an existing queued/failed message for this phone
            $stmt = $this->pdo->prepare("SELECT id FROM messages WHERE phone = ? AND status IN ('queued', 'failed', 'sending') ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$phone]);
            $id = $stmt->fetchColumn();
            if ($id) {
                $this->markSent($id);
                return ['id' => $id, 'phone' => $phone, 'message_type' => $messageType, 'success' => true];
            }
        }
        
        // For queued messages, create a new queue entry
        if ($errorMessage === 'Queued for sending') {
            $msgId = $this->queueMessage($phone, $messageType, 'text', ['to' => $phone, 'text' => ''], $details);
            return ['id' => $msgId, 'db_id' => $msgId, 'phone' => $phone, 'message_type' => $messageType, 'success' => false];
        }
        
        // For failed messages, update existing or create entry
        $stmt = $this->pdo->prepare("SELECT id, attempts, max_attempts FROM messages WHERE phone = ? AND status IN ('queued', 'failed', 'sending') ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$phone]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $this->markFailed($existing['id'], $errorMessage ?? 'Unknown', $existing['attempts'], $existing['max_attempts']);
            return ['id' => $existing['id'], 'phone' => $phone, 'message_type' => $messageType, 'success' => false];
        }
        
        return ['id' => null, 'phone' => $phone, 'message_type' => $messageType, 'success' => $success];
    }

    /**
     * Legacy: retryMessage() - returns the message data for re-sending
     * Used by whatsapp_log.php retry flow
     */
    public function retryMessage($messageId) {
        $stmt = $this->pdo->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msg) return null;
        
        // Return in legacy format expected by whatsapp_log.php
        return [
            'id' => $msg['id'],
            'phone' => $msg['phone'],
            'message_type' => $msg['message_type'],
            'recipient_name' => $msg['recipient_name'],
            'wasel' => $msg['wasel'],
            'country_code' => $msg['country_code'],
            'retry_count' => $msg['attempts'],
            'status' => $msg['status'],
            'error' => $msg['error_message']
        ];
    }

    /**
     * Legacy: markAsPermanentlyFailed()
     * Used by whatsapp_log.php
     */
    public function markAsPermanentlyFailed($messageId, $reason = null) {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("UPDATE messages SET status = 'failed_permanent', error_message = ?, failed_at = ? WHERE id = ?");
        $stmt->execute([$reason ?? 'Marked as permanently failed', $now, $messageId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Legacy: removeFromQueue() - alias for deleteMessage
     */
    public function removeFromQueue($messageId) {
        return $this->deleteMessage($messageId);
    }

    /**
     * Legacy: clearOldLogs() - alias for clearOldMessages
     */
    public function clearOldLogs($days = 30) {
        return $this->clearOldMessages($days);
    }

    /**
     * Reset all failed/failed_permanent messages back to queued
     * Used by WaSender::retryAll()
     */
    public function resetAllFailed($limit = 10, $now = null) {
        $now = $now ?? date('Y-m-d H:i:s');
        // SQLite doesn't support LIMIT in UPDATE, so use subquery
        $stmt = $this->pdo->prepare("UPDATE messages SET 
            status = 'queued', 
            next_retry_at = ?,
            attempts = 0,
            error_message = 'Manual retry all'
            WHERE id IN (SELECT id FROM messages WHERE status IN ('failed', 'failed_permanent') ORDER BY created_at ASC LIMIT ?)");
        $stmt->execute([$now, $limit]);
        return $stmt->rowCount();
    }

    /**
     * Get earliest next_retry_at for messages waiting to retry
     * Used by processQueueLoop to decide whether to wait or exit
     */
    public function getNextRetryTime() {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("SELECT MIN(next_retry_at) FROM messages WHERE status = 'queued' AND next_retry_at > ?");
        $stmt->execute([$now]);
        return $stmt->fetchColumn() ?: null;
    }
}

// Message type constants (backward-compatible)
if (!defined('WHATSAPP_MSG_REGISTRATION')) {
    define('WHATSAPP_MSG_REGISTRATION', 'registration_received');
    define('WHATSAPP_MSG_ACCEPTANCE', 'acceptance');
    define('WHATSAPP_MSG_UNIFIED_APPROVAL', 'approval_badge_unified');
    define('WHATSAPP_MSG_BADGE', 'badge');
    define('WHATSAPP_MSG_QR_ONLY', 'qr_only');
    define('WHATSAPP_MSG_REJECTION', 'rejection');
    define('WHATSAPP_MSG_BROADCAST', 'broadcast');
    define('WHATSAPP_MSG_REMINDER', 'reminder');
    define('WHATSAPP_MSG_ACTIVATION', 'activation');
}
