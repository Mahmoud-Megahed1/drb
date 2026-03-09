<?php
/**
 * WhatsApp Health Check API
 * Returns API connection status and pending message count
 */
session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/wasender.php';

// Handle actions
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

switch ($action) {
    case 'status':
        $wa = new WaSender();
        $health = $wa->checkHealth();
        $health['pending_count'] = $wa->getPendingCount();
        echo json_encode($health, JSON_UNESCAPED_UNICODE);
        break;
    
    case 'retry':
        $id = $_POST['message_id'] ?? '';
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'معرف الرسالة مطلوب']);
            break;
        }
        $wa = new WaSender();
        echo json_encode($wa->retryMessage($id), JSON_UNESCAPED_UNICODE);
        break;
    
    case 'retry_all':
        $wa = new WaSender();
        echo json_encode($wa->retryAll(), JSON_UNESCAPED_UNICODE);
        break;
    
    case 'remove':
        $id = $_POST['message_id'] ?? '';
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'معرف الرسالة مطلوب']);
            break;
        }
        $wa = new WaSender();
        $wa->removeFromQueue($id);
        echo json_encode(['success' => true, 'message' => 'تم الحذف']);
        break;
    
    case 'clear_sent':
        $wa = new WaSender();
        $wa->clearSentMessages();
        echo json_encode(['success' => true, 'message' => 'تم مسح الرسائل المرسلة']);
        break;
    
    case 'pending':
        $wa = new WaSender();
        $messages = array_values($wa->getQueuedMessages($_GET['filter'] ?? 'pending'));
        echo json_encode(['success' => true, 'messages' => $messages, 'count' => count($messages)], JSON_UNESCAPED_UNICODE);
        break;
    
    default:
        echo json_encode(['error' => 'Unknown action']);
}
