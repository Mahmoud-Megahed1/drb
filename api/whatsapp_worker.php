<?php
/**
 * WhatsApp Background Worker
 * This script runs in the background and processes the message queue 
 * with safe delays to prevent API blocking.
 */

// Allow script to run in background even if browser closes
ignore_user_abort(true);
set_time_limit(0); 

// Fast-close the connection to the browser so the HTTP request finishes immediately
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Fallback for standard PHP (e.g. Apache)
    ob_end_clean();
    header("Connection: close");
    ob_start();
    echo "Worker Started";
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    @ob_flush();
    flush(); 
}

// ---------------- BACKGROUND EXECUTION STARTS HERE ---------------- 

require_once __DIR__ . '/../wasender.php';

$lockFile = __DIR__ . '/../admin/data/whatsapp_worker.lock';
$logFile = __DIR__ . '/../admin/data/worker_debug.log';

function logWorker($msg) {
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// Ensure data directory exists
if (!is_dir(dirname($lockFile))) {
    mkdir(dirname($lockFile), 0755, true);
}

// Delete stale lock file (older than 10 minutes)
if (file_exists($lockFile) && (time() - filemtime($lockFile) > 600)) {
    @unlink($lockFile);
    logWorker("Stale lock file detected and removed.");
}

// Prevent simultaneous workers using a file lock
$fp = fopen($lockFile, "w+");
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    // Another worker is already running
    logWorker("Worker already running. Exiting.");
    fclose($fp);
    exit;
}

try {
    logWorker("Worker Started: Claimed Lock.");
    
    // Process queue for up to 4 minutes and 50 seconds (290s)
    // This allows cron or further triggers to pick up naturally without hitting 5min limits
    $wasender = new WaSender();
    $processedCount = $wasender->processQueueLoop(290);
    
    logWorker("Worker Finished: Processed $processedCount messages.");
} catch (Exception $e) {
    logWorker("Worker Error: " . $e->getMessage());
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
