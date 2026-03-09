<?php
/**
 * Violations API
 * API ?????? ?????????
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../include/ViolationManager.php';

// ?????? ?? ????? ??????
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => '??? ????']);
    exit;
}

$currentUser = $_SESSION['user'];
$username = is_object($currentUser) ? ($currentUser->username ?? '') : ($currentUser['username'] ?? '');
$userRole = is_object($currentUser) ? ($currentUser->role ?? 'viewer') : ($currentUser['role'] ?? 'viewer');
if ($username === 'root') $userRole = 'root';

// ????? ?????????
$canAdd = in_array($userRole, ['root', 'admin', 'approver', 'notes']);
$canRemove = in_array($userRole, ['root', 'admin']);
$canView = true;

$violationManager = new ViolationManager();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$memberCode = $_POST['member_code'] ?? $_GET['member_code'] ?? '';

try {
    switch ($action) {
        
        // ????? ??????? ???
        case 'list':
            if (empty($memberCode)) {
                throw new Exception('??? ????? ?????');
            }
            
            $activeOnly = ($_GET['active_only'] ?? '1') === '1';
            $violations = $violationManager->getMemberViolations($memberCode, $activeOnly);
            
            echo json_encode([
                'success' => true,
                'member_code' => $memberCode,
                'count' => count($violations),
                'violations' => array_values($violations)
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        // ????? ??????
        case 'add':
            if (!$canAdd) {
                throw new Exception('??? ???? ?????? ????? ???????');
            }
            
            if (empty($memberCode)) {
                throw new Exception('??? ????? ?????');
            }
            
            $description = $_POST['description'] ?? '';
            if (empty($description)) {
                throw new Exception('??? ???????? ?????');
            }
            
            $result = $violationManager->add($memberCode, $description, [
                'type' => $_POST['type'] ?? 'warning',
                'severity' => $_POST['severity'] ?? 'medium',
                'title' => $_POST['title'] ?? '??????',
                'added_by' => $username,
                'round_id' => $_POST['round_id'] ?? null
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => '?? ????? ???????? ?????',
                'violation_id' => $result['violation_id'] ?? null
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        // ??/????? ??????
        case 'resolve':
        case 'remove':
            if (!$canRemove) {
                throw new Exception('??? ???? ?????? ????? ?????????');
            }
            
            $violationId = $_POST['violation_id'] ?? '';
            if (empty($violationId)) {
                throw new Exception('???? ???????? ?????');
            }
            
            $notes = $_POST['notes'] ?? '';
            $result = $violationManager->resolve($violationId, $username, $notes);
            
            echo json_encode([
                'success' => true,
                'message' => '?? ?? ???????? ?????'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        // ?????? ?? ???? ???
        case 'check_blocker':
            if (empty($memberCode)) {
                throw new Exception('??? ????? ?????');
            }
            
            $hasBlocker = $violationManager->hasBlocker($memberCode);
            $activeCount = $violationManager->getActiveCount($memberCode);
            
            echo json_encode([
                'success' => true,
                'member_code' => $memberCode,
                'has_blocker' => $hasBlocker,
                'active_violations' => $activeCount
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        // ?? ????????? (??????)
        case 'all':
            if (!in_array($userRole, ['root', 'admin'])) {
                throw new Exception('??? ????');
            }
            
            // ??? ?? ????????? ?? ?????
            $violationsFile = __DIR__ . '/../data/violations.json';
            $violations = [];
            if (file_exists($violationsFile)) {
                $violations = json_decode(file_get_contents($violationsFile), true) ?? [];
            }
            
            $activeOnly = ($_GET['active_only'] ?? '0') === '1';
            if ($activeOnly) {
                $violations = array_filter($violations, function($v) {
                    return !($v['resolved'] ?? false);
                });
            }
            
            // Limit
            $limit = intval($_GET['limit'] ?? 100);
            $violations = array_slice($violations, 0, $limit);
            
            echo json_encode([
                'success' => true,
                'count' => count($violations),
                'violations' => array_values($violations)
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
