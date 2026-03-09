<?php
/**
 * Violation Manager - ????? ?????????
 * 
 * ????:
 * - ????? ??????
 * - ????? ??????
 * - ??? ?????????
 */

class ViolationManager {
    
    private $pdo;
    private $notesFile;
    
    // ????? ?????????
    const TYPE_WARNING = 'warning';      // ?????
    const TYPE_BLOCKER = 'blocker';      // ???
    const TYPE_INFO = 'info';            // ??????
    
    // ????? ???????
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    
    public function __construct() {
        $this->notesFile = __DIR__ . '/../admin/data/violations.json';
        
        try {
            require_once __DIR__ . '/db.php';
            $this->pdo = db();
            $this->ensureTable();
        } catch (Exception $e) {
            // Continue without DB
        }
    }
    
    /**
     * ????? ???? ?????????
     */
    private function ensureTable() {
        if ($this->pdo) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS violations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_code TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'warning',
                severity TEXT DEFAULT 'medium',
                title TEXT,
                description TEXT NOT NULL,
                championship_id TEXT,
                round_id INTEGER,
                added_by TEXT NOT NULL,
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                resolved INTEGER DEFAULT 0,
                resolved_by TEXT,
                resolved_at DATETIME,
                resolve_notes TEXT
            )");
            
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_violations_member ON violations(member_code)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_violations_resolved ON violations(resolved)");
        }
    }
    
    /**
     * ????? ??????
     */
    public function add($memberCode, $description, $options = []) {
        $violation = [
            'id' => uniqid('vio_'),
            'member_code' => $memberCode,
            'type' => $options['type'] ?? self::TYPE_WARNING,
            'severity' => $options['severity'] ?? self::SEVERITY_MEDIUM,
            'title' => $options['title'] ?? '??????',
            'description' => $description,
            'championship_id' => $options['championship_id'] ?? $this->getCurrentChampionship(),
            'round_id' => $options['round_id'] ?? null,
            'added_by' => $options['added_by'] ?? 'admin',
            'added_at' => date('Y-m-d H:i:s'),
            'resolved' => 0
        ];
        
        // ??? ?? ????? ????????
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO violations 
                    (member_code, type, severity, title, description, championship_id, round_id, added_by, added_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+3 hours'))
                ");
                $stmt->execute([
                    $violation['member_code'],
                    $violation['type'],
                    $violation['severity'],
                    $violation['title'],
                    $violation['description'],
                    $violation['championship_id'],
                    $violation['round_id'],
                    $violation['added_by']
                ]);
                $violation['db_id'] = $this->pdo->lastInsertId();
            } catch (Exception $e) {
                error_log("ViolationManager DB Error: " . $e->getMessage());
            }
        }
        
        // ??? ?? JSON
        $this->saveToJson($violation);
        
        // ????? ?? Admin Log
        $this->logAction('add', $violation);
        
        return [
            'success' => true,
            'violation_id' => $violation['id'],
            'message' => '?? ????? ???????? ?????'
        ];
    }
    
    /**
     * ?????/?? ??????
     */
    public function resolve($violationId, $resolvedBy, $notes = '') {
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->prepare("
                    UPDATE violations 
                    SET resolved = 1, resolved_by = ?, resolved_at = datetime('now', '+3 hours'), resolve_notes = ?
                    WHERE id = ? OR member_code = ?
                ");
                $stmt->execute([$resolvedBy, $notes, $violationId, $violationId]);
            } catch (Exception $e) {
                error_log("ViolationManager Resolve Error: " . $e->getMessage());
            }
        }
        
        // ????? JSON
        $this->resolveInJson($violationId, $resolvedBy, $notes);
        
        return [
            'success' => true,
            'message' => '?? ?? ????????'
        ];
    }
    
    /**
     * ?????? ??? ??????? ???
     */
    public function getMemberViolations($memberCode, $activeOnly = true) {
        if ($this->pdo) {
            $sql = "SELECT * FROM violations WHERE member_code = ?";
            if ($activeOnly) {
                $sql .= " AND resolved = 0";
            }
            $sql .= " ORDER BY added_at DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$memberCode]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Fallback to JSON
        return $this->getFromJson($memberCode, $activeOnly);
    }
    
    /**
     * ??? ????????? ??????
     */
    public function getActiveCount($memberCode) {
        $violations = $this->getMemberViolations($memberCode, true);
        return count($violations);
    }
    
    /**
     * ?? ???? ??????? ????
     */
    public function hasBlocker($memberCode) {
        $violations = $this->getMemberViolations($memberCode, true);
        foreach ($violations as $v) {
            if ($v['type'] === self::TYPE_BLOCKER) {
                return true;
            }
        }
        return false;
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
     * ??? ?? JSON
     */
    private function saveToJson($violation) {
        $violations = [];
        if (file_exists($this->notesFile)) {
            $violations = json_decode(file_get_contents($this->notesFile), true) ?? [];
        }
        
        array_unshift($violations, $violation);
        
        file_put_contents($this->notesFile, json_encode($violations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * ?????? ?? JSON
     */
    private function getFromJson($memberCode, $activeOnly) {
        if (!file_exists($this->notesFile)) {
            return [];
        }
        
        $violations = json_decode(file_get_contents($this->notesFile), true) ?? [];
        
        return array_filter($violations, function($v) use ($memberCode, $activeOnly) {
            $match = $v['member_code'] === $memberCode;
            if ($activeOnly) {
                $match = $match && !($v['resolved'] ?? false);
            }
            return $match;
        });
    }
    
    /**
     * ?? ?????? ?? JSON
     */
    private function resolveInJson($violationId, $resolvedBy, $notes) {
        if (!file_exists($this->notesFile)) {
            return;
        }
        
        $violations = json_decode(file_get_contents($this->notesFile), true) ?? [];
        
        foreach ($violations as &$v) {
            if ($v['id'] === $violationId || $v['member_code'] === $violationId) {
                $v['resolved'] = 1;
                $v['resolved_by'] = $resolvedBy;
                $v['resolved_at'] = date('Y-m-d H:i:s');
                $v['resolve_notes'] = $notes;
            }
        }
        
        file_put_contents($this->notesFile, json_encode($violations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * ????? ???????
     */
    private function logAction($action, $violation) {
        try {
            require_once __DIR__ . '/AdminLogger.php';
            $logger = new AdminLogger();
            $logger->log(
                $action === 'add' ? 'violation_add' : 'violation_resolve',
                $violation['added_by'] ?? 'system',
                $action === 'add' ? '????? ??????' : '?? ??????',
                [
                    'member_code' => $violation['member_code'],
                    'type' => $violation['type'] ?? '',
                    'description' => $violation['description'] ?? ''
                ]
            );
        } catch (Exception $e) {
            // Ignore logging errors
        }
    }
}
