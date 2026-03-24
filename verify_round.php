<?php
/**
 * Verify Round API (JSON Version)
 * Handles enter/exit for rounds reading from data.json
 * 
 * UPDATES:
 * - منع دخول الجولة 3 مرتين
 * - تسجيل جميع العمليات في Admin Log
 * - إلغاء الخروج (دخول فقط)
 */

require_once 'include/auth.php';
require_once 'include/errors.php';
require_once 'include/AdminLogger.php'; 
require_once 'include/EntryExitLogger.php'; // NEW: Entry/Exit status check

header('Content-Type: application/json; charset=utf-8');

ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// DEBUG LOGGING
function scanLog($msg) {
    file_put_contents(__DIR__ . '/scan_debug.log', date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}
scanLog("Hit verify_round: " . json_encode($_POST));

if (!hasPermission('rounds')) {
    ob_end_clean();
    jsonError('UNAUTHORIZED', [], 401);
}

// 2. Input Validation
$badge_id = trim($_POST['badge_id'] ?? '');
if (strpos($badge_id, 'http') !== false || strpos($badge_id, '?') !== false) {
    $queryString = parse_url($badge_id, PHP_URL_QUERY);
    parse_str($queryString, $params);
    if (!empty($params['token'])) {
        $badge_id = $params['token'];
    } elseif (!empty($params['badge_id'])) {
        $badge_id = $params['badge_id'];
    }
}

$round_id = intval($_POST['round_id'] ?? 0);
$action = $_POST['action'] ?? '';
$device = $_POST['device'] ?? 'unknown';

// NEW: Exit action is disabled - only entry allowed from scanner
if ($action === 'exit') {
    jsonError('EXIT_DISABLED', ['message' => 'عذراً - النظام يعمل بنظام الدخول فقط (Entry Only)'], 400);
}

// Default action to enter if missing (since exit is removed)
if (empty($action)) $action = 'enter';

if (empty($badge_id) || !$round_id || $action !== 'enter') {
    jsonError('INVALID_INPUT', ['message' => 'بيانات غير مكتملة']);
}

try {
    // 3. Load Data
    $dataFile = __DIR__ . '/admin/data/data.json';
    if (!file_exists($dataFile)) {
        throw new ApiException('DATA_FILE_NOT_FOUND');
    }
    
    $participants = json_decode(file_get_contents($dataFile), true) ?? [];
    $participant = null;
    $pIndex = -1;
    $matchedParticipants = [];

    // 4. Find Participant (Enhanced Search)
    foreach ($participants as $index => $p) {
        $pParams = [
            strval($p['wasel'] ?? ''), 
            strval($p['registration_code'] ?? ''), 
            strval($p['badge_id'] ?? ''),
            strval($p['badge_token'] ?? ''),
            strval($p['plate_number'] ?? ''),
            strval($p['plate_full'] ?? '')
        ];
        
        if (in_array(strval($badge_id), $pParams, true)) {
            $matchedParticipants[] = [
                'index' => $index,
                'participant' => $p,
            ];
        }
    }

    if (empty($matchedParticipants)) {
        $debugInfo = [
            'received_id' => $badge_id,
            'file_exists' => file_exists($dataFile),
            'records_count' => count($participants),
            'json_error' => json_last_error_msg()
        ];
        scanLog("Participant Not Found for ID: $badge_id");
        throw new ApiException('PARTICIPANT_NOT_FOUND', $debugInfo);
    }

    // When multiple records match the same badge/token across edits/championships,
    // choose the most relevant one (approved + allowed type + latest timestamp).
    $allowedTypes = ['المشاركة بالاستعراض الحر', 'free_show'];
    $best = null;
    $bestScore = -1;

    foreach ($matchedParticipants as $candidate) {
        $p = $candidate['participant'];
        $isApproved = ($p['status'] ?? '') === 'approved';
        $pType = $p['participation_type'] ?? '';
        $isAllowedType = in_array($pType, $allowedTypes, true);

        $ts = 0;
        foreach (['approved_date', 'registration_date', 'created_at'] as $timeKey) {
            if (!empty($p[$timeKey])) {
                $parsed = strtotime((string)$p[$timeKey]);
                if ($parsed !== false && $parsed > $ts) {
                    $ts = $parsed;
                }
            }
        }

        $score = ($isApproved ? 1000000000 : 0)
               + ($isAllowedType ? 500000000 : 0)
               + $ts;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }

    $participant = $best['participant'] ?? null;
    $pIndex = $best['index'] ?? -1;

    scanLog("Participant Found: " . $participant['wasel']);

    if (($participant['status'] ?? '') !== 'approved') {
        throw new ApiException('PARTICIPANT_NOT_APPROVED', ['status' => $participant['status'] ?? 'unknown']);
    }

    // NEW: 4.b Check Participation Type
    $pType = $participant['participation_type'] ?? '';
    
    if (!in_array($pType, $allowedTypes)) {
        $msg = "عذراً - نوع مشاركتك ($pType) لا يسمح لك بدخول جولات الاستعراض";
        
        // Special mapping for common types to match user request
        if ($pType === 'special_car' || mb_strpos($pType, 'مميزة') !== false) {
            $msg = 'عذراً - هذه السيارة مشاركة كسيارة مميزة ولا يسمح لها بدخول جولات الاستعراض';
        } elseif ($pType === 'burnout' || mb_strpos($pType, 'Burnout') !== false) {
            $msg = 'عذراً - هذه السيارة مشاركة بفعالية Burnout ولا يسمح لها بدخول جولات الاستعراض الحر';
        }
        
        throw new ApiException('ACCESS_DENIED_TYPE', [
            'message' => $msg,
            'type' => $pType
        ]);
    }

    // NEW: 4.c Check Main Gate Entry
    $entryLogger = new EntryExitLogger();
    if (!$entryLogger->isMemberInside($participant['registration_code'] ?? $participant['wasel'] ?? $badge_id)) {
        throw new ApiException('MAIN_GATE_NOT_SCANNED', [
            'message' => 'عذراً - يجب تسجيل الدخول من البوابة الرئيسية أولاً'
        ]);
    }

    // 5. Load Logs
    $logsFile = __DIR__ . '/admin/data/round_logs.json';
    $logs = [];
    if (file_exists($logsFile)) {
        $logs = json_decode(file_get_contents($logsFile), true) ?? [];
    }
    
    // 6. Count Rounds Entered by This Participant (Current Championship)
    $regTimestamp = 0;
    if (!empty($participant['registration_date'])) {
        $regTimestamp = strtotime($participant['registration_date']);
    }
    
    // Count unique rounds this participant has entered in CURRENT championship
    $participantAllLogs = array_filter($logs, function($l) use ($participant, $regTimestamp) {
        if ($l['participant_id'] != ($participant['wasel'] ?? '')) {
            return false;
        }
        if ($l['timestamp'] < ($regTimestamp - 60)) {
            return false;
        }
        return $l['action'] === 'enter';
    });
    
    $enteredRounds = [];
    foreach ($participantAllLogs as $log) {
        $enteredRounds[$log['round_id']] = true;
    }
    $currentChampRounds = count($enteredRounds);

    // Calculate Lifetime Rounds (Historic + Current)
    $membersFile = __DIR__ . '/admin/data/members.json';
    $lifetimeRounds = 0;
    $historicRounds = 0;
    
    if (file_exists($membersFile)) {
        $membersData = json_decode(file_get_contents($membersFile), true) ?? [];
        // Try to find member record 
        $memKey = $participant['registration_code'] ?? '';
        if (empty($memKey) && !empty($participant['wasel'])) $memKey = 'W'. $participant['wasel'];

        if (!empty($memKey) && isset($membersData[$memKey])) {
            $historicRounds = intval($membersData[$memKey]['total_rounds_all_time'] ?? 0);
        }
    }
    
    // Total Lifetime = Historic + Current
    $lifetimeRounds = $historicRounds + $currentChampRounds;
    
    // Compatibility
    $totalRoundsEntered = $currentChampRounds;
    
    // Get max rounds from config
    $roundsConfigFile = __DIR__ . '/admin/data/rounds_config.json';
    $maxRounds = 3; // Default
    if (file_exists($roundsConfigFile)) {
        $config = json_decode(file_get_contents($roundsConfigFile), true);
        $maxRounds = $config['total_rounds'] ?? 3;
    }
    
    // NEW: Check if already entered maximum rounds (Limit applies to Current Championship only)
    if ($currentChampRounds >= $maxRounds && !isset($enteredRounds[$round_id])) {
        throw new ApiException('MAX_ROUNDS_REACHED', [
            'message' => "لقد أكمل المشارك جميع الجولات المتاحة ($maxRounds جولات)",
            'rounds_entered' => $currentChampRounds,
            'lifetime_rounds' => $lifetimeRounds,
            'max_rounds' => $maxRounds,
            'participant_name' => $participant['full_name'] ?? $participant['name'] ?? ''
        ]);
    }
    
    // 7. Check if already entered THIS round
    $participantRoundLogs = array_filter($participantAllLogs, function($l) use ($round_id) {
        return $l['round_id'] == $round_id;
    });
    
    $alreadyInThisRound = !empty($participantRoundLogs);
    
    if ($alreadyInThisRound) {
        // Already entered this round - return warning
        return jsonResponse(apiSuccess('مسجل دخول بالفعل في هذه الجولة', [
            'status' => 'entered',
            'participant' => [
                'id' => $participant['wasel'],
                'name' => $participant['full_name'] ?? $participant['name'] ?? 'مشارك',
                'car' => $participant['car_type'] ?? '',
                'plate' => $participant['plate_full'] ?? '',
            ],
            'round' => ['id' => $round_id, 'name' => "الجولة $round_id"],
            'warning' => 'ALREADY_ENTERED',
            'rounds_completed' => $totalRoundsEntered,
            'max_rounds' => $maxRounds
        ]));
    }

    // 8. Record Action
    $currentUser = getCurrentUser();
    $newLog = [
        'participant_id' => $participant['wasel'],
        'round_id' => $round_id,
        'action' => $action,
        'timestamp' => time(),
        'device' => $device,
        'scanned_by' => $currentUser['username'] ?? 'unknown',
        'scanned_by_id' => $currentUser['id'] ?? null
    ];
    
    $logs[] = $newLog;
    file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // NEW: Sync to SQL round_logs for unified tracking
    try {
        require_once 'include/db.php';
        $pdo = db();
        
        // 1. Ensure participant exists in SQL participants cache (required for FK)
        $stmtP = $pdo->prepare("SELECT id FROM participants WHERE wasel = ? OR registration_code = ? OR badge_id = ?");
        // FIX: Also check by the actual scanned badge_id
        $stmtP->execute([$participant['wasel'], $participant['registration_code'] ?? '', $badge_id]);
        $sqlParticipantId = $stmtP->fetchColumn();
        
        if (!$sqlParticipantId) {
            // Auto-create participant record if missing in SQL (Sync on the fly)
            $stmtInsertP = $pdo->prepare("
                INSERT INTO participants (badge_id, registration_code, wasel, name, car_type, car_color, plate, phone)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsertP->execute([
                $badge_id, // Use the actual token they scanned
                $participant['registration_code'] ?? '', 
                $participant['wasel'],
                $participant['full_name'] ?? $participant['name'] ?? '',
                $participant['car_type'] ?? '',
                $participant['car_color'] ?? '',
                $participant['plate_full'] ?? '',
                $participant['phone'] ?? ''
            ]);
            $sqlParticipantId = $pdo->lastInsertId();
        }
        
        // 2. Log entry to SQL
        $stmtLog = $pdo->prepare("
            INSERT INTO round_logs (participant_id, round_id, action, scanned_by, device_name, created_at)
            VALUES (?, ?, ?, ?, ?, datetime('now', '+3 hours'))
        ");
        $stmtLog->execute([
            $sqlParticipantId,
            $round_id,
            $action,
            $currentUser['id'] ?? null,
            $device
        ]);
        
    } catch (Exception $e) {
        // Log SQL error but don't fail the request since JSON succeeded
        error_log("SQL Sync Error in verify_round: " . $e->getMessage());
    }
    
    // 9. Log to Admin Log
    $logger = new AdminLogger();
    $logger->log(
        'round_entry',
        $currentUser['username'] ?? 'unknown',
        "تسجيل دخول جولة $round_id",
        [
            'participant_code' => $participant['wasel'],
            'participant_name' => $participant['full_name'] ?? '',
            'round_id' => $round_id,
            'device' => $device,
            'rounds_completed' => $currentChampRounds + 1,
            'lifetime_rounds' => $lifetimeRounds + 1
        ]
    );

    // 10. Calculate Stats
    $enteredCount = 0;
    $roundLogs = array_filter($logs, function($l) use ($round_id) { 
        return $l['round_id'] == $round_id && $l['action'] === 'enter'; 
    });
    $enteredCount = count(array_unique(array_column($roundLogs, 'participant_id')));
    
    // Clear Badge Cache for this member
    try {
        require_once 'services/BadgeCacheService.php';
        BadgeCacheService::refresh($badge_id);
        if (!empty($participant['registration_code'])) {
            BadgeCacheService::refresh($participant['registration_code']);
        }
    } catch (Exception $e) {}
    
    // Success Response
    jsonResponse([
        'success' => true,
        'status' => 'entered',
        'participant' => [
            'id' => $participant['wasel'],
            'name' => $participant['full_name'] ?? $participant['name'] ?? 'Unknown',
            'car' => $participant['car_type'] ?? '',
            'plate' => $participant['plate_full'] ?? '',
            'wasel' => $participant['wasel']
        ],
        'round' => [
            'id' => $round_id,
            'name' => "الجولة $round_id",
        ],
        'rounds_completed' => $currentChampRounds + 1,
        'lifetime_rounds' => $lifetimeRounds + 1,
        'max_rounds' => $maxRounds,
        'stats' => [
            'entered' => $enteredCount
        ]
    ]);

} catch (ApiException $e) {
    jsonResponse($e->toArray(), 400);
} catch (Exception $e) {
    error_log("verify_round error: " . $e->getMessage());
    jsonError('SERVER_ERROR: ' . $e->getMessage(), [], 500);
}
