<?php
/**
 * WhatsApp Messaging System Health Monitor v2.0
 * ==============================================
 * Real-time observability endpoint for monitoring the messaging system.
 * 
 * Usage:
 *   GET /api/whatsapp_monitor.php           → Full health dashboard (HTML)
 *   GET /api/whatsapp_monitor.php?format=json → JSON health data
 *   GET /api/whatsapp_monitor.php?format=json&key=CRON_KEY → Unauthenticated JSON (for external monitors)
 * 
 * What it monitors:
 *   - DB connectivity
 *   - Queue depth and stuck messages
 *   - Worker health (last run, lock status)
 *   - API health (last success, last error, error rate)
 *   - Alert conditions (high queue, stuck sending, DB failure)
 */

session_start();

// Allow unauthenticated JSON access with secret key (for external monitors)
$MONITOR_KEY = 'cron_secret_2026';
$format = $_GET['format'] ?? 'html';
$authenticated = isset($_SESSION['user']);
$keyAuth = ($_GET['key'] ?? '') === $MONITOR_KEY;

if (!$authenticated && !$keyAuth) {
    if ($format === 'json') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Use ?key=YOUR_KEY or login.']);
    } else {
        http_response_code(401);
        echo '<h1>401 Unauthorized</h1>';
    }
    exit;
}

require_once dirname(__DIR__) . '/include/WhatsAppLogger.php';

// ========== COLLECT HEALTH DATA ==========
$health = [];
$alerts = [];

