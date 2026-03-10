<?php
/**
 * Diagnostic script to test if data.json saves actually work
 * Access: https://yoursite.com/test_save_diag.php?wasel=XXXX
 * This is a read-only test (unless ?action=test_write is passed)
 */
header('Content-Type: application/json; charset=utf-8');

$dataFile = 'admin/data/data.json';

// Check 1: File exists and is readable
$checks = [];
$checks['file_exists'] = file_exists($dataFile);
$checks['is_readable'] = is_readable($dataFile);
$checks['is_writable'] = is_writable($dataFile);
$checks['file_size'] = file_exists($dataFile) ? filesize($dataFile) : 0;
$checks['file_perms'] = file_exists($dataFile) ? substr(sprintf('%o', fileperms($dataFile)), -4) : 'N/A';
$checks['dir_writable'] = is_writable(dirname($dataFile));

// Check 2: Can we read data.json?
$data = null;
$raw = @file_get_contents($dataFile);
if ($raw === false) {
    $checks['read_status'] = 'FAILED - cannot read file';
} else {
    $checks['read_status'] = 'OK';
    $checks['raw_length'] = strlen($raw);
    $data = json_decode($raw, true);
    if ($data === null) {
        $checks['json_parse'] = 'FAILED - ' . json_last_error_msg();
    } else {
        $checks['json_parse'] = 'OK';
        $checks['total_records'] = count($data);
        
        // Count by status
        $statuses = [];
        foreach ($data as $rec) {
            $s = $rec['status'] ?? 'unknown';
            $statuses[$s] = ($statuses[$s] ?? 0) + 1;
        }
        $checks['status_counts'] = $statuses;
    }
}

// Check 3: Find specific wasel
$wasel = $_GET['wasel'] ?? null;
if ($wasel && is_array($data)) {
    $found = false;
    foreach ($data as $i => $rec) {
        if (($rec['wasel'] ?? '') == $wasel) {
            $checks['found_wasel'] = true;
            $checks['wasel_index'] = $i;
            $checks['wasel_status'] = $rec['status'] ?? 'NOT SET';
            $checks['wasel_name'] = $rec['full_name'] ?? 'N/A';
            $checks['wasel_approved_date'] = $rec['approved_date'] ?? 'N/A';
            $checks['wasel_badge_token'] = !empty($rec['badge_token']) ? 'EXISTS' : 'MISSING';
            $found = true;
            break;
        }
    }
    if (!$found) {
        $checks['found_wasel'] = false;
    }
}

// Check 4: Test write (only if explicitly requested)
if (($_GET['action'] ?? '') === 'test_write' && $wasel && is_array($data)) {
    // Test 1: Can we write to a temp file?
    $tempFile = 'admin/data/test_write_' . time() . '.json';
    $testWrite = @file_put_contents($tempFile, '{"test":true}', LOCK_EX);
    $checks['test_temp_write'] = $testWrite !== false ? 'OK' : 'FAILED';
    if ($testWrite !== false) @unlink($tempFile);
    
    // Test 2: Try to read-modify-write data.json for a specific wasel
    // Add a test field, save, re-read, check
    foreach ($data as $i => $rec) {
        if (($rec['wasel'] ?? '') == $wasel) {
            $oldStatus = $data[$i]['status'] ?? 'pending';
            $data[$i]['_diag_test'] = date('Y-m-d H:i:s');
            
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $saveResult = file_put_contents($dataFile, $json, LOCK_EX);
            
            $checks['save_result'] = $saveResult !== false ? 'OK (wrote ' . $saveResult . ' bytes)' : 'FAILED';
            
            // Re-read to verify
            $reRead = json_decode(file_get_contents($dataFile), true);
            if ($reRead && isset($reRead[$i]['_diag_test'])) {
                $checks['verify_reread'] = 'OK - test field found';
                // Clean up test field
                unset($reRead[$i]['_diag_test']);
                file_put_contents($dataFile, json_encode($reRead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                $checks['cleanup'] = 'OK';
            } else {
                $checks['verify_reread'] = 'FAILED - test field NOT found after save';
            }
            break;
        }
    }
}

// Check 5: Check if there are any lock files or concurrent processes
$checks['php_sapi'] = php_sapi_name();
$checks['max_execution_time'] = ini_get('max_execution_time');
$checks['memory_limit'] = ini_get('memory_limit');
$checks['litespeed_finish_request'] = function_exists('litespeed_finish_request') ? 'AVAILABLE' : 'NOT AVAILABLE';
$checks['fastcgi_finish_request'] = function_exists('fastcgi_finish_request') ? 'AVAILABLE' : 'NOT AVAILABLE';

// Check 6: Check SQLite status for this wasel
if ($wasel) {
    try {
        $dbPath = __DIR__ . '/admin/data/database.sqlite';
        if (file_exists($dbPath)) {
            $pdo = new PDO('sqlite:' . $dbPath);
            $stmt = $pdo->prepare("SELECT id, wasel, status, full_name FROM registrations WHERE wasel = ?");
            $stmt->execute([$wasel]);
            $sqliteRec = $stmt->fetch(PDO::FETCH_ASSOC);
            $checks['sqlite_record'] = $sqliteRec ?: 'NOT FOUND';
        } else {
            $checks['sqlite'] = 'Database file not found';
        }
    } catch (Exception $e) {
        $checks['sqlite_error'] = $e->getMessage();
    }
}

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
