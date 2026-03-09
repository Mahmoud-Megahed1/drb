<?php
/**
 * Entry Statistics API
 * Returns count of entered users vs remaining
 */

header('Content-Type: application/json');

// Define paths
$dataFile = __DIR__ . '/../admin/data/data.json';

// Initialize counters
$entered = 0;
$total = 0;

if (file_exists($dataFile)) {
    $data = json_decode(file_get_contents($dataFile), true);
    
    if (is_array($data)) {
        foreach ($data as $reg) {
            // Only count approved registrations
            if (($reg['status'] ?? '') === 'approved') {
                $total++;
                if (!empty($reg['has_entered'])) {
                    $entered++;
                }
            }
        }
    }
}

// Return stats
echo json_encode([
    'success' => true,
    'total' => $total,
    'entered' => $entered,
    'remaining' => $total - $entered
]);
