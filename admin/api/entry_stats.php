<?php
/**
 * Entry Stats API - إحصائيات الدخول
 * يدعم نظام البوابتين (Two-Gate System)
 */

header('Content-Type: application/json; charset=utf-8');

$dataFile = __DIR__ . '/../data/data.json';

if (!file_exists($dataFile)) {
    echo json_encode(['success' => false, 'message' => 'No data']);
    exit;
}

$data = json_decode(file_get_contents($dataFile), true);
$twoGateMode = isset($_GET['two_gate']) && $_GET['two_gate'] == '1';

$approved = 0;
$entered = 0;          // دخلوا بالكامل (البوابتين)
$remaining = 0;        // لم يبدأوا
$waitingGate2 = 0;     // أكملوا البوابة 1، بانتظار البوابة 2
$gate1Only = 0;        // مسح من البوابة 1 فقط

foreach ($data as $reg) {
    if (($reg['status'] ?? '') === 'approved') {
        $approved++;
        
        $hasEntered = ($reg['has_entered'] ?? false) === true;
        $gate1Scanned = ($reg['gate1_scanned'] ?? false) === true;
        $gate2Scanned = ($reg['gate2_scanned'] ?? false) === true;
        
        if ($hasEntered) {
            // دخل بالكامل
            $entered++;
        } elseif ($gate1Scanned && !$gate2Scanned) {
            // أكمل البوابة 1، بانتظار البوابة 2
            $waitingGate2++;
            $gate1Only++;
        } else {
            // لم يبدأ
            $remaining++;
        }
    }
}

$response = [
    'success' => true,
    'total_approved' => $approved,
    'entered' => $entered,
    'remaining' => $remaining
];

// إضافة إحصائيات البوابتين
if ($twoGateMode) {
    $response['fully_entered'] = $entered;
    $response['waiting_gate2'] = $waitingGate2;
    $response['not_started'] = $remaining;
    $response['gate1_only'] = $gate1Only;
}

echo json_encode($response);
