<?php
/**
 * Badge Control API - التحكم بالباجات
 * يتم تسجيل جميع العمليات في Admin Log
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Check if logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

// Get current user
$currentUser = $_SESSION['user'];
$username = is_object($currentUser) ? ($currentUser->username ?? 'unknown') : ($currentUser['username'] ?? 'unknown');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$settingsFile = __DIR__ . '/../data/site_settings.json';

// Include DB and Logger
require_once '../../include/db.php';
require_once '../../include/AdminLogger.php';

$logger = new AdminLogger();

switch ($action) {
    case 'toggle_badges':
        $pdo = db();
        
        $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = 'badge_enabled'");
        $stmt->execute();
        $currentStr = $stmt->fetchColumn();
        
        $current = ($currentStr !== false) ? trim($currentStr) : 'true';
        
        if ($current === 'true' || $current === '1') {
            $newState = false;
            $newValue = 'false';
        } else {
            $newState = true;
            $newValue = 'true';
        }
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_by INTEGER
        )");
        
        $update = $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES ('badge_enabled', ?, CURRENT_TIMESTAMP)");
        if ($update->execute([$newValue])) {
            
            if (file_exists($settingsFile)) {
                $jsonSettings = json_decode(file_get_contents($settingsFile), true) ?? [];
                $jsonSettings['badges_enabled'] = $newState;
                file_put_contents($settingsFile, json_encode($jsonSettings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            
            $logger->log(
                AdminLogger::ACTION_BADGE_TOGGLE,
                $username,
                $newState ? 'تفعيل الباجات' : 'إيقاف الباجات',
                ['new_state' => $newState]
            );

            echo json_encode([
                'success' => true,
                'badges_enabled' => $newState,
                'message' => $newState ? 'تم تفعيل الباجات' : 'تم إيقاف الباجات'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'فشل في تحديث قاعدة البيانات']);
        }
        break;

    case 'toggle_qr_mode':
        $pdo = db();
        
        $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = 'qr_only_mode'");
        $stmt->execute();
        $currentStr = $stmt->fetchColumn();
        $current = ($currentStr !== false) ? trim($currentStr) : 'false';
        
        if ($current === 'true' || $current === '1') {
            $newState = false;
            $newValue = 'false';
        } else {
            $newState = true;
            $newValue = 'true';
        }
        
        $update = $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES ('qr_only_mode', ?, CURRENT_TIMESTAMP)");
        if ($update->execute([$newValue])) {
            if (file_exists($settingsFile)) {
                $jsonSettings = json_decode(file_get_contents($settingsFile), true) ?? [];
                $jsonSettings['qr_only_mode'] = $newState;
                file_put_contents($settingsFile, json_encode($jsonSettings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            
            $logger->log(
                AdminLogger::ACTION_SETTINGS_CHANGE,
                $username,
                $newState ? 'تفعيل وضع QR فقط' : 'إلغاء وضع QR فقط',
                ['setting' => 'qr_only_mode', 'new_state' => $newState]
            );
            
            echo json_encode([
                'success' => true,
                'qr_only_mode' => $newState,
                'message' => $newState ? 'تم تفعيل وضع QR فقط' : 'تم تفعيل الباج الكامل'
            ]);
        } else {
             echo json_encode(['success' => false, 'message' => 'فشل التحديث']);
        }
        break;
        
    case 'get_status':
        $pdo = db();
        
        $stmt = $pdo->query("SELECT value FROM system_settings WHERE key = 'badge_enabled'");
        $val = $stmt->fetchColumn();
        $badgesEnabled = ($val === false) ? true : ($val === 'true' || $val === '1');
        
        $stmt = $pdo->query("SELECT value FROM system_settings WHERE key = 'qr_only_mode'");
        $valQr = $stmt->fetchColumn();
        $qrMode = ($valQr === false) ? false : ($valQr === 'true' || $valQr === '1');
        
        echo json_encode([
            'success' => true,
            'badges_enabled' => $badgesEnabled,
            'qr_only_mode' => $qrMode
        ]);
        break;
        
    case 'reset_entries':
        $dataFile = __DIR__ . '/../data/data.json';
        $jsonSuccess = false;
        $resetCount = 0;
        
        if (file_exists($dataFile)) {
            $data = json_decode(file_get_contents($dataFile), true);
            foreach ($data as &$reg) {
                if (($reg['has_entered'] ?? false) === true) {
                    $reg['has_entered'] = false;
                    $reg['entry_status'] = false;
                    $reg['entry_time'] = null;
                    $resetCount++;
                }
            }
            $jsonSuccess = file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $sqlSuccess = false;
        try {
            require_once '../../include/db.php';
            $pdo = db();
            $pdo->exec("UPDATE registrations SET has_entered = 0, entry_time = NULL");
            $sqlSuccess = true;
        } catch (Exception $e) {
            error_log("Global Reset SQL Error: " . $e->getMessage());
        }
        
        if ($jsonSuccess || $sqlSuccess) {
            $logger->log(
                AdminLogger::ACTION_ROUND_RESET,
                $username,
                'إعادة تعيين جميع حالات الدخول',
                [
                    'reset_count' => $resetCount,
                    'json_success' => $jsonSuccess,
                    'sql_success' => $sqlSuccess
                ]
            );
            
            echo json_encode([
                'success' => true,
                'reset_count' => $resetCount,
                'message' => "تم إعادة تعيين $resetCount تسجيل دخول (JSON+SQL)"
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'فشل في حفظ البيانات']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'إجراء غير صالح']);
}
?>
