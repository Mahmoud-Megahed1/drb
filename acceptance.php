<?php
// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
/**
 * Acceptance Page - صفحة القبول
 * - لو الشخص عنده edited_image → يعرضها مباشرة
 * - لو عنده saved_frame_settings → يستخدمها (إعدادات وقت القبول)
 * - غير كده → يستخدم الإعدادات العامة الحالية
 */

session_start();
$isAdmin = isset($_SESSION['user']) && !empty($_SESSION['user']);

$token = $_GET['token'] ?? '';
$wasel = $_GET['id'] ?? $_GET['wasel'] ?? '';

// Include dependencies
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/services/MemberService.php';

// Fetch profile using the unified service
$profile = MemberService::getProfile($token ?: $wasel);
$registration = $profile['current_registration'] ?? null;

// If not found
if (!$registration) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>غير موجود</title><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet"><style>body{font-family:"Cairo",sans-serif;background:#1a1a2e;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#fff;text-align:center;padding:20px}.c{max-width:400px}.i{font-size:80px;margin-bottom:20px}h1{font-size:24px;margin-bottom:10px}p{opacity:.7}</style></head><body><div class="c"><div class="i">🔍</div><h1>غير موجود</h1><p>هذا التسجيل غير موجود</p></div></body></html>';
    exit;
}

// If not approved yet, only allow admins to view
if (($registration['status'] ?? '') !== 'approved' && !$isAdmin) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>قيد المراجعة</title><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet"><style>body{font-family:"Cairo",sans-serif;background:#1a1a2e;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#fff;text-align:center;padding:20px}.c{max-width:400px}.i{font-size:80px;margin-bottom:20px}h1{font-size:24px;margin-bottom:10px}p{opacity:.7}</style></head><body><div class="c"><div class="i">⏳</div><h1>لم تتم الموافقة بعد</h1><p>طلبك قيد المراجعة</p><p>رقم التسجيل: #' . htmlspecialchars($registration['wasel'] ?? '') . '</p></div></body></html>';
    exit;
}

// ============ BUILD OG IMAGE URL FOR WHATSAPP PREVIEW ============
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

// Always use generate_acceptance.php for the real rendered frame!
$ogImage = $protocol . $host . $basePath . '/generate_acceptance.php?token=' . urlencode($token ?: $wasel);

// If user has edited_image from Visual Editor, show it directly in HTML
$editedImage = null;
$showEditedImage = false;

if (!empty($registration['edited_image'])) {
    $ePath = ltrim(str_replace('\\', '/', $registration['edited_image']), '/');
    $editedPaths = [
        __DIR__ . '/' . $ePath,
        __DIR__ . '/admin/' . $ePath,
        __DIR__ . '/admin/images/settings/' . basename($ePath)
    ];
    
    foreach ($editedPaths as $fullPath) {
        if (file_exists($fullPath) && !is_dir($fullPath)) {
            // Found it! Now construct the web-accessible path
            if (strpos($fullPath, __DIR__) === 0) {
                $editedImage = ltrim(str_replace('\\', '/', substr($fullPath, strlen(__DIR__))), '/');
            } else {
                $editedImage = $ePath; // Fallback
            }
            $showEditedImage = true;
            $ogImage = $protocol . $host . $basePath . '/' . $editedImage;
            break;
        }
    }
}



// ============ LOAD FRAME SETTINGS ============
// Priority: 
// 1. Person's saved settings (snapshot from approval time)
// 2. DEFAULT settings (for old registrations without saved settings - NOT affected by new global changes)
$frameSettings = [];
$frameImage = 'images/acceptance_frame.png';

