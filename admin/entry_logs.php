<?php
/**
 * Entry Logs Viewer - عارض سجلات الدخول
 * المشرفين فقط
 */

session_start();

// التحقق من الصلاحيات
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];
$username = is_object($user) ? ($user->username ?? '') : ($user['username'] ?? '');
$role = is_object($user) ? ($user->role ?? '') : ($user['role'] ?? '');

if ($username !== 'root' && !in_array($role, ['root', 'admin'])) {
    header('Location: ../dashboard.php?error=no_permission');
    exit;
}

$message = '';
$messageType = '';
$isRoot = ($username === 'root');

// Handle Clear Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all_logs']) && $isRoot) {
    try {
        $logsFile = __DIR__ . '/data/entry_logs.json';
        if (file_exists($logsFile)) {
            file_put_contents($logsFile, json_encode([], JSON_PRETTY_PRINT));
        }
        
        // Log the action explicitly
        require_once '../include/AdminLogger.php';
        $adminLogger = new AdminLogger();
        $adminLogger->log('settings_change', $username, 'قام بمسح سجل الدخول والخروج', []);
        
        $message = "تم مسح السجلات بنجاح!";
        $messageType = "success";
    } catch (\Exception $e) {
        $message = "حدث خطأ أثناء مسح السجلات: " . $e->getMessage();
        $messageType = "error";
    }
}

// تحميل السجلات
$logsFile = __DIR__ . '/data/entry_logs.json';
$logs = [];
if (file_exists($logsFile)) {
    $logs = json_decode(file_get_contents($logsFile), true) ?? [];
}

// ترتيب من الأحدث للأقدم
$logs = array_reverse($logs);

// تصفية
$filterAction = $_GET['action_filter'] ?? '';
$filterMember = $_GET['member'] ?? '';
$filterOperator = $_GET['operator'] ?? '';

if ($filterAction) {
    $logs = array_filter($logs, fn($l) => ($l['action'] ?? '') === $filterAction);
}
if ($filterMember) {
    $logs = array_filter($logs, fn($l) => stripos($l['member_name'] ?? '', $filterMember) !== false || ($l['member_wasel'] ?? '') === $filterMember);
}
if ($filterOperator) {
    $logs = array_filter($logs, fn($l) => stripos($l['operator_name'] ?? '', $filterOperator) !== false);
}

