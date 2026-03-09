<?php
/**
 * Final Server Cleanup Script
 * WARNING: This deletes temporary debug scripts.
 */
header('Content-Type: text/plain; charset=utf-8');

$filesToDelete = [
    __DIR__ . '/DEBUG_SERVER.php',
    __DIR__ . '/DEBUG_SERVER_FULL.php',
    __DIR__ . '/DEBUG_QUEUE.php',
    __DIR__ . '/DEBUG_LOGS_DB.php',
    __DIR__ . '/REPAIR_QUEUE.php',
    __DIR__ . '/CLEANUP_EVERYTHING.php'
];

echo "=== STARTING SERVER CLEANUP ===\n";

foreach ($filesToDelete as $file) {
    if (file_exists($file)) {
        if (unlink($file)) {
            echo "DELETED: " . basename($file) . "\n";
        } else {
            echo "FAILED to delete: " . basename($file) . "\n";
        }
    } else {
        echo "NOT FOUND: " . basename($file) . "\n";
    }
}

echo "=== CLEANUP COMPLETE ===\n";
