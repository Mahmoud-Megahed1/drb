<?php
/**
 * WhatsApp Message Logger
 * Handles logging messages to DB and JSON
 */
date_default_timezone_set('Asia/Baghdad');

class WhatsAppLogger {
    private $pdo;
    private $logFile;
    private $failedQueueFile;

    public function __construct() {
        $dataDir = __DIR__ . '/../admin/data';
        $this->logFile = $dataDir . '/whatsapp_log.json';
        $this->failedQueueFile = $dataDir . '/whatsapp_failed_queue.json';
        
        // Create files if not exist
        if (!file_exists($this->logFile)) $this->initFile($this->logFile);
        if (!file_exists($this->failedQueueFile)) $this->initFile($this->failedQueueFile);

        // Init DB
        require_once __DIR__ . '/db.php';
        try {
            $this->pdo = db();
            $this->initTable();
        } catch (Exception $e) {
            // Fallback
        }
    }

    private function initTable() {
        $sql = "CREATE TABLE IF NOT EXISTS whatsapp_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone TEXT,
            message_type TEXT,
            success INTEGER,
            error_message TEXT,
            recipient_name TEXT,
            details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
    }
    
    private function initFile($file) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));
    }
    
    public function log($phone, $messageType, $success, $errorMessage = null, $details = []) {
        $dbId = null;
        // 1. Log to DB
        if ($this->pdo) {
            try {
                $dbUpdated = false;
                if ($errorMessage !== 'Queued for sending') {
                    // Try to update existing Queued entry based on DB ID if provided
                    $dbUpdated = false;
                    $currentTime = date('Y-m-d H:i:s');
                    
                    if (!empty($details['db_id'])) {
                        $stmt = $this->pdo->prepare("UPDATE whatsapp_logs SET message_type = ?, success = ?, error_message = ?, details = ?, created_at = ? WHERE id = ?");
                        $stmt->execute([
                            $messageType,
                            $success ? 1 : 0,
                            $errorMessage,
                            json_encode($details, JSON_UNESCAPED_UNICODE),
                            $currentTime,
                            $details['db_id']
                        ]);
                        if ($stmt->rowCount() > 0) {
                            $dbUpdated = true;
                            $dbId = $details['db_id'];
                        }
                    }
                    
                    if (!$dbUpdated) {
                        $stmt = $this->pdo->prepare("UPDATE whatsapp_logs SET message_type = ?, success = ?, error_message = ?, details = ?, created_at = ? WHERE id = (SELECT id FROM whatsapp_logs WHERE phone = ? AND error_message = 'Queued for sending' AND success = 0 ORDER BY id ASC LIMIT 1)");
                        $stmt->execute([
                            $messageType,
                            $success ? 1 : 0,
                            $errorMessage,
                            json_encode($details, JSON_UNESCAPED_UNICODE),
                            $currentTime,
                            $phone
                        ]);
                        if ($stmt->rowCount() > 0) {
                            $dbUpdated = true;
                            // Get the ID of the updated row (careful with NULL error_message in SQLite)
                            if ($errorMessage === null) {
                                $stmtId = $this->pdo->prepare("SELECT id FROM whatsapp_logs WHERE phone = ? AND success = ? AND error_message IS NULL ORDER BY id DESC LIMIT 1");
                                $stmtId->execute([$phone, $success ? 1 : 0]);
                            } else {
                                $stmtId = $this->pdo->prepare("SELECT id FROM whatsapp_logs WHERE phone = ? AND success = ? AND error_message = ? ORDER BY id DESC LIMIT 1");
                                $stmtId->execute([$phone, $success ? 1 : 0, $errorMessage]);
                            }
                            $dbId = $stmtId->fetchColumn();
                        }
                    }
                }
                
                if (!$dbUpdated) {
                    $currentTime = date('Y-m-d H:i:s');
                    $stmt = $this->pdo->prepare("INSERT INTO whatsapp_logs (phone, message_type, success, error_message, recipient_name, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $phone,
                        $messageType,
                        $success ? 1 : 0,
                        $errorMessage,
                        $details['name'] ?? null,
                        json_encode($details, JSON_UNESCAPED_UNICODE),
                        $currentTime
                    ]);
                    $dbId = $this->pdo->lastInsertId();
                }
            } catch (Exception $e) {
                error_log("WhatsAppLogger DB Error: " . $e->getMessage());
            }
        }

        // 2. Log to JSON
        $logs = $this->getJsonLogs();
        $jsonUpdated = false;
        
        $entryInfo = [
            'timestamp' => date('Y-m-d H:i:s'),
            'phone' => $phone,
            'country_code' => $details['country_code'] ?? '+964',
            'message_type' => $messageType,
            'success' => $success,
            'error' => $errorMessage,
            'recipient_name' => $details['name'] ?? null,
            'wasel' => $details['wasel'] ?? null,
            'db_id' => $dbId, // NEW: Include DB ID in JSON entry
            'registration_code' => $details['registration_code'] ?? null,
            'retry_count' => $details['retry_count'] ?? 0,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $entry = null;
        
        if ($errorMessage !== 'Queued for sending') {
            for ($i = 0; $i < count($logs); $i++) {
                $jsonLog = $logs[$i];
                if (($jsonLog['phone'] ?? '') === $phone && 
                    !($jsonLog['success'] ?? true) && 
                    ($jsonLog['error'] ?? '') === 'Queued for sending') {
                    
                    // Update existing
                    $entry = array_merge($jsonLog, $entryInfo);
                    $logs[$i] = $entry;
                    $jsonUpdated = true;
                    
                    // Move to top
                    unset($logs[$i]);
                    array_unshift($logs, $entry);
                    
                    if (!empty($entry['id'])) {
                        $this->removeFromQueue($entry['id']);
                    }
                    break;
                }
            }
        }
        
        if (!$jsonUpdated) {
            $entry = array_merge(['id' => uniqid('msg_')], $entryInfo);
            array_unshift($logs, $entry);
        }
        
        if (count($logs) > 5000) $logs = array_slice($logs, 0, 5000);
        
        file_put_contents($this->logFile, json_encode(array_values($logs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        if (!$success && $errorMessage !== 'Queued for sending') {
            $this->addToFailedQueue($entry);
        }
        
        return $entry;
    }
    
    private function getJsonLogs() {
        if (!file_exists($this->logFile)) return [];
        return json_decode(file_get_contents($this->logFile), true) ?? [];
    }

    public function getLogs($filters = []) {
        $logs = [];

        // 1. Get from DB
        if ($this->pdo) {
            try {
                $sql = "SELECT * FROM whatsapp_logs WHERE 1=1";
                $params = [];
                
                if (isset($filters['success'])) {
                    $sql .= " AND success = ?";
                    $params[] = $filters['success'] ? 1 : 0;
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
                
                foreach($rows as $row) {
                    $details = json_decode($row['details'], true) ?? [];
                    $logs[] = [
                        'id' => $row['id'],
                        'timestamp' => $row['created_at'],
                        'phone' => $row['phone'],
                        'message_type' => $row['message_type'],
                        'success' => (bool)$row['success'],
                        'error' => $row['error_message'],
                        'recipient_name' => $row['recipient_name'],
                        'wasel' => $details['wasel'] ?? null,
                        'registration_code' => $details['registration_code'] ?? null
                    ];
                }
            } catch (Exception $e) {
                // Continue to JSON
            }
        }
        
        // 2. Merge with JSON (Legacy data)
        $jsonLogs = $this->getJsonLogs();
        
        // Apply filters to JSON logs
        if (isset($filters['success'])) {
            $jsonLogs = array_filter($jsonLogs, fn($log) => $log['success'] === $filters['success']);
        }
        if (!empty($filters['message_type'])) {
            $jsonLogs = array_filter($jsonLogs, fn($log) => ($log['message_type'] ?? '') === $filters['message_type']);
        }
        if (!empty($filters['phone'])) {
            $jsonLogs = array_filter($jsonLogs, fn($log) => strpos($log['phone'] ?? '', $filters['phone']) !== false);
        }
        if (!empty($filters['from_date'])) {
            $jsonLogs = array_filter($jsonLogs, fn($log) => ($log['timestamp'] ?? '') >= $filters['from_date']);
        }
        if (!empty($filters['to_date'])) {
            $jsonLogs = array_filter($jsonLogs, fn($log) => ($log['timestamp'] ?? '') <= $filters['to_date']);
        }
        
        // Merge and sort by timestamp (preventing duplicates between DB and JSON)
        $seen = [];
        foreach ($logs as $dbLog) {
            $key = ($dbLog['phone'] ?? '') . '_' . ($dbLog['message_type'] ?? '') . '_' . substr($dbLog['timestamp'] ?? '', 0, 16);
            $seen[$key] = true;
        }
        
        foreach ($jsonLogs as $jsonLog) {
            $key = ($jsonLog['phone'] ?? '') . '_' . ($jsonLog['message_type'] ?? '') . '_' . substr($jsonLog['timestamp'] ?? '', 0, 16);
            if (!isset($seen[$key])) {
                $logs[] = $jsonLog;
                $seen[$key] = true;
            }
        }
        
        usort($logs, fn($a, $b) => strtotime($b['timestamp'] ?? 0) - strtotime($a['timestamp'] ?? 0));
        
        return array_slice($logs, 0, 500);
    }
    
    /**
     * Get statistics
     */
    public function getStats() {
        // 1. Try DB Stats
        if ($this->pdo) {
            try {
                $stats = [
                    'total' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'success_rate' => 0,
                    'by_type' => [],
                    'common_errors' => [],
                    'pending_retry' => 0,
                    'permanently_failed' => 0
                ];

                // Total & Success/Failed
                $row = $this->pdo->query("SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN success = 0 AND error_message != 'Queued for sending' THEN 1 ELSE 0 END) as failed
                FROM whatsapp_logs")->fetch(PDO::FETCH_ASSOC);
                
                $stats['total'] = $row['total'];
                $stats['success'] = $row['success'] ?? 0;
                $stats['failed'] = $row['failed'] ?? 0;
                $stats['success_rate'] = ($stats['total'] - ($stats['total'] - ($stats['success'] + $stats['failed']))) > 0 ? 
                    round(($stats['success'] / ($stats['success'] + $stats['failed'])) * 100, 1) : 0;

                // By Type
                $rows = $this->pdo->query("SELECT message_type, COUNT(*) as cnt, SUM(CASE WHEN success=1 THEN 1 ELSE 0 END) as success_cnt FROM whatsapp_logs GROUP BY message_type")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $stats['by_type'][$r['message_type']] = [
                        'total' => $r['cnt'],
                        'success' => $r['success_cnt'],
                        'failed' => $r['cnt'] - $r['success_cnt']
                    ];
                }

                // Common Errors
                $rows = $this->pdo->query("SELECT error_message as error, COUNT(*) as cnt FROM whatsapp_logs WHERE success = 0 AND error_message IS NOT NULL GROUP BY error_message ORDER BY cnt DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $stats['common_errors'][$r['error']] = $r['cnt'];
                }

                // Queue Stats (From File)
                $queue = $this->getFailedQueue();
                $stats['pending_retry'] = count(array_filter($queue, fn($q) => $q['status'] === 'pending'));
                $stats['permanently_failed'] = count(array_filter($queue, fn($q) => $q['status'] === 'failed_permanent'));

                return $stats;

            } catch (Exception $e) {
                // Fallback
            }
        }

        // 2. Fallback JSON Stats
        $logs = $this->getJsonLogs(); // Use direct JSON read
        $queue = $this->getFailedQueue();
        
        $total = count($logs);
        $success = count(array_filter($logs, fn($log) => $log['success']));
        $failed = $total - $success;
        
        $byType = [];
        foreach ($logs as $log) {
            $type = $log['message_type'] ?? 'unknown';
            if (!isset($byType[$type])) {
                $byType[$type] = ['total' => 0, 'success' => 0, 'failed' => 0];
            }
            $byType[$type]['total']++;
            if ($log['success']) {
                $byType[$type]['success']++;
            } else {
                $byType[$type]['failed']++;
            }
        }
        
        // Common errors
        $errorCounts = [];
        foreach ($logs as $log) {
            if (!$log['success'] && !empty($log['error'])) {
                $error = $log['error'];
                $errorCounts[$error] = ($errorCounts[$error] ?? 0) + 1;
            }
        }
        arsort($errorCounts);
        
        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($success / $total) * 100, 1) : 0,
            'by_type' => $byType,
            'common_errors' => array_slice($errorCounts, 0, 10),
            'pending_retry' => count(array_filter($queue, fn($q) => $q['status'] === 'pending')),
            'permanently_failed' => count(array_filter($queue, fn($q) => $q['status'] === 'failed_permanent'))
        ];
    }
    
    /**
     * Add to failed messages queue
     */
    private function addToFailedQueue($entry) {
        $queue = json_decode(file_get_contents($this->failedQueueFile), true) ?? [];
        
        $exists = false;
        foreach ($queue as $item) {
            if ($item['phone'] === $entry['phone'] && $item['message_type'] === $entry['message_type']) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $entry['queued_at'] = date('Y-m-d H:i:s');
            $entry['status'] = 'pending';
            $queue[] = $entry;
            file_put_contents($this->failedQueueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
    
    /**
     * Get failed queue
     */
    public function getFailedQueue($status = null) {
        $queue = json_decode(file_get_contents($this->failedQueueFile), true) ?? [];
        
        if ($status) {
            $queue = array_filter($queue, fn($item) => $item['status'] === $status);
        }
        
        return array_values($queue);
    }
    
    /**
     * Retry sending a failed message
     */
    public function retryMessage($messageId) {
        $queue = json_decode(file_get_contents($this->failedQueueFile), true) ?? [];
        
        foreach ($queue as $index => $item) {
            if ($item['id'] === $messageId) {
                $queue[$index]['retry_count'] = ($item['retry_count'] ?? 0) + 1;
                $queue[$index]['last_retry'] = date('Y-m-d H:i:s');
                $queue[$index]['status'] = 'retrying';
                
                file_put_contents($this->failedQueueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * Mark message as permanently failed
     */
    public function markAsPermanentlyFailed($messageId, $reason = null) {
        $queue = json_decode(file_get_contents($this->failedQueueFile), true) ?? [];
        
        foreach ($queue as $index => $item) {
            if ($item['id'] === $messageId) {
                $queue[$index]['status'] = 'failed_permanent';
                $queue[$index]['failed_reason'] = $reason;
                $queue[$index]['failed_at'] = date('Y-m-d H:i:s');
                
                file_put_contents($this->failedQueueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Remove from failed queue (when successfully sent)
     */
    public function removeFromQueue($messageId) {
        $queue = json_decode(file_get_contents($this->failedQueueFile), true) ?? [];
        $queue = array_filter($queue, fn($item) => $item['id'] !== $messageId);
        file_put_contents($this->failedQueueFile, json_encode(array_values($queue), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Clear old logs
     */
    public function clearOldLogs($days = 30) {
        $logs = $this->getLogs();
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $logs = array_filter($logs, fn($log) => $log['timestamp'] >= $cutoffDate);
        
        file_put_contents($this->logFile, json_encode(array_values($logs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return count($logs);
    }
}

// Message type constants
define('WHATSAPP_MSG_REGISTRATION', 'registration_received');
define('WHATSAPP_MSG_ACCEPTANCE', 'acceptance');
define('WHATSAPP_MSG_BADGE', 'badge');
define('WHATSAPP_MSG_QR_ONLY', 'qr_only');
define('WHATSAPP_MSG_REJECTION', 'rejection');
define('WHATSAPP_MSG_BROADCAST', 'broadcast');
define('WHATSAPP_MSG_REMINDER', 'reminder');
