<?php
/**
 * Minimal approval test - just calls approve_registration.php logic
 * to see if it crashes and what the actual error is
 */
header('Content-Type: application/json; charset=utf-8');

// Read data.json to find a pending wasel
$dataFile = 'admin/data/data.json';
$data = json_decode(file_get_contents($dataFile), true);

$pendingList = [];
foreach ($data as $i => $rec) {
    if (($rec['status'] ?? '') === 'pending') {
        $pendingList[] = [
            'index' => $i,
            'wasel' => $rec['wasel'] ?? 'N/A',
            'name' => $rec['full_name'] ?? 'N/A'
        ];
    }
}

$wasel = $_GET['wasel'] ?? null;
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    echo json_encode(['pending' => $pendingList, 'count' => count($pendingList)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'test_approve' && $wasel) {
    // Find the registration
    $found = false;
    $index = null;
    $registration = null;
    foreach ($data as $i => $rec) {
        if (($rec['wasel'] ?? '') == $wasel) {
            $found = true;
            $index = $i;
            $registration = $rec;
            break;
        }
    }
    
    if (!$found) {
        echo json_encode(['error' => 'Wasel not found: ' . $wasel]);
        exit;
    }
    
    $result = [
        'step' => 'start',
        'wasel' => $wasel,
        'current_status' => $registration['status'] ?? 'unknown',
        'name' => $registration['full_name'] ?? 'N/A'
    ];
    
    // Step 1: Try setting status
    $data[$index]['status'] = 'approved';
    $data[$index]['approved_date'] = date('Y-m-d H:i:s');
    $data[$index]['approved_by'] = 'test_script';
    $result['step1_set_status'] = 'OK';
    
    // Step 2: Try saving
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $result['step2_json_encode'] = 'FAILED: ' . json_last_error_msg();
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result['step2_json_encode'] = 'OK (' . strlen($json) . ' bytes)';
    
    $saveResult = @file_put_contents($dataFile, $json, LOCK_EX);
    if ($saveResult === false) {
        $result['step3_save'] = 'FAILED';
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result['step3_save'] = 'OK (wrote ' . $saveResult . ' bytes)';
    
    // Step 3: Verify save
    $reRead = json_decode(file_get_contents($dataFile), true);
    $result['step4_verify'] = ($reRead[$index]['status'] ?? '') === 'approved' ? 'OK - status is approved' : 'FAILED - status is: ' . ($reRead[$index]['status'] ?? 'missing');
    
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Use ?action=list or ?action=test_approve&wasel=XXXXX']);
