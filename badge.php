<?php
/**
 * Member Profile / Badge Page
 * - Displays Member Stats, Violations, and Entry QR
 * - High Availability: Uses SQLite with JSON Cache Fallback
 * - Version: FIXED_2026_02_01
 */

// Suppress errors to prevent breaking the page
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Check if required files exist before including
$dbFileExists = file_exists(__DIR__ . '/include/db.php');
$helpersFileExists = file_exists(__DIR__ . '/include/helpers.php');
$memberServiceExists = file_exists(__DIR__ . '/services/MemberService.php');
$badgeCacheServiceExists = file_exists(__DIR__ . '/services/BadgeCacheService.php');

// Only include if files exist
if ($dbFileExists) {
    require_once __DIR__ . '/include/db.php';
}
if ($helpersFileExists) {
    require_once __DIR__ . '/include/helpers.php';
}
if ($memberServiceExists) {
    require_once __DIR__ . '/services/MemberService.php';
}
if ($badgeCacheServiceExists) {
    require_once __DIR__ . '/services/BadgeCacheService.php';
}

session_start();

// Check Admin Access
$isAdmin = false;
if (isset($_SESSION['user'])) {
    $u = $_SESSION['user'];
    $role = is_object($u) ? ($u->role ?? '') : ($u['role'] ?? '');
    $username = is_object($u) ? ($u->username ?? '') : ($u['username'] ?? '');
    
    // Check various admin flags supported by the system
    if ($role === 'admin' || $role === 'root' || $username === 'root' || $role === 'approver' || $role === 'notes') {
        $isAdmin = true;
    }
}

// 1. Get Settings - with error handling
$settings = [
    'badges_enabled' => true,
    'qr_only_mode' => false,
    'show_violations_list' => true
];

// Try DB first, fallback to JSON
try {
    if ($dbFileExists && function_exists('db')) {
        $pdo = db();
        $stmt = $pdo->query("SELECT key, value FROM system_settings");
        $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (isset($dbSettings['badge_enabled'])) {
            $settings['badges_enabled'] = ($dbSettings['badge_enabled'] === 'true' || $dbSettings['badge_enabled'] === '1');
        }
        if (isset($dbSettings['qr_only_mode'])) {
            $settings['qr_only_mode'] = ($dbSettings['qr_only_mode'] === 'true' || $dbSettings['qr_only_mode'] === '1');
        }
        if (isset($dbSettings['show_violations_list'])) {
            $settings['show_violations_list'] = ($dbSettings['show_violations_list'] === 'true' || $dbSettings['show_violations_list'] === '1');
        }
    }
} catch (Exception $e) {
    // Fallback to JSON if DB fails
    $jsonSettingsFile = __DIR__ . '/admin/data/site_settings.json';
    if (file_exists($jsonSettingsFile)) {
        $jsonSettings = json_decode(file_get_contents($jsonSettingsFile), true);
        if (isset($jsonSettings['badges_enabled'])) {
            $settings['badges_enabled'] = ($jsonSettings['badges_enabled'] === true || $jsonSettings['badges_enabled'] === 'true' || $jsonSettings['badges_enabled'] === 1);
        }
    }
}

// Global Kill Switch
if (($settings['badges_enabled'] ?? true) === false && !$isAdmin) {
    http_response_code(410);
    die('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>انتهت الصلاحية</title><style>body{font-family:sans-serif;background:#1a1a2e;color:white;text-align:center;padding:50px}</style></head><body><h1>🚫 الخدمة متوقفة مؤقتاً</h1></body></html>');
}

$token = $_GET['token'] ?? $_GET['badge_id'] ?? '';
$token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);

if (empty($token)) {
    http_response_code(404);
    die('Badge ID Required');
}

// 2. Fetch Profile Data - DIRECT JSON APPROACH FIRST for reliability
$profile = null;
$source = 'json';

// 2. Fetch Profile Data
$profile = null;
$source = 'json';

// ==== PRIORITY 1: Fetch Profile Data ====
// This ensures we get the latest Notes, Warnings, and Stats from DB
if ($memberServiceExists && class_exists('MemberService')) {
    try {
        $profile = MemberService::getProfile($token);
        if ($profile) {
            $source = 'MemberService';
        }
    } catch (Exception $e) {
        // Database error - continue to fallbacks
        error_log("Badge Lookup Failed: " . $e->getMessage());
    }
}

