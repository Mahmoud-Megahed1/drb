<?php
/**
 * WhatsApp Cron Endpoint v2.2
 * ============================
 * Runs every minute via Cron. Three jobs:
 *   1. Trigger worker if pending messages exist
 *   2. Proactive health checks with SMART alerting
 *   3. Metrics snapshot (last 60 minutes rolling history)
 * 
 * IMPROVEMENTS over v2.1:
 *   - Alert deduplication (same alert won't spam every minute)
 *   - Severity escalation (warning → critical after sustained failure)
 *   - Minimum volume threshold (no false alarms on small batches)
 *   - Rolling metrics history (60-minute trends)
 *   - One-line SYSTEM_STATUS file (OK / DEGRADED / DOWN)
 * 
 * Cron setup:
 * wget -q -O /dev/null "https://yellowgreen-quail-410393.hostingersite.com/api/whatsapp_cron.php?key=cron_secret_2026" >/dev/null 2>&1
 */

$CRON_KEY = 'cron_secret_2026';

if (($_GET['key'] ?? '') !== $CRON_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid cron key']);
    exit;
}

header('Content-Type: application/json');

require_once __DIR__ . '/../wasender.php';
require_once __DIR__ . '/../include/WhatsAppLogger.php';

$dataDir   = __DIR__ . '/../admin/data';
$logFile   = $dataDir . '/worker_debug.log';
$alertFile = $dataDir . '/ALERT_STATUS.json';
$metricsFile = $dataDir . '/metrics_history.json';
$statusFile  = $dataDir . '/SYSTEM_STATUS.txt';
$criticalFile = $dataDir . '/CRITICAL_ALERT.txt';

if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);

function cronLog($msg, $level = 'INFO') {
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . " [$level] [CRON] $msg\n", FILE_APPEND);
}

cronLog("Cron tick");

// ========================================
// JOB 1: Trigger worker if needed
// ========================================
$wasender = new WaSender();
$pending = $wasender->getPendingCount();
$workerTriggered = false;

if ($pending > 0) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'yellowgreen-quail-410393.hostingersite.com';
    $workerUrl = "$protocol://$host/api/whatsapp_worker.php";
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $workerUrl);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        @curl_exec($ch);
        curl_close($ch);
    }
    $workerTriggered = true;
}

// ========================================
// JOB 2: Health Checks + Smart Alerting
// ========================================
$alerts = [];
$overallStatus = 'OK';

// Load previous alert state for deduplication
$prevAlerts = [];
if (file_exists($alertFile)) {
    $prev = json_decode(file_get_contents($alertFile), true);
    if (is_array($prev['alerts'] ?? null)) {
        foreach ($prev['alerts'] as $a) {
            $prevAlerts[$a['check']] = $a;
        }
    }
}

/**
 * Smart alert: only fire if state CHANGED or escalation threshold hit
 */
function smartAlert($check, $level, $msg, $value) {
    global $alerts, $prevAlerts, $overallStatus;
    
    $prev = $prevAlerts[$check] ?? null;
    $now = time();
    
    // Calculate how long this condition has been active
    $firstSeen = ($prev && $prev['check'] === $check) ? ($prev['first_seen'] ?? $now) : $now;
    $durationMin = round(($now - $firstSeen) / 60);
    
    // Severity escalation: sustained issues get worse
    if ($level === 'warning' && $durationMin >= 15) {
        $level = 'critical';
        $msg .= " (مستمر منذ {$durationMin} دقيقة ⬆️)";
    }
    
    // Deduplication: don't re-log if same alert already active AND level hasn't changed
    $isNew = !$prev || ($prev['level'] ?? '') !== $level;
    
    $alert = [
        'level' => $level,
        'check' => $check,
        'msg' => $msg,
        'value' => $value,
        'first_seen' => $firstSeen,
        'duration_min' => $durationMin,
        'is_new' => $isNew
    ];
    
    $alerts[] = $alert;
    
    if ($level === 'critical') $overallStatus = 'DOWN';
    elseif ($level === 'warning' && $overallStatus === 'OK') $overallStatus = 'DEGRADED';
}

