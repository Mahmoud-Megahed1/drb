/**
 * QR Scan API - محرك مسح كود الـ QR والتحقق
 * 
 * الميزات:
 * 1. جلب بيانات العضو وصورته
 * 2. تسجيل دخول/خروج
 * 3. عرض المخالفات
 * 4. إدارة الجولات (النزلة الأولى...)
 */

header('Content-Type: application/json; charset=utf-8');
require_once '../../include/db.php';
require_once '../../include/auth.php';
require_once '../../include/EntryExitLogger.php';
require_once '../../include/ViolationManager.php';
require_once '../../include/AdminLogger.php';

// التحقق من الجلسة الموحدة
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$currentUser = getCurrentUser();
$username = $currentUser['username'] ?? '';
$userRole = $currentUser['role'] ?? 'guest';

// ????? ?????????
$canView = true; // ?????? ?????? ???????
$canAddViolation = in_array($userRole, ['root', 'admin', 'approver', 'notes']);
$canRemoveViolation = in_array($userRole, ['root', 'admin']);
$canLogEntry = in_array($userRole, ['root', 'admin', 'gate', 'rounds']);

$action = $_POST['action'] ?? $_GET['action'] ?? 'get_profile';
$memberCode = $_POST['member_code'] ?? $_GET['member_code'] ?? '';
$token = $_POST['token'] ?? $_GET['token'] ?? '';

// ??????? ????? ?? ?????? ??? ??? URL
if (empty($memberCode) && !empty($token)) {
    $memberCode = $token;
}

if (strpos($memberCode, 'http') !== false || strpos($memberCode, '?') !== false) {
    $queryString = parse_url($memberCode, PHP_URL_QUERY);
    parse_str($queryString ?? '', $params);
    $memberCode = $params['token'] ?? $params['badge_id'] ?? $params['code'] ?? $memberCode;
}

// ????? ?? ?????
function findMember($code) {
    $dataFile = __DIR__ . '/../data/data.json';
    $membersFile = __DIR__ . '/../data/members.json';
    
    $member = null;
    $isCurrentParticipant = false;
    
    // ????? ?? ????????? ???????
    if (file_exists($dataFile)) {
        $registrations = json_decode(file_get_contents($dataFile), true) ?? [];
        foreach ($registrations as $reg) {
            $matches = [
                $reg['wasel'] ?? '',
                $reg['registration_code'] ?? '',
                $reg['badge_token'] ?? '',
                $reg['badge_id'] ?? ''
            ];
            
            if (in_array($code, $matches, true) || in_array(strval($code), array_map('strval', $matches), true)) {
                $member = $reg;
                $isCurrentParticipant = ($reg['status'] ?? '') === 'approved';
                break;
            }
        }
    }
    
    // ????? ?? ????? ??????? ???????
    if (!$member && file_exists($membersFile)) {
        $members = json_decode(file_get_contents($membersFile), true) ?? [];
        if (isset($members[$code])) {
            $member = $members[$code];
            $member['registration_code'] = $code;
        } else {
            foreach ($members as $regCode => $m) {
                if (($m['badge_token'] ?? '') === $code || ($m['badge_id'] ?? '') === $code) {
                    $member = $m;
                    $member['registration_code'] = $regCode;
                    break;
                }
            }
        }
    }
    
    return [
        'member' => $member,
        'is_current_participant' => $isCurrentParticipant
    ];
}

