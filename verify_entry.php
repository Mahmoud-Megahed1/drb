<?php
/**
 * Verify Entry API
 * Handles QR code scanning and badge verification
 * 
 * تحديث: إضافة سجلات مفصلة لكل عملية
 */

header('Content-Type: application/json');
session_start();

// Check if logged in (Admin or Gate User)
$isGateUser = isset($_SESSION['gate_user']) && $_SESSION['gate_user'] === true;

// Check system user - support both object and array session formats
$isSystemUser = false;
$operatorName = 'غير معروف';
$operatorRole = '';
$operatorDepartment = 'البوابة';

// 1. Check New SQLite Auth System
if (isset($_SESSION['user_role']) || isset($_SESSION['user_id'])) {
    $isSystemUser = in_array($_SESSION['user_role'] ?? '', ['root', 'gate', 'admin', 'scanner', 'rounds', 'notes']);
    $operatorName = $_SESSION['username'] ?? 'غير معروف';
    $operatorRole = $_SESSION['user_role'] ?? '';
} 
// 2. Check Legacy Auth System
elseif (isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
    
    if (is_object($user)) {
        $isSystemUser = in_array($user->role ?? '', ['root', 'gate', 'admin', 'scanner']) || isset($user->username);
        $operatorName = $user->full_name ?? $user->username ?? 'غير معروف';
        $operatorRole = $user->role ?? '';
        $operatorDepartment = $user->department ?? 'البوابة';
    } elseif (is_array($user)) {
        $isSystemUser = in_array($user['role'] ?? '', ['root', 'gate', 'admin', 'scanner']) || isset($user['username']);
        $operatorName = $user['full_name'] ?? $user['username'] ?? 'غير معروف';
        $operatorRole = $user['role'] ?? '';
        $operatorDepartment = $user['department'] ?? 'البوابة';
    } else {
        $isSystemUser = true;
    }
}

if (!$isGateUser && !$isSystemUser) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$dataFile = __DIR__ . '/admin/data/data.json';
$logsFile = __DIR__ . '/admin/data/entry_logs.json';

if (!file_exists($dataFile)) {
    echo json_encode(['success' => false, 'message' => 'Database not found']);
    exit;
}

// Get input
$action = $_GET['action'] ?? '';
$badgeId = $_GET['badge_id'] ?? '';
$token = $_GET['token'] ?? '';
$wasel = $_GET['wasel'] ?? '';

// Search for registration
$data = json_decode(file_get_contents($dataFile), true) ?? [];
$logs = file_exists($logsFile) ? (json_decode(file_get_contents($logsFile), true) ?? []) : [];
$membersFile = __DIR__ . '/admin/data/members.json';
$membersData = file_exists($membersFile) ? (json_decode(file_get_contents($membersFile), true) ?? []) : [];

$targetIndex = -1;
$registration = null;
$searchId = $badgeId ?: $token ?: $wasel;
$source = '';

// Strategy:
// 1. Try to find the token/id in data.json (Source of Truth for Tokens)
// 2. If found, use the 'registration_code' or 'wasel' to look up members.json (Source of Truth for Member Data)
// 3. Fallback to data.json data if not in members.json

$foundInData = null;
$foundIndex = -1;

