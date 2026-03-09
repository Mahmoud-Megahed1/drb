<?php
/**
 * Admin Actions Log Page
 * سجل إجراءات المشرفين
 */

session_start();
require_once '../include/AdminLogger.php';

// التحقق من الصلاحيات
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$currentUser = $_SESSION['user'];
$username = is_object($currentUser) ? ($currentUser->username ?? '') : ($currentUser['username'] ?? '');
$isRoot = ($username === 'root');

if (!$isRoot) {
    die('<div style="text-align:center;padding:50px;font-family:Cairo,sans-serif">
        <h2>غير مصرح لك</h2>
        <p>هذه الصفحة متاحة لمدير النظام فقط</p>
        
    </div>');
}

// تهيئة سجل الأحداث
$logger = new AdminLogger();

// Handle Clear Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all_logs']) && $isRoot) {
    try {
        $pdo = \db();
        $pdo->exec("DELETE FROM activity_logs");
        $logFile = __DIR__ . '/data/admin_actions.json';
        if (file_exists($logFile)) {
            file_put_contents($logFile, json_encode([], JSON_PRETTY_PRINT));
        }
        $logger->log('settings_change', $username, 'قام بمسح سجل الإجراءات كاملاً', []);
        $message = "تم مسح السجلات بنجاح!";
        $messageType = "success";
    } catch (\Exception $e) {
        $message = "حدث خطأ أثناء مسح السجلات: " . $e->getMessage();
        $messageType = "error";
    }
}

$filter_action = $_GET['action'] ?? '';
$filter_user = $_GET['user'] ?? '';
$filter_from_date = $_GET['from_date'] ?? '';
$filter_to_date = $_GET['to_date'] ?? '';
$limit = intval($_GET['limit'] ?? 100);

$logs = $logger->getLogs($limit, $filter_action ?: null, $filter_user ?: null);
$stats = $logger->getStats();

// Apply date filters (post-filter since AdminLogger::getLogs doesn't support dates)
if ($filter_from_date || $filter_to_date) {
    $logs = array_filter($logs, function($log) use ($filter_from_date, $filter_to_date) {
        $logDate = $log['datetime'] ?? date('Y-m-d H:i:s', $log['timestamp'] ?? 0);
        $logDay = substr($logDate, 0, 10);
        if ($filter_from_date && $logDay < $filter_from_date) return false;
        if ($filter_to_date && $logDay > $filter_to_date) return false;
        return true;
    });
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin_log_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Arabic support
    $output = fopen('php://output', 'w');
    fputcsv($output, ['التاريخ', 'المستخدم', 'الإجراء', 'الوصف', 'التفاصيل']);
    foreach ($logs as $log) {
        $logTime = $log['datetime'] ?? date('Y-m-d H:i:s', $log['timestamp'] ?? time());
        $details = '';
        if (!empty($log['details'])) {
            if (is_array($log['details'])) {
                $details = json_encode($log['details'], JSON_UNESCAPED_UNICODE);
            } else {
                $details = $log['details'];
            }
        }
        fputcsv($output, [
            $logTime,
            $log['username'] ?? 'System',
            $log['action'] ?? '',
            $log['description'] ?? '',
            $details
        ]);
    }
    fclose($output);
    exit;
}

// أنواع الإجراءات
$actionTypes = [
    '' => 'كل الإجراءات',
    'round_entry' => 'دخول جولة',
    'round_reset' => 'تصفير جولة يدوياً',
    'manual_entry' => 'إدخال يدوي',
    'participant_approve' => 'قبول متسابق',
    'participant_reject' => 'رفض متسابق',
    'participant_edit' => 'تعديل متسابق',
];

// ألوان الإجراءات
$actionColors = [
    'round_entry' => '#28a745',
    'round_reset' => '#dc3545',
    'manual_entry' => '#ffc107',
    'participant_approve' => '#17a2b8',
    'participant_reject' => '#dc3545',
    'participant_edit' => '#6f42c1',
    'participant_delete' => '#343a40',
    'badge_toggle' => '#e83e8c',
    'settings_change' => '#fd7e14',
    'import' => '#20c997',
    'export' => '#007bff',
    'whatsapp_send' => '#25D366',
    'login' => '#6c757d',
    'logout' => '#6c757d',
    'gate_entry' => '#28a745',
    'delete_all_members' => '#dc3545',
    'round_delete' => '#e83e8c',
    'championship_reset' => '#ff6b35'
];

