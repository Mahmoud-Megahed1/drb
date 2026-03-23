<?php
session_start();
if (!isset($_SESSION['user'])) {
    die('Access denied');
}

require_once __DIR__ . '/../include/db.php';
$pdo = db();

echo "<pre style='direction:ltr;text-align:left;'>\n";
echo "=== Instagram Recovery from Backups ===\n\n";

$backupDir = __DIR__ . '/data/backups/';
$files = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . '*.json');
}

// Also check the main data.json just in case
array_unshift($files, __DIR__ . '/data/data.json');

$foundInstagrams = [];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    if (!$content) continue;
    
    $data = json_decode($content, true);
    if (!is_array($data)) continue;
    
    foreach ($data as $item) {
        $phone = $item['phone'] ?? '';
        $insta = $item['instagram'] ?? '';
        
        if (!empty($phone) && !empty($insta)) {
            // Normalize phone
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($cleanPhone) > 10) $cleanPhone = substr($cleanPhone, -10);
            
            // Only keep the most recent/valid instagram we find
            if (!isset($foundInstagrams[$cleanPhone])) {
                $foundInstagrams[$cleanPhone] = $insta;
            }
        }
    }
}

echo "Found " . count($foundInstagrams) . " unique instagram handles across all backups.\n\n";

$restoredCount = 0;
$notFoundCount = 0;
$alreadyCorrectCount = 0;

foreach ($foundInstagrams as $phoneSuffix => $insta) {
    // Find member by phone
    $stmt = $pdo->prepare("SELECT id, instagram FROM members WHERE phone LIKE ?");
    $stmt->execute(['%' . $phoneSuffix]);
    $member = $stmt->fetch();
    
    if ($member) {
        if (empty($member['instagram']) || $member['instagram'] !== $insta) {
            $pdo->prepare("UPDATE members SET instagram = ? WHERE id = ?")
                ->execute([$insta, $member['id']]);
            echo "RESTORED: Member #{$member['id']} => $insta\n";
            $restoredCount++;
        } else {
            // Already correct
            $alreadyCorrectCount++;
        }
    } else {
        echo "MISSING DB RECORD: Phone *$phoneSuffix not found in members table!\n";
        $notFoundCount++;
    }
}

// 2nd phase: also sync them back to data.json
require_once __DIR__ . '/../services/MemberService.php';
$dataJsonFile = __DIR__ . '/data/data.json';
$dataJson = json_decode(file_get_contents($dataJsonFile), true) ?? [];
$jsonUpdated = 0;

foreach ($dataJson as &$item) {
    $phone = $item['phone'] ?? '';
    if (empty($phone)) continue;
    
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($cleanPhone) > 10) $cleanPhone = substr($cleanPhone, -10);
    
    // If we recovered an instagram for this phone, make sure data.json has it
    if (isset($foundInstagrams[$cleanPhone])) {
        if (($item['instagram'] ?? '') !== $foundInstagrams[$cleanPhone]) {
            $item['instagram'] = $foundInstagrams[$cleanPhone];
            $jsonUpdated++;
        }
    }
}

if ($jsonUpdated > 0) {
    file_put_contents($dataJsonFile, json_encode($dataJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nResynced $jsonUpdated handles to data.json\n";
}

echo "\n=== DONE ===\n";
echo "Total Restored to Database: $restoredCount\n";
echo "</pre>";
