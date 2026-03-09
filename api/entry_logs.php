<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../include/db.php';
require_once '../include/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$limit = intval($_GET['limit'] ?? 50);
$pdo = db();

try {
    // Use wasel as the join key since badge_id doesn't exist
    $stmt = $pdo->prepare("SELECT el.*, m.full_name as member_name 
                           FROM entry_logs el 
                           LEFT JOIN members m ON el.participant_id = m.wasel 
                           ORDER BY el.timestamp DESC LIMIT ?");
    $stmt->execute([$limit]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format timestamp
    foreach ($logs as &$log) {
        $log['time_formatted'] = date('H:i:s', $log['timestamp']);
        $log['date_formatted'] = date('Y-m-d', $log['timestamp']);
    }
    
    echo json_encode(['success' => true, 'logs' => $logs]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
