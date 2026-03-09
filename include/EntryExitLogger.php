<?php
/**
 * Entry/Exit Logger - ???? ????? ?????? ???????
 * 
 * ????:
 * - ????/???? ???????
 * - ??????? ?????????
 * - ????? ??????
 * - ?????? (?? ????)
 */

class EntryExitLogger {
    
    private $logFile;
    private $pdo;
    
    // ????? ????????
    const GATE_MAIN = 'main';           // ??????? ????????
    const GATE_VIP = 'vip';             // ????? VIP
    const GATE_ARENA = 'arena';         // ????? ??????
    const GATE_PARKING = 'parking';     // ???? ????????
    const GATE_ROUNDS = 'rounds';       // ????? ???????
    
    // ????? ?????????
    const ACTION_ENTRY = 'entry';
    const ACTION_EXIT = 'exit';
    
    public function __construct() {
        $this->logFile = __DIR__ . '/../admin/data/entry_exit_logs.json';
        
        // ?????? ?? ???? ??????
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // ??????? ?????? ????????
        try {
            require_once __DIR__ . '/db.php';
            $this->pdo = db();
            $this->ensureTable();
        } catch (Exception $e) {
            // Continue without DB
        }
    }
    
    /**
     * ????? ???? ????? ??? ?? ??? ???????
     */
    private function ensureTable() {
        if ($this->pdo) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS entry_exit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_code TEXT NOT NULL,
                member_name TEXT,
                action TEXT NOT NULL,
                gate TEXT NOT NULL,
                round_id INTEGER,
                championship_id TEXT,
                scanned_by TEXT,
                device TEXT,
                ip_address TEXT,
                notes TEXT,
                timestamp INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            
            // ????? ????? ????? ??????
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_member_code ON entry_exit_logs(member_code)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_timestamp ON entry_exit_logs(timestamp)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_gate ON entry_exit_logs(gate)");
        }
    }
    
    /**
     * ????? ???? ?? ????
     * 
     * @param string $memberCode ??? ?????
     * @param string $action ??????? (entry/exit)
     * @param string $gate ???????
     * @param array $details ?????? ??????
     * @return array
     */
    public function log($memberCode, $action, $gate, $details = []) {
        $logEntry = [
            'id' => uniqid('log_'),
            'member_code' => $memberCode,
            'member_name' => $details['member_name'] ?? '',
            'action' => $action,
            'gate' => $gate,
            'round_id' => $details['round_id'] ?? null,
            'championship_id' => $details['championship_id'] ?? $this->getCurrentChampionship(),
            'scanned_by' => $details['scanned_by'] ?? 'system',
            'device' => $details['device'] ?? 'unknown',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'notes' => $details['notes'] ?? '',
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s')
        ];
        
        // ??? ?? ????? ????????
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO entry_exit_logs 
                    (member_code, member_name, action, gate, round_id, championship_id, scanned_by, device, ip_address, notes, timestamp, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+3 hours'))
                ");
                $stmt->execute([
                    $logEntry['member_code'],
                    $logEntry['member_name'],
                    $logEntry['action'],
                    $logEntry['gate'],
                    $logEntry['round_id'],
                    $logEntry['championship_id'],
                    $logEntry['scanned_by'],
                    $logEntry['device'],
                    $logEntry['ip_address'],
                    $logEntry['notes'],
                    $logEntry['timestamp']
                ]);
                $logEntry['db_id'] = $this->pdo->lastInsertId();
            } catch (Exception $e) {
                error_log("EntryExitLogger DB Error: " . $e->getMessage());
            }
        }
        
        // ??? ?? JSON ????? ????????
        $this->saveToJson($logEntry);
        
        return [
            'success' => true,
            'log_id' => $logEntry['id'],
            'message' => $action === self::ACTION_ENTRY ? '?? ????? ??????' : '?? ????? ??????'
        ];
    }
    
    /**
     * ??? ?? ??? JSON
     */
    private function saveToJson($logEntry) {
        $logs = $this->getJsonLogs();
        array_unshift($logs, $logEntry);
        
        // ???????? ???? 50000 ???
        if (count($logs) > 50000) {
            $logs = array_slice($logs, 0, 50000);
        }
        
        file_put_contents($this->logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * ?????? ??? ????? JSON
     */
    private function getJsonLogs() {
        if (file_exists($this->logFile)) {
            return json_decode(file_get_contents($this->logFile), true) ?? [];
        }
        return [];
    }
    
    /**
     * ?????? ??? ??????? ???????
     */
    private function getCurrentChampionship() {
        $settingsFile = __DIR__ . '/../admin/data/site_settings.json';
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true);
            return $settings['current_championship_id'] ?? date('Y') . '_default';
        }
        return date('Y') . '_default';
    }
    
    /**
     * ?????? ??? ????? ?????
     */
    public function getTodayLogs($gate = null) {
        $today = date('Y-m-d');
        $startOfDay = strtotime($today . ' 00:00:00');
        $endOfDay = strtotime($today . ' 23:59:59');
        
        if ($this->pdo) {
            $sql = "SELECT * FROM entry_exit_logs WHERE timestamp BETWEEN ? AND ?";
            $params = [$startOfDay, $endOfDay];
            
            if ($gate) {
                $sql .= " AND gate = ?";
                $params[] = $gate;
            }
            
            $sql .= " ORDER BY timestamp DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Fallback to JSON
        $logs = $this->getJsonLogs();
        return array_filter($logs, function($log) use ($startOfDay, $endOfDay, $gate) {
            $inRange = ($log['timestamp'] >= $startOfDay && $log['timestamp'] <= $endOfDay);
            if ($gate) {
                return $inRange && ($log['gate'] === $gate);
            }
            return $inRange;
        });
    }
    
    /**
     * ?????? ??? ????? ??? ????
     */
    public function getMemberLogs($memberCode, $limit = 50) {
        if ($this->pdo) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM entry_exit_logs 
                WHERE member_code = ? 
                ORDER BY timestamp DESC 
                LIMIT ?
            ");
            $stmt->execute([$memberCode, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Fallback to JSON
        $logs = $this->getJsonLogs();
        $memberLogs = array_filter($logs, function($log) use ($memberCode) {
            return $log['member_code'] === $memberCode;
        });
        return array_slice($memberLogs, 0, $limit);
    }
    
    /**
     * ???????? ????????
     */
    public function getGateStats($date = null) {
        $date = $date ?? date('Y-m-d');
        $startOfDay = strtotime($date . ' 00:00:00');
        $endOfDay = strtotime($date . ' 23:59:59');
        
        $stats = [
            'date' => $date,
            'gates' => [],
            'total_entries' => 0,
            'total_exits' => 0,
            'currently_inside' => 0
        ];
        
        if ($this->pdo) {
            // ???????? ??? ???????
            $stmt = $this->pdo->prepare("
                SELECT gate, action, COUNT(*) as count
                FROM entry_exit_logs 
                WHERE timestamp BETWEEN ? AND ?
                GROUP BY gate, action
            ");
            $stmt->execute([$startOfDay, $endOfDay]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($results as $row) {
                $gate = $row['gate'];
                if (!isset($stats['gates'][$gate])) {
                    $stats['gates'][$gate] = ['entries' => 0, 'exits' => 0];
                }
                
                if ($row['action'] === 'entry') {
                    $stats['gates'][$gate]['entries'] = $row['count'];
                    $stats['total_entries'] += $row['count'];
                } else {
                    $stats['gates'][$gate]['exits'] = $row['count'];
                    $stats['total_exits'] += $row['count'];
                }
            }
            
            $stats['currently_inside'] = $stats['total_entries'] - $stats['total_exits'];
        }
        
        return $stats;
    }
    
    /**
     * ?????? ??? ??? ????? ????
     */
    public function getLastAction($memberCode) {
        if ($this->pdo) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM entry_exit_logs 
                WHERE member_code = ? 
                ORDER BY timestamp DESC 
                LIMIT 1
            ");
            $stmt->execute([$memberCode]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $logs = $this->getMemberLogs($memberCode, 1);
        return !empty($logs) ? $logs[0] : null;
    }
    
    /**
     * ?? ????? ???? ???????
     */
    public function isMemberInside($memberCode) {
        $lastAction = $this->getLastAction($memberCode);
        return $lastAction && $lastAction['action'] === self::ACTION_ENTRY;
    }
    
    /**
     * ?????? ??? ??????? ????????? ??????
     */
    public function getCurrentlyInside($gate = null) {
        $today = date('Y-m-d');
        $startOfDay = strtotime($today . ' 00:00:00');
        
        if ($this->pdo) {
            $sql = "
                SELECT member_code, member_name, MAX(timestamp) as last_time, action, gate
                FROM entry_exit_logs 
                WHERE timestamp >= ?
            ";
            $params = [$startOfDay];
            
            if ($gate) {
                $sql .= " AND gate = ?";
                $params[] = $gate;
            }
            
            $sql .= " GROUP BY member_code HAVING action = 'entry'";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return [];
    }
    /**
     * ?????? ??? ??????? ??????? ?????? ???
     * Count unique rounds entered by a member
     */
    public function getRoundsCount($memberCode) {
        if ($this->pdo) {
            // Count total entries (History)
            // We use COUNT(*) because 'round_id' might be 0 or reused across championships.
            // Since duplicate entries are blocked by verify_entry, each log is a valid participation.
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM entry_exit_logs 
                WHERE member_code = ? AND action = 'entry'
            ");
            $stmt->execute([$memberCode]);
            return (int)$stmt->fetchColumn();
        }
        
        // Fallback to JSON
        $roundLogsFile = __DIR__ . '/../admin/data/round_logs.json';
        if (file_exists($roundLogsFile)) {
            $roundLogs = json_decode(file_get_contents($roundLogsFile), true) ?? [];
            $memberRounds = array_filter($roundLogs, function($log) use ($memberCode) {
                // Check both participant_id (legacy) and member_code
                $pid = $log['participant_id'] ?? '';
                // Simple loose check as json might have int/string
                return ($pid == $memberCode) && ($log['action'] ?? '') === 'enter';
            });
            return count(array_unique(array_column($memberRounds, 'round_id')));
        }
        
        return 0;
    }
}
