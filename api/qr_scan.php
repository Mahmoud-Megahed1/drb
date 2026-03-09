<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../include/db.php';
require_once '../include/auth.php';

// Check auth
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        case 'gate_stats':
            // Calculate stats for today
            $today = date('Y-m-d');
            $startOfDay = strtotime("today midnight");
            
            $stmt = $pdo->prepare("SELECT 
                COUNT(*) as total_entries,
                SUM(CASE WHEN action = 'exit' THEN 1 ELSE 0 END) as total_exits
                FROM entry_logs 
                WHERE timestamp >= ?");
            $stmt->execute([$startOfDay]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Currently inside (entries - exits, rough estimate or count distinct currently inside)
            // Better approach: count distinct member_id where last action is 'entry'
            // For now simple calculation:
            $currentlyInside = max(0, $res['total_entries'] - $res['total_exits']); // Simplified
            
            echo json_encode(['success' => true, 'stats' => [
                'currently_inside' => $currentlyInside,
                'total_entries' => $res['total_entries'] ?? 0,
                'total_exits' => $res['total_exits'] ?? 0
            ]]);
            break;

        case 'get_profile':
            $code = $_GET['member_code'] ?? '';
            if (!$code) throw new Exception("Code required");

            // Fetch member
            $stmt = $pdo->prepare("SELECT * FROM members WHERE wasel = ? OR phone = ?");
            $stmt->execute([$code, $code]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) throw new Exception("Member not found");

            // Fetch generic stats
            $stats = [
                'violations_count' => 0, // Placeholder
                'rounds_entered' => 0,   // Placeholder
                'championships_participated' => 0 // Placeholder
            ];

            // Check violations
            $violations = []; // Placeholder

            // Last logs - use wasel as the participant identifier
            $participantId = $member['wasel'] ?? $member['permanent_code'] ?? $code;
            $stmt = $pdo->prepare("SELECT * FROM entry_logs WHERE participant_id = ? ORDER BY timestamp DESC LIMIT 5");
            $stmt->execute([$participantId]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 
                'member' => [
                    'name' => $member['full_name'],
                    'code' => $member['wasel'] ?? $member['permanent_code'] ?? '',
                    'personal_photo' => $member['photo_url'] ?? '',
                    'car_type' => '',
                    'car_year' => '', 
                    'plate_full' => $member['plate_number'] ?? '',
                    'governorate' => '',
                    'participation_type' => $member['membership_type'] ?? 'member'
                ],
                'status' => [
                    'has_blocker' => false,
                    'is_current_participant' => true 
                ],
                'stats' => $stats,
                'violations' => $violations,
                'entry_logs' => $logs
            ]);
            break;
            
        case 'log_entry':
        case 'log_exit':
            $code = $_POST['member_code'] ?? '';
            $gate = $_POST['gate'] ?? 'main';
            $act = ($action == 'log_entry') ? 'entry' : 'exit';
            
            if (!$code) throw new Exception("Code required");
            
            $stmt = $pdo->prepare("INSERT INTO entry_logs (participant_id, action, gate, timestamp, operator_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$code, $act, $gate, time(), $_SESSION['user']['id'] ?? 0]);
            
            echo json_encode(['success' => true, 'message' => "Logged $act successfully"]);
            break;

        case 'add_violation':
            // Simplified placeholder
            echo json_encode(['success' => true, 'message' => 'Violation added (Mock)']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
