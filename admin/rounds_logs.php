<?php
/**
 * Rounds Logs - سجلات الجولات
 * عرض جميع حركات دخول وخروج الجولات
 */

require_once '../include/db.php';
require_once '../include/auth.php';

requireAuth();
if (!hasPermission('admin') && !hasPermission('rounds') && !hasPermission('root')) {
    header('Location: ../login.php');
    exit;
}

$user = getCurrentUser();
$isRoot = (is_object($user) ? ($user->username ?? '') : ($user['username'] ?? '')) === 'root';

$message = '';
$messageType = '';

// Handle Clear Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all_logs']) && $isRoot) {
    try {
        $pdo = \db();
        $pdo->exec("DELETE FROM round_logs");
        $logsFile = __DIR__ . '/data/round_logs.json';
        if (file_exists($logsFile)) {
            file_put_contents($logsFile, json_encode([], JSON_PRETTY_PRINT));
        }
        
        // Log the action explicitly
        require_once '../include/AdminLogger.php';
        $adminLogger = new AdminLogger();
        $adminLogger->log('settings_change', $isRoot ? 'root' : 'admin', 'قام بمسح سجل الجولات', []);
        
        $message = "تم مسح السجلات بنجاح!";
        $messageType = "success";
    } catch (\Exception $e) {
        $message = "حدث خطأ أثناء مسح السجلات: " . $e->getMessage();
        $messageType = "error";
    }
}

// Load rounds logs
$logsFile = __DIR__ . '/data/round_logs.json';
$logs = file_exists($logsFile) ? (json_decode(file_get_contents($logsFile), true) ?? []) : [];

// Load data.json for participant names
$dataFile = __DIR__ . '/data/data.json';
$participants = file_exists($dataFile) ? (json_decode(file_get_contents($dataFile), true) ?? []) : [];

// Build lookup
$participantNames = [];
foreach ($participants as $p) {
    $wasel = $p['wasel'] ?? '';
    if (!empty($wasel)) {
        $participantNames[$wasel] = $p['full_name'] ?? $p['name'] ?? 'غير معروف';
    }
}

// Filters
$filterAction = $_GET['action'] ?? '';
$filterRound = $_GET['round'] ?? '';
$filterDate = $_GET['date'] ?? '';
$filterSearch = $_GET['search'] ?? '';

// Apply filters
$filteredLogs = $logs;

if (!empty($filterAction)) {
    $filteredLogs = array_filter($filteredLogs, fn($l) => ($l['action'] ?? '') === $filterAction);
}

if (!empty($filterRound)) {
    $filteredLogs = array_filter($filteredLogs, fn($l) => ($l['round_id'] ?? '') == $filterRound);
}

if (!empty($filterDate)) {
    $filterTimestamp = strtotime($filterDate);
    $endTimestamp = $filterTimestamp + 86400;
    $filteredLogs = array_filter($filteredLogs, function($l) use ($filterTimestamp, $endTimestamp) {
        $t = $l['timestamp'] ?? 0;
        return $t >= $filterTimestamp && $t < $endTimestamp;
    });
}

if (!empty($filterSearch)) {
    $filteredLogs = array_filter($filteredLogs, function($l) use ($filterSearch, $participantNames) {
        $pid = $l['participant_id'] ?? '';
        $name = $participantNames[$pid] ?? '';
        return stripos($pid, $filterSearch) !== false || stripos($name, $filterSearch) !== false;
    });
}

// Sort by timestamp desc
usort($filteredLogs, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));

// Pagination
$perPage = 50;
$page = max(1, intval($_GET['page'] ?? 1));
$totalLogs = count($filteredLogs);
$totalPages = ceil($totalLogs / $perPage);
$offset = ($page - 1) * $perPage;
$logsPage = array_slice($filteredLogs, $offset, $perPage);

// Stats (aligned with rounds scanner logic)
$allowedParticipationTypes = ['المشاركة بالاستعراض الحر', 'free_show'];
$eligibleParticipants = [];