// ==== Enrich with Rounds/Notes from JSON logs ====
if ($profile) {
    $searchIds = [
        $token,
        $profile['member']['permanent_code'] ?? '',
        $profile['current_registration']['wasel'] ?? '',
        $profile['raw_json']['badge_token'] ?? '',
        $profile['raw_json']['badge_id'] ?? ''
    ];
    $searchIds = array_unique(array_filter($searchIds));
    
    // Get rounds from JSON log
    $roundLogsFile = __DIR__ . '/admin/data/round_logs.json';
    $totalRoundsEntered = 0;
    
    if (file_exists($roundLogsFile)) {
        $roundLogs = json_decode(file_get_contents($roundLogsFile), true) ?? [];
        foreach ($roundLogs as $log) {
            $pid = (string)($log['participant_id'] ?? $log['badge_id'] ?? '');
            if (!empty($pid) && in_array($pid, $searchIds) && ($log['action'] ?? '') === 'enter') {
                $totalRoundsEntered++;
            }
        }
    }
    $profile['rounds_entered'] = max($profile['rounds_entered'] ?? 0, $totalRoundsEntered);
}

// 5. Validation
if (!$profile) {
    http_response_code(404);
    die('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>غير موجود</title><style>body{text-align:center;padding:50px;font-family:sans-serif;background:#f8f9fa}</style></head><body><h1>⚠️ البادج غير موجود</h1><p style="color:gray;font-size:12px">Token: ' . htmlspecialchars($token) . '</p></body></html>');
}

// 5. Registration Check
$currentReg = $profile['current_registration'] ?? null;

// Admin Fallback: If no current registration (e.g. New Championship), use latest history
if (!$currentReg && $isAdmin && !empty($profile['registrations'])) {
    $currentReg = $profile['registrations'][0];
    // Optional: Add visual indicator for admin?
}

