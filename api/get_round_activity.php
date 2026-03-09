<?php
/**
 * Get Round Activity API - Refined V2
 * Returns the latest 30 actions for a specific round, strictly filtered by current championship
 */

require_once '../include/auth.php';
require_once '../include/db.php';
require_once '../include/AdminLogger.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$round_id = intval($_GET['round_id'] ?? 0);
if (!$round_id) {
    echo json_encode(['success' => false, 'message' => 'Missing Round ID']);
    exit;
}

/**
 * Get the strict start date of the current championship
 */
function getChampionshipStartDate($pdo) {
    // 1. Check system_settings table (Primary)
    try {
        $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = 'championship_start_date'");
        $stmt->execute();
        $date = $stmt->fetchColumn();
        if ($date) return $date;
    } catch (Exception $e) {}

    // 2. Check site_settings.json
    $settingsFile = __DIR__ . '/../admin/data/site_settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        if (!empty($settings['championship_start_date'])) {
            return $settings['championship_start_date'];
        }
    }

    // 3. Fallback: Earliest registration in CURRENT table
    try {
        $stmt = $pdo->query("SELECT MIN(created_at) FROM registrations");
        $date = $stmt->fetchColumn();
        if ($date) return $date;
    } catch (Exception $e) {}

    // 4. Default: Something very far in the future if we want to BE SURE not to show old logs
    // But better to use "Now - 1 hour" if no registrations yet
    return date('Y-m-d H:i:s', strtotime('-1 hour'));
}

try {
    $pdo = db();
    $start_date = getChampionshipStartDate($pdo);
    $startTimestamp = strtotime($start_date);

    $allLogs = [];

    // --- SOURCE 1: Database (audit_logs or activity_logs) ---
    $tablesToTry = ['activity_logs', 'audit_logs'];
    foreach ($tablesToTry as $table) {
        try {
            // Check if table exists first to avoid exception noise
            $stmtExists = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $stmtExists->execute([$table]);
            if (!$stmtExists->fetch()) continue;

            $sql = "SELECT action, username, description, details, created_at 
                    FROM $table 
                    WHERE (action IN ('round_entry', 'reset_round', 'manual_entry'))
                    AND created_at >= ?
                    AND (details LIKE ? OR details LIKE ? OR description LIKE ?)
                    ORDER BY created_at DESC LIMIT 50";
            
            $stmt = $pdo->prepare($sql);
            $pattern1 = '%"round_id":' . $round_id . '%';
            $pattern2 = '%"id":' . $round_id . '%';
            $descPattern = '%' . $round_id . '%';
            
            $stmt->execute([$start_date, $pattern1, $pattern2, $descPattern]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $allLogs[] = [
                    'action' => $row['action'],
                    'username' => $row['username'] ?? 'System',
                    'description' => $row['description'] ?? '',
                    'details' => json_decode($row['details'], true) ?? [],
                    'timestamp' => strtotime($row['created_at'])
                ];
            }
            break;
        } catch (Exception $e) {}
    }

    // --- SOURCE 2: JSON Logs (admin_actions.json) ---
    $jsonFile = __DIR__ . '/../admin/data/admin_actions.json';
    if (file_exists($jsonFile)) {
        $jsonLogs = json_decode(file_get_contents($jsonFile), true) ?? [];
        foreach ($jsonLogs as $log) {
            $ts = $log['timestamp'] ?? strtotime($log['datetime'] ?? '');
            if (!$ts || $ts < $startTimestamp) continue; 

            $action = $log['action'] ?? '';
            if (!in_array($action, ['round_entry', 'reset_round', 'manual_entry'])) continue;

            $details = $log['details'] ?? [];
            $roundIdMatches = ($details['round_id'] ?? $details['id'] ?? 0) == $round_id;
            // Also check for round_id in description if details is missing it
            $descMatches = strpos($log['description'] ?? '', "الجولة " . $round_id) !== false 
                         || strpos($log['description'] ?? '', "Round " . $round_id) !== false;

            if ($roundIdMatches || $descMatches) {
                // Avoid duplicates 
                $isDuplicate = false;
                foreach ($allLogs as $existing) {
                    if ($existing['timestamp'] == $ts) { $isDuplicate = true; break; }
                }
                
                if (!$isDuplicate) {
                    $allLogs[] = [
                        'action' => $action,
                        'username' => $log['username'] ?? 'System',
                        'description' => $log['description'] ?? '',
                        'details' => $details,
                        'timestamp' => $ts
                    ];
                }
            }
        }
    }

    // Sort combined logs
    usort($allLogs, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    $allLogs = array_slice($allLogs, 0, 30);

    $formattedLogs = [];
    foreach ($allLogs as $log) {
        $details = $log['details'];
        $desc = $log['description'];

        if ($log['action'] === 'reset_round') {
            $desc = "⚠️ تم تصفير الجولة " . $round_id;
        } elseif ($log['action'] === 'manual_entry') {
            $desc = "⌨️ دخول يدوي: " . ($details['participant_name'] ?? $details['participant_code'] ?? 'مشارك');
        } elseif ($log['action'] === 'round_entry') {
            $desc = "✅ دخول: " . ($details['participant_name'] ?? 'مشارك');
        }

        $formattedLogs[] = [
            'action' => $log['action'],
            'username' => $log['username'],
            'description' => $desc,
            'badge_id' => $details['participant_code'] ?? $details['badge_id'] ?? $details['participant_id'] ?? $details['wasel'] ?? null,
            'time' => date('H:i:s', $log['timestamp']),
            'date' => date('d/m', $log['timestamp']),
            'timestamp' => $log['timestamp']
        ];
    }

    echo json_encode([
        'success' => true, 
        'activity' => $formattedLogs, 
        'debug' => [
            'start_date' => $start_date,
            'registrations_empty' => empty($allLogs),
            'round_id' => $round_id
        ]
    ]);

} catch (Exception $e) {
    error_log("get_round_activity error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal Error: ' . $e->getMessage()]);
}
