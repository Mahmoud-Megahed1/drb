<?php
/**
 * Badge Settings Admin Page
 * Control badge display and QR mode settings
 */

session_start();
require_once '../include/db.php';
require_once '../include/helpers.php';

// Auth check
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$currentUser = $_SESSION['user'];
$isRoot = ($currentUser->username ?? '') === 'root' || ($currentUser->role ?? '') === 'root';

if (!$isRoot) {
    header('Location: ../dashboard.php');
    exit;
}

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_settings') {
            // Save badge settings
            setSetting('badge_enabled', isset($_POST['badge_enabled']), $currentUser->id ?? null);
            setSetting('qr_only_mode', isset($_POST['qr_only_mode']), $currentUser->id ?? null);
            setSetting('badge_visible_to_staff', isset($_POST['badge_visible_to_staff']), $currentUser->id ?? null);
            setSetting('require_current_registration', isset($_POST['require_current_registration']), $currentUser->id ?? null);
            setSetting('show_violations_list', isset($_POST['show_violations_list']), $currentUser->id ?? null);
            
            $message = 'تم حفظ الإعدادات بنجاح';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$settings = getSettings([
    'badge_enabled',
    'qr_only_mode', 
    'badge_visible_to_staff',
    'require_current_registration',
    'show_violations_list'
]);

// Defaults
$badgeEnabled = $settings['badge_enabled'] ?? true;
$qrOnlyMode = $settings['qr_only_mode'] ?? false;
$badgeVisibleToStaff = $settings['badge_visible_to_staff'] ?? true;
$requireCurrentReg = $settings['require_current_registration'] ?? true;
$showViolationsList = $settings['show_violations_list'] ?? true;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚙️ إعدادات البادج</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
        }
        .header {
            background: rgba(0,0,0,0.3);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 20px; }
        .header a { color: #aaa; text-decoration: none; }
        
        .container { padding: 20px; max-width: 800px; margin: 0 auto; }
        
        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .message.success { background: rgba(40,167,69,0.2); border: 1px solid #28a745; }
        .message.error { background: rgba(220,53,69,0.2); border: 1px solid #dc3545; }
        
        .card {
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .setting-item:last-child { border-bottom: none; }
        
        .setting-info h4 { font-size: 16px; margin-bottom: 5px; }
        .setting-info p { font-size: 13px; opacity: 0.7; }
        
        /* Toggle Switch */
        .toggle {
            position: relative;
            width: 60px;
            height: 32px;
            cursor: pointer;
        }
        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle .slider {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #444;
            border-radius: 16px;
            transition: 0.3s;
        }
        .toggle .slider::before {
            content: '';
            position: absolute;
            width: 26px;
            height: 26px;
            background: white;
            border-radius: 50%;
            top: 3px;
            left: 3px;
            transition: 0.3s;
        }
        .toggle input:checked + .slider {
            background: #28a745;
        }
        .toggle input:checked + .slider::before {
            left: 31px;
        }
        
        .btn-save {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            font-family: inherit;
            margin-top: 10px;
        }
        .btn-save:hover {
            opacity: 0.9;
        }
        
        .info-box {
            background: rgba(23,162,184,0.2);
            border: 1px solid #17a2b8;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
        }
        .info-box h4 { margin-bottom: 10px; }
        .info-box ul { padding-right: 20px; }
        .info-box li { margin-bottom: 5px; font-size: 14px; }
        
        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
    </style>


</head>
<body>
    <?php include '../include/navbar-custom.php'; ?>
    <div class="header">
        <h1><i class="fa-solid fa-id-card"></i> إعدادات البادج</h1>
        
    </div>
    
    <div class="container">
        <?php if ($message): ?>
        <div class="message success"><i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="message error"><i class="fa-solid fa-times-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_settings">
            
            <div class="card">
                <h2><i class="fa-solid fa-paper-plane"></i> إعدادات إرسال البادج</h2>
                
                <div class="setting-item">
                    <div class="setting-info">
                        <h4>تفعيل إرسال البادج</h4>
                        <p>إرسال رابط البادج للمتسابقين عند القبول</p>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="badge_enabled" <?= $badgeEnabled ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div class="setting-item">
                    <div class="setting-info">
                        <h4>وضع QR فقط</h4>
                        <p>إرسال رابط QR فقط بدون صورة البادج الكاملة</p>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="qr_only_mode" <?= $qrOnlyMode ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
            
            <div class="card">
                <h2><i class="fa-solid fa-lock"></i> إعدادات العرض</h2>
                
                <div class="setting-item">
                    <div class="setting-info">
                        <h4>البادج مرئي للموظفين</h4>
                        <p>السماح لموظفي الكاميرات برؤية البادج/البروفايل حتى لو معطّل للمتسابقين</p>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="badge_visible_to_staff" <?= $badgeVisibleToStaff ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div class="setting-item">
                    <div class="setting-info">
                        <h4>يتطلب التسجيل بالبطولة الحالية</h4>
                        <p>QR يعمل فقط إذا كان العضو مسجّل بالبطولة الحالية</p>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="require_current_registration" <?= $requireCurrentReg ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <div class="setting-item">
                    <div class="setting-info">
                        <h4>إظهار تفاصيل المخالفات</h4>
                        <p>عرض قائمة التنبيهات في صفحة البادج العامة</p>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="show_violations_list" <?= $showViolationsList ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
            
            <button type="submit" class="btn-save"><i class="fa-solid fa-save"></i> حفظ الإعدادات</button>
        </form>
        
        <div class="info-box">
            <h4><i class="fa-solid fa-info-circle"></i> شرح الإعدادات:</h4>
            <ul>
                <li><strong>تفعيل البادج:</strong> لو مفعّل، المتسابق يستلم رابط البادج عند القبول</li>
                <li><strong>وضع QR فقط:</strong> لو مفعّل، يستلم رابط QR فقط بدون الصورة الكاملة</li>
                <li><strong>مرئي للموظفين:</strong> لو البادج معطّل للمتسابقين، الموظفين يقدرون يشوفون البروفايل</li>
                <li><strong>يتطلب التسجيل:</strong> QR ما يشتغل إلا للمسجّلين بالبطولة الحالية</li>
            </ul>
        </div>
    </div>
</body>
</html>