// ?????? ?????????
try {
    switch ($action) {
        
        // ====== ??? ????????? ======
        case 'get_profile':
        case 'scan':
            if (empty($memberCode)) {
                throw new Exception('??? ????? ?????');
            }
            
            $result = findMember($memberCode);
            $member = $result['member'];
            
            if (!$member) {
                echo json_encode([
                    'success' => false,
                    'error' => '????? ??? ?????',
                    'code' => $memberCode
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // ??? ?????????
            $violationManager = new ViolationManager();
            $violations = $violationManager->getMemberViolations($member['registration_code'] ?? $memberCode);
            $hasBlocker = $violationManager->hasBlocker($member['registration_code'] ?? $memberCode);
            
            // ??? ??? ??????/??????
            $entryLogger = new EntryExitLogger();
            $entryLogs = $entryLogger->getMemberLogs($member['registration_code'] ?? $memberCode, 10);
            $isInside = $entryLogger->isMemberInside($member['registration_code'] ?? $memberCode);
            
            // ??? ???????? ???????
            $roundsEntered = $entryLogger->getRoundsCount($member['registration_code'] ?? $memberCode);
            
            // ???? ?????????
            $profile = [
                'success' => true,
                'member' => [
                    'code' => $member['registration_code'] ?? $member['wasel'] ?? $memberCode,
                    'wasel' => $member['wasel'] ?? '',
                    'name' => $member['full_name'] ?? $member['name'] ?? '??? ?????',
                    'phone' => $member['phone'] ?? '',
                    'governorate' => $member['governorate'] ?? '',
                    'car_type' => $member['car_type'] ?? '',
                    'car_year' => $member['car_year'] ?? '',
                    'car_color' => $member['car_color'] ?? '',
                    'plate_full' => $member['plate_full'] ?? '',
                    'participation_type' => $member['participation_type_label'] ?? $member['participation_type'] ?? '',
                    'personal_photo' => $member['images']['personal_photo'] ?? null
                ],
                'status' => [
                    'is_current_participant' => $result['is_current_participant'],
                    'approval_status' => $member['status'] ?? 'unknown',
                    'is_inside' => $isInside,
                    'has_blocker' => $hasBlocker
                ],
                'stats' => [
                    'violations_count' => count($violations),
                    'rounds_entered' => $roundsEntered,
                    'championships_participated' => $member['championships_participated'] ?? 1
                ],
                'violations' => $violations,
                'entry_logs' => array_slice($entryLogs, 0, 5),
                'permissions' => [
                    'can_add_violation' => $canAddViolation,
                    'can_remove_violation' => $canRemoveViolation,
                    'can_log_entry' => $canLogEntry
                ]
            ];
            
            echo json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;
            
        // ====== ????? ???? ======
        case 'log_entry':
            if (!$canLogEntry) {
                throw new Exception('??? ???? ?????? ????? ??????');
            }
            
            $result = findMember($memberCode);
            $member = $result['member'];
            
            if (!$member) {
                throw new Exception('????? ??? ?????');
            }
            
            // Block entry if not registered in current championship
            if (!$result['is_current_participant']) {
                echo json_encode([
                    'success' => false,
                    'error' => 'هذا العضو غير مسجل في البطولة الحالية. لا يمكن تسجيل دخوله.',
                    'is_old_member' => true,
                    'member_name' => $member['full_name'] ?? $member['name'] ?? ''
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $gate = $_POST['gate'] ?? 'main';
            $roundId = $_POST['round_id'] ?? null;
            
            $logger = new EntryExitLogger();
            $logResult = $logger->log(
                $member['registration_code'] ?? $memberCode,
                EntryExitLogger::ACTION_ENTRY,
                $gate,
                [
                    'member_name' => $member['full_name'] ?? $member['name'] ?? '',
                    'round_id' => $roundId,
                    'scanned_by' => $username,
                    'device' => $_POST['device'] ?? 'scanner'
                ]
            );
            
            echo json_encode([
                'success' => true,
                'action' => 'entry',
                'member_name' => $member['full_name'] ?? $member['name'] ?? '',
                'gate' => $gate,
                'message' => '?? ????? ?????? ?????'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        // ====== ????? ???? ======
        case 'log_exit':
            if (!$canLogEntry) {
                throw new Exception('??? ???? ?????? ????? ??????');
            }
            
            $result = findMember($memberCode);
            $member = $result['member'];
            
            if (!$member) {
                throw new Exception('????? ??? ?????');
            }
            
            $gate = $_POST['gate'] ?? 'main';
            
            $logger = new EntryExitLogger();
            $logResult = $logger->log(
                $member['registration_code'] ?? $memberCode,
                EntryExitLogger::ACTION_EXIT,
                $gate,
                [
                    'member_name' => $member['full_name'] ?? $member['name'] ?? '',
                    'scanned_by' => $username,
                    'device' => $_POST['device'] ?? 'scanner'
                ]
            );
            
            echo json_encode([
                'success' => true,
                'action' => 'exit',
                'member_name' => $member['full_name'] ?? $member['name'] ?? '',
                'gate' => $gate,
                'message' => '?? ????? ?????? ?????'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        // ====== ????? ?????? ======
        case 'add_violation':
            if (!$canAddViolation) {
                throw new Exception('??? ???? ?????? ????? ??????');
            }
            
            $description = $_POST['description'] ?? '';
            $type = $_POST['type'] ?? 'warning';
            $severity = $_POST['severity'] ?? 'medium';
            
            if (empty($description)) {
                throw new Exception('??? ???????? ?????');
            }
            
            $result = findMember($memberCode);
            $member = $result['member'];
            
            if (!$member) {
                throw new Exception('????? ??? ?????');
            }
            
            $violationManager = new ViolationManager();
            $addResult = $violationManager->add(
                $member['registration_code'] ?? $memberCode,
                $description,
                [
                    'type' => $type,
                    'severity' => $severity,
                    'title' => $_POST['title'] ?? '??????',
                    'added_by' => $username,
                    'round_id' => $_POST['round_id'] ?? null
                ]
            );
            
            echo json_encode([
                'success' => true,
                'message' => '?? ????? ???????? ?????',
                'violation_id' => $addResult['violation_id'] ?? null
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        // ====== ????? ?????? ======
        case 'remove_violation':
            if (!$canRemoveViolation) {
                throw new Exception('??? ???? ?????? ????? ?????????');
            }
            
            $violationId = $_POST['violation_id'] ?? '';
            $notes = $_POST['notes'] ?? '';
            
            if (empty($violationId)) {
                throw new Exception('???? ???????? ?????');
            }
            
            $violationManager = new ViolationManager();
            $resolveResult = $violationManager->resolve($violationId, $username, $notes);
            
            echo json_encode([
                'success' => true,
                'message' => '?? ?? ???????? ?????'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        // ====== ???????? ???????? ======
        case 'gate_stats':
            $logger = new EntryExitLogger();
            $stats = $logger->getGateStats();
            
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        // ====== ??????? ????????? ?????? ======
        case 'currently_inside':
            $logger = new EntryExitLogger();
            $gate = $_GET['gate'] ?? null;
            $inside = $logger->getCurrentlyInside($gate);
            
            echo json_encode([
                'success' => true,
                'count' => count($inside),
                'members' => $inside
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            throw new Exception('????? ??? ????');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