// Check if person has saved frame settings (snapshot from approval time)
if (!empty($registration['saved_frame_settings'])) {
    $frameSettings = $registration['saved_frame_settings'];
    if (!empty($frameSettings['frame_image'])) {
        $frameImage = $frameSettings['frame_image'];
    }
} else {
    // OLD REGISTRATIONS: Try to load CURRENT GLOBAL settings instead of hardcoded defaults
    // This provides a better fallback than fixed coordinates if the frame has changed.
    $globalSettingsFile = __DIR__ . '/admin/data/frame_settings.json';
    if (file_exists($globalSettingsFile)) {
        $frameSettings = json_decode(file_get_contents($globalSettingsFile), true) ?? [];
        if (!empty($frameSettings['frame_image'])) {
            $frameImage = $frameSettings['frame_image'];
        }
    }
    
    // If global settings also fail, use hardcoded baseline
    if (empty($frameSettings)) {
        $frameSettings = [
            'elements' => [
                'personal_photo' => ['enabled' => true, 'x' => 50, 'y' => 60, 'width' => 35, 'shape' => 'circle', 'border_color' => '#FFD700', 'border_width' => 4],
                'participant_name' => ['enabled' => true, 'x' => 50, 'y' => 70, 'font_size' => 22, 'color' => '#FFD700'],
                'registration_id' => ['enabled' => true, 'x' => 50, 'y' => 88, 'font_size' => 32, 'color' => '#FFD700'],
                'plate_number' => ['enabled' => true, 'x' => 50, 'y' => 90, 'font_size' => 18, 'color' => '#FFFFFF'],
                'governorate' => ['enabled' => true, 'x' => 50, 'y' => 95, 'font_size' => 18, 'color' => '#FFD700'],
                'car_type' => ['enabled' => false],
                'car_image' => ['enabled' => false]
            ]
        ];
    }
}

// Fix frame image path
if (!file_exists($frameImage) && file_exists('admin/' . $frameImage)) {
    $frameImage = 'admin/' . $frameImage;
}

// Get elements positions
$elements = $frameSettings['elements'] ?? [];
$personalPhoto = $elements['personal_photo'] ?? ['enabled' => true, 'x' => 50, 'y' => 60, 'width' => 35, 'shape' => 'circle'];
$carImageSettings = $elements['car_image'] ?? ['enabled' => false];
$nameSettings = $elements['participant_name'] ?? ['enabled' => true, 'x' => 50, 'y' => 70, 'font_size' => 22, 'color' => '#FFD700'];
$regIdSettings = $elements['registration_id'] ?? ['enabled' => true, 'x' => 50, 'y' => 88, 'font_size' => 32, 'color' => '#FFD700'];
$plateSettings = $elements['plate_number'] ?? ['enabled' => true, 'x' => 50, 'y' => 90, 'font_size' => 18, 'color' => '#FFFFFF'];
$govSettings = $elements['governorate'] ?? ['enabled' => true, 'x' => 50, 'y' => 95, 'font_size' => 18, 'color' => '#FFD700'];
$carTypeSettings = $elements['car_type'] ?? ['enabled' => false];

// Determine which photo to show:
// - If car_image is enabled AND personal_photo is disabled → show car image
// - If car_image is enabled AND personal_photo is enabled → show both
// - If only personal_photo is enabled → show personal photo
$photoPath = '';
$carPhotoPath = '';

// Check if car_image is enabled and personal_photo is disabled
$showCarInstead = ($carImageSettings['enabled'] ?? false) && !($personalPhoto['enabled'] ?? true);

$personalPhotoCandidates = [
    $registration['images']['personal_photo'] ?? '',
    $profile['member']['images']['personal_photo'] ?? '',
    $registration['personal_photo'] ?? '',
    $profile['member']['personal_photo'] ?? '',
    'admin/' . ($registration['images']['personal_photo'] ?? ''),
    'admin/' . ($registration['personal_photo'] ?? '')
];
foreach ($personalPhotoCandidates as $candidate) {
    if (empty($candidate)) continue;
    $cleanCand = ltrim(str_replace(['\\', 'admin/admin/'], ['/', 'admin/'], $candidate), '/');
    
    // Test direct
    if (file_exists(__DIR__ . '/' . $cleanCand) && !is_dir(__DIR__ . '/' . $cleanCand)) {
        $photoPath = $cleanCand;
        break;
    }
    // Test with admin/ prefix if not present
    if (strpos($cleanCand, 'admin/') !== 0) {
        if (file_exists(__DIR__ . '/admin/' . $cleanCand) && !is_dir(__DIR__ . '/admin/' . $cleanCand)) {
            $photoPath = 'admin/' . $cleanCand;
            break;
        }
    }
}

