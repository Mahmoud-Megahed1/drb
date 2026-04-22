<?php
/**
 * Migration: Fix member ID alignment
 * 
 * Problem: Phantom member at ID 1 (0 registrations) shifts all real member IDs by +1
 * Solution: Delete phantom member, then decrement all IDs and foreign keys by 1
 * 
 * SAFETY: Creates a backup before making changes
 */
require_once __DIR__ . '/include/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>🔧 Member ID Realignment Migration</h2>";

$pdo = db();

// Step 0: Verify the phantom member
$stmt = $pdo->prepare("SELECT id, name, phone FROM members WHERE id = 1");
$stmt->execute();
$phantom = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$phantom) {
    echo "<p style='color:green'>✅ No member at ID 1. Nothing to fix.</p>";
    exit;
}

$regCount = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE member_id = 1");
$regCount->execute();
$regs = $regCount->fetchColumn();

echo "<p>Member #1: <strong>{$phantom['name']}</strong> (Phone: {$phantom['phone']}) — Registrations: {$regs}</p>";

if ($regs > 0) {
    echo "<p style='color:red'>❌ Member #1 has $regs registrations — NOT a phantom. Aborting.</p>";
    exit;
}

// Step 1: Backup
$dbPath = realpath(__DIR__ . '/database/members.db');
$backupPath = $dbPath . '.backup_' . date('Ymd_His');
if (copy($dbPath, $backupPath)) {
    echo "<p>📦 Backup created: " . basename($backupPath) . "</p>";
} else {
    echo "<p style='color:red'>❌ Failed to create backup. Aborting.</p>";
    exit;
}

// Step 2: Begin transaction
$pdo->beginTransaction();
try {
    // Delete phantom member
    $pdo->exec("DELETE FROM members WHERE id = 1");
    echo "<p>🗑️ Deleted phantom member #1</p>";
    
    // Check for warnings/notes linked to phantom member
    $warnCount = $pdo->query("SELECT COUNT(*) FROM warnings WHERE member_id = 1")->fetchColumn();
    if ($warnCount > 0) {
        $pdo->exec("DELETE FROM warnings WHERE member_id = 1");
        echo "<p>🗑️ Deleted $warnCount orphan warnings for member #1</p>";
    }
    
    // Check notes table exists and clean up
    try {
        $noteCount = $pdo->query("SELECT COUNT(*) FROM notes WHERE member_id = 1")->fetchColumn();
        if ($noteCount > 0) {
            $pdo->exec("DELETE FROM notes WHERE member_id = 1");
            echo "<p>🗑️ Deleted $noteCount orphan notes for member #1</p>";
        }
    } catch (Exception $e) { /* notes table might not exist */ }
    
    // Decrement ALL member IDs by 1
    // SQLite allows updating INTEGER PRIMARY KEY (it's an alias for rowid)
    // Must update in reverse order to avoid UNIQUE constraint violations
    $maxId = $pdo->query("SELECT MAX(id) FROM members")->fetchColumn();
    for ($i = 2; $i <= $maxId; $i++) {
        $pdo->prepare("UPDATE members SET id = ? WHERE id = ?")->execute([$i - 1, $i]);
    }
    echo "<p>✅ Decremented all member IDs (2→1, 3→2, ... {$maxId}→" . ($maxId - 1) . ")</p>";
    
    // Update foreign keys in registrations
    $pdo->exec("UPDATE registrations SET member_id = member_id - 1 WHERE member_id > 1");
    $regUpdated = $pdo->query("SELECT changes()")->fetchColumn();
    echo "<p>✅ Updated $regUpdated registration records</p>";
    
    // Update foreign keys in warnings
    $pdo->exec("UPDATE warnings SET member_id = member_id - 1 WHERE member_id > 1");
    $warnUpdated = $pdo->query("SELECT changes()")->fetchColumn();
    echo "<p>✅ Updated $warnUpdated warning records</p>";
    
    // Update foreign keys in notes (if table exists)
    try {
        $pdo->exec("UPDATE notes SET member_id = member_id - 1 WHERE member_id > 1");
        $noteUpdated = $pdo->query("SELECT changes()")->fetchColumn();
        echo "<p>✅ Updated $noteUpdated note records</p>";
    } catch (Exception $e) { /* notes table might not have member_id */ }
    
    // Update sqlite_sequence to reflect new max ID
    $newMax = $pdo->query("SELECT MAX(id) FROM members")->fetchColumn();
    $pdo->prepare("UPDATE sqlite_sequence SET seq = ? WHERE name = 'members'")->execute([$newMax]);
    echo "<p>✅ Updated auto-increment counter to $newMax</p>";
    
    // Commit
    $pdo->commit();
    echo "<h3 style='color:green'>✅ Migration completed successfully!</h3>";
    
    // Verify
    echo "<h3>Verification (first 10 members):</h3>";
    echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Name</th><th>Phone</th><th>Last Wasel</th><th>Match?</th></tr>";
    $stmt = $pdo->query("
        SELECT m.id, m.name, m.phone,
        (SELECT wasel FROM registrations r WHERE r.member_id = m.id ORDER BY created_at ASC LIMIT 1) as first_wasel
        FROM members m ORDER BY m.id ASC LIMIT 10
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $match = ($row['id'] == $row['first_wasel']) ? '✅' : '⚠️';
        echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['phone']}</td><td>{$row['first_wasel']}</td><td>$match</td></tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h3 style='color:red'>❌ Migration FAILED — rolled back!</h3>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    echo "<p>Database is unchanged. Backup at: " . basename($backupPath) . "</p>";
}