if ((!$currentReg || ($currentReg['status'] ?? '') !== 'approved') && !$isAdmin) {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>غير مصرح</title><style>body{text-align:center;padding:50px;font-family:sans-serif;background:#fff5f5;color:#c0392b}</style></head>
    <body>
        <h1>🚫 التسجيل غير مقبول</h1>
        <p>عذراً، هذا البادج غير فعال في البطولة الحالية.</p>
        <p>حالة التسجيل: <strong>' . htmlspecialchars($currentReg['status'] ?? 'غير مسجل') . '</strong></p>
    </body></html>');
}

$member = $profile['member'];
$reg = $currentReg ?: []; // Ensure $reg is always an array

// PATCH: Ensure Governorate exists (Fallback to Plate Governorate)
if (empty($member['governorate']) || $member['governorate'] === '-') {
    $plateGov = $reg['plate_governorate'] ?? '';
    // Extract logical governorate check
    if (!empty($plateGov)) {
        $member['governorate'] = $plateGov;
    }
}
// PATCH: Ensure Car Year exists
if (empty($reg['car_year'])) {
    $reg['car_year'] = '---';
}

// MATCHING LOGIC: Combine Warnings + Notes(Warning/Blocker)
$displayViolations = $profile['warnings'] ?? [];

// 4. Privacy & View Logic
$qrOnlyMode = ($settings['qr_only_mode'] ?? false);

// DEBUG INFO
$debugInfo = [
    'token' => $token,
    'source' => $source ?? 'unknown',
    'rounds_entered' => $profile['rounds_entered'] ?? 0,
    'notes_count' => count($profile['notes'] ?? []),
    'warnings_count' => count($profile['warnings'] ?? []),
    'display_violations' => count($displayViolations)
];
?>
<?php
// Load Frame Settings for Championship Name
$frameSettingsFile = __DIR__ . '/admin/data/frame_settings.json';
$championshipName = '';
if (file_exists($frameSettingsFile)) {
    $frameSettings = json_decode(file_get_contents($frameSettingsFile), true);
    $championshipName = $frameSettings['form_titles']['sub_title'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بطاقة العضوية | <?= htmlspecialchars($member['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a1a2e;
            --secondary: #16213e;
            --accent: #ffc107;
            --danger: #dc3545;
            --success: #28a745;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            min-height: 100vh;
            color: #333;
            display: flex;
            justify-content: center;
            padding: 20px 10px;
        }
        
        .profile-card {
            background: #fff;
            width: 100%;
            max-width: 400px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0,0,0,0.3);
            position: relative;
        }
        
        /* Valid/Active Indicator */
        .status-bar {
            height: 6px;
            background: var(--success);
            width: 100%;
        }
        .status-bar.warning { background: var(--danger); }
        
        .header {
            text-align: center;
            padding: 25px 20px 10px;
        }
        
        .avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #f0f0f0;
            margin: 0 auto 15px;
            border: 3px solid var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            overflow: hidden;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        
        .name { font-size: 22px; font-weight: 800; margin-bottom: 5px; color: var(--primary); }
        .code { font-family: monospace; color: #666; font-size: 14px; background: #f8f9fa; padding: 4px 10px; border-radius: 10px; display: inline-block; }
        
        /* Stats Grid */
        .stats-grid {
            display: flex;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
            margin-top: 20px;
        }
        .stat-item {
            flex: 1;
            text-align: center;
            padding: 15px 5px;
            border-left: 1px solid #eee;
        }
        .stat-item:last-child { border-left: none; }
        .stat-val { font-size: 18px; font-weight: 700; color: var(--primary); }
        .stat-label { font-size: 11px; color: #888; margin-top: 2px; }
        
        /* Warning Banner */
        .violation-banner {
            background: #fff5f5;
            border: 1px solid #feb2b2;
            color: #c53030;
            margin: 15px;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
        }
        .violation-banner h4 { display: flex; align-items: center; gap: 5px; margin-bottom: 5px; }
        .violation-date { font-size: 11px; opacity: 0.8; display: block; margin-top: 3px; }

        /* QR Section */
        .qr-box {
            background: #f8f9fa;
            margin: 15px;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            border: 2px dashed #e9ecef;
        }
        .qr-img { width: 160px; height: 160px; mix-blend-mode: multiply; }
        
        /* Privacy Overlay */
        .limited-mode {
            text-align: center;
            padding: 30px;
            color: #666;
            font-size: 14px;
        }

        .footer {
            text-align: center;
            padding: 15px;
            font-size: 11px;
            color: #aaa;
            background: #fdfdfd;
            border-top: 1px solid #f0f0f0;
        }
        
        /* Car Info */
        .car-info {
            background: #f1f8ff;
            color: #094067;
            padding: 10px;
            margin: 10px 15px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
    </style>
</head>
<body>

    <div class="profile-card">
        <!-- Validity Indicator -->
        <div class="status-bar <?= !empty($displayViolations) ? 'warning' : '' ?>"></div>
        
        <div class="header">
            <div class="avatar">
                <?php 
                // MemberService does heavy lifting to extract photos into ['images']['personal_photo']
                $photoPathRaw = $member['images']['personal_photo'] ?? $reg['images']['personal_photo'] ?? $member['personal_photo'] ?? $reg['personal_photo'] ?? '';
                $finalSrc = '';
                $debugPaths = [$photoPathRaw];
                
                if (!empty($photoPathRaw)) {
                    $clean = ltrim(str_replace('../', '', $photoPathRaw), '/');
                    $debugPaths[] = $clean;
                    // First check relative to current script
                    if (file_exists($clean) && !is_dir($clean)) {
                        $finalSrc = $clean;
                    } 
                    // Then check inside admin folder
                    elseif (file_exists('admin/' . $clean) && !is_dir('admin/' . $clean)) {
                        $finalSrc = 'admin/' . $clean;
                    }
                }
                
                // If no photo found at all, $finalSrc remains empty
                
                $debugData = [
                    'm_photo' => $member['personal_photo'] ?? '',
                    'r_photo' => $reg['personal_photo'] ?? '',
                    'tried' => $debugPaths
                ];
                ?>
                
                <?php if (!empty($finalSrc)): ?>
                    <img src="<?= htmlspecialchars($finalSrc) ?>" data-debug='<?= htmlspecialchars(json_encode($debugData), ENT_QUOTES, 'UTF-8') ?>' onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <i class="fa-solid fa-user" style="display:none;font-size:50px;color:#ccc;line-height:100px;align-items:center;justify-content:center;width:100%;height:100%"></i>
                <?php else: ?>
                    <i class="fa-solid fa-user" style="display:flex;font-size:50px;color:#ccc;line-height:100px;align-items:center;justify-content:center;width:100%;height:100%"></i>
                <?php endif; ?>
            </div>
            
            <script>console.log("DEBUG_PHOTO:", <?= json_encode($debugData) ?>);</script>
            <div id="debug-photo-dump" style="display:none;"><?= htmlspecialchars(json_encode($debugData)) ?></div>
            
            <h1 class="name"><?= htmlspecialchars($member['name']) ?></h1>
            
            <!-- FIXED: Show Registration Code instead of Hash -->
            <?php if (!empty($reg['registration_code'])): ?>
                <div class="code" style="background:#e8f4fd; color:#0d6efd; border:1px solid #b6d4fe">
                    NO: <?= htmlspecialchars($reg['registration_code']) ?>
                </div>
            <?php endif; ?>

            <!-- Member Type Badge -->
            <?php if (!empty($reg['register_type_label'])): ?>
                <span style="font-size:12px; background:#eee; padding:4px 8px; border-radius:10px; margin-right:5px">
                    <?= htmlspecialchars($reg['register_type_label']) ?>
                </span>
            <?php endif; ?>

            <?php if (!empty($championshipName)): ?>
                <div style="color:#666;font-size:12px;margin-top:10px;font-weight:600">🏆 <?= htmlspecialchars($championshipName) ?></div>
            <?php endif; ?>
            
            <?php if (!empty($reg)): ?>
            <div class="car-info">
                <i class="fa-solid fa-car"></i>
                <?= htmlspecialchars($reg['car_type'] ?? '') ?> | <?= htmlspecialchars(($reg['plate_governorate'] ?? '') . ' ' . ($reg['plate_letter'] ?? '') . ' ' . ($reg['plate_number'] ?? '')) ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($qrOnlyMode): ?>
            <!-- Limited View Mode -->
            <div class="qr-box">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode("https://{$_SERVER['HTTP_HOST']}/verify_entry.php?token={$token}&action=checkin") ?>" class="qr-img">
                <p style="margin-top:10px;color:var(--success);font-weight:bold">✅ جاهز للمسح</p>
            </div>
            <div class="limited-mode">
                <i class="fa-solid fa-shield-halved"></i><br>
                وضع العرض المحدود<br>
                <small>تم إخفاء التفاصيل الشخصية للخصوصية</small>
            </div>
        <?php else: ?>
            <!-- Full Profile View -->
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-val" style="font-size:14px">
                        <?php
                            $ptLabel = $reg['participation_type_label'] ?? '';
                            $ptRaw = $reg['participation_type'] ?? '';
                            
                            // Map of raw keys to Arabic labels
                            $ptMap = [
                                'race' => 'متسابق',
                                'racer' => 'متسابق',
                                'organizer' => 'منظم',
                                'organization' => 'منظم',
                                'media' => 'إعلامي',
                                'special' => 'سيارة مميزة',
                                'special_car' => 'سيارة مميزة',
                                'vip' => 'VIP',
                                'sponsor' => 'راعي',
                                'visitor' => 'زائر',
                                // Shorten long labels
                                'المشاركة كسيارة مميزة فقط بدون استعراض' => 'سيارة مميزة',
                                'مشاركة كسيارة مميزة فقط بدون استعراض' => 'سيارة مميزة',
                                'استعراض حر (Free Drift)' => 'استعراض حر',
                                'سباق السرعة (Drag Race)' => 'سباق سرعة'
                            ];

                            // Try to map raw key
                            if (isset($ptMap[$ptRaw])) {
                                $ptLabel = $ptMap[$ptRaw];
                            }
                            // Try to map existing label if it's too long
                            elseif (isset($ptMap[$ptLabel])) {
                                $ptLabel = $ptMap[$ptLabel];
                            }
                            
                            echo htmlspecialchars(!empty($ptLabel) ? $ptLabel : ($ptRaw ?: '-'));
                        ?>
                    </div>
                    <div class="stat-label">نوع المشاركة</div>
                </div>
                <div class="stat-item">
                    <div class="stat-val" style="font-size:14px"><?= htmlspecialchars($reg['car_year'] ?? '-') ?></div>
                    <div class="stat-label">الموديل</div>
                </div>
                <div class="stat-item">
                    <div class="stat-val" style="font-size:14px"><?= htmlspecialchars($reg['car_color'] ?? '-') ?></div>
                    <div class="stat-label">اللون</div>
                </div>
            </div>
            
            <div class="stats-grid" style="border-top:none; margin-top:-1px">
                 <div class="stat-item">
                    <div class="stat-val" style="font-size:14px"><?= htmlspecialchars($member['governorate'] ?? '-') ?></div>
                    <div class="stat-label">المحافظة</div>
                </div>
                <div class="stat-item">
                    <div class="stat-val" style="font-size:14px; direction:ltr"><?= htmlspecialchars($member['phone'] ?? '-') ?></div>
                    <div class="stat-label">الهاتف</div>
                </div>
                <!-- Combined Engine/Status if needed, or just Engine -->
                <div class="stat-item">
                    <div class="stat-val" style="font-size:14px">
                        <?php
                            $engLabel = $reg['engine_size_label'] ?? '';
                            $engRaw = $reg['engine_size'] ?? '';
                            echo htmlspecialchars(!empty($engLabel) ? $engLabel : ($engRaw ?: '-'));
                        ?>
                    </div>
                    <div class="stat-label">المحرك</div>
                </div>
            </div>

            <!-- RESTORED STATS (Rounds & Violations) -->
            <div style="margin-top:20px; border-top:1px solid #eee; padding-top:10px">
                <h6 style="text-align:center; color:#999;font-size:12px;margin-bottom:10px">الإحصائيات</h6>
                <div class="stats-grid" style="border:none">
                    <div class="stat-item">
                        <div class="stat-val"><?= number_format($profile['rounds_entered'] ?? 0) ?></div>
                        <div class="stat-label">عدد النزلات</div>
                    </div>
                     <div class="stat-item">
                        <div class="stat-val" style="color:<?= count($displayViolations) > 0 ? '#dc3545' : '#28a745' ?>">
                            <?= count($displayViolations) ?>
                        </div>
                        <div class="stat-label">مخالفات (ملاحظات)</div>
                    </div>
                </div>
            </div>

            <!-- Active Violations -->
            <?php if (!empty($displayViolations) && ($settings['show_violations_list'] ?? true)): ?>
                <?php foreach($displayViolations as $w): ?>
                <div class="violation-banner">
                    <h4><i class="fa-solid fa-triangle-exclamation"></i> تنبيه نشط</h4>
                    <p><?= htmlspecialchars($w['warning_text'] ?? $w['text'] ?? '') ?></p>
                    <?php if(!empty($w['created_by_name'])): ?>
                        <div style="font-size:10px;opacity:0.7;margin-top:2px">بواسطة: <?= htmlspecialchars($w['created_by_name']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Assigned Time Slot -->
            <?php 
            // Get assigned time from raw JSON data
            $assignedTime = $reg['assigned_time'] ?? ($profile['raw_json']['assigned_time'] ?? '');
            $assignedDate = $reg['assigned_date'] ?? ($profile['raw_json']['assigned_date'] ?? '');
            $assignedOrder = $reg['assigned_order'] ?? ($profile['raw_json']['assigned_order'] ?? 0);
            if (!empty($assignedTime)): 
            ?>
            <div style="margin: 15px; padding: 15px; background: linear-gradient(135deg, #1a1a2e, #16213e); border-radius: 12px; text-align: center; color: white;">
                <div style="font-size: 12px; opacity: 0.8; margin-bottom: 5px;">
                    <i class="fa-solid fa-clock"></i> موعد دخولك
                </div>
                <div style="font-size: 32px; font-weight: 800; color: #ffc107; letter-spacing: 2px;">
                    <?= htmlspecialchars($assignedTime) ?>
                </div>
                <?php if (!empty($assignedDate)): ?>
                <div style="font-size: 13px; color: #aaa; margin-top: 5px;">
                    <i class="fa-solid fa-calendar"></i> <?= htmlspecialchars($assignedDate) ?>
                </div>
                <?php endif; ?>
                <?php if ($assignedOrder > 0): ?>
                <div style="font-size: 11px; color: #ffc107; margin-top: 5px;">
                    ترتيبك: #<?= intval($assignedOrder) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- QR Code -->
            <div class="qr-box">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode("https://{$_SERVER['HTTP_HOST']}/verify_entry.php?token={$token}&action=checkin") ?>" class="qr-img">
                <div style="font-size:12px;color:#999;margin-top:10px">امسح الكود عند الدخول</div>
            </div>
        <?php endif; ?>

        <div class="footer">
            نادي بلاد الرافدين للسيارات &copy; <?= date('Y') ?>
        </div>
    </div>

</body>
</html>