// 1. DB Health
try {
    $logger = new WhatsAppLogger();
    $health['db'] = ['status' => 'ok', 'error' => null];
} catch (Exception $e) {
    $health['db'] = ['status' => 'FAILED', 'error' => $e->getMessage()];
    $alerts[] = ['level' => 'critical', 'msg' => 'Database connection failed: ' . $e->getMessage()];
    // Can't continue without DB
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['health' => $health, 'alerts' => $alerts, 'status' => 'critical', 'timestamp' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
    } else {
        echo '<h1 style="color:red">🔴 CRITICAL: Database Failed</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    }
    exit;
}

// 2. Queue Depth
$stats = $logger->getStats();
$health['queue'] = [
    'total' => $stats['total'],
    'queued' => $stats['queued'],
    'sending' => $stats['sending'],
    'sent' => $stats['sent'],
    'failed' => $stats['failed'],
    'failed_permanent' => $stats['failed_permanent'],
    'success_rate' => $stats['success_rate'] . '%'
];

// 3. Stuck Messages (sending > 5 minutes)
$pdo = db();
$stuckStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE status = 'sending' AND sending_at < ?");
$stuckTime = date('Y-m-d H:i:s', time() - 300);
$stuckStmt->execute([$stuckTime]);
$stuckCount = (int)$stuckStmt->fetchColumn();
$health['stuck_sending'] = $stuckCount;

if ($stuckCount > 0) {
    $alerts[] = ['level' => 'warning', 'msg' => "$stuckCount messages stuck in 'sending' state for > 5 minutes"];
}

// High queue alert
if ($stats['queued'] > 20) {
    $alerts[] = ['level' => 'warning', 'msg' => "High queue depth: {$stats['queued']} messages waiting"];
}

// Failed permanent rate
if ($stats['total'] > 10 && $stats['failed_permanent'] > 0) {
    $permRate = round(($stats['failed_permanent'] / $stats['total']) * 100, 1);
    if ($permRate > 20) {
        $alerts[] = ['level' => 'critical', 'msg' => "High permanent failure rate: {$permRate}%"];
    }
}

// 4. Worker Health
$lockFile = dirname(__DIR__) . '/admin/data/whatsapp_worker.lock';
$workerLog = dirname(__DIR__) . '/admin/data/worker_debug.log';

$health['worker'] = [
    'lock_active' => file_exists($lockFile),
    'lock_age' => file_exists($lockFile) ? (time() - filemtime($lockFile)) . 's ago' : 'N/A',
    'log_exists' => file_exists($workerLog),
    'last_log_lines' => []
];

if (file_exists($workerLog)) {
    $lines = file($workerLog);
    $health['worker']['last_log_lines'] = array_slice($lines, -5); // Last 5 lines
    $health['worker']['log_size'] = round(filesize($workerLog) / 1024, 1) . 'KB';
}

// 5. API Health
$healthFile = dirname(__DIR__) . '/admin/data/wasender_health.json';
if (file_exists($healthFile)) {
    $apiHealth = json_decode(file_get_contents($healthFile), true) ?? [];
    $health['api'] = [
        'status' => $apiHealth['status'] ?? 'unknown',
        'last_success' => $apiHealth['last_success'] ?? 'never',
        'last_error_time' => $apiHealth['last_error'] ?? 'never',
        'last_error_msg' => $apiHealth['error'] ?? '',
    ];
    
    // API down for > 10 minutes
    if (($apiHealth['status'] ?? '') === 'disconnected') {
        $lastOk = $apiHealth['last_success'] ?? null;
        $downTime = $lastOk ? (time() - strtotime($lastOk)) : 999999;
        if ($downTime > 600) {
            $alerts[] = ['level' => 'critical', 'msg' => 'API disconnected for ' . round($downTime / 60) . ' minutes'];
        }
    }
} else {
    $health['api'] = ['status' => 'unknown', 'note' => 'No health file found'];
}

// 6. Recent Errors (last 5)
$errStmt = $pdo->query("SELECT id, phone, message_type, error_message, failed_at, attempts FROM messages WHERE error_message IS NOT NULL AND error_message != '' ORDER BY failed_at DESC LIMIT 5");
$health['recent_errors'] = $errStmt->fetchAll(PDO::FETCH_ASSOC);

// Overall Status
$overallStatus = 'healthy';
foreach ($alerts as $a) {
    if ($a['level'] === 'critical') { $overallStatus = 'critical'; break; }
    if ($a['level'] === 'warning') { $overallStatus = 'warning'; }
}

$result = [
    'status' => $overallStatus,
    'timestamp' => date('Y-m-d H:i:s'),
    'health' => $health,
    'alerts' => $alerts,
    'alert_count' => count($alerts)
];

// ========== OUTPUT ==========
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// HTML Dashboard
$statusColors = ['healthy' => '#22c55e', 'warning' => '#f59e0b', 'critical' => '#ef4444'];
$statusEmoji = ['healthy' => '🟢', 'warning' => '🟡', 'critical' => '🔴'];
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مراقبة نظام الرسائل</title>
    <meta http-equiv="refresh" content="30">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; }
        .header { text-align: center; padding: 20px; margin-bottom: 20px; }
        .status-badge { display: inline-block; padding: 8px 24px; border-radius: 50px; font-size: 1.2em; font-weight: bold;
            background: <?= $statusColors[$overallStatus] ?>22; color: <?= $statusColors[$overallStatus] ?>;
            border: 2px solid <?= $statusColors[$overallStatus] ?>; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
        .card { background: #1e293b; border-radius: 12px; padding: 20px; border: 1px solid #334155; }
        .card h3 { color: #94a3b8; font-size: 0.85em; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 1px; }
        .stat { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #334155; }
        .stat:last-child { border: none; }
        .stat .label { color: #94a3b8; }
        .stat .value { font-weight: bold; }
        .stat .value.ok { color: #22c55e; }
        .stat .value.warn { color: #f59e0b; }
        .stat .value.bad { color: #ef4444; }
        .alert { padding: 10px 16px; border-radius: 8px; margin-bottom: 8px; font-size: 0.9em; }
        .alert.critical { background: #ef444422; border: 1px solid #ef4444; color: #fca5a5; }
        .alert.warning { background: #f59e0b22; border: 1px solid #f59e0b; color: #fde68a; }
        .error-row { background: #1e1e2e; padding: 8px 12px; border-radius: 6px; margin-bottom: 6px; font-size: 0.85em; }
        .error-phone { color: #60a5fa; }
        .error-msg { color: #fca5a5; }
        .log-line { font-family: monospace; font-size: 0.78em; color: #94a3b8; line-height: 1.6; }
        .refresh-note { text-align: center; color: #475569; margin-top: 20px; font-size: 0.85em; }
        .json-link { text-align: center; margin-top: 10px; }
        .json-link a { color: #60a5fa; text-decoration: none; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📡 مراقبة نظام الرسائل</h1>
        <div style="margin-top: 10px">
            <span class="status-badge"><?= $statusEmoji[$overallStatus] ?> <?= strtoupper($overallStatus) ?></span>
        </div>
        <div style="color:#64748b; margin-top:8px"><?= date('Y-m-d H:i:s') ?></div>
    </div>

    <?php if (!empty($alerts)): ?>
    <div style="margin-bottom: 20px">
        <?php foreach ($alerts as $a): ?>
        <div class="alert <?= $a['level'] ?>">
            <?= $a['level'] === 'critical' ? '🔴' : '🟡' ?> <?= htmlspecialchars($a['msg']) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="grid">
        <!-- Queue Stats -->
        <div class="card">
            <h3>📊 حالة الطابور</h3>
            <div class="stat"><span class="label">إجمالي</span><span class="value"><?= $stats['total'] ?></span></div>
            <div class="stat"><span class="label">في الانتظار</span><span class="value <?= $stats['queued'] > 10 ? 'warn' : 'ok' ?>"><?= $stats['queued'] ?></span></div>
            <div class="stat"><span class="label">جاري الإرسال</span><span class="value"><?= $stats['sending'] ?></span></div>
            <div class="stat"><span class="label">تم الإرسال</span><span class="value ok"><?= $stats['sent'] ?></span></div>
            <div class="stat"><span class="label">فشل (مؤقت)</span><span class="value <?= $stats['failed_retryable'] > 0 ? 'warn' : '' ?>"><?= $stats['failed_retryable'] ?? 0 ?></span></div>
            <div class="stat"><span class="label">فشل (نهائي)</span><span class="value <?= $stats['failed_permanent'] > 0 ? 'bad' : '' ?>"><?= $stats['failed_permanent'] ?></span></div>
            <div class="stat"><span class="label">نسبة النجاح</span><span class="value <?= $stats['success_rate'] > 90 ? 'ok' : ($stats['success_rate'] > 50 ? 'warn' : 'bad') ?>"><?= $stats['success_rate'] ?>%</span></div>
        </div>

        <!-- API Health -->
        <div class="card">
            <h3>🔌 حالة الـ API</h3>
            <div class="stat"><span class="label">الحالة</span><span class="value <?= ($health['api']['status'] ?? '') === 'connected' ? 'ok' : 'bad' ?>"><?= $health['api']['status'] ?? 'unknown' ?></span></div>
            <div class="stat"><span class="label">آخر نجاح</span><span class="value"><?= $health['api']['last_success'] ?? 'never' ?></span></div>
            <div class="stat"><span class="label">آخر خطأ</span><span class="value"><?= $health['api']['last_error_time'] ?? 'never' ?></span></div>
            <?php if (!empty($health['api']['last_error_msg'])): ?>
            <div class="stat"><span class="label">رسالة الخطأ</span><span class="value bad"><?= htmlspecialchars(mb_substr($health['api']['last_error_msg'], 0, 80)) ?></span></div>
            <?php endif; ?>
            <div class="stat"><span class="label">رسائل عالقة</span><span class="value <?= $stuckCount > 0 ? 'bad' : 'ok' ?>"><?= $stuckCount ?></span></div>
        </div>

        <!-- Worker Health -->
        <div class="card">
            <h3>⚙️ حالة العامل</h3>
            <div class="stat"><span class="label">قفل نشط</span><span class="value"><?= $health['worker']['lock_active'] ? '🔒 نعم' : '🔓 لا' ?></span></div>
            <div class="stat"><span class="label">عمر القفل</span><span class="value"><?= $health['worker']['lock_age'] ?></span></div>
            <div class="stat"><span class="label">حجم اللوج</span><span class="value"><?= $health['worker']['log_size'] ?? 'N/A' ?></span></div>
            <div class="stat"><span class="label">قاعدة البيانات</span><span class="value <?= $health['db']['status'] === 'ok' ? 'ok' : 'bad' ?>"><?= $health['db']['status'] ?></span></div>
        </div>

        <!-- Recent Errors -->
        <div class="card" style="grid-column: 1 / -1">
            <h3>❌ آخر الأخطاء</h3>
            <?php if (empty($health['recent_errors'])): ?>
                <div style="color:#22c55e; text-align:center; padding:20px">✅ لا توجد أخطاء حديثة</div>
            <?php else: ?>
                <?php foreach ($health['recent_errors'] as $err): ?>
                <div class="error-row">
                    <span class="error-phone"><?= htmlspecialchars($err['phone']) ?></span>
                    <span style="color:#64748b"> | <?= htmlspecialchars($err['message_type']) ?> | محاولة <?= $err['attempts'] ?></span>
                    <br><span class="error-msg">⚠️ <?= htmlspecialchars($err['error_message']) ?></span>
                    <span style="color:#475569; font-size:0.8em"> — <?= $err['failed_at'] ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Worker Logs -->
        <div class="card" style="grid-column: 1 / -1">
            <h3>📋 آخر سطور اللوج</h3>
            <?php if (!empty($health['worker']['last_log_lines'])): ?>
                <?php foreach ($health['worker']['last_log_lines'] as $line): ?>
                <div class="log-line"><?= htmlspecialchars(trim($line)) ?></div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:#475569; text-align:center; padding:10px">لا توجد سطور لوج</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="refresh-note">🔄 يتم التحديث تلقائياً كل 30 ثانية</div>
    <div class="json-link"><a href="?format=json">📄 عرض بصيغة JSON</a></div>
</body>
</html>
