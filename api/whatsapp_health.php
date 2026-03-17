<?php
/**
 * WhatsApp Health Check API v2.0
 * ==============================
 * Handles all messaging management actions.
 * Now reads from SQLite `messages` table (single source of truth).
 */
session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/wasender.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

switch ($action) {
    case 'status':
        $wa = new WaSender();
        $health = $wa->checkHealth();
        $health['pending_count'] = $wa->getPendingCount();
        echo json_encode($health, JSON_UNESCAPED_UNICODE);
        break;
    
    case 'pending':
        $wa = new WaSender();
        $filter = $_GET['filter'] ?? 'pending';
        $messages = array_values($wa->getQueuedMessages($filter));
        echo json_encode(['success' => true, 'messages' => $messages, 'count' => count($messages)], JSON_UNESCAPED_UNICODE);
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
    
    case 'mark_sent':
        $id = $_POST['message_id'] ?? '';
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'معرف الرسالة مطلوب']);
            break;
        }
        // Get admin username
        $username = 'admin';
        if (isset($_SESSION['user'])) {
            $u = $_SESSION['user'];
            $username = is_object($u) ? ($u->username ?? 'admin') : ($u['username'] ?? 'admin');
        }
        $wa = new WaSender();
        echo json_encode($wa->markAsSent($id, $username), JSON_UNESCAPED_UNICODE);
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
        echo json_encode(['success' => true, 'message' => 'تم مسح الرسائل المرسلة والفاشلة']);
        break;
    
    case 'stats':
        require_once dirname(__DIR__) . '/include/WhatsAppLogger.php';
        $logger = new WhatsAppLogger();
        echo json_encode($logger->getStats(), JSON_UNESCAPED_UNICODE);
        break;
    
    case 'trace':
        $id = $_GET['id'] ?? '';
        if (empty($id)) {
            echo json_encode(['error' => 'Message ID required']);
            break;
        }
        require_once dirname(__DIR__) . '/include/WhatsAppLogger.php';
        $logger = new WhatsAppLogger();
        $trace = $logger->getMessageTrace($id);
        echo json_encode($trace ?? ['error' => 'Message not found'], JSON_UNESCAPED_UNICODE);
        break;
    
    default:
        echo json_encode(['error' => 'Unknown action']);
}
