<?php
/**
 * Deep diagnostic: Check member_id vs wasel relationship
 */
require_once __DIR__ . '/include/db.php';

header('Content-Type: text/html; charset=utf-8');
$pdo = db();

echo "<h3>First 20 Members (by ID):</h3>";
echo "<table border='1' cellpadding='5'><tr><th>members.id</th><th>Name</th><th>Phone</th><th>1st Wasel</th><th>Diff</th><th>permanent_code</th></tr>";

$stmt = $pdo->query("
    SELECT m.id, m.name, m.phone, m.permanent_code,
    (SELECT wasel FROM registrations r WHERE r.member_id = m.id ORDER BY created_at ASC LIMIT 1) as first_wasel,
    (SELECT COUNT(*) FROM registrations r WHERE r.member_id = m.id) as reg_count
    FROM members m
    ORDER BY m.id ASC
    LIMIT 20
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $diff = $row['first_wasel'] ? ($row['id'] - (int)$row['first_wasel']) : 'N/A (0 regs)';
    $bg = ($diff === 0 || $diff === '0') ? '#d4edda' : (($diff === 'N/A (0 regs)') ? '#fff3cd' : '#f8d7da');
    echo "<tr style='background:$bg'><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['phone']}</td><td>{$row['first_wasel']}</td><td>$diff</td><td>{$row['permanent_code']}</td></tr>";
}
echo "</table>";

// Stats
$total = $pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
$minId = $pdo->query("SELECT MIN(id) FROM members")->fetchColumn();
$maxId = $pdo->query("SELECT MAX(id) FROM members")->fetchColumn();
$noRegs = $pdo->query("SELECT COUNT(*) FROM members m WHERE NOT EXISTS (SELECT 1 FROM registrations r WHERE r.member_id = m.id)")->fetchColumn();

echo "<p><strong>Total:</strong> $total members | <strong>ID range:</strong> $minId - $maxId | <strong>Members with 0 registrations:</strong> $noRegs</p>";

// Check for gaps
$gaps = $pdo->query("
    SELECT m1.id + 1 as gap_start, 
           (SELECT MIN(m2.id) FROM members m2 WHERE m2.id > m1.id) - 1 as gap_end
    FROM members m1
    WHERE NOT EXISTS (SELECT 1 FROM members m2 WHERE m2.id = m1.id + 1)
    AND m1.id < (SELECT MAX(id) FROM members)
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if ($gaps) {
    echo "<p><strong>ID Gaps:</strong></p><ul>";
    foreach ($gaps as $g) {
        echo "<li>Gap: {$g['gap_start']} - {$g['gap_end']}</li>";
    }
    echo "</ul>";
} else {
    echo "<p>✅ No ID gaps found</p>";
}

// Check sqlite_sequence
$seq = $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 'members'")->fetchColumn();
echo "<p><strong>Auto-increment counter:</strong> $seq</p>";