// Fallback to default user image if profile picture is missing
if (empty($photoPath)) {
    // Injecting a base64 SVG directly to prevent 404s
    $photoPath = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iI2RkZCI+PHBhdGggZD0iTTEyIDEyYzIuMjEgMCA0LTEuNzkgNC00cy0xLjc5LTQtNC00LTQgMS43OS00IDQgMS43OSA0IDQgNHptMCAyYy0yLjY3IDAtOCAxLjM0LTggNHYyaDE2di0yYzAtMi42Ni01LjMzLTQtOC00eiIvPjwvc3ZnPg==';
}

// Get car photo path
$carPhotoCandidates = [
    $registration['front_image'] ?? '',
    $registration['images']['front_image'] ?? '',
    'admin/' . ($registration['front_image'] ?? ''),
    'admin/' . ($registration['images']['front_image'] ?? '')
];
foreach ($carPhotoCandidates as $candidate) {
    if (empty($candidate)) continue;
    $cleanCand = ltrim(str_replace('\\', '/', $candidate), '/');
    $fullPath = __DIR__ . '/' . $cleanCand;
    if (file_exists($fullPath) && !is_dir($fullPath)) {
        $carPhotoPath = $cleanCand;
        break;
    }
}

// If showing car instead of personal photo
if ($showCarInstead && !empty($carPhotoPath)) {
    $photoPath = $carPhotoPath;
    // Use car_image settings for position, but keep the shape from settings
    $personalPhoto = array_merge($personalPhoto, [
        'x' => $carImageSettings['x'] ?? 50,
        'y' => $carImageSettings['y'] ?? 50,
        'width' => $carImageSettings['width'] ?? 30,
        'height' => $carImageSettings['height'] ?? 20,
        'enabled' => true,
        'shape' => $carImageSettings['shape'] ?? $personalPhoto['shape'] ?? 'circle' // Use setting, not hardcoded
    ]);
}

