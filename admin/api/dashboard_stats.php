<?php
/**
 * Fixed Dashboard Statistics API (Database Version)
 * إحصائيات لوحة التحكم - نسخة قاعدة البيانات
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../include/db.php';

try {
    $pdo = db();
    
    // 1. Registration Statistics (From Database)
    $stats = [
        'total_registrations' => 0,
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'by_governorate' => [],
        'by_car_type' => [],
        'by_participation_type' => [],
        'returning_members' => 0,
        'new_members' => 0,
        'imported_members' => 0,
        'form_registrations' => 0,
        'activated_members' => 0,
        'not_activated' => 0
    ];
    
    // Total & Status Counts
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM registrations WHERE is_active = 1 GROUP BY status");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $stats['total_registrations'] += $row['count'];
        $s = $row['status'] ?? 'pending';
        if (isset($stats[$s])) $stats[$s] = $row['count'];
    }
    
    // Governorate (Top 10)
    $stmt = $pdo->query("SELECT plate_governorate as name, COUNT(*) as count FROM registrations WHERE is_active=1 AND status='approved' GROUP BY plate_governorate ORDER BY count DESC LIMIT 10");
    $stats['by_governorate'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Car Type (Top 10)
    $stmt = $pdo->query("SELECT car_type as name, COUNT(*) as count FROM registrations WHERE is_active=1 AND status='approved' GROUP BY car_type ORDER BY count DESC LIMIT 10");
    $stats['by_car_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Participation Type
    $stmt = $pdo->query("SELECT participation_type as name, COUNT(*) as count FROM registrations WHERE is_active=1 AND status='approved' GROUP BY participation_type ORDER BY count DESC");
    $stats['by_participation_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Activation Status (Joined with Members)
    $stmt = $pdo->query("
        SELECT m.account_activated, COUNT(*) as count 
        FROM registrations r 
        JOIN members m ON r.member_id = m.id 
        WHERE r.is_active = 1 
        GROUP BY m.account_activated
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if ($row['account_activated']) {
            $stats['activated_members'] += $row['count'];
        } else {
            $stats['not_activated'] += $row['count'];
        }
    }
    
    // New vs Returning (Estimate based on member creation date vs reg date, or just simple logic)
    // For now, we can check if member has > 1 championship participation
    $stmt = $pdo->query("
        SELECT 
            CASE WHEN m.championships_participated > 1 THEN 'returning' ELSE 'new' END as type,
            COUNT(*) as count
        FROM registrations r
        JOIN members m ON r.member_id = m.id
        WHERE r.is_active = 1
        GROUP BY type
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if ($row['type'] === 'returning') $stats['returning_members'] = $row['count'];
        else $stats['new_members'] = $row['count'];
    }
    
    // Imported? (Check if permanent_code is number or specific pattern, or use created_at)
    // This is hard to distinguish purely from DB schema without specific flag, but we can assume 'approved' + 'created_at' == 'entry_time' roughly.
    // Let's rely on registration source if we added it, or skip for now.
    
    
    // 2. Members Statistics
    $stmt = $pdo->query("SELECT COUNT(*) FROM members WHERE is_active = 1");
    $stats['total_members'] = (int)$stmt->fetchColumn();
    
    
    // 3. Round Statistics (From entry_exit_logs)
    $roundStats = [
        'total_entries' => 0,
        'unique_participants' => 0,
        'by_round' => []
    ];
    
    // Check if table exists first (just to be safe, though schema should be there)
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM entry_exit_logs WHERE action = 'entry' AND round_id IS NOT NULL");
        $roundStats['total_entries'] = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(DISTINCT member_code) FROM entry_exit_logs WHERE action = 'entry' AND round_id IS NOT NULL");
        $roundStats['unique_participants'] = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT round_id as round, COUNT(*) as entries FROM entry_exit_logs WHERE action = 'entry' AND round_id IS NOT NULL GROUP BY round_id ORDER BY round_id");
        $roundStats['by_round'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {
        // Table might be missing or empty
    }
    
    $stats['rounds'] = $roundStats;
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'generated_at' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
