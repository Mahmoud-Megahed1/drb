<?php
/**
 * Admin Logger - ????? ???? ???????? ????????
 * 
 * ????:
 * - ????? ????? ???????
 * - ????? ???? ???????
 * - ????? ???? ???????
 * - ??????? ?????????
 * - ?? ????? ??????
 */

class AdminLogger {
    
    private $logFile;
    
    // ????? ????????
    const ACTION_ROUND_ENTRY = 'round_entry';
    const ACTION_ROUND_RESET = 'round_reset';
    const ACTION_MANUAL_ENTRY = 'manual_entry';
    const ACTION_PARTICIPANT_EDIT = 'participant_edit';
    const ACTION_PARTICIPANT_DELETE = 'participant_delete';
    const ACTION_PARTICIPANT_APPROVE = 'participant_approve';
    const ACTION_PARTICIPANT_REJECT = 'participant_reject';
    const ACTION_BADGE_TOGGLE = 'badge_toggle';
    const ACTION_SETTINGS_CHANGE = 'settings_change';
    const ACTION_IMPORT = 'import';
    const ACTION_EXPORT = 'export';
    const ACTION_WHATSAPP_SEND = 'whatsapp_send';
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_DELETE_ALL_MEMBERS = 'delete_all_members';
    const ACTION_ROUND_DELETE = 'round_delete';
    const ACTION_CHAMPIONSHIP_RESET = 'championship_reset';
    
    private $pdo;

    public function __construct() {
        $this->logFile = __DIR__ . '/../admin/data/admin_actions.json';
        
        // Ensure directory exists
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Init DB
        require_once __DIR__ . '/db.php';
        try {
            $this->pdo = db();
            $this->initTable();
        } catch (Exception $e) {
            // Fallback to file only if DB fails
        }
    }

    private function initTable() {
        $sql = "CREATE TABLE IF NOT EXISTS activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            username TEXT,
            description TEXT,
            details TEXT,
            ip_address TEXT,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
    }
    
    public function log($action, $username, $description, $details = []) {
        // 1. Log to Database
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->prepare("INSERT INTO activity_logs (action, username, description, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now', '+3 hours'))");
                $stmt->execute([
                    $action,
                    $username,
                    $description,
                    json_encode($details, JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);
            } catch (Exception $e) {
                error_log("AdminLogger DB Error: " . $e->getMessage());
            }
        }

        // 2. Log to JSON (Legacy Support)
        try {
            $logs = $this->getJsonLogs();
            $newLog = [
                'id' => uniqid('log_'),
                'action' => $action,
                'username' => $username,
                'description' => $description,
                'details' => $details,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'timestamp' => time(),
                'datetime' => date('Y-m-d H:i:s')
            ];
            
            array_unshift($logs, $newLog);
            if (count($logs) > 2000) $logs = array_slice($logs, 0, 2000);
            
            file_put_contents(
                $this->logFile, 
                json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function getJsonLogs() {
        if (!file_exists($this->logFile)) return [];
        return json_decode(file_get_contents($this->logFile), true) ?? [];
    }

    public function getLogs($limit = 100, $action = null, $username = null) {
        $logs = [];

        // 1. Try DB first
        if ($this->pdo) {
            try {
                $sql = "SELECT * FROM activity_logs WHERE 1=1";
                $params = [];
                
                if ($action) {
                    $sql .= " AND action = ?";
                    $params[] = $action;
                }
                if ($username) {
                    $sql .= " AND username = ?";
                    $params[] = $username;
                }
                
                $sql .= " ORDER BY created_at DESC LIMIT ?";
                $params[] = $limit;
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach($rows as $row) {
                    $logs[] = [
                        'id' => $row['id'],
                        'action' => $row['action'],
                        'username' => $row['username'],
                        'description' => $row['description'],
                        'details' => json_decode($row['details'], true),
                        'ip' => $row['ip_address'],
                        'user_agent' => $row['user_agent'],
                        'timestamp' => strtotime($row['created_at']),
                        'datetime' => $row['created_at']
                    ];
                }
                
                return $logs; // Return DB logs if successful
                
            } catch (Exception $e) {
                // Fallback to JSON
            }
        }

        // 2. Fallback to JSON
        $logs = $this->getJsonLogs();
        
        if ($action) {
            $logs = array_filter($logs, fn($l) => ($l['action'] ?? '') === $action);
        }
        if ($username) {
            $logs = array_filter($logs, fn($l) => ($l['username'] ?? '') === $username);
        }
        
        return array_slice(array_values($logs), 0, $limit);
    }

    public function getStats() {
        // Simple stats from DB or JSON
        // Using getLogs(1000) for approximation
        $logs = $this->getLogs(1000);
        
        $stats = [
            'total' => count($logs),
            'today_count' => 0, // renamed key to match usage
            'login_count' => 0,
            'by_action' => [],
            'by_user' => [],
            'top_user' => ['username' => '-', 'count' => 0]
        ];
        
        $today = date('Y-m-d');
        
        foreach ($logs as $log) {
            $date = is_numeric($log['timestamp']) ? date('Y-m-d', $log['timestamp']) : substr($log['datetime'], 0, 10);
            
            if ($date === $today) {
                $stats['today_count']++;
            }
            
            if (($log['action'] ?? '') === 'login' && $date === $today) {
                $stats['login_count']++;
            }
            
            // By User
            $u = $log['username'] ?? 'unknown';
            $stats['by_user'][$u] = ($stats['by_user'][$u] ?? 0) + 1;
        }
        
        // Find Top User
        if (!empty($stats['by_user'])) {
            arsort($stats['by_user']);
            $topUser = array_key_first($stats['by_user']);
            $stats['top_user'] = ['username' => $topUser, 'count' => $stats['by_user'][$topUser]];
        }
        
        return $stats;
    }
    
    /**
     * ??? ??????? ??????? (???? ?? 30 ???)
     */
    public function cleanup($daysToKeep = 30) {
        $logs = $this->getLogs(10000);
        $cutoff = time() - ($daysToKeep * 86400);
        
        $filtered = array_filter($logs, function($log) use ($cutoff) {
            return ($log['timestamp'] ?? 0) > $cutoff;
        });
        
        file_put_contents(
            $this->logFile, 
            json_encode(array_values($filtered), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        return count($logs) - count($filtered);
    }
    
    /**
     * Helper: تسجيل تصفير الجولات
     */
    public static function logRoundReset($username, $roundId = null) {
        $logger = new self();
        return $logger->log(
            self::ACTION_ROUND_RESET,
            $username,
            $roundId ? "تصفير يدوي للجولة $roundId" : "تصفير يدوي لكل الجولات",
            ['round_id' => $roundId]
        );
    }
    
    /**
     * Helper: تسجيل دخول يدوي
     */
    public static function logManualEntry($username, $participantCode, $roundId, $notes = '') {
        $logger = new self();
        return $logger->log(
            self::ACTION_MANUAL_ENTRY,
            $username,
            "إدخال يدوي للمتسابق $participantCode في الجولة $roundId",
            [
                'participant_code' => $participantCode,
                'round_id' => $roundId,
                'notes' => $notes
            ]
        );
    }
}
