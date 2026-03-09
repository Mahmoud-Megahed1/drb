<?php
/**
 * Entry Logs API
 * API ???? ????? ?????? ???????
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../include/EntryExitLogger.php';

$logger = new EntryExitLogger();

$action = $_GET['action'] ?? 'list';
$gate = $_GET['gate'] ?? null;
$filterAction = $_GET['action_filter'] ?? null;
$limit = intval($_GET['limit'] ?? 50);
$date = $_GET['date'] ?? date('Y-m-d');

try {
    switch ($action) {
        case 'list':
        default:
            $logs = $logger->getTodayLogs($gate);
            
            // Filter by action type
            if ($filterAction) {
                $logs = array_filter($logs, function($log) use ($filterAction) {
                    return $log['action'] === $filterAction;
                });
            }
            
            // Limit
            $logs = array_slice(array_values($logs), 0, $limit);
            
            echo json_encode([
                'success' => true,
                'count' => count($logs),
                'logs' => $logs
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'member':
            $memberCode = $_GET['member_code'] ?? '';
            if (empty($memberCode)) {
                throw new Exception('??? ????? ?????');
            }
            
            $logs = $logger->getMemberLogs($memberCode, $limit);
            
            echo json_encode([
                'success' => true,
                'member_code' => $memberCode,
                'count' => count($logs),
                'logs' => $logs
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'stats':
            $stats = $logger->getGateStats($date);
            
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'currently_inside':
            $inside = $logger->getCurrentlyInside($gate);
            
            echo json_encode([
                'success' => true,
                'count' => count($inside),
                'members' => $inside
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
