<?php
/**
 * Check Registration Status - Public Page
 * =========================================
 * المشارك يدخل رقم لوحته (بدون الترميز والمحافظة) ثم يتحقق بالموبايل
 * ويشوف حالة قبوله + يحمّل QR لو مقبول
 * 
 * لا يحتاج تسجيل دخول - صفحة عامة
 */

$dataFile = __DIR__ . '/admin/data/data.json';
$membersFile = __DIR__ . '/admin/data/members.json';
$settingsFile = __DIR__ . '/admin/data/settings.json';

$data = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) ?? [] : [];
$members = file_exists($membersFile) ? json_decode(file_get_contents($membersFile), true) ?? [] : [];
$settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) ?? [] : [];

$step = 'plate'; // plate -> phone -> result
$result = null;
$error = '';
$plateNumber = '';
$phone = '';
$foundRegistrations = [];

/**
 * Check if a plate field matches the search number
 * Handles: "77268", "K 77268", "بغداد - K 77268", etc.
 */
function plateMatches($plateValue, $searchNumber) {
    if (empty($plateValue) || empty($searchNumber)) return false;
    
    $plateValue = trim(strval($plateValue));
    $searchNumber = trim(strval($searchNumber));
    
    // Exact match
    if ($plateValue === $searchNumber) return true;
    
    // Extract all numbers from the plate value
    preg_match_all('/\d+/', $plateValue, $matches);
    if (!empty($matches[0])) {
        foreach ($matches[0] as $num) {
            if ($num === $searchNumber) return true;
        }
    }
    
    return false;
}

/**
 * Search for a registration by plate number in data.json + members.json
 */
function findByPlate($data, $members, $plateNumber) {
    $results = [];
    
    // 1. Search in data.json
    foreach ($data as $reg) {
        $matched = false;
        
        // Check plate_number field
        if (plateMatches($reg['plate_number'] ?? '', $plateNumber)) $matched = true;
        
        // Check plate_full field (may contain "بغداد - K 77268")
        if (!$matched && plateMatches($reg['plate_full'] ?? '', $plateNumber)) $matched = true;
        
        if ($matched) {
            $results[] = $reg;
        }
    }
    
    // 2. Search in members.json (for imported members)
    if (empty($results)) {
        foreach ($members as $code => $member) {
            $matched = false;
            
            if (plateMatches($member['plate_number'] ?? '', $plateNumber)) $matched = true;
            if (!$matched && plateMatches($member['plate_full'] ?? '', $plateNumber)) $matched = true;
            
            if ($matched) {
                // Add registration_code and status if missing
                $member['registration_code'] = $member['registration_code'] ?? $code;
                $member['status'] = $member['status'] ?? 'approved'; // Members are approved by default
                $results[] = $member;
            }
        }
    }
    
    return $results;
}

/**
 * Clean phone number for comparison
 */
function cleanPhone($phone) {
    $clean = preg_replace('/\D/', '', $phone);
    if (substr($clean, 0, 3) === '964') $clean = substr($clean, 3);
    if (substr($clean, 0, 2) === '07') $clean = substr($clean, 1);
    if (substr($clean, 0, 1) === '0') $clean = substr($clean, 1);
    return substr($clean, -10);
}

/**
 * Search ALL registrations by phone number
 */
