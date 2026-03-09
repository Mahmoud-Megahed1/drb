<?php
/**
 * Rounds Activity Logger
 * ??? ?????? ??????? ???????
 */

class RoundsLogger {
    private $logFile;
    
    public function __construct() {
        $this->logFile = __DIR__ . '/../admin/data/rounds_log.json';
        
        // Create log file if not exists
        if (!file_exists($this->logFile)) {
            $dir = dirname($this->logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->logFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
    
    /**
     * Log an action
     */
    public function log($action, $roundId = null, $details = [], $username = null) {
        $logs = $this->getLogs();
        
        // Get username from session if not provided
        if (!$username) {
            session_start();
            if (isset($_SESSION['user'])) {
                $user = $_SESSION['user'];
                $username = is_object($user) ? ($user->username ?? 'unknown') : ($user['username'] ?? 'unknown');
            } else {
                $username = 'system';
            }
        }
        
        $entry = [
            'id' => uniqid('log_'),
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'round_id' => $roundId,
            'username' => $username,
            'details' => $details,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        array_unshift($logs, $entry);
        
        // Keep only last 1000 entries
        if (count($logs) > 1000) {
            $logs = array_slice($logs, 0, 1000);
        }
        
        file_put_contents($this->logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $entry;
    }
    
    /**
     * Get logs with optional filters
     */
    public function getLogs($filters = []) {
        if (!file_exists($this->logFile)) {
            return [];
        }
        
        $logs = json_decode(file_get_contents($this->logFile), true) ?? [];
        
        // Apply filters
        if (!empty($filters['action'])) {
            $logs = array_filter($logs, fn($log) => $log['action'] === $filters['action']);
        }
        
        if (!empty($filters['round_id'])) {
            $logs = array_filter($logs, fn($log) => $log['round_id'] == $filters['round_id']);
        }
        
        if (!empty($filters['username'])) {
            $logs = array_filter($logs, fn($log) => $log['username'] === $filters['username']);
        }
        
        if (!empty($filters['from_date'])) {
            $logs = array_filter($logs, fn($log) => $log['timestamp'] >= $filters['from_date']);
        }
        
        if (!empty($filters['to_date'])) {
            $logs = array_filter($logs, fn($log) => $log['timestamp'] <= $filters['to_date']);
        }
        
        return array_values($logs);
    }
    
    /**
     * Get statistics
     */
    public function getStats() {
        $logs = $this->getLogs();
        
        $stats = [
            'total' => count($logs),
            'by_action' => [],
            'by_user' => [],
            'recent' => array_slice($logs, 0, 10)
        ];
        
        foreach ($logs as $log) {
            $action = $log['action'] ?? 'unknown';
            $user = $log['username'] ?? 'unknown';
            
            if (!isset($stats['by_action'][$action])) {
                $stats['by_action'][$action] = 0;
            }
            $stats['by_action'][$action]++;
            
            if (!isset($stats['by_user'][$user])) {
                $stats['by_user'][$user] = 0;
            }
            $stats['by_user'][$user]++;
        }
        
        return $stats;
    }
    
    /**
     * Clear old logs (older than X days)
     */
    public function clearOldLogs($days = 30) {
        $logs = $this->getLogs();
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $logs = array_filter($logs, fn($log) => $log['timestamp'] >= $cutoffDate);
        
        file_put_contents($this->logFile, json_encode(array_values($logs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return count($logs);
    }
}

// Action constants
define('ROUNDS_ACTION_CREATE', 'create_round');
define('ROUNDS_ACTION_UPDATE', 'update_round');
define('ROUNDS_ACTION_DELETE', 'delete_round');
define('ROUNDS_ACTION_ACTIVATE', 'activate_round');
define('ROUNDS_ACTION_DEACTIVATE', 'deactivate_round');
define('ROUNDS_ACTION_SCAN_ENTRY', 'scan_entry');
define('ROUNDS_ACTION_SCAN_EXIT', 'scan_exit');
define('ROUNDS_ACTION_RESET', 'reset_rounds');
define('ROUNDS_ACTION_CONFIG_UPDATE', 'config_update');
