<?php
// Disable buffering for real-time output
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
while (@ob_end_flush());
ini_set('implicit_flush', true);
ob_implicit_flush(true);

session_start();
header('Content-Type: text/plain');

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo "Unauthorized";
    exit;
}

require_once '../../wasender.php';
$sender = new WaSender();

$message = $_POST['message'] ?? '';
$group = $_POST['group'] ?? 'all';
$numbers = [];

echo "Started at " . date('H:i:s') . "\n";
echo "Group: $group\n";

// 1. Get Numbers
if ($group === 'custom_file' && isset($_FILES['file'])) {
    $content = file_get_contents($_FILES['file']['tmp_name']);
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $num = trim($line);
        if (!empty($num)) $numbers[] = $num;
    }
} else {
    // Load from data.json
    $dataFile = __DIR__ . '/../data/data.json';
    if (!file_exists($dataFile)) {
        echo "Error: Data file not found\n";
        exit;
    }
    
    $data = json_decode(file_get_contents($dataFile), true);
    foreach ($data as $reg) {
        $phone = $reg['phone'] ?? '';
        $status = $reg['status'] ?? 'pending';
        
        if (empty($phone)) continue;

        if ($group === 'all') {
            $numbers[] = $phone;
        } elseif ($group === 'approved' && $status === 'approved') {
            $numbers[] = $phone;
        } elseif ($group === 'pending' && $status === 'pending') {
            $numbers[] = $phone;
        }
    }
}

$total = count($numbers);
echo "Found $total numbers.\n----------------\n";

// 2. Send
$count = 0;
foreach ($numbers as $phone) {
    $count++;
    echo "[$count/$total] Sending to $phone... ";
    
    try {
        if (!empty($_POST['image'])) {
            $sender->sendImage($phone, $_POST['image'], $message);
        } else {
            $sender->sendMessage($phone, $message);
        }
        echo "✅ OK\n";
    } catch (Exception $e) {
        echo "❌ Failed\n";
    }
    
    // Delay to prevent blocking
    usleep(500000); // 0.5 sec
}

echo "\n----------------\nDone!";
?>
