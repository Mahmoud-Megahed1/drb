<?php
/**
 * Participation Type Statistics API
 * ???????? ????? ????????
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

// Check auth
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => '??? ????']);
    exit;
}

$dataFile = __DIR__ . '/../admin/data/data.json';
$settingsFile = __DIR__ . '/../admin/data/registration_settings.json';

// Load participation type labels
$participationLabels = [
    'free_show' => '???????? ?????????? ????',
    'special_car' => '???????? ?????? ????? ???',
    'burnout' => '???????? ??????? Burnout',
    'unknown' => '??? ????'
];

if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    if (!empty($settings['participation_types'])) {
        foreach ($settings['participation_types'] as $pt) {
            $participationLabels[$pt['id']] = $pt['label'];
        }
    }
}

// Load data
$data = [];
if (file_exists($dataFile)) {
    $data = json_decode(file_get_contents($dataFile), true) ?? [];
}

// Filter non-archived
$data = array_filter($data, fn($item) => !isset($item['remove']) || $item['remove'] != 1);

// Count by participation type
$stats = [];
$total = 0;

foreach ($data as $item) {
    $partType = $item['participation_type'] ?? 'unknown';
    if (!isset($stats[$partType])) {
        $stats[$partType] = [
            'id' => $partType,
            'label' => $participationLabels[$partType] ?? $partType,
            'count' => 0,
            'approved' => 0,
            'pending' => 0,
            'rejected' => 0
        ];
    }
    $stats[$partType]['count']++;
    $total++;
    
    $status = $item['status'] ?? 'pending';
    if ($status === 'approved') $stats[$partType]['approved']++;
    elseif ($status === 'pending') $stats[$partType]['pending']++;
    elseif ($status === 'rejected') $stats[$partType]['rejected']++;
}

// Calculate percentages
foreach ($stats as $key => $stat) {
    $stats[$key]['percentage'] = $total > 0 ? round(($stat['count'] / $total) * 100, 1) : 0;
}

// Sort by count desc
usort($stats, fn($a, $b) => $b['count'] - $a['count']);

// Chart colors
$colors = [
    'free_show' => '#3b82f6',      // Blue
    'special_car' => '#ef4444',    // Red
    'burnout' => '#f59e0b',        // Orange
    'unknown' => '#6b7280'         // Gray
];

// Add colors to stats
foreach ($stats as $key => $stat) {
    $stats[$key]['color'] = $colors[$stat['id']] ?? '#' . substr(md5($stat['id']), 0, 6);
}

echo json_encode([
    'success' => true,
    'total' => $total,
    'stats' => array_values($stats),
    'labels' => $participationLabels
], JSON_UNESCAPED_UNICODE);