foreach ($data as $index => $reg) {
    $match = false;
    // Check all possible identifiers, IGNORING status for now (we check status later)
    if (!empty($badgeId)) {
        $match = (isset($reg['badge_id']) && $reg['badge_id'] === $badgeId) || 
                 (isset($reg['badge_token']) && $reg['badge_token'] === $badgeId) ||
                 (isset($reg['registration_code']) && strcasecmp($reg['registration_code'], $badgeId) === 0) ||
                 // Search by plate number (without governorate prefix)
                 (isset($reg['plate_number']) && strval($reg['plate_number']) === strval($badgeId)) ||
                 (isset($reg['plate_full']) && strval($reg['plate_full']) === strval($badgeId));
        
        // Partial plate search: if badge_id is numeric, try matching plate_number
        if (!$match && is_numeric($badgeId)) {
            $regPlate = strval($reg['plate_number'] ?? '');
            if (!empty($regPlate) && $regPlate === strval($badgeId)) {
                $match = true;
            }
        }
    } elseif (!empty($token)) {
        $match = (isset($reg['badge_token']) && $reg['badge_token'] === $token) ||
                 (isset($reg['session_badge_token']) && $reg['session_badge_token'] === $token) ||
                 (isset($reg['badge_id']) && $reg['badge_id'] === $token) ||
                 (isset($reg['registration_code']) && strcasecmp($reg['registration_code'], $token) === 0);
    } elseif (!empty($wasel)) {
        $regWasel = $reg['wasel'] ?? $reg['id'] ?? '';
        // Loose comparison for numbers
        if (is_numeric($regWasel) && is_numeric($wasel)) {
             $match = ((int)$regWasel === (int)$wasel);
        } else {
             $match = (strval($regWasel) === strval($wasel));
        }
        if (!$match) {
            $match = (isset($reg['registration_code']) && strcasecmp($reg['registration_code'], $wasel) === 0) ||
                     (isset($reg['badge_token']) && $reg['badge_token'] === $wasel);
        }
    }
    
    if ($match) {
        $foundInData = $reg;
        $foundIndex = $index;
        break; // Match found
    }
}

