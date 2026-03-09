<?php
/**
 * Print QR Codes - طباعة QR
 * Simple: Name + Large QR only
 */
session_start();

if (!isset($_SESSION['user'])) {
    header('location:login.php');
    exit;
}

// Load data
$dataFile = 'admin/data/data.json';
$data = [];
if (file_exists($dataFile)) {
    $data = json_decode(file_get_contents($dataFile), true) ?? [];
}

$printItems = [];

// Print all approved
if (isset($_GET['all'])) {
    foreach ($data as $item) {
        if (($item['status'] ?? '') === 'approved') {
            $printItems[] = $item;
        }
    }
}
// Print single by wasel
elseif (isset($_GET['wasel'])) {
    $wasel = $_GET['wasel'];
    foreach ($data as $item) {
        if ($item['wasel'] == $wasel) {
            $printItems[] = $item;
            break;
        }
    }
}

// Build absolute URL for QR
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة QR</title>
    <style>
        @media print {
            @page {
                size: A4;
                margin: 15mm;
            }
            .no-print {
                display: none !important;
            }
            .page-break {
                page-break-after: always;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;
            background: #fff;
            direction: rtl;
        }
        
        .no-print {
            background: #333;
            color: white;
            padding: 15px;
            text-align: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
        }
        
        .no-print button {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 30px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            margin: 0 10px;
        }
        
        .no-print button.close-btn {
            background: #dc3545;
        }
        
        .container {
            padding-top: 70px;
        }
        
        .qr-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 90vh;
            padding: 20px;
            text-align: center;
        }
        
        .member-name {
            font-size: 42px;
            font-weight: bold;
            color: #000;
            margin-bottom: 40px;
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
        }
        
        .qr-code {
            width: 350px;
            height: 350px;
            border: 5px solid #000;
            border-radius: 15px;
            padding: 10px;
            background: #fff;
        }
        
        .wasel-number {
            font-size: 28px;
            font-weight: bold;
            margin-top: 30px;
            color: #333;
        }
        
        .empty-message {
            text-align: center;
            padding: 100px 20px;
            font-size: 24px;
            color: #dc3545;
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">🖨️ طباعة</button>
        <button class="close-btn" onclick="window.close()">❌ إغلاق</button>
        <span style="margin-right: 20px;">عدد: <?= count($printItems) ?></span>
    </div>
    
    <div class="container">
        <?php if (empty($printItems)): ?>
        <div class="empty-message">
            ⚠️ لا توجد بيانات للطباعة
        </div>
        <?php else: ?>
        
        <?php foreach ($printItems as $index => $item): 
            $badgeId = $item['badge_id'] ?? $item['registration_code'] ?? $item['wasel'];
            $verifyUrl = $protocol . $host . $basePath . '/verify_entry.php?badge_id=' . urlencode($badgeId) . '&action=checkin';
            $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($verifyUrl);
            $name = $item['full_name'] ?? $item['name'] ?? 'بدون اسم';
        ?>
        <div class="qr-card <?= ($index < count($printItems) - 1) ? 'page-break' : '' ?>">
            <div class="member-name"><?= htmlspecialchars($name) ?></div>
            <img class="qr-code" src="<?= $qrCodeUrl ?>" alt="QR Code">
            <div class="wasel-number">#<?= $item['wasel'] ?></div>
        </div>
        <?php endforeach; ?>
        
        <?php endif; ?>
    </div>
</body>
</html>
