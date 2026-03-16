<?php
/**
 * REPAIR SCRIPT: Fix Wasel ID Counter
 * 
 * This script:
 * 1. Re-indexes all wasel IDs in data.json to sequential values (1, 2, 3, ...)
 * 2. Resets the wasel_counter.json to the correct next value
 * 3. Creates backups before making changes
 * 
 * RUN THIS ONCE on the server, then delete it.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre style='direction:rtl; font-family:monospace; font-size:14px; padding:20px;'>\n";
echo "========================================\n";
echo "  REPAIR: Fixing Wasel ID Counter\n";
echo "========================================\n\n";

$dataFile = __DIR__ . '/admin/data/data.json';
$counterFile = __DIR__ . '/admin/data/wasel_counter.json';

if (!file_exists($dataFile)) {
    die("ERROR: data.json not found!\n");
}

// Backup
$backup = __DIR__ . '/admin/data/data_backup_wasel_' . date('Y-m-d_H-i-s') . '.json';
copy($dataFile, $backup);
echo "Backed up data.json to: " . basename($backup) . "\n\n";

$data = json_decode(file_get_contents($dataFile), true);
if (!is_array($data)) {
    die("ERROR: data.json is invalid!\n");
}

echo "Total records: " . count($data) . "\n\n";

// Show current wasel range
$wasels = array_map(fn($d) => intval($d['wasel'] ?? 0), $data);
if (!empty($wasels)) {
    echo "Current wasel range: min=" . min($wasels) . " max=" . max($wasels) . "\n";
    echo "Expected max should be: " . count($data) . "\n\n";
}

// Sort by original wasel to maintain order
usort($data, function($a, $b) {
    return intval($a['wasel'] ?? 0) - intval($b['wasel'] ?? 0);
});

// Re-index
$oldToNew = [];
foreach ($data as $i => &$record) {
    $oldWasel = $record['wasel'] ?? 0;
    $newWasel = $i + 1;
    if ($oldWasel != $newWasel) {
        echo "  [$oldWasel] -> [$newWasel] " . ($record['full_name'] ?? $record['name'] ?? 'Unknown') . "\n";
        $oldToNew[$oldWasel] = $newWasel;
    }
    $record['wasel'] = $newWasel;
}
unset($record);

// Save
$lockHandle = fopen($dataFile . '.lock', 'w');
if ($lockHandle) flock($lockHandle, LOCK_EX);

file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Reset counter
$nextWasel = count($data) + 1;
file_put_contents($counterFile, json_encode(['next_wasel' => $nextWasel], JSON_PRETTY_PRINT));

if ($lockHandle) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }

echo "\n========================================\n";
echo "  RESULTS\n";
echo "========================================\n";
echo "Re-indexed: " . count($oldToNew) . " records\n";
echo "Next wasel set to: $nextWasel\n";
echo "New wasel range: 1 to " . count($data) . "\n";
echo "\n✅ WASEL REPAIR COMPLETE!\n";
echo "\n⚠ DELETE THIS FILE (repair_wasel.php) from the server after running!\n";
echo "</pre>\n";
?>