// Now resolve to a Member or Valid Registration
if ($foundInData) {
    // We found a record in data.json. Now check members.json
    $linkCode = $foundInData['registration_code'] ?? $foundInData['wasel'] ?? '';
    
    if (isset($membersData[$linkCode])) {
        // Found in members.json -> IT IS A MEMBER
        $registration = $membersData[$linkCode];
        $registration['permanent_code'] = $linkCode;
        $registration['badge_token'] = $foundInData['badge_token'] ?? ''; // Preserve token
        $registration['registration_code'] = $linkCode;
        // FIXED: Preserve actual status from data.json - don't auto-approve
        $registration['status'] = $foundInData['status'] ?? 'pending';
        // Merge latest has_entered from data.json if available
        $registration['has_entered'] = $foundInData['has_entered'] ?? false;
        $registration['entry_time'] = $foundInData['entry_time'] ?? null;
        $registration['entered_by'] = $foundInData['entered_by'] ?? null;
        
        $source = 'members_linked';
        $targetIndex = $foundIndex; // We update data.json
        
        // FIXED: Check if actually approved in the current championship
        if (($registration['status'] ?? '') !== 'approved') {
            echo json_encode([
                'success' => false, 
                'message' => 'التسجيل غير معتمد بعد (الحالة: ' . ($registration['status'] ?? 'pending') . ')'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        // Not in members.json. Check if approved in data.json
        if (($foundInData['status'] ?? '') === 'approved') {
            $registration = $foundInData;
            $source = 'data_approved';
            $targetIndex = $foundIndex;
        } else {
            // Found keys but NOT approved and NOT in members.json
            echo json_encode(['success' => false, 'message' => 'التسجيل غير معتمد (Status: ' . ($foundInData['status'] ?? 'unknown') . ')']);
            exit;
        }
    }
} else {
    // Not found in data.json. Try searching members.json directly (Maybe strict key or manual entry)
    foreach ($membersData as $key => $m) {
        $s = $searchId;
        if (
            ($key === $s) ||
            (($m['badge_token'] ?? '') === $s) ||
            (($m['badge_id'] ?? '') == $s) ||
            (($m['wasel'] ?? '') == $s) ||
            (($m['phone'] ?? '') === $s)
        ) {
            $registration = $m;
            $registration['wasel'] = $m['wasel'] ?? $key;
            $registration['registration_code'] = $key;
            $registration['status'] = 'not_registered'; // NOT approved - old member not in current championship
            $registration['has_entered'] = false;
            $source = 'members_direct';
            break;
        }
    }
}

// SQL Fallback (kept for compatibility)
if (!$registration) {
    require_once 'include/db.php';
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM participants WHERE badge_id = ?");
        $stmt->execute([$searchId]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($participant) {
            $registration = [
                'full_name' => $participant['name'],
                'car_type' => $participant['car_type'],
                'plate_full' => $participant['plate_number'],
                'wasel' => $participant['wasel'],
                'registration_code' => $participant['registration_code'],
                'status' => 'approved',
                'badge_token' => $participant['badge_id']
            ];
            $source = 'db';
            
            // Try to find index in data.json to update
            foreach ($data as $idx => $d) {
                if (($d['registration_code'] ?? '') === $registration['registration_code']) {
                    $targetIndex = $idx;
                    $foundInData = $d;
                    break;
                }
            }
        }
    } catch (Exception $e) {}
}

if (!$registration) {
    // DEBUG: Return what we searched for to help user
    echo json_encode(['success' => false, 'message' => 'التسجيل غير موجود (' . htmlspecialchars(substr($searchId, 0, 10)) . '...)']);
    exit;
}

// Block old members not registered in current championship
if ($source === 'members_direct') {
    if ($action === 'checkin') {
        echo json_encode([
            'success' => false,
            'status' => 'not_registered',
            'message' => 'هذا العضو موجود كعضو قديم لكنه غير مسجل في البطولة الحالية. يرجى التسجيل أولاً.',
            'member_info' => [
                'name' => $registration['full_name'] ?? '',
                'car' => $registration['car_type'] ?? '',
                'plate' => $registration['plate_full'] ?? '',
                'wasel' => $registration['wasel'] ?? '',
                'is_old_member' => true
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // For verify only (no action), return info with not-registered flag
    echo json_encode([
        'success' => true,
        'status' => 'not_registered',
        'message' => 'عضو قديم - غير مسجل في البطولة الحالية',
        'data' => [
            'name' => $registration['full_name'] ?? '',
            'car' => $registration['car_type'] ?? '',
            'plate' => $registration['plate_full'] ?? '',
            'has_entered' => false,
            'wasel' => $registration['wasel'] ?? '',
            'is_old_member' => true,
            'not_registered_current' => true
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * دالة حفظ السجل
 */
function saveEntryLog($logsFile, $logData) {
    $logs = file_exists($logsFile) ? (json_decode(file_get_contents($logsFile), true) ?? []) : [];
    $logData['timestamp'] = date('Y-m-d H:i:s');
    $logData['log_id'] = uniqid('ENTRY_');
    $logs[] = $logData;
    if (count($logs) > 10000) {
        $logs = array_slice($logs, -10000);
    }
    file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Perform Action
if ($action === 'checkin') {
    // Check if already entered
    if (($registration['has_entered'] ?? false) === true) {
        echo json_encode([
            'success' => true,
            'status' => 'already_entered',
            'message' => 'تم الدخول مسبقاً',
            'name' => $registration['full_name'],
            'entry_time' => $registration['entry_time'] ?? '',
            'car' => $registration['car_type'],
            'plate' => $registration['plate_full'],
            'badge_id' => $registration['badge_token'] ?? $registration['badge_id'] ?? $searchId ?? '',
            'wasel' => $registration['wasel'],
            'assigned_time' => $registration['assigned_time'] ?? '',
            'assigned_date' => $registration['assigned_date'] ?? '',
            'assigned_order' => $registration['assigned_order'] ?? 0,
            'entered_by' => $registration['entered_by'] ?? '',
            'permanent_id' => $registration['registration_code'] ?? $registration['wasel'] // For profile link and violations
        ]);
        exit;
    }
    
    // Check for warnings in members.json
    $membersFile = __DIR__ . '/admin/data/members.json';
    $warnings = [];
    if (file_exists($membersFile)) {
        $membersData = json_decode(file_get_contents($membersFile), true) ?? [];
        $memCode = $registration['registration_code'] ?? $registration['wasel'] ?? '';
        if (isset($membersData[$memCode])) {
            $warnings = $membersData[$memCode]['warnings'] ?? [];
        }
    }

    // ADDED: Fetch Unified Violations (Warnings + Notes) from DB (Manual Query)
    try {
        if (!function_exists('db')) require_once 'include/db.php';
        $pdo = db();
        
        $regCode = $registration['registration_code'] ?? '';
        $wasel = $registration['wasel'] ?? '';
        
        // Find Member ID
        $memId = null;
        if (!empty($regCode)) {
            $stmt = $pdo->prepare("SELECT id FROM members WHERE permanent_code = ? LIMIT 1");
            $stmt->execute([$regCode]);
            $memId = $stmt->fetchColumn();
        }
        
        // Robust Lookup
        if (!$memId) {
             $searchToken = $badgeId ?: $token ?: $wasel ?: '';
             if (!empty($wasel) || (is_numeric($searchToken) && strlen($searchToken) < 10)) {
                 $w = !empty($wasel) ? $wasel : $searchToken;
                 $stmt = $pdo->prepare("SELECT member_id FROM registrations WHERE wasel = ? LIMIT 1");
                 $stmt->execute([$w]);
                 $memId = $stmt->fetchColumn();
             }
        }
        
        if ($memId) {
            // Get Phone for Unification
            $stmt = $pdo->prepare("SELECT phone FROM members WHERE id = ?");
            $stmt->execute([$memId]);
            $phone = $stmt->fetchColumn();
            
            $phoneCondition = "";
            $phoneParams = [$memId];
            if (!empty($phone) && strlen($phone) > 5) {
                 $phoneCondition = "OR member_id IN (SELECT id FROM members WHERE phone = ?)";
                 $phoneParams[] = $phone;
            }

            // 1. Get Active DB Warnings
            $stmt = $pdo->prepare("SELECT warning_text, severity, created_at FROM warnings WHERE (member_id = ? $phoneCondition) AND is_resolved = 0");
            $stmt->execute($phoneParams);
            $dbWarnings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dbWarnings as $dw) {
                $warnings[] = [
                    'text' => $dw['warning_text'],
                    'severity' => $dw['severity'],
                    'date' => substr($dw['created_at'], 0, 10),
                    'type' => 'warning'
                ];
            }
            
            // 2. Get Active DB Notes (Warning/Blocker)
            $stmt = $pdo->prepare("SELECT note_text, note_type, created_at FROM notes WHERE (member_id = ? $phoneCondition) AND note_type IN ('warning', 'blocker') AND is_resolved = 0");
            $stmt->execute($phoneParams);
            $dbNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dbNotes as $dn) {
                $warnings[] = [
                    'text' => '[ملاحظة] ' . $dn['note_text'],
                    'severity' => ($dn['note_type'] == 'blocker' ? 'high' : 'medium'),
                    'date' => substr($dn['created_at'], 0, 10),
                    'type' => 'note'
                ];
            }
        }
    } catch(Exception $e) {
        // Silent fail
    }

    // ADDED: Calculate Rounds Count (Al-Nazlat)
    // Disabled temporarily to fix warnings issue
    $roundsCount = 0;
    /*
    $roundLogsFile = __DIR__ . '/admin/data/round_logs.json';
    if (file_exists($roundLogsFile)) {
        // ... Logic commented out for safety ...
    }
    */

    // Update status
    // FIXED: Block entry for members not in data.json (not registered in current championship)
    if ($targetIndex === -1) {
        // Member exists in DB/members.json but NOT in current championship data.json
        // Do NOT auto-create a registration entry
        echo json_encode([
            'success' => false,
            'status' => 'not_registered',
            'message' => 'هذا العضو غير مسجل في البطولة الحالية. لا يمكن تسجيل دخوله.',
            'member_info' => [
                'name' => $registration['full_name'] ?? '',
                'car' => $registration['car_type'] ?? '',
                'plate' => $registration['plate_full'] ?? '',
                'wasel' => $registration['wasel'] ?? '',
                'is_old_member' => true
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $data[$targetIndex]['has_entered'] = true;
        $data[$targetIndex]['entry_time'] = date('Y-m-d H:i:s');
        $data[$targetIndex]['entered_by'] = $operatorName;
        $data[$targetIndex]['entered_department'] = $operatorDepartment;
    }
    
    // Save log
    saveEntryLog($logsFile, [
        'action' => 'single_gate_entry',
        'member_wasel' => $registration['wasel'],
        'badge_id' => $registration['badge_token'] ?? '', 
        'registration_code' => $registration['registration_code'] ?? '', 
        'member_name' => $registration['full_name'],
        'operator_name' => $operatorName,
        'operator_role' => $operatorRole,
        'operator_department' => $operatorDepartment,
        'result' => 'entry_confirmed',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'warnings_count' => count($warnings),
        'rounds_count' => $roundsCount // Log it too
    ]);
    
    // Save
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Save log using EntryExitLogger (DB)
    try {
        require_once 'include/EntryExitLogger.php';
        $logger = new EntryExitLogger();
        $logger->log(
            $registration['registration_code'] ?? $registration['wasel'], // Member Code
            'entry', // Action
            'main', // Gate (Default)
            [
                'member_name' => $registration['full_name'],
                'round_id' => 0, // 0 for "Practice/General" or fetch current round if dynamic
                'championship_id' => $settings['current_championship_id'] ?? null,
                'scanned_by' => $operatorName,
                'device' => 'qr_scanner',
                'notes' => count($warnings) > 0 ? 'Verified with warnings' : 'Verified'
            ]
        );
        
        // Recalculate rounds count from DB directly
        $roundsCount = $logger->getRoundsCount($registration['registration_code'] ?? $registration['wasel']);
        
    } catch (Exception $e) {
        // error_log($e->getMessage());
    }

    // Admin Logger Integration
    try {
        require_once __DIR__ . '/include/AdminLogger.php';
        $adminLogger = new AdminLogger();
        $adminLogger->log(
            'gate_entry',
            $operatorName,
            "دخول المشارك {$registration['full_name']} ({$registration['wasel']})",
            [
                'participant_id' => $registration['wasel'],
                'participant_name' => $registration['full_name'],
                'car' => $registration['car_type'] ?? '',
                'badge' => $registration['badge_token'] ?? ''
            ]
        );
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
    echo json_encode([
        'success' => true,
        'status' => 'checked_in',
        'message' => 'تم تسجيل الدخول بنجاح',
        'name' => $registration['full_name'],
        'entry_time' => $data[$targetIndex]['entry_time'],
        'car' => $registration['car_type'],
        'plate' => $registration['plate_full'],
        'badge_id' => $registration['badge_token'] ?? $registration['badge_id'] ?? $searchId ?? '',
        'wasel' => $registration['wasel'],
            'assigned_time' => $registration['assigned_time'] ?? '',
            'assigned_date' => $registration['assigned_date'] ?? '',
            'assigned_order' => $registration['assigned_order'] ?? 0,
        'entered_by' => $operatorName,
        'warnings' => $warnings,
        'rounds_count' => $roundsCount, // Return to scanner
        'permanent_id' => $registration['registration_code'] ?? $registration['wasel'] // For profile link
    ]);
    exit;
}

// Just verify details without checkin
echo json_encode([
    'success' => true,
    'status' => 'found',
    'data' => [
        'name' => $registration['full_name'],
        'car' => $registration['car_type'],
        'plate' => $registration['plate_full'],
        'has_entered' => $registration['has_entered'] ?? false,
        'wasel' => $registration['wasel'],
            'assigned_time' => $registration['assigned_time'] ?? '',
            'assigned_date' => $registration['assigned_date'] ?? '',
            'assigned_order' => $registration['assigned_order'] ?? 0,
        'entered_by' => $registration['entered_by'] ?? null
    ]
]);
