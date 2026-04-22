<?php
/**
 * Get Round Participants API
 * Returns list of participants (Entered or Remaining) for a specific round
 */

require_once '../include/auth.php';
require_once '../include/errors.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    jsonError('UNAUTHORIZED', [], 401);
}

$roundId = intval($_GET['round_id'] ?? 0);
$status = $_GET['status'] ?? 'entered'; // 'entered' or 'remaining'

if (!$roundId) {
    jsonError('INVALID_INPUT', ['message' => 'رقم الجولة مطلوب']);
}

try {
    // 1. Load Data
    $dataFile = __DIR__ . '/../admin/data/data.json';
    $allParticipants = [];
    if (file_exists($dataFile)) {
        $allParticipants = json_decode(file_get_contents($dataFile), true) ?? [];
    }
    
    // Filter approved + allowed type + entered through main gate
    // Only show participants who are actually inside the garage (has_entered=true)
    $allowedTypes = ['المشاركة بالاستعراض الحر', 'free_show'];
    $eligibleParticipants = array_filter($allParticipants, function($reg) use ($allowedTypes) {
        $isApproved = ($reg['status'] ?? '') === 'approved';
        $pType = $reg['participation_type'] ?? '';
        $hasEntered = !empty($reg['has_entered']); // Must have entered main gate
        return $isApproved && in_array($pType, $allowedTypes) && $hasEntered;
    });

    // 2. Load Logs to find who entered
    $logsFile = __DIR__ . '/../admin/data/round_logs.json';
    $logs = [];
    if (file_exists($logsFile)) {
        $logs = json_decode(file_get_contents($logsFile), true) ?? [];
    }
    
    $rLogs = array_filter($logs, function($l) use ($roundId) { 
        return $l['round_id'] == $roundId && $l['action'] === 'enter'; 
    });
    $enteredIds = array_unique(array_column($rLogs, 'participant_id')); // array of 'wasel' usually
    
    $resultParticipants = [];
    
    if ($status === 'entered') {
        foreach ($eligibleParticipants as $p) {
            $pId = $p['wasel'] ?? ''; // round_logs usually stores wasel as participant_id
            if (in_array($pId, $enteredIds)) {
                $resultParticipants[] = [
                    'name' => $p['full_name'] ?? $p['name'] ?? '',
                    'registration_code' => $p['registration_code'] ?? '',
                    'wasel' => $p['wasel'] ?? '',
                    'car_type' => $p['car_type'] ?? '',
                    'car_number' => $p['car_number'] ?? '',
                    'phone' => $p['phone'] ?? '',
                    'participation_type' => $p['participation_type'] ?? '',
                    'participation_type_label' => $p['participation_type_label'] ?? $p['participation_type'] ?? '',
                    'badge_id' => $p['registration_code'] ?? $p['wasel'] ?? ''
                ];
            }
        }
    } else {
        // Remaining
        foreach ($eligibleParticipants as $p) {
            $pId = $p['wasel'] ?? '';
            if (!in_array($pId, $enteredIds)) {
                 $resultParticipants[] = [
                    'name' => $p['full_name'] ?? $p['name'] ?? '',
                    'registration_code' => $p['registration_code'] ?? '',
                    'wasel' => $p['wasel'] ?? '',
                    'car_type' => $p['car_type'] ?? '',
                    'car_number' => $p['car_number'] ?? '',
                    'phone' => $p['phone'] ?? '',
                    'participation_type' => $p['participation_type'] ?? '',
                    'participation_type_label' => $p['participation_type_label'] ?? $p['participation_type'] ?? '',
                    'badge_id' => $p['registration_code'] ?? $p['wasel'] ?? ''
                ];
            }
        }
    }
    
    // Sort logic by name alphabetically
    usort($resultParticipants, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    
    echo json_encode([
        'success' => true,
        'participants' => $resultParticipants,
        'count' => count($resultParticipants)
    ]);

} catch (Exception $e) {
    error_log("get_round_participants error: " . $e->getMessage());
    jsonError('ERROR', ['message' => $e->getMessage()], 500);
}