foreach ($participants as $p) {
    $isApproved = ($p['status'] ?? '') === 'approved';
    $pType = $p['participation_type'] ?? '';
    $pid = (string)($p['wasel'] ?? '');

    if ($isApproved && in_array($pType, $allowedParticipationTypes, true) && $pid !== '') {
        $eligibleParticipants[$pid] = true;
    }
}

$enterLogs = array_filter($logs, fn($l) => ($l['action'] ?? '') === 'enter');
$eligibleEnterLogs = array_filter($enterLogs, function($l) use ($eligibleParticipants) {
    $pid = (string)($l['participant_id'] ?? '');
    return $pid !== '' && isset($eligibleParticipants[$pid]);
});

$totalEligibleParticipants = count($eligibleParticipants);
$totalEnters = count($eligibleEnterLogs);
$uniqueParticipants = count(array_unique(array_map('strval', array_column($eligibleEnterLogs, 'participant_id'))));
$remainingParticipants = max(0, $totalEligibleParticipants - $uniqueParticipants);

// Get unique rounds
$rounds = array_unique(array_column($logs, 'round_id'));
sort($rounds);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجلات الجولات</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 { font-size: 24px; }
        .back-btn {
            background: rgba(255,255,255,0.1);
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-card .number { font-size: 32px; font-weight: 800; }
        .stat-card .label { font-size: 12px; opacity: 0.7; }
        .stat-card.green .number { color: #28a745; }
        .stat-card.red .number { color: #dc3545; }
        .stat-card.blue .number { color: #007bff; }
        .stat-card.purple .number { color: #9b59b6; }
        
        .filters {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        .filters select, .filters input {
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
        }
        .filters button {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            background: #007bff;
            color: #fff;
            font-family: inherit;
            cursor: pointer;
        }
        
        .logs-table {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 15px;
            text-align: right;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        th {
            background: rgba(0,0,0,0.3);
            font-weight: 600;
        }
        tr:hover { background: rgba(255,255,255,0.05); }
        
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge.enter { background: rgba(40,167,69,0.2); color: #28a745; }
        .badge.exit { background: rgba(220,53,69,0.2); color: #dc3545; }
        .badge.round_reset { background: rgba(255,165,0,0.2); color: #ffa500; }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        .pagination a {
            padding: 10px 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            color: #fff;
            text-decoration: none;
        }
        .pagination a.active { background: #007bff; }
        
        .export-btn {
            background: #28a745;
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        @media (max-width: 768px) {
            .logs-table { overflow-x: auto; }
            table { min-width: 700px; }
        }
    </style>


</head>
<body>
    <?php include '../include/navbar-custom.php'; ?>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fa-solid fa-stopwatch"></i> سجل الجولات</h1>
                <p style="opacity: 0.7; margin-top:5px;">مراقبة تحركات المشتركين عبر البوابات والجولات</p>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <?php if ($isRoot): ?>
                <form method="POST" onsubmit="return confirm('تأكيد نهائي: هل أنت متأكد من مسح جميع السجلات؟ هذا الإجراء لا يمكن التراجع عنه!');" style="display:inline;">
                    <input type="hidden" name="clear_all_logs" value="1">
                    <button type="submit" class="back-btn" style="background:#dc3545; border:none; cursor:pointer;"><i class="fa-solid fa-trash-can"></i> مسح جميع السجلات</button>
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
            <div class="stat-card green">
                <div class="number"><?= number_format($totalEnters) ?></div>
                <div class="label">عمليات الدخول</div>
            </div>
            <div class="stat-card red">
                <div class="number"><?= number_format($remainingParticipants) ?></div>
                <div class="label">المتبقي من المتسابقين</div>
            </div>
            <div class="stat-card blue">
                <div class="number"><?= number_format($totalEligibleParticipants) ?></div>
                <div class="label">المتسابقين</div>
            </div>
            <div class="stat-card purple">
                <div class="number"><?= number_format(count($logs)) ?></div>
                <div class="label">إجمالي السجلات</div>
            </div>
        </div>
        
        <form class="filters" method="GET">
            <select name="action">
                <option value="">كل العمليات</option>
                <option value="enter" <?= $filterAction === 'enter' ? 'selected' : '' ?>>دخول</option>
                <option value="exit" <?= $filterAction === 'exit' ? 'selected' : '' ?>>خروج</option>
                <option value="round_reset" <?= $filterAction === 'round_reset' ? 'selected' : '' ?>>إعادة تعيين</option>
            </select>
            
            <select name="round">
                <option value="">كل الجولات</option>
                <?php foreach ($rounds as $r): ?>
                <option value="<?= $r ?>" <?= $filterRound == $r ? 'selected' : '' ?>>جولة <?= $r ?></option>
                <?php endforeach; ?>
            </select>
            
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" placeholder="التاريخ">
            
            <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="اسم المتسابق أو الكود">
            
            <button type="submit"><i class="fa-solid fa-filter"></i> بحث</button>
            
            <?php if (!empty($filterAction) || !empty($filterRound) || !empty($filterDate) || !empty($filterSearch)): ?>
            <a href="rounds_logs.php" style="color: #ffc107; text-decoration: none;"><i class="fa-solid fa-times"></i> مسح</a>
            <?php endif; ?>
        </form>
        
        <div class="logs-table">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الوقت</th>
                        <th>المتسابق</th>
                        <th>الجولة</th>
                        <th>الإجراء</th>
                        <th>الجهاز</th>
                        <th>المسؤول</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logsPage)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; opacity: 0.5;">لا توجد سجلات</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logsPage as $index => $log): ?>
                    <?php 
                        $pid = $log['participant_id'] ?? '';
                        $action = $log['action'] ?? '';
                        $isReset = ($action === 'round_reset');
                        
                        if ($isReset) {
                            $pName = 'عملية نظام';
                            $pidDisplay = $log['scanned_by'] ?? 'المسؤول';
                        } else {
                            $pName = $participantNames[$pid] ?? 'غير معروف';
                            $pidDisplay = $pid;
                        }
                        
                        $timestamp = $log['timestamp'] ?? 0;
                        $time = $timestamp ? date('Y-m-d H:i:s', $timestamp) : '-';
                    ?>
                    <tr>
                        <td><?= $offset + $index + 1 ?></td>
                        <td style="direction: ltr; text-align: right; font-family: monospace; font-size: 12px;"><?= $time ?></td>
                        <td>
                            <?php if ($isReset): ?>
                                <div style="font-weight: 600; color: #ff6b35;"><i class="fa-solid fa-rotate"></i> تصفير الجولة</div>
                                <div style="font-size: 11px; opacity: 0.7;">بواسطة: <?= htmlspecialchars($pidDisplay) ?></div>
                            <?php else: ?>
                                <div style="font-weight: 600;"><?= htmlspecialchars($pName) ?></div>
                                <div style="font-size: 11px; opacity: 0.7;">#<?= htmlspecialchars($pidDisplay) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>جولة <?= $log['round_id'] ?? '-' ?></td>
                        <td>
                            <span class="badge <?= $log['action'] ?? '' ?>">
                                <?php if (($log['action'] ?? '') === 'enter'): ?>
                                    <i class="fa-solid fa-arrow-down"></i> دخول
                                <?php elseif (($log['action'] ?? '') === 'round_reset'): ?>
                                    <i class="fa-solid fa-rotate"></i> إعادة تعيين
                                <?php else: ?>
                                    <i class="fa-solid fa-arrow-up"></i> خروج
                                <?php endif; ?>
                            </span>
                        </td>
                        <td style="font-size: 12px; opacity: 0.7;"><?= htmlspecialchars($log['device'] ?? '-') ?></td>
                        <td style="font-size: 12px;"><?= htmlspecialchars($log['scanned_by'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">السابق</a>
            <?php endif; ?>
            
            <?php 
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++): 
            ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">التالي</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <p style="text-align: center; margin-top: 20px; opacity: 0.5; font-size: 12px;">
            عرض <?= count($logsPage) ?> من <?= $totalLogs ?> سجل
        </p>
    </div>
</body>
</html>





