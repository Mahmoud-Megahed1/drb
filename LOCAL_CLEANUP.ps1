/**
 * Conservative Local Cleanup
 * ONLY removes temporary PowerShell scripts and non-essential logs.
 * STRICTLY preserves all .php files.
 */

$filesToDelete = @(
    "diagnose_result.json",
    "mappings_result.json",
    "phone_map_result.json",
    "live_state.json",
    "db_live.json",
    "ftp_commands.txt",
    "patch_v37.zip",
    "CLEANUP_EVERYTHING.php",
    "DEPLOY_CLEANUP.ps1",
    "DEBUG_SERVER.php",
    "DEBUG_SERVER_FULL.php",
    "DEBUG_QUEUE.php",
    "DEBUG_LOGS_DB.php",
    "REPAIR_QUEUE.php",
    "TIME_REPAIR_TOOL.php",
    "DEBUG_*.txt",
    "FINAL*.txt",
    "DB_*.txt"
)

$wildcards = @(
    "DEPLOY_*.ps1",
    "run_*.ps1",
    "fetch_*.ps1",
    "check_*.ps1"
)

Write-Host "=== STARTING CONSERVATIVE LOCAL CLEANUP ===" -ForegroundColor Yellow

foreach ($file in $filesToDelete) {
    if (Test-Path $file) {
        Remove-Item $file -Force
        Write-Host "DELETED: $file"
    }
}

foreach ($pattern in $wildcards) {
    $foundFiles = Get-ChildItem $pattern
    foreach ($match in $foundFiles) {
        if ($match.Name -ne "MASTER_DEPLOY.ps1" -and $match.Name -ne "SYNC_FROM_PROD.ps1") {
            Remove-Item $match.FullName -Force
            Write-Host "DELETED: $($match.Name)"
        }
    }
}

Write-Host "=== CLEANUP COMPLETE ===" -ForegroundColor Green
