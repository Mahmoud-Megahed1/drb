<?php
/**
 * WhatsApp Background Worker v2.0
 * ===============================
 * Processes the message queue from SQLite `messages` table.
 * Can be triggered via HTTP (from WaSender) or via Cron.
 * 
 * Concurrency: Uses file lock to prevent multiple workers.
 * Observability: Logs start/end/errors to worker_debug.log.
 */

// Allow background execution
ignore_user_abort(true);
set_time_limit(0); 

// Fast-close HTTP connection
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level() > 0) ob_end_clean();
    header("Connection: close");
    ob_start();
    echo "Worker Started";
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    @ob_flush();
    flush(); 
}

// ---------------- BACKGROUND EXECUTION ----------------

require_once __DIR__ . '/../wasender.php';

$lockFile = __DIR__ . '/../admin/data/whatsapp_worker.lock';
$logFile = __DIR__ . '/../admin/data/worker_debug.log';

function logWorker($msg, $level = 'INFO') {
    global $logFile;
    $memory = round(memory_get_usage(true) / 1024 / 1024, 1);
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . " [$level] [${memory}MB] $msg\n", FILE_APPEND);
    
    // Keep log file under 500KB
    if (file_exists($logFile) && filesize($logFile) > 512000) {
        $lines = file($logFile);
        $lines = array_slice($lines, -200); // Keep last 200 lines
        file_put_contents($logFile, implode('', $lines));
    }
}

// Ensure data directory exists
if (!is_dir(dirname($lockFile))) {
    mkdir(dirname($lockFile), 0755, true);
}

// Delete stale lock file (older than 10 minutes)
if (file_exists($lockFile) && (time() - filemtime($lockFile) > 600)) {
    @unlink($lockFile);
    logWorker("Stale lock file removed", "WARN");
}

// Prevent simultaneous workers
$fp = fopen($lockFile, "w+");
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    logWorker("Another worker is running. Exiting.", "INFO");
    fclose($fp);
    exit;
}

try {
    logWorker("Worker started (PID: " . getmypid() . ")");
    
    $wasender = new WaSender();
    $processedCount = $wasender->processQueueLoop(290); // ~4:50 max
    
    logWorker("Worker finished: $processedCount messages processed");
} catch (Exception $e) {
    logWorker("Worker CRASH: " . $e->getMessage(), "ERROR");
} catch (Error $e) {
    logWorker("Worker FATAL: " . $e->getMessage(), "ERROR");
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
