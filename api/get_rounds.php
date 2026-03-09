<?php
/**
 * Get Rounds API (JSON Version)
 */
require_once '../include/auth.php';
require_once '../include/errors.php';
header('Content-Type: application/json; charset=utf-8');
ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

if (!isLoggedIn()) {
    ob_end_clean();
    jsonError('UNAUTHORIZED', [], 401);
}

try {
    // Load Rounds
    $roundsFile = __DIR__ . '/../admin/data/rounds.json';
    $rounds = [];
    if (file_exists($roundsFile)) {
        $rounds = json_decode(file_get_contents($roundsFile), true) ?? [];
    } else {
        $rounds = [
            ['id' => 1, 'round_number' => 1, 'round_name' => 'الجولة الأولى', 'is_active' => 1],
            ['id' => 2, 'round_number' => 2, 'round_name' => 'الجولة الثانية', 'is_active' => 1],
            ['id' => 3, 'round_number' => 3, 'round_name' => 'الجولة الثالثة', 'is_active' => 1]
        ];
    }
    
    // Load Data for total participants (approved + specific participation_type)
    $dataFile = __DIR__ . '/../admin/data/data.json';
    $totalParticipants = 0;
    $allowedTypes = ['المشاركة بالاستعراض الحر', 'free_show']; // Filter for rounds
    $eligiblePids = []; // List of eligible participant wasel IDs

    if (file_exists($dataFile)) {
        $data = json_decode(file_get_contents($dataFile), true) ?? [];
        foreach ($data as $reg) {
            $isApproved = ($reg['status'] ?? '') === 'approved';
            $pType = $reg['participation_type'] ?? '';
            if ($isApproved && in_array($pType, $allowedTypes)) {
                $totalParticipants++;
                $eligiblePids[] = $reg['wasel'] ?? '';
            }
        }
    }

    // Load Logs
    $logsFile = __DIR__ . '/../admin/data/round_logs.json';
    $logs = [];
    if (file_exists($logsFile)) {
        $logs = json_decode(file_get_contents($logsFile), true) ?? [];
    }
    
    // Calculate Stats (only count eligible/free_show participants)
    foreach ($rounds as &$r) {
        $rid = $r['id'];
        
        $rLogs = array_filter($logs, function($l) use ($rid) { 
            return $l['round_id'] == $rid && $l['action'] === 'enter'; 
        });
        
        // Count distinct ELIGIBLE participants who entered
        $enteredPids = array_unique(array_column($rLogs, 'participant_id'));
        $eligibleEnteredCount = 0;
        foreach ($enteredPids as $pid) {
            if (in_array($pid, $eligiblePids)) {
                $eligibleEnteredCount++;
            }
        }
        
        $r['total_entered'] = $eligibleEnteredCount;
        $r['total_participants'] = $totalParticipants;
        $r['remaining'] = max(0, $totalParticipants - $eligibleEnteredCount);
        $r['currently_in'] = 0;
        $r['total_exited'] = 0;
    }
    
    if (ob_get_length()) ob_end_clean();
    
    echo json_encode(['success' => true, 'data' => [
        'rounds' => $rounds,
        'total_participants' => $totalParticipants
    ]]);
    
} catch (Exception $e) {
    error_log("get_rounds error: " . $e->getMessage());
    jsonError('ERROR', ['message' => $e->getMessage()], 500);
}