// ============ SHOW EDITED IMAGE IF EXISTS ============
if ($showEditedImage):
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تم قبول التسجيل - #<?= htmlspecialchars($registration['wasel']) ?></title>
    
    <!-- Open Graph for WhatsApp Preview -->
    <meta property="og:title" content="✅ تم قبول التسجيل - #<?= htmlspecialchars($registration['wasel']) ?>">
    <meta property="og:description" content="👤 <?= htmlspecialchars($registration['full_name'] ?? '') ?> | 🚗 <?= htmlspecialchars($registration['car_type'] ?? '') ?>">
    <?php if (!empty($ogImage)): ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 10px;
        }
        .image-container {
            width: 100%;
            max-width: 500px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .image-container img {
            width: 100%;
            height: auto;
            display: block;
        }
        .success-footer {
            text-align: center;
            padding: 20px;
            color: #28a745;
            font-size: 16px;
            margin-top: 15px;
            background: rgba(40, 167, 69, 0.1);
            border-radius: 10px;
            width: 100%;
            max-width: 500px;
        }
        .success-footer .icon { font-size: 40px; display: block; margin-bottom: 10px; }
        .action-btn {
            display: inline-block;
            margin: 5px;
            padding: 10px 20px;
            color: white;
            border: none;
            border-radius: 25px;
            font-family: 'Cairo', sans-serif;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .btn-print { background: #28a745; }
        .btn-whatsapp { background: #25D366; }
        .btn-facebook { background: #1877F2; }
        .btn-download { background: #6c757d; }
        .btn-share { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .share-section {
            margin-top: 15px;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }
        .share-section h4 { color: #FFD700; margin-bottom: 10px; font-size: 14px; }
        .social-note { color: #aaa; font-size: 12px; margin-top: 10px; }
        @media print { .success-footer { display: none; } }
    </style>
</head>
<body>
    <div class="image-container">
        <img src="<?= htmlspecialchars($editedImage) ?>" alt="صورة القبول" id="acceptanceImage">
    </div>
    <div class="success-footer">
        <span class="icon">✅</span>
        <strong>تم قبول تسجيلك بنجاح!</strong>
        <br><small>يرجى حفظ هذه الصورة أو إبرازها عند الدخول</small>
        
        <div class="share-section">
            <h4>📱 شارك هذه الصورة على مواقع التواصل الاجتماعي</h4>
            <button onclick="downloadDirectScreenshot()" class="action-btn btn-success" style="display: inline-block; padding: 10px 30px; border-radius: 25px; border: none; font-family: 'Cairo', sans-serif; font-size: 16px; font-weight: bold; background: #28a745; color: white; cursor: pointer;">⬇️ حفظ الصورة على الجهاز</button>
            <p class="social-note">💡 يمكنك مشاركة صورة قبولك مع أصدقائك!</p>
        </div>

    </div>

    <script>
    function downloadDirectScreenshot() {
        var btn = document.querySelector('button[onclick="downloadDirectScreenshot()"]');
        var originalText = btn.innerHTML;
        btn.innerHTML = '⏳ جاري الحفظ...';
        btn.style.pointerEvents = 'none';
        
        var container = document.querySelector('.image-container');
        var shareSection = document.querySelector('.share-section');
        if (shareSection) shareSection.style.display = 'none';

        // Wait to ensure UI is updated
        setTimeout(function() {
            // Create a clone to render without viewport constraints
            var clone = container.cloneNode(true);
            var wrapper = document.createElement('div');
            wrapper.style.position = 'absolute';
            wrapper.style.top = '-9999px';
            wrapper.style.left = '-9999px';
            wrapper.appendChild(clone);
            document.body.appendChild(wrapper);

            html2canvas(clone, {
                useCORS: true,
                allowTaint: true,
                backgroundColor: null,
                scale: window.devicePixelRatio || 2,
                logging: false
            }).then(function(canvas) {
                document.body.removeChild(wrapper); // Cleanup
                
                var link = document.createElement('a');
                link.download = 'acceptance_<?= htmlspecialchars($registration['wasel'] ?? '') ?>.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
                
                btn.innerHTML = originalText;
                btn.style.pointerEvents = 'auto';
                if (shareSection) shareSection.style.display = 'block';
            }).catch(function(err) {
                document.body.removeChild(wrapper); // Cleanup
                console.error('Error capturing image:', err);
                alert('حدث خطأ أثناء الحفظ. جرب متصفحاً آخر أو اضغط ضغطة مطولة لحفظ الصورة.');
                btn.innerHTML = originalText;
                btn.style.pointerEvents = 'auto';
                if (shareSection) shareSection.style.display = 'block';
            });
        }, 150);
    }
    </script>
</body>
</html>
<?php
exit;
endif;
// ============ SHOW DYNAMIC FRAME ============
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تم قبول التسجيل - #<?= htmlspecialchars($registration['wasel']) ?></title>
    
    <!-- Open Graph for WhatsApp Preview -->
    <meta property="og:title" content="✅ تم قبول التسجيل - #<?= htmlspecialchars($registration['wasel']) ?>">
    <meta property="og:description" content="👤 <?= htmlspecialchars($registration['full_name'] ?? '') ?> | 🚗 <?= htmlspecialchars($registration['car_type'] ?? '') ?>">
    <?php if (!empty($ogImage)): ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 10px;
        }
        .frame-container {
            position: relative;
            width: 100%;
            max-width: 500px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .frame-image { width: 100%; height: auto; display: block; }
        .personal-photo {
            position: absolute;
            transform: translate(-50%, -50%);
            border: <?= $personalPhoto['border_width'] ?? 4 ?>px solid <?= $personalPhoto['border_color'] ?? '#FFD700' ?>;
            box-shadow: 0 4px 15px rgba(0,0,0,0.4);
            <?php if (($personalPhoto['shape'] ?? 'circle') === 'circle'): ?>
            border-radius: 50%;
            height: 0;
            padding-bottom: <?= $personalPhoto['width'] ?? 35 ?>%;
            <?php else: ?>
            border-radius: 10px;
            height: <?= $personalPhoto['height'] ?? 27 ?>%;
            <?php endif; ?>
            left: <?= $personalPhoto['x'] ?? 50 ?>%;
            top: <?= $personalPhoto['y'] ?? 60 ?>%;
            width: <?= $personalPhoto['width'] ?? 35 ?>%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-color: #eee;
        }
        .text-overlay {
            position: absolute;
            transform: translate(-50%, -50%);
            text-align: center;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.8), 0 0 10px rgba(0,0,0,0.5);
            white-space: nowrap;
        }
        .participant-name {
            left: <?= $nameSettings['x'] ?? 50 ?>%;
            top: <?= $nameSettings['y'] ?? 70 ?>%;
            font-size: clamp(14px, <?= ($nameSettings['font_size'] ?? 22) / 22 * 4 ?>vw, <?= $nameSettings['font_size'] ?? 22 ?>px);
            color: <?= $nameSettings['color'] ?? '#FFD700' ?>;
        }
        .registration-id {
            left: <?= $regIdSettings['x'] ?? 50 ?>%;
            top: <?= $regIdSettings['y'] ?? 88 ?>%;
            font-size: clamp(18px, <?= ($regIdSettings['font_size'] ?? 32) / 32 * 6 ?>vw, <?= $regIdSettings['font_size'] ?? 32 ?>px);
            color: <?= $regIdSettings['color'] ?? '#FFD700' ?>;
        }
        .plate-number {
            left: <?= $plateSettings['x'] ?? 50 ?>%;
            top: <?= $plateSettings['y'] ?? 90 ?>%;
            font-size: clamp(12px, <?= ($plateSettings['font_size'] ?? 18) / 18 * 3 ?>vw, <?= $plateSettings['font_size'] ?? 18 ?>px);
            color: <?= $plateSettings['color'] ?? '#FFFFFF' ?>;
        }
        .governorate {
            left: <?= $govSettings['x'] ?? 50 ?>%;
            top: <?= $govSettings['y'] ?? 95 ?>%;
            font-size: clamp(12px, <?= ($govSettings['font_size'] ?? 18) / 18 * 3 ?>vw, <?= $govSettings['font_size'] ?? 18 ?>px);
            color: <?= $govSettings['color'] ?? '#FFD700' ?>;
        }
        .car-type {
            left: <?= $carTypeSettings['x'] ?? 50 ?>%;
            top: <?= $carTypeSettings['y'] ?? 92 ?>%;
            font-size: clamp(12px, <?= ($carTypeSettings['font_size'] ?? 18) / 18 * 3 ?>vw, <?= $carTypeSettings['font_size'] ?? 18 ?>px);
            color: <?= $carTypeSettings['color'] ?? '#FFD700' ?>;
        }
        .success-footer {
            text-align: center;
            padding: 20px;
            color: #28a745;
            font-size: 16px;
            margin-top: 15px;
            background: rgba(40, 167, 69, 0.1);
            border-radius: 10px;
            width: 100%;
            max-width: 500px;
        }
        .success-footer .icon { font-size: 40px; display: block; margin-bottom: 10px; }
        .action-btn {
            display: inline-block;
            margin: 5px;
            padding: 10px 20px;
            color: white;
            border: none;
            border-radius: 25px;
            font-family: 'Cairo', sans-serif;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .btn-print { background: #28a745; }
        .btn-whatsapp { background: #25D366; }
        .btn-facebook { background: #1877F2; }
        .btn-download { background: #6c757d; }
        .btn-share { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .share-section {
            margin-top: 15px;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }
        .share-section h4 { color: #FFD700; margin-bottom: 10px; font-size: 14px; }
        .social-note { color: #aaa; font-size: 12px; margin-top: 10px; }
        @media print {
            body { background: white; padding: 0; }
            .success-footer { display: none; }
            .frame-container { box-shadow: none; max-width: 100%; }
        }
    </style>
</head>
<body>
    <div class="frame-container" id="frameContainer">
        <img src="<?= htmlspecialchars($frameImage) ?>" alt="Frame" class="frame-image">
        
        <?php if (($personalPhoto['enabled'] ?? true) && !empty($photoPath)): ?>
        <div class="personal-photo" style="background-image: url('<?= htmlspecialchars($photoPath) ?>');"></div>
        <?php endif; ?>
        
        <?php if ($nameSettings['enabled'] ?? true): ?>
        <div class="text-overlay participant-name"><?= htmlspecialchars($registration['full_name'] ?? $member['name'] ?? '') ?></div>
        <?php endif; ?>
        
        <?php if ($regIdSettings['enabled'] ?? true): ?>
        <div class="text-overlay registration-id">#<?= htmlspecialchars($registration['wasel'] ?? '') ?></div>
        <?php endif; ?>
        
        <?php if ($plateSettings['enabled'] ?? true): ?>
        <div class="text-overlay plate-number"><?= htmlspecialchars($registration['plate_full'] ?? '') ?></div>
        <?php endif; ?>

        <?php if ($govSettings['enabled'] ?? true): 
            $govFull = $registration['governorate'] ?? '';
            if (empty($govFull) || $govFull === '-') {
                $govFull = $registration['plate_governorate'] ?? '';
            }
        ?>
        <div class="text-overlay governorate"><?= htmlspecialchars($govFull) ?></div>
        <?php endif; ?>

        <?php if ($carTypeSettings['enabled'] ?? false): ?>
        <div class="text-overlay car-type"><?= htmlspecialchars(($registration['car_type'] ?? '') . ' ' . ($registration['car_year'] ?? '')) ?></div>
        <?php endif; ?>
    </div>
    
    <div class="success-footer">
        <span class="icon">✅</span>
        <strong>تم قبول تسجيلك بنجاح!</strong>
        <br><small>يرجى حفظ هذه الصورة أو إبرازها عند الدخول</small>
        
        <div class="share-section">
            <h4>📱 شارك هذه الصورة على مواقع التواصل الاجتماعي</h4>
            <button onclick="downloadScreenshot()" class="action-btn btn-success" style="display: inline-block; padding: 10px 30px; border-radius: 25px; border: none; font-family: 'Cairo', sans-serif; font-size: 16px; font-weight: bold; background: #28a745; color: white; cursor: pointer;">⬇️ حفظ الصورة على الجهاز</button>
            <p class="social-note">💡 يمكنك مشاركة صورة قبولك مع أصدقائك!</p>
        </div>

    </div>

    <script>
    function downloadScreenshot() {
        var btn = document.querySelector('button[onclick="downloadScreenshot()"]');
        var originalText = btn.innerHTML;
        btn.innerHTML = '⏳ جاري الحفظ...';
        btn.style.pointerEvents = 'none';
        
        var container = document.getElementById('frameContainer');
        var shareSection = document.querySelector('.share-section');
        if (shareSection) shareSection.style.display = 'none';

        // Wait to ensure UI is updated
        setTimeout(function() {
            // Create a clone to render without viewport constraints
            var clone = container.cloneNode(true);
            var wrapper = document.createElement('div');
            wrapper.style.position = 'absolute';
            wrapper.style.top = '-9999px';
            wrapper.style.left = '-9999px';
            wrapper.appendChild(clone);
            document.body.appendChild(wrapper);

            html2canvas(clone, {
                useCORS: true,
                allowTaint: true,
                backgroundColor: null,
                scale: window.devicePixelRatio || 2,
                logging: false
            }).then(function(canvas) {
                document.body.removeChild(wrapper); // Cleanup
                
                var link = document.createElement('a');
                link.download = 'acceptance_<?= htmlspecialchars($registration['wasel'] ?? '') ?>.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
                
                btn.innerHTML = originalText;
                btn.style.pointerEvents = 'auto';
                if (shareSection) shareSection.style.display = 'block';
            }).catch(function(err) {
                document.body.removeChild(wrapper); // Cleanup
                console.error('Error capturing image:', err);
                alert('حدث خطأ أثناء الحفظ. جرب متصفحاً آخر أو اضغط ضغطة مطولة لحفظ الصورة.');
                btn.innerHTML = originalText;
                btn.style.pointerEvents = 'auto';
                if (shareSection) shareSection.style.display = 'block';
            });
        }, 150);
    }
    </script>
</body>
</html>

