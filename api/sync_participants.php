<?php
/**
 * Sync Participants API
 * Syncs participants from data.json to SQLite
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// Debug: Catch Fatal Errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
        // Ensure clean JSON output
        if (!headers_sent()) {
             header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'error' => 'FATAL: ' . $error['message'], 'details' => $error]);
    }
});

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/errors.php';
require_once __DIR__ . '/../include/helpers.php'; // Added for getCurrentChampionshipId

// Only admin can sync
if (!hasRole('admin')) {
    jsonError('UNAUTHORIZED', [], 401);
}

// Using __DIR__ based path based on successful debug test
$dataFile = __DIR__ . '/../admin/data/data.json';

if (!file_exists($dataFile)) {
    jsonError('INVALID_INPUT', ['detail' => 'data.json not found']);
}

try {
    $data = json_decode(file_get_contents($dataFile), true) ?? [];
    
    if (empty($data)) {
        jsonResponse(apiSuccess('لا توجد بيانات للمزامنة', ['synced' => 0]));
    }
    
    $pdo = db();
    $currentChampId = getCurrentChampionshipId();
    
    $synced = 0;
    $skipped = 0;
    $errors = [];
    $debug = [];
    
    // Direct SQL Statements for maximum reliability
    $checkMember = $pdo->prepare("SELECT id, permanent_code FROM members WHERE phone = ?");
    $checkMemberByCode = $pdo->prepare("SELECT id FROM members WHERE permanent_code = ?");
    
    // Schema on server might be missing updated_at, so we remove it for safety
    $insertMember = $pdo->prepare("INSERT INTO members (name, phone, permanent_code, created_at, is_active) VALUES (?, ?, ?, datetime('now', '+3 hours'), 1)");
    $updateMember = $pdo->prepare("UPDATE members SET name = ?, permanent_code = ? WHERE id = ?");
    
    $checkReg = $pdo->prepare("SELECT id FROM registrations WHERE member_id = ? AND championship_id = ?");
    // Also remove updated_at, registration_code, AND checked_in_at from registrations
    $insertReg = $pdo->prepare("INSERT INTO registrations (championship_id, member_id, wasel, status, car_type, plate_number, car_color, created_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now', '+3 hours'), 1)");
    $updateReg = $pdo->prepare("UPDATE registrations SET wasel = ?, status = ?, car_type = ?, plate_number = ?, car_color = ? WHERE id = ?");
    
    // Disabled transaction to force auto-commit and debug potential silent errors
    // $pdo->beginTransaction();
    
    foreach ($data as $reg) {
        $status = $reg['status'] ?? 'pending';
        // Only sync approved
        if ($status !== 'approved') {
            $skipped++;
            continue;
        }

        $badgeId = $reg['badge_id'] ?? $reg['badge_token'] ?? $reg['registration_code'] ?? '';
        $phone = $reg['phone'] ?? '';
        $name = $reg['full_name'] ?? $reg['name'] ?? '';
        
        // Normalize phone
        $searchPhone = $phone;
        try {
            if ($phone) $searchPhone = normalizePhone($phone);
        } catch (Exception $e) {}
        
        if (empty($name)) {
            $skipped++;
            continue;
        }

        try {
            // 1. Resolve Member
            // Strategy: Find by Badge ID first (Strongest link), then by Phone
            $memberId = null;
            $existingCode = null;
            
            if (!empty($badgeId)) {
                $checkMemberByCode->execute([$badgeId]);
                $found = $checkMemberByCode->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $memberId = $found['id'];
                }
            }
            
            if (!$memberId && !empty($searchPhone)) {
                $checkMember->execute([$searchPhone]);
                $found = $checkMember->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $memberId = $found['id'];
                    $existingCode = $found['permanent_code'];
                }
            }
            
            // Generate badge ID if missing
            if (empty($badgeId)) {
                if (!empty($existingCode) && strpos($existingCode, 'DRB-') === false) {
                    $badgeId = $existingCode; // Keep existing valid code
                } else {
                    $badgeId = 'DRB-' . substr(md5(uniqid() . $phone), 0, 8);
                }
            }
            
            if ($memberId) {
                // Update
                $updateMember->execute([$name, $badgeId, $memberId]);
            } else {
                // Insert
                $insertMember->execute([$name, $searchPhone, $badgeId]);
                $memberId = $pdo->lastInsertId();
            }
            
            // 2. Resolve Registration
            $wasel = $reg['wasel'] ?? '';
            // Registration code fallback to badgeId if empty
            $regCode = $reg['registration_code'] ?? $badgeId; 
            $carType = $reg['car_type'] ?? '';
            $plate = $reg['plate_full'] ?? '';
            $carColor = $reg['car_color'] ?? '';
            $entryTime = $reg['entry_time'] ?? null;
            
            $checkReg->execute([$memberId, $currentChampId]);
            $existingReg = $checkReg->fetch(PDO::FETCH_ASSOC);
            
            if ($existingReg) {
                // Update
                $updateReg->execute([
                    $wasel, $status, 
                    $carType, $plate, $carColor, 
                    $existingReg['id']
                ]);
            } else {
                // Insert
                $insertReg->execute([
                    $currentChampId, $memberId, $wasel,
                    $status, $carType, $plate, $carColor
                ]);
            }
            
            $synced++;
            $debug[] = "Wasel: $wasel | Name: $name | Phone: $phone | JsonBadge: " . ($reg['badge_id'] ?? 'NULL') . " => SavedCode: $badgeId";
            
        } catch (Exception $e) {
            $errors[] = "Error processing {$name}: " . $e->getMessage();
        }
    }
    
    // $pdo->commit();
    
    jsonResponse(apiSuccess('تمت المزامنة بنجاح (Auto-Commit)', [
        'synced' => $synced,
        'skipped' => $skipped,
        'errors' => $errors,
        'debug' => $debug, // Show what happened!
        'total_in_file' => count($data)
    ]));

} catch (Exception $e) {
    // if (isset($pdo) && $pdo->inTransaction()) {
    //     $pdo->rollBack();
    // }
    jsonError('DB_ERROR', ['detail' => $e->getMessage()], 500);
}