function findByPhone($data, $members, $phone) {
    $cleanPhone = cleanPhone($phone);
    if (empty($cleanPhone)) return null;
    
    // Search data.json
    foreach ($data as $reg) {
        if (cleanPhone($reg['phone'] ?? '') === $cleanPhone) {
            return $reg;
        }
    }
    
    // Search members.json
    foreach ($members as $code => $member) {
        if (cleanPhone($member['phone'] ?? '') === $cleanPhone) {
            $member['registration_code'] = $member['registration_code'] ?? $code;
            $member['status'] = $member['status'] ?? 'approved';
            return $member;
        }
    }
    
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plateNumber = trim($_POST['plate_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $step = $_POST['step'] ?? 'plate';
    
    if ($step === 'plate' && !empty($plateNumber)) {
        // Step 1: Always go to phone step (don't block if plate not found)
        $foundRegistrations = findByPlate($data, $members, $plateNumber);
        $step = 'phone';
        
    } elseif ($step === 'phone' && !empty($phone)) {
        // Step 2: Try to find by plate+phone first, then by phone only
        $cleanPhone = cleanPhone($phone);
        
        // Method 1: If plate was provided, search plate matches and verify phone
        if (!empty($plateNumber)) {
            $allMatches = findByPlate($data, $members, $plateNumber);
            foreach ($allMatches as $reg) {
                if (cleanPhone($reg['phone'] ?? '') === $cleanPhone) {
                    $result = $reg;
                    $step = 'result';
                    break;
                }
            }
        }
        
        // Method 2: If no result from plate search, search ALL data by phone number
        if (!$result) {
            $phoneResult = findByPhone($data, $members, $phone);
            if ($phoneResult) {
                $result = $phoneResult;
                $step = 'result';
            }
        }
        
        // Still no result
        if (!$result) {
            $error = 'لم يتم العثور على تسجيل بهذا الرقم. تأكد من إدخال نفس رقم الهاتف المسجل به.';
            $step = 'phone';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فحص حالة التسجيل</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Cairo', sans-serif; 
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 450px;
            margin-top: 30px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo h1 { font-size: 28px; margin-bottom: 5px; }
        .logo p { opacity: 0.7; font-size: 14px; }
        
        .card {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            margin-bottom: 20px;
        }
        
        .card h2 { font-size: 20px; margin-bottom: 15px; text-align: center; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 14px; opacity: 0.8; }
        .form-group input {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 12px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 20px;
            font-family: inherit;
            text-align: center;
            letter-spacing: 2px;
            transition: border-color 0.3s;
        }
        .form-group input::placeholder { color: rgba(255,255,255,0.4); font-size: 16px; letter-spacing: 0; }
        .form-group input:focus { outline: none; border-color: #007bff; background: rgba(255,255,255,0.15); }
        
        .btn-primary {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: #fff;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            font-family: inherit;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(0,123,255,0.4); }
        .btn-primary:active { transform: scale(0.98); }
        
        .error-msg {
            background: rgba(220,53,69,0.2);
            border: 1px solid rgba(220,53,69,0.5);
            color: #ff6b6b;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            text-align: center;
            font-size: 14px;
        }
        
        /* Result styles */
        .status-approved {
            background: linear-gradient(135deg, rgba(40,167,69,0.2), rgba(32,201,151,0.2));
            border: 2px solid #28a745;
        }
        .status-pending {
            background: linear-gradient(135deg, rgba(255,193,7,0.2), rgba(255,152,0,0.2));
            border: 2px solid #ffc107;
        }
        .status-rejected {
            background: linear-gradient(135deg, rgba(220,53,69,0.2), rgba(200,35,51,0.2));
            border: 2px solid #dc3545;
        }
        
        .status-icon { font-size: 60px; text-align: center; margin-bottom: 15px; }
        .status-text { font-size: 22px; font-weight: bold; text-align: center; margin-bottom: 5px; }
        .status-sub { font-size: 14px; text-align: center; opacity: 0.8; margin-bottom: 20px; }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-size: 14px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { opacity: 0.7; }
        .info-value { font-weight: bold; }
        
        .qr-section {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid rgba(255,255,255,0.1);
        }
        .qr-section canvas { border-radius: 15px; background: #fff; padding: 15px; }
        
        .btn-download {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 30px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: #fff;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
        }
        
        .btn-back {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: rgba(255,255,255,0.6);
            text-decoration: none;
            font-size: 14px;
        }
        
        .hint {
            text-align: center;
            font-size: 13px;
            opacity: 0.5;
            margin-top: 10px;
        }
        
        .plate-preview {
            text-align: center;
            background: rgba(255,193,7,0.15);
            border: 1px solid rgba(255,193,7,0.3);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 15px;
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="logo">
        <h1>🏁 فحص حالة التسجيل</h1>
        <p>تحقق من حالة قبولك وحمّل كود الدخول</p>
    </div>

    <?php if ($step === 'plate'): ?>
    <!-- Step 1: Enter Plate Number -->
    <div class="card">
        <h2>📋 أدخل رقم لوحتك</h2>
        
        <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="step" value="plate">
            <div class="form-group">
                <label>رقم اللوحة فقط (بدون الحروف والمحافظة)</label>
                <input type="text" name="plate_number" placeholder="مثال: 77268" 
                       value="<?= htmlspecialchars($plateNumber) ?>" 
                       required autofocus inputmode="numeric" pattern="[0-9]*">
            </div>
            <button type="submit" class="btn-primary">🔍 بحث</button>
        </form>
        <p class="hint">أدخل الأرقام فقط بدون الحروف أو اسم المحافظة</p>
    </div>

    <?php elseif ($step === 'phone'): ?>
    <!-- Step 2: Verify Phone -->
    <div class="card">
        <?php if (count($foundRegistrations) > 0): ?>
        <div class="plate-preview">
            🚗 رقم اللوحة: <strong><?= htmlspecialchars($plateNumber) ?></strong>
            — تم العثور على تسجيل ✅
        </div>
        <?php else: ?>
        <div class="plate-preview" style="background:rgba(255,193,7,0.25);border-color:rgba(255,193,7,0.5);">
            🚗 رقم اللوحة: <strong><?= htmlspecialchars($plateNumber) ?></strong>
            — سيتم البحث برقم الهاتف 📱
        </div>
        <?php endif; ?>
        
        <h2>📱 أدخل رقم الهاتف للتحقق</h2>
        
        <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="step" value="phone">
            <input type="hidden" name="plate_number" value="<?= htmlspecialchars($plateNumber) ?>">
            <div class="form-group">
                <label>أدخل رقم الهاتف الذي سجلت به</label>
                <input type="tel" name="phone" placeholder="07XXXXXXXXX" 
                       value="<?= htmlspecialchars($phone) ?>"
                       required autofocus inputmode="tel">
            </div>
            <button type="submit" class="btn-primary">✅ تحقق</button>
        </form>
        
        <a href="check_status.php" class="btn-back">← رجوع لتغيير رقم اللوحة</a>
    </div>

    <?php elseif ($step === 'result' && $result): ?>
    <!-- Step 3: Show Result -->
    <?php 
        $status = $result['status'] ?? 'pending';
        $statusClass = 'status-' . $status;
        $statusLabels = ['approved' => '✅ مقبول', 'pending' => '⏳ قيد المراجعة', 'rejected' => '❌ مرفوض'];
        $statusIcons = ['approved' => '🎉', 'pending' => '⏳', 'rejected' => '😔'];
    ?>
    
    <div class="card <?= $statusClass ?>">
        <div class="status-icon"><?= $statusIcons[$status] ?? '❓' ?></div>
        <div class="status-text"><?= $statusLabels[$status] ?? 'غير معروف' ?></div>
        <div class="status-sub">
            <?php if ($status === 'approved'): ?>
                تهانينا! تم قبول تسجيلك بنجاح
            <?php elseif ($status === 'pending'): ?>
                طلبك قيد المراجعة، يرجى الانتظار
            <?php else: ?>
                عذراً، تم رفض طلبك
            <?php endif; ?>
        </div>
        
        <?php if ($status === 'rejected'): ?>
        <?php if (!empty($result['rejection_reason'])): ?>
        <div style="background:rgba(220,53,69,0.15);border:1px solid rgba(220,53,69,0.4);border-radius:12px;padding:15px;margin-bottom:15px;text-align:center;">
            <div style="font-size:14px;opacity:0.8;margin-bottom:5px;">📝 سبب الرفض:</div>
            <div style="font-size:16px;font-weight:bold;"><?= htmlspecialchars($result['rejection_reason']) ?></div>
        </div>
        <?php endif; ?>
        
        <!-- Re-registration section for rejected registrations -->
        <div style="background:rgba(0,123,255,0.15);border:1px solid rgba(0,123,255,0.4);border-radius:12px;padding:20px;margin-bottom:15px;text-align:center;">
            <div style="font-size:14px;margin-bottom:10px;opacity:0.9;">
                💡 يمكنك تعديل بياناتك وإعادة التسجيل من جديد
            </div>
            <div style="font-size:13px;margin-bottom:15px;opacity:0.7;">
                قم بتعديل المعلومات المطلوبة وأرسل الطلب مرة أخرى للمراجعة
            </div>
            <?php 
                $regCode = $result['registration_code'] ?? '';
                $reRegUrl = 'index.php';
                if (!empty($regCode)) {
                    $reRegUrl .= '?code=' . urlencode($regCode);
                }
            ?>
            <a href="<?= htmlspecialchars($reRegUrl) ?>" 
               style="display:inline-block;padding:14px 35px;border:none;border-radius:12px;background:linear-gradient(135deg, #007bff, #0056b3);color:#fff;font-size:17px;font-weight:bold;text-decoration:none;font-family:inherit;transition:transform 0.2s, box-shadow 0.2s;box-shadow:0 4px 15px rgba(0,123,255,0.3);">
                ✏️ تعديل وإعادة التسجيل
            </a>
            <?php if (!empty($regCode)): ?>
            <div style="font-size:12px;margin-top:10px;opacity:0.6;">
                كود التسجيل: <strong><?= htmlspecialchars($regCode) ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">الاسم</span>
            <span class="info-value"><?= htmlspecialchars($result['full_name'] ?? $result['name'] ?? '') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">السيارة</span>
            <span class="info-value"><?= htmlspecialchars(($result['car_type'] ?? '') . ' ' . ($result['car_year'] ?? '')) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">اللوحة</span>
            <span class="info-value"><?= htmlspecialchars($result['plate_full'] ?? (($result['plate_letter'] ?? '') . ' ' . ($result['plate_number'] ?? ''))) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">نوع المشاركة</span>
            <span class="info-value"><?= htmlspecialchars($result['participation_type_label'] ?? $result['participation_type'] ?? '-') ?></span>
        </div>
        <?php 
            $assignedTime = $result['assigned_time'] ?? '';
            $assignedDate = $result['assigned_date'] ?? '';
            if ($status === 'approved' && !empty($assignedTime)): 
        ?>
        <div style="margin:15px 0;padding:15px;background:linear-gradient(135deg,rgba(255,193,7,0.2),rgba(255,152,0,0.2));border:2px solid rgba(255,193,7,0.5);border-radius:12px;text-align:center;">
            <div style="font-size:13px;opacity:0.8;margin-bottom:5px;"><i class="fa-solid fa-clock"></i> موعد دخولك المحدد</div>
            <div style="font-size:36px;font-weight:800;color:#ffc107;letter-spacing:2px;"><?= htmlspecialchars($assignedTime) ?></div>
            <?php if (!empty($assignedDate)): ?>
            <div style="font-size:13px;opacity:0.7;margin-top:5px;"><i class="fa-solid fa-calendar"></i> <?= htmlspecialchars($assignedDate) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span class="info-label">رقم الواصل</span>
            <span class="info-value">#<?= htmlspecialchars($result['wasel'] ?? '-') ?></span>
        </div>
        
        <?php if ($status === 'approved'): 
            $badgeToken = $result['badge_token'] ?? $result['badge_id'] ?? $result['registration_code'] ?? '';
            $qrUrl = '';
            if (!empty($badgeToken)) {
                $siteUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $qrUrl = $siteUrl . '/badge.php?token=' . urlencode($badgeToken);
            }
        ?>
        <!-- QR Code for approved participants -->
        <div class="qr-section">
            <h3>📲 كود الدخول الخاص بك</h3>
            <p style="font-size:13px;opacity:0.7;margin:10px 0;">احفظ هذا الكود وأظهره عند بوابة الدخول</p>
            <div id="qrcode" style="display:inline-block;background:#fff;padding:15px;border-radius:15px;"></div>
            <br>
            <button class="btn-download" onclick="downloadQR()">📥 تحميل QR</button>
        </div>
        
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var qrData = <?= json_encode($qrUrl ?: $badgeToken) ?>;
            if (qrData) {
                new QRCode(document.getElementById('qrcode'), {
                    text: qrData,
                    width: 250,
                    height: 250,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
            }
        });
        
        function downloadQR() {
            var img = document.querySelector('#qrcode img');
            if (!img) {
                var canvas = document.querySelector('#qrcode canvas');
                if (canvas) {
                    img = { src: canvas.toDataURL('image/png') };
                }
            }
            if (img) {
                var link = document.createElement('a');
                link.download = 'qr_<?= htmlspecialchars($result['wasel'] ?? 'code') ?>.png';
                link.href = img.src;
                link.click();
            }
        }
        </script>
        <?php endif; ?>
    </div>
    
    <a href="check_status.php" class="btn-back">← بحث جديد</a>

    <?php endif; ?>
</div>

</body>
</html>
