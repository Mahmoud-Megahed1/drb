<?php
/**
 * AJAX Reset User Round
 * Endpoint to clear a specific participant's entry logs for a specific round.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../include/auth.php';
require_once '../include/AdminLogger.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = getCurrentUser();
$logUsername = $user['username'] ?? $_SESSION['user']->username ?? 'unknown';

// Only admins, root, or rounds manager can reset rounds
$isRoot = (isset($user['username']) && $user['username'] === 'root');
$userRole = $user['role'] ?? ($isRoot ? 'root' : 'viewer');
if ($isRoot) $userRole = 'root';

if (!in_array($userRole, ['root', 'admin']) && !hasPermission('rounds')) {
    echo json_encode(['success' => false, 'message' => 'لا توجد صلاحية لإعادة التعيين']);
    exit;
}

$wasel = $_POST['wasel'] ?? '';
$round_id = intval($_POST['round_id'] ?? 0);

if (empty($wasel) || empty($round_id)) {
    echo json_encode(['success' => false, 'message' => 'رقم واصل ورقم الجولة مطلوبان']);
    exit;
}

$logsFile = __DIR__ . '/data/round_logs.json';
$logs = file_exists($logsFile) ? (json_decode(file_get_contents($logsFile), true) ?? []) : [];

// Remove previous enters/exits for this participant in this specific round
$newLogs = array_filter($logs, function($l) use ($wasel, $round_id) {
    if (($l['participant_id'] ?? '') === $wasel && intval($l['round_id'] ?? 0) === $round_id) {
        return false;
    }
    return true;
});

// Create explicitly tracked reset interaction
$resetEntry = [
    'action' => 'round_reset',
    'round_id' => $round_id,
    'participant_id' => $wasel,
    'timestamp' => time(),
    'device' => 'dashboard_admin',
    'scanned_by' => $logUsername,
    'details' => 'تم استرجاع/تصفير الجولة عبر الداشبورد'
];

$logs = array_values($newLogs);
array_unshift($logs, $resetEntry); // Add to beginning (or end depending on your app's push preference, data usually pushes to end but usort runs on output anyway)

// Sort descending by timestamp since the other files use usort for display but assume chronological appends
usort($logs, function($a, $b) { return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0); });

file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Also clear SQL logs if they exist for compatibility
try {
    require_once '../include/db.php';
    $pdo = db();
    
    // 1. Resolve internal participant ID from wasel
    $stmtP = $pdo->prepare("SELECT id FROM participants WHERE wasel = ? OR registration_code = ? OR badge_id = ?");
    $stmtP->execute([$wasel, $wasel, $wasel]);
    $sqlParticipantId = $stmtP->fetchColumn();
    
    if ($sqlParticipantId) {
        // 2. Clear round_logs
        $stmt = $pdo->prepare("DELETE FROM round_logs WHERE participant_id = ? AND round_id = ?");
        $stmt->execute([$sqlParticipantId, $round_id]);
        
        // 3. Clear entry_exit_logs (gate = rounds) to keep things strictly in sync
        $stmtEntry = $pdo->prepare("DELETE FROM entry_exit_logs WHERE member_code = ? AND gate = 'rounds' AND round_id = ?");
        $stmtEntry->execute([$wasel, $round_id]);
    }
} catch (Exception $e) {
    error_log("SQL Reset Error: " . $e->getMessage());
}


// Admin Audit Logging
try {
    $adminLogger = new AdminLogger();
    $adminLogger->log(
        'participant_round_reset',
        $logUsername,
        "تصفير جولة $round_id للمتسابق (واصل: $wasel)",
        [
            'wasel' => $wasel,
            'round_id' => $round_id
        ]
    );
} catch (Exception $e) {}

echo json_encode(['success' => true, 'message' => "تم إعادة تعيين الجولة $round_id بنجاح"]);
