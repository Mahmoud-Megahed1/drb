<?php
$pdo = new PDO('sqlite:admin/data/database.db');

echo "=== MEMBERS TABLE ===\n";
$cols = $pdo->query('PRAGMA table_info(members)')->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) {
    echo $c['name'] . ' | ' . $c['type'] . "\n";
}

echo "\n=== REGISTRATIONS TABLE ===\n";
$cols2 = $pdo->query('PRAGMA table_info(registrations)')->fetchAll(PDO::FETCH_ASSOC);
foreach($cols2 as $c) {
    echo $c['name'] . ' | ' . $c['type'] . "\n";
}