$logs = array_values($logs);
$displayLogs = array_slice($logs, 0, 500);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجلات الدخول والخروج - لوحة التحكم</title>
    <!-- Bootstrap 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; padding-bottom: 50px; }
        .header-panel { 
            background: linear-gradient(135deg, #1a1a2e, #16213e); 
            color: #fff; 
            padding: 30px 0;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header-panel h2 { margin-top: 0; font-weight: 700; color: #fff; }
        .header-panel p { opacity: 0.8; margin-bottom: 0; }
        
        .stat-card {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            border-bottom: 4px solid #ddd;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card h3 { margin: 10px 0 0; font-size: 32px; font-weight: 700; color: #1a1a2e; }
        .stat-card p { color: #666; margin-bottom: 0; font-weight: 600; }
        .stat-card i { font-size: 24px; opacity: 0.3; }
        
        .card-success { border-bottom-color: #28a745; }
        .card-danger { border-bottom-color: #dc3545; }
        .card-info { border-bottom-color: #17a2b8; }

        .table-panel { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #eee; margin-bottom: 30px; }
        .table-panel-heading { 
            background: #fdfdfd; 
            border-bottom: 1px solid #eee; 
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .table-panel-title { font-weight: 700; color: #333; font-size: 16px; margin: 0; }
        
        .log-row-gate_scan { border-right: 4px solid #17a2b8; }
        .log-row-entry_reset { border-right: 4px solid #dc3545; }
        .log-row-full_entry_confirmed { border-right: 4px solid #28a745; }
        
        .badge-action { padding: 5px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge-gate_scan { background: #e3f2fd; color: #0d47a1; }
        .badge-entry_reset { background: #ffebee; color: #b71c1c; }
        .badge-reset_all { background: #f3e5f5; color: #4a148c; }
        
        .filter-panel { margin-bottom: 20px; background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #eee; }

        /* RTL Specifics */
        .pull-left-rtl { float: left !important; }
        .pull-right-rtl { float: right !important; }

        .table > tbody > tr > td { vertical-align: middle; padding: 12px 8px; }
        .table > thead > tr > th { border-bottom: 1px solid #eee; padding: 12px 8px; font-weight: 700; color: #555; }
    </style>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div style="height: 60px;"></div>

    <div class="header-panel">
        <div class="container-fluid">
            <div class="row" style="display: flex; align-items: center;">
                <div class="col-md-8">
                    <h2><i class="fa-solid fa-clipboard-list"></i> سجلات الدخول والخروج</h2>
                    <p>المراقبة اللحظية لعمليات المسح والدخول من البوابات في النادي</p>
                </div>
                <div class="col-md-4 text-left">
                    <?php if ($isRoot): ?>
                    <form method="POST" onsubmit="return confirm('تأكيد نهائي: هل أنت متأكد من مسح جميع السجلات؟');" style="display:inline;">
                        <input type="hidden" name="clear_all_logs" value="1">
                        <button type="submit" class="btn btn-danger" style="border-radius: 20px; padding: 8px 20px;">
                            <i class="fa-solid fa-trash-can"></i> مسح جميع السجلات
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container-fluid">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible" role="alert" style="border-radius: 8px;">
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i> <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filter-panel">
            <form method="GET" class="form-horizontal">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group" style="margin: 0 10px;">
                            <label class="control-label" style="margin-bottom: 5px;">نوع الإجراء:</label>
                            <select name="action_filter" class="form-control">
                                <option value="">الكل</option>
                                <option value="gate_scan" <?= $filterAction === 'gate_scan' ? 'selected' : '' ?>>مسح بوابة</option>
                                <option value="entry_reset" <?= $filterAction === 'entry_reset' ? 'selected' : '' ?>>إلغاء دخول</option>
                                <option value="reset_all_entries" <?= $filterAction === 'reset_all_entries' ? 'selected' : '' ?>>تصفير شامل</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group" style="margin: 0 10px;">
                            <label class="control-label" style="margin-bottom: 5px;">اسم العضو / واصل:</label>
                            <input type="text" name="member" class="form-control" value="<?= htmlspecialchars($filterMember) ?>" placeholder="الاسم أو رقم الواصل">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group" style="margin: 0 10px;">
                            <label class="control-label" style="margin-bottom: 5px;">اسم المشغل:</label>
                            <input type="text" name="operator" class="form-control" value="<?= htmlspecialchars($filterOperator) ?>" placeholder="بحث باسم المشغل">
                        </div>
                    </div>
                    <div class="col-md-3 text-left">
                        <div class="form-group" style="margin: 0 10px; padding-top: 25px;">
                            <button type="submit" class="btn btn-primary" style="padding: 8px 25px;"><i class="fa-solid fa-filter"></i> تصفية</button>
                            <a href="entry_logs.php" class="btn btn-default" style="padding: 8px 15px;">إعادة ضبط</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Stats Summary -->
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card card-success">
                    <i class="fa-solid fa-user-check pull-left-rtl"></i>
                    <?php 
                    $enteredLogs = array_filter($logs, fn($l) => ($l['result'] ?? '') === 'full_entry_confirmed');
                    $uniqueEntered = count(array_unique(array_column($enteredLogs, 'member_wasel')));
                    ?>
                    <h3><?= $uniqueEntered ?></h3>
                    <p>عدد الحضور (أشخاص)</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card card-danger">
                    <i class="fa-solid fa-user-xmark pull-left-rtl"></i>
                    <?php 
                    $cancelled = count(array_filter($logs, fn($l) => ($l['action'] ?? '') === 'entry_reset'));
                    ?>
                    <h3><?= $cancelled ?></h3>
                    <p>إلغاء دخول / مكرر</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card card-info">
                    <i class="fa-solid fa-list-ul pull-left-rtl"></i>
                    <h3><?= count($logs) ?></h3>
                    <p>إجمالي سجلات النظام</p>
                </div>
            </div>
        </div>
        
        <!-- Logs Table -->
        <div class="table-panel">
            <div class="table-panel-heading">
                <h4 class="table-panel-title"><i class="fa-solid fa-clock-rotate-left"></i> أحدث العمليات (آخر 500 سجل)</h4>
                <span class="label label-primary"><?= count($displayLogs) ?> سجل معروض حالياً</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover" style="margin-bottom:0">
                    <thead style="background: #fafafa">
                        <tr>
                            <th style="padding-right: 25px;">التوقيت</th>
                            <th>الإجراء</th>
                            <th>تفاصيل العضو</th>
                            <th>المشغل والقسم</th>
                            <th>النتيجة</th>
                            <th class="text-center">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($displayLogs)): ?>
                        <tr>
                            <td colspan="6" class="text-center" style="padding: 60px;">
                                <i class="fa-solid fa-folder-open" style="font-size: 50px; color: #eee; margin-bottom: 15px;"></i>
                                <p class="text-muted" style="font-size: 16px;">لا توجد سجلات مطابقة للبحث حالياً</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($displayLogs as $log): 
                            $actionClass = 'log-row-' . ($log['action'] ?? '');
                            if (($log['result'] ?? '') === 'full_entry_confirmed') $actionClass = 'log-row-full_entry_confirmed';
                        ?>
                        <tr class="<?= $actionClass ?>">
                            <td style="padding-right: 25px;">
                                <span class="text-primary" style="font-weight:700"><?= date('H:i:s', strtotime($log['timestamp'] ?? 'now')) ?></span>
                                <br><small class="text-muted"><?= date('Y/m/d', strtotime($log['timestamp'] ?? 'now')) ?></small>
                            </td>
                            <td>
                                <span class="badge-action badge-<?= $log['action'] ?? 'default' ?>">
                                    <?php
                                    $actionMap = [
                                        'gate_scan' => '<i class="fa-solid fa-qrcode"></i> مسح بوابة',
                                        'entry_reset' => '<i class="fa-solid fa-rotate-left"></i> إلغاء دخول',
                                        'reset_all_entries' => '<i class="fa-solid fa-bolt"></i> تصفير شامل'
                                    ];
                                    echo $actionMap[$log['action'] ?? ''] ?? $log['action'] ?? '-';
                                    ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #333;"><?= htmlspecialchars($log['member_name'] ?? '-') ?></div>
                                <small class="text-muted">رقم الواصل: <?= $log['member_wasel'] ?? '-' ?></small>
                                <?php if (isset($log['gate_number'])): ?>
                                <br><span class="label label-<?= $log['gate_number'] == 1 ? 'success' : 'warning' ?>" style="font-size:10px; margin-top: 3px; display: inline-block;">
                                    بوابة <?= $log['gate_number'] ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 600;"><i class="fa-solid fa-user-shield text-muted" style="font-size:12px"></i> <?= htmlspecialchars($log['operator_name'] ?? '-') ?></div>
                                <small class="text-muted"><?= htmlspecialchars($log['operator_department'] ?? '-') ?></small>
                            </td>
                            <td style="font-weight: 600;">
                                <?php
                                $resultMap = [
                                    'gate1_registered' => '<span class="text-info"><i class="fa-solid fa-circle-check"></i> ب1: مسجل</span>',
                                    'full_entry_confirmed' => '<span class="text-success" style="font-weight:800"><i class="fa-solid fa-check-double"></i> دخول تام</span>',
                                    'already_entered' => '<span class="text-danger"><i class="fa-solid fa-ban"></i> مكرر</span>',
                                ];
                                echo $resultMap[$log['result'] ?? ''] ?? ($log['result'] ?? '-');
                                ?>
                            </td>
                            <td class="text-center">
                                <code style="font-size: 10px; color: #999; border: none; background: #f9f9f9;" title="IP Address"><?= $log['ip_address'] ?? '0.0.0.0' ?></code>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-1.12.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
</body>
</html>