// أيقونات الإجراءات
$actionIcons = [
    'round_entry' => 'fa-flag-checkered',
    'round_reset' => 'fa-rotate',
    'manual_entry' => 'fa-keyboard',
    'participant_approve' => 'fa-check',
    'participant_reject' => 'fa-times',
    'participant_edit' => 'fa-pen',
    'participant_delete' => 'fa-trash',
    'badge_toggle' => 'fa-id-card',
    'settings_change' => 'fa-gear',
    'import' => 'fa-file-import',
    'export' => 'fa-file-export',
    'whatsapp_send' => 'fa-brands fa-whatsapp',
    'login' => 'fa-right-to-bracket',
    'logout' => 'fa-right-from-bracket',
    'gate_entry' => 'fa-door-open',
    'delete_all_members' => 'fa-users-slash',
    'round_delete' => 'fa-flag',
    'championship_reset' => 'fa-rotate'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سجل إجراءات النظام</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #1a1a2e; color: #fff; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 30px;
            background: rgba(255,255,255,0.05);
            padding: 20px;
            border-radius: 12px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-card .number { font-size: 32px; font-weight: bold; margin-bottom: 5px; }
        .stat-card .label { font-size: 14px; opacity: 0.7; }
        
        .filters {
            background: rgba(255,255,255,0.05);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .filters select, .filters input {
            padding: 10px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.2);
            color: #fff;
            font-family: inherit;
        }
        
        .filters button, .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            background: #007bff;
            color: #fff;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .log-entry {
            background: rgba(255,255,255,0.05);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-right: 4px solid #6c757d;
        }
        
        .log-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.1);
            font-size: 18px;
        }
        
        .log-content { flex: 1; }
        .log-time { font-size: 12px; opacity: 0.5; }
        .log-user { font-weight: bold; color: #ffc107; }
        
        .log-details {
            background: rgba(0,0,0,0.2);
            padding: 8px;
            border-radius: 6px;
            margin-top: 5px;
            font-size: 13px;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px;
            opacity: 0.5;
        }
    </style>


</head>
<body>
    <?php include '../include/navbar-custom.php'; ?>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fa-solid fa-clipboard-list"></i> سجل إجراءات النظام</h1>
                <p style="opacity: 0.7; margin-top: 5px;">مراقبة وتتبع جميع العمليات التي يقوم بها المشرفون</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <?php if ($isRoot): ?>
                <form method="POST" onsubmit="return confirm('تأكيد نهائي: هل أنت متأكد من مسح جميع السجلات؟ هذا الإجراء لا يمكن التراجع عنه!');" style="display:inline;">
                    <input type="hidden" name="clear_all_logs" value="1">
                    <button type="submit" class="btn" style="background:#dc3545"><i class="fa-solid fa-trash-can"></i> مسح جميع السجلات</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($message)): ?>
            <div style="padding: 15px; margin-bottom: 20px; border-radius: 8px; background: <?= $messageType === 'success' ? '#28a745' : '#dc3545' ?>; color: white;">
                <?= $message ?>
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?= $stats['today_count'] ?? 0 ?></div>
                <div class="label">إجراءات اليوم</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #28a745;"><?= $stats['top_user']['count'] ?? 0 ?></div>
                <div class="label">الأنشط: <?= $stats['top_user']['username'] ?? '-' ?></div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #ffc107;"><?= $stats['login_count'] ?? 0 ?></div>
                <div class="label">تسجيلات دخول اليوم</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #17a2b8;"><?= $stats['total'] ?? 0 ?></div>
                <div class="label">إجمالي السجل</div>
            </div>
        </div>
        
        <form class="filters">
            <select name="action">
                <option value="">كل الإجراءات</option>
                <?php foreach ($actionTypes as $key => $label): 
                    if ($key === '') continue;
                ?>
                <option value="<?= $key ?>" <?= $filter_action === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            
            <input type="text" name="user" placeholder="اسم المستخدم..." value="<?= htmlspecialchars($filter_user) ?>">
            
            <input type="date" name="from_date" value="<?= htmlspecialchars($filter_from_date) ?>" placeholder="من تاريخ" title="من تاريخ">
            <input type="date" name="to_date" value="<?= htmlspecialchars($filter_to_date) ?>" placeholder="إلى تاريخ" title="إلى تاريخ">
            
            <select name="limit">
                <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>آخر 50</option>
                <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>آخر 100</option>
                <option value="500" <?= $limit == 500 ? 'selected' : '' ?>>آخر 500</option>
            </select>
            
            <button type="submit"><i class="fa-solid fa-filter"></i> تصفية</button>
            <?php if ($filter_action || $filter_user || $filter_from_date || $filter_to_date): ?>
            <a href="admin_log.php" class="btn" style="background:#dc3545"><i class="fa-solid fa-times"></i> إلغاء</a>
            <?php endif; ?>
            
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn" style="background:#20c997">
                <i class="fa-solid fa-file-csv"></i> تصدير CSV
            </a>
        </form>
        
        <div class="logs-list">
            <?php if (empty($logs)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-file-circle-xmark fa-3x"></i>
                <p>لا توجد سجلات مطابقة</p>
            </div>
            <?php else: ?>
                <?php foreach ($logs as $log): 
                    $color = $actionColors[$log['action']] ?? '#6c757d';
                    $icon = $actionIcons[$log['action']] ?? 'fa-circle';
                    $actionLabel = $actionTypes[$log['action']] ?? $log['action'];
                    $logTime = $log['datetime'] ?? date('Y-m-d H:i:s', $log['timestamp'] ?? time());
                    
                    // Format details
                    $detailsStr = '';
                    if (!empty($log['details'])) {
                        if (is_array($log['details'])) {
                             foreach($log['details'] as $k => $v) {
                                 if (is_string($v) || is_numeric($v)) {
                                     $detailsStr .= "<b>$k:</b> $v | ";
                                 }
                             }
                             $detailsStr = rtrim($detailsStr, " | ");
                        } else {
                            $detailsStr = $log['details'];
                        }
                    }
                ?>
                <div class="log-entry" style="border-right-color: <?= $color ?>">
                    <div class="log-icon" style="color: <?= $color ?>">
                        <i class="fa-solid <?= $icon ?>"></i>
                    </div>
                    <div class="log-content">
                        <div style="display:flex;justify-content:space-between;">
                            <div>
                                <span class="log-user"><?= htmlspecialchars($log['username'] ?? 'System') ?></span>
                                قام بـ <strong><?= htmlspecialchars($actionLabel) ?></strong>
                                <div style="font-size:0.9em;color:#ccc"><?= htmlspecialchars($log['description'] ?? '') ?></div>
                            </div>
                            <div class="log-time" dir="ltr"><?= $logTime ?></div>
                        </div>
                        
                        <?php if (!empty($detailsStr)): ?>
                        <div class="log-details">
                            <?= $detailsStr ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>