try {
    $logger = new WhatsAppLogger();
    $stats = $logger->getStats();
    $pdo = db();
    
    // CHECK 1: High queue depth
    if ($stats['queued'] > 20) {
        smartAlert('queue_depth', 'warning', "طابور عالي: {$stats['queued']} رسالة منتظرة", $stats['queued']);
    }
    
    // CHECK 2: Stuck sending > 5 minutes
    $stuckStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE status = 'sending' AND sending_at < ?");
    $stuckStmt->execute([date('Y-m-d H:i:s', time() - 300)]);
    $stuckCount = (int)$stuckStmt->fetchColumn();
    if ($stuckCount > 0) {
        smartAlert('stuck_sending', 'critical', "$stuckCount رسالة عالقة في الإرسال > 5 دقائق", $stuckCount);
    }
    
    // CHECK 3: High failure rate — WITH minimum volume guard
    $hourAgo = date('Y-m-d H:i:s', time() - 3600);
    $recentStmt = $pdo->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status IN ('failed', 'failed_permanent') THEN 1 ELSE 0 END) as failed
        FROM messages WHERE created_at > ?");
    $recentStmt->execute([$hourAgo]);
    $recent = $recentStmt->fetch(PDO::FETCH_ASSOC);
    if ($recent['total'] >= 10) {  // Minimum 10 messages to avoid false alarms
        $failRate = round(($recent['failed'] / $recent['total']) * 100, 1);
        if ($failRate > 30) {
            smartAlert('failure_rate', 'critical', "نسبة فشل عالية: {$failRate}% ({$recent['failed']}/{$recent['total']} في الساعة)", $failRate);
        }
    }
    
    // CHECK 4: API disconnected > 10 minutes
    $healthFile = $dataDir . '/wasender_health.json';
    if (file_exists($healthFile)) {
        $apiHealth = json_decode(file_get_contents($healthFile), true) ?? [];
        if (($apiHealth['status'] ?? '') === 'disconnected') {
            $lastOk = $apiHealth['last_success'] ?? null;
            if ($lastOk) {
                $downMin = round((time() - strtotime($lastOk)) / 60);
                if ($downMin > 10) {
                    smartAlert('api_down', 'critical', "API منقطع منذ {$downMin} دقيقة", $downMin);
                }
            }
        }
    }
    
    // CHECK 5: No sends in 30 min while queue has messages
    if ($stats['queued'] > 0 || $stats['sending'] > 0) {
        $lastSentStmt = $pdo->query("SELECT sent_at FROM messages WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1");
        $lastSent = $lastSentStmt->fetchColumn();
        if ($lastSent) {
            $minSince = round((time() - strtotime($lastSent)) / 60);
            if ($minSince > 30) {
                smartAlert('stale_sends', 'warning', "لا يوجد إرسال ناجح منذ {$minSince} دقيقة", $minSince);
            }
        }
    }

} catch (Exception $e) {
    smartAlert('db_connection', 'critical', "قاعدة البيانات فشلت: " . $e->getMessage(), $e->getMessage());
    $stats = ['queued' => '?', 'sent' => '?', 'failed' => '?', 'success_rate' => 0];
}

// ========================================
// JOB 3: Metrics Snapshot (rolling 60 min)
// ========================================
$metrics = [];
if (file_exists($metricsFile)) {
    $metrics = json_decode(file_get_contents($metricsFile), true) ?? [];
}

// Add current snapshot
$metrics[] = [
    'ts' => date('Y-m-d H:i:s'),
    'queued' => $stats['queued'] ?? 0,
    'sent' => $stats['sent'] ?? 0,
    'failed' => $stats['failed'] ?? 0,
    'success_rate' => $stats['success_rate'] ?? 0,
    'pending' => $pending,
    'status' => $overallStatus
];

// Keep only last 60 entries (60 minutes at 1/min)
if (count($metrics) > 60) {
    $metrics = array_slice($metrics, -60);
}
file_put_contents($metricsFile, json_encode($metrics, JSON_UNESCAPED_UNICODE));

// ========================================
// Write SYSTEM_STATUS (one-line file)
// ========================================
file_put_contents($statusFile, $overallStatus);

// ========================================
// Write ALERT_STATUS.json
// ========================================
$alertData = [
    'status' => $overallStatus,
    'checked_at' => date('Y-m-d H:i:s'),
    'alert_count' => count($alerts),
    'alerts' => $alerts,
    'queue_pending' => $pending,
    'worker_triggered' => $workerTriggered
];
file_put_contents($alertFile, json_encode($alertData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// ========================================
// Log ONLY new or escalated alerts
// ========================================
$newAlertCount = 0;
foreach ($alerts as $a) {
    if ($a['is_new']) {
        $logLevel = $a['level'] === 'critical' ? 'CRITICAL' : 'WARN';
        cronLog("🚨 " . $a['msg'], $logLevel);
        $newAlertCount++;
    }
}

// Write/clear CRITICAL_ALERT.txt
if ($overallStatus === 'DOWN') {
    $txt = "⚠️ SYSTEM DOWN — " . date('Y-m-d H:i:s') . "\n";
    $txt .= str_repeat('=', 50) . "\n";
    foreach ($alerts as $a) {
        if ($a['level'] === 'critical') {
            $txt .= "🔴 " . $a['msg'] . " (منذ " . $a['duration_min'] . " دقيقة)\n";
        }
    }
    $txt .= str_repeat('=', 50) . "\n";
    $txt .= "Dashboard: https://yellowgreen-quail-410393.hostingersite.com/api/whatsapp_monitor.php\n";
    file_put_contents($criticalFile, $txt);
} else {
    if (file_exists($criticalFile)) @unlink($criticalFile);
}

// ========================================
// RESPONSE
// ========================================
echo json_encode([
    'system_status' => $overallStatus,
    'pending' => $pending,
    'worker_triggered' => $workerTriggered,
    'alerts' => count($alerts),
    'new_alerts' => $newAlertCount,
    'metrics_points' => count($metrics)
], JSON_UNESCAPED_UNICODE);
