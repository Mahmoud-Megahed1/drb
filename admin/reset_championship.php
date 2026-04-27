<?php
// Debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session
session_start();

// Auth Check
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

// Handle User Object/Array
$user_role = '';
if (is_object($_SESSION['user'])) {
    $user_role = $_SESSION['user']->username ?? '';
} elseif (is_array($_SESSION['user'])) {
    $user_role = $_SESSION['user']['username'] ?? '';
}

if ($user_role !== 'root') {
    die("Access Denied: You are not root.");
}

// Paths
$baseDir = __DIR__;
$archiveDir = $baseDir . '/data/archives/';
$settingsFile = $baseDir . '/data/site_settings.json';
$dataFile = $baseDir . '/data/data.json';

// Create directories
if (!file_exists($archiveDir)) {
    @mkdir($archiveDir, 0777, true);
}

$message = '';
$messageType = '';

// Database Connection
require_once dirname(__DIR__) . '/include/db.php';
$pdo = db();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'clear_all_system_logs' && isset($_POST['confirm_clear']) && $_POST['confirm_clear'] === 'yes') {
        try {
            // Delete DB records safely bypassing if tables don't exist
            try { $pdo->exec("DELETE FROM activity_logs"); } catch (Exception $e) {}
            try { $pdo->exec("DELETE FROM whatsapp_logs"); } catch (Exception $e) {}
            try { $pdo->exec("DELETE FROM round_logs"); } catch (Exception $e) {}
            try { $pdo->exec("DELETE FROM warnings"); } catch (Exception $e) {}
            try { $pdo->exec("DELETE FROM notes"); } catch (Exception $e) {}
            
            // Empty JSON Files
            $dataDir = __DIR__ . '/data';
            $emptyJson = json_encode([], JSON_PRETTY_PRINT);
            @file_put_contents($dataDir . '/admin_actions.json', $emptyJson);
            @file_put_contents($dataDir . '/whatsapp_log.json', $emptyJson);
            @file_put_contents($dataDir . '/whatsapp_failed_queue.json', $emptyJson);
            @file_put_contents($dataDir . '/message_logs.json', $emptyJson);
            @file_put_contents($dataDir . '/entry_logs.json', $emptyJson);
            @file_put_contents($dataDir . '/round_logs.json', $emptyJson);
            @file_put_contents($dataDir . '/notes_logs.json', $emptyJson);
            
            // Empty Archives Directory
            $archivesPath = $dataDir . '/archives/';
            if (is_dir($archivesPath)) {
                $files = glob($archivesPath . '*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            
            // Re-log the action itself so that at least we know it was cleared!
            require_once dirname(__DIR__) . '/include/AdminLogger.php';
            $logger = new AdminLogger();
            $logger->log('settings_change', $user_role, 'مسح شامل لجميع السجلات بالنظام من الإدارة', []);
            
            $message = "تم إجراء مسح شامل لجميع السجلات بنجاح!";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "حدث خطأ أثناء مسح السجلات: " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'delete_archive' && isset($_POST['filename'])) {
        $filename = basename($_POST['filename']);
        $filePath = $archiveDir . $filename;
        if (file_exists($filePath)) {
            if (unlink($filePath)) {
                $message = "تم حذف الأرشيف بنجاح!";
                $messageType = 'success';
            } else {
                $message = "فشل في حذف الأرشيف.";
                $messageType = 'error';
            }
        } else {
            $message = "الأرشيف غير موجود.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'start_new' && isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
        try {
            // Disable Foreign Keys during critical reset phase to prevent constraint violations
            $pdo->exec("PRAGMA foreign_keys = OFF");
            
            // 1. Snapshot Current Data (Include Pending and Approved)
            $stmt = $pdo->query("SELECT r.*, m.name as member_name, m.phone FROM registrations r JOIN members m ON r.member_id = m.id WHERE r.is_active = 1");
            $regData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $filename = 'championship_' . date('Y-m-d_H-i-s') . '.json';
            $archiveData = [
                'date' => date('Y-m-d H:i:s'),
                'count' => count($regData),
                'data' => $regData
            ];

            file_put_contents($archiveDir . $filename, json_encode($archiveData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // --- SYNC ALL MEMBERS FROM DATA.JSON TO SQLITE (Data & Image Preservation) ---
            // Even if they are pending, we must preserve their images and data into their permanent profile.
            $currentDataFile = __DIR__ . '/data/data.json';
            if (file_exists($currentDataFile)) {
                $cJson = json_decode(file_get_contents($currentDataFile), true) ?? [];
                require_once dirname(__DIR__) . '/services/MemberService.php';
                
                // BATCH: Read members.json ONCE before the loop
                $membersJsonPath = __DIR__ . '/data/members.json';
                $membersLockFile = __DIR__ . '/data/members.lock';
                $mjLockHandle = fopen($membersLockFile, 'w');
                if ($mjLockHandle) {
                    flock($mjLockHandle, LOCK_EX);
                }
                $mjTemp = file_exists($membersJsonPath) ? json_decode(file_get_contents($membersJsonPath), true) : [];
                if (!is_array($mjTemp)) $mjTemp = [];
                
                foreach ($cJson as $m) {
                    // Include EVERYTHING (approved, pending, rejected) so their images aren't lost
                    // REMOVED: if (!in_array(($m['status'] ?? ''), ['approved', 'pending'])) continue;
                    
                    $phone = $m['phone'] ?? '';
                    if (empty($phone)) continue;
                    
                    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                    if (strlen($cleanPhone) < 10) continue;
                    
                    $name = $m['full_name'] ?? $m['name'] ?? 'Unknown';
                    $gov = $m['governorate'] ?? '';
                    
                    // Creates member and generates permanent_code if not exists
                    $member = MemberService::getOrCreateMember($cleanPhone, $name, $gov);
                    
                    // FIX: DO NOT overwrite permanent_code!
                    // The permanent_code is a database-generated identity and must NEVER change.
                    $regCodeToUpdate = $member['permanent_code'];
                    
                    // Store all images in members.json so they aren't lost when data.json is cleared
                    if (!isset($mjTemp[$regCodeToUpdate])) {
                        $mjTemp[$regCodeToUpdate] = [];
                    }
                    if (!isset($mjTemp[$regCodeToUpdate]['images'])) {
                        $mjTemp[$regCodeToUpdate]['images'] = [];
                    }
                    
                    // Copy images from data.json to members.json's profile
                    $imageKeys = ['personal_photo', 'front_image', 'side_image', 'back_image', 'edited_image', 'acceptance_image', 'id_front', 'id_back', 'national_id_front', 'national_id_back', 'license_front', 'license_back'];
                    foreach ($imageKeys as $imgKey) {
                        $val = $m[$imgKey] ?? $m['images'][$imgKey] ?? null;
                        if (!empty($val)) {
                            $mjTemp[$regCodeToUpdate]['images'][$imgKey] = $val;
                            if ($imgKey === 'id_front' || $imgKey === 'id_back') {
                                $mjTemp[$regCodeToUpdate]['images']['national_'.$imgKey] = $val;
                            }
                        }
                    }
                    
                    // Also sync member info to members.json
                    $mjTemp[$regCodeToUpdate]['full_name'] = $name;
                    $mjTemp[$regCodeToUpdate]['phone'] = $cleanPhone;
                    $mjTemp[$regCodeToUpdate]['governorate'] = $gov;
                    $mjTemp[$regCodeToUpdate]['car_type'] = $m['car_type'] ?? '';
                    $mjTemp[$regCodeToUpdate]['car_year'] = $m['car_year'] ?? '';
                    $mjTemp[$regCodeToUpdate]['car_color'] = $m['car_color'] ?? '';
                    $mjTemp[$regCodeToUpdate]['engine_size'] = $m['engine_size'] ?? $m['engine'] ?? '';
                    $mjTemp[$regCodeToUpdate]['plate_number'] = $m['plate_number'] ?? '';
                    $mjTemp[$regCodeToUpdate]['plate_letter'] = $m['plate_letter'] ?? '';
                    $mjTemp[$regCodeToUpdate]['plate_governorate'] = $m['plate_governorate'] ?? '';
                    $mjTemp[$regCodeToUpdate]['participation_type'] = $m['participation_type'] ?? '';
                    
                    // Persist car profile and photos into member's permanent SQLite record
                    try {
                        $pdo->prepare("UPDATE members SET last_car_type=?, last_car_year=?, last_car_color=?, last_plate_number=?, last_plate_letter=?, last_plate_governorate=?, last_engine_size=?, last_participation_type=?, personal_photo=?, national_id_front=?, national_id_back=? WHERE id=?")
                            ->execute([
                                $m['car_type'] ?? '', $m['car_year'] ?? '', $m['car_color'] ?? '',
                                $m['plate_number'] ?? '', $m['plate_letter'] ?? '', $m['plate_governorate'] ?? '',
                                $m['engine_size'] ?? $m['engine'] ?? '',
                                $m['participation_type'] ?? '',
                                $m['personal_photo'] ?? $m['images']['personal_photo'] ?? '',
                                $m['images']['id_front'] ?? $m['images']['national_id_front'] ?? $m['id_front'] ?? '',
                                $m['images']['id_back'] ?? $m['images']['national_id_back'] ?? $m['id_back'] ?? '',
                                $member['id']
                            ]);
                    } catch(Exception $e) { /* columns may not exist yet */ }
                    
                    // Ensure they have a record in `registrations` table for historical stats
                    $champId = $settings['current_championship_id'] ?? date('Y') . '_default';
                    $stmtChk = $pdo->prepare("SELECT id FROM registrations WHERE member_id = ? AND championship_id = ?");
                    $stmtChk->execute([$member['id'], $champId]);
                    if (!$stmtChk->fetchColumn()) {
                        $pdo->prepare("
                            INSERT INTO registrations (
                                member_id, championship_id, wasel,
                                car_type, car_year, car_color,
                                plate_governorate, plate_letter, plate_number,
                                participation_type, engine_size, session_badge_token,
                                status, created_at, is_active,
                                personal_photo, front_image, side_image, back_image, acceptance_image,
                                championship_name
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, 1, ?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $member['id'], $champId, $m['wasel'] ?? 0,
                            $m['car_type'] ?? '', $m['car_year'] ?? '', $m['car_color'] ?? '',
                            $m['plate_governorate'] ?? '', $m['plate_letter'] ?? '', $m['plate_number'] ?? '',
                            $m['participation_type'] ?? '', $m['engine_size'] ?? $m['engine'] ?? '',
                            $m['badge_token'] ?? $m['session_badge_token'] ?? '',
                            $m['approved_date'] ?? date('Y-m-d H:i:s'),
                            $m['personal_photo'] ?? $m['images']['personal_photo'] ?? null,
                            $m['images']['front_image'] ?? null,
                            $m['images']['side_image'] ?? null,
                            $m['images']['back_image'] ?? null,
                            $m['acceptance_image'] ?? null,
                            $m['championship_name'] ?? 'البطولة الحالية'
                        ]);
                    }
                }
                
                // BATCH: Write members.json ONCE after the loop with file locking
                file_put_contents($membersJsonPath, json_encode($mjTemp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                if ($mjLockHandle) {
                    flock($mjLockHandle, LOCK_UN);
                    fclose($mjLockHandle);
                }
            }

            // 3. Increment Participation
            $memberStmt = $pdo->query("SELECT DISTINCT member_id FROM registrations");
            $memberIds = $memberStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($memberIds)) {
                // Self-healing: Ensure column exists
                try {
                    $pdo->exec("ALTER TABLE members ADD COLUMN championships_participated INTEGER DEFAULT 0");
                } catch (PDOException $e) {
                    // Ignore if column already exists (e.g., "duplicate column name")
                    if (strpos($e->getMessage(), 'duplicate column name') === false) {
                        error_log("Error adding championships_participated column: " . $e->getMessage());
                    }
                }
            }
            
            if (!empty($memberIds)) {
                try {
                    // Update permanent championships count before clearing current registrations
                    $ids = implode(',', $memberIds);
                    $pdo->exec("UPDATE members SET championships_participated = championships_participated + 1 WHERE id IN ($ids)");
                    
                    // PRESERVE LAST CAR INFO IN MEMBERS TABLE
                    // Ensure columns exist
                    $cols = [
                        'last_car_type' => 'TEXT',
                        'last_car_year' => 'TEXT',
                        'last_car_color' => 'TEXT',
                        'last_plate_number' => 'TEXT',
                        'last_plate_letter' => 'TEXT',
                        'last_plate_governorate' => 'TEXT'
                    ];
                    foreach ($cols as $col => $type) {
                        try { $pdo->exec("ALTER TABLE members ADD COLUMN $col $type"); } catch (Exception $e) {}
                    }
                    
                    // Sync latest registration data to permanent member profile
                    foreach ($memberIds as $mid) {
                        $latestReg = $pdo->prepare("SELECT * FROM registrations WHERE member_id = ? ORDER BY created_at DESC LIMIT 1");
                        $latestReg->execute([$mid]);
                        $r = $latestReg->fetch(PDO::FETCH_ASSOC);
                        if ($r) {
                            $pdo->prepare("
                                UPDATE members SET 
                                    last_car_type = ?, last_car_year = ?, last_car_color = ?,
                                    last_plate_number = ?, last_plate_letter = ?, last_plate_governorate = ?
                                WHERE id = ?
                            ")->execute([
                                $r['car_type'], $r['car_year'], $r['car_color'],
                                $r['plate_number'], $r['plate_letter'], $r['plate_governorate'],
                                $mid
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    error_log("Failed to preserve member stats during reset: " . $e->getMessage());
                }
            }

            // 4. Reset Tables
            try { $pdo->exec("DELETE FROM registrations"); } catch (Exception $e) {}
            try { $pdo->exec("DELETE FROM badge_tokens"); } catch (Exception $e) {}
            
            @file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT));
            @file_put_contents($baseDir . '/data/round_logs.json', json_encode([], JSON_PRETTY_PRINT));
            @file_put_contents($baseDir . '/data/entry_logs.json', json_encode([], JSON_PRETTY_PRINT));

            // 5. Update/Save Start Date
            $now = date('Y-m-d H:i:s');
            
            // File Settings
            $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
            $settings['championship_start_date'] = $now;
            file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // System Settings Table
            try {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES ('championship_start_date', ?, ?)");
                $stmt->execute([$now, $now]);
            } catch (Exception $e) { /* Ignore */ }

            // 6. Log
            try {
                require_once dirname(__DIR__) . '/include/AdminLogger.php';
                $logger = new AdminLogger();
                $logger->log('championship_reset', $user_role, 'بدء بطولة جديدة', ['archive' => $filename]);
            } catch (Exception $e) { /* Ignore */ }

            // Re-Enable Foreign Keys after the reset process
            $pdo->exec("PRAGMA foreign_keys = ON");
            
            $message = "تم بدء البطولة الجديدة بنجاح!";
            $messageType = 'success';

        } catch (Exception $e) {
            // Re-Enable Foreign Keys in case of error
            $pdo->exec("PRAGMA foreign_keys = ON");
            $message = "Error: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Stats
$total = 0;
if (file_exists($dataFile)) {
    $cJson = json_decode(file_get_contents($dataFile), true);
    if (is_array($cJson)) {
        $total = count($cJson);
    }
}

// Archives List (Optimized)
$archives = [];
if (file_exists($archiveDir)) {
    $files1 = glob($archiveDir . 'data_archive_*.json') ?: [];
    $files2 = glob($archiveDir . 'championship_*.json') ?: [];
    $files = array_merge($files1, $files2);
    
    if ($files) {
        foreach ($files as $file) {
            $champName = 'البطولة المؤرشفة';
            $count = 0;
            
            // Read basic info without decoding the whole file if possible, but decoding is safer
            $content = json_decode(file_get_contents($file), true);
            if ($content) {
                if (isset($content['data']) && is_array($content['data']) && count($content['data']) > 0) {
                    $champName = $content['data'][0]['championship_name'] ?? $champName;
                    $count = $content['count'] ?? count($content['data']);
                } else if (is_array($content) && !isset($content['data'])) {
                    $count = count($content);
                    if ($count > 0 && isset($content[0]['championship_name'])) {
                        $champName = $content[0]['championship_name'];
                    }
                }
            }
            
            // Extract date from filename if available
            $dateStr = date('Y-m-d H:i', filemtime($file));
            if (preg_match('/championship_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', basename($file), $m)) {
                $dateStr = substr($m[1], 0, 10) . ' ' . str_replace('-', ':', substr($m[1], 11));
            }
            
            $archives[] = [
                'filename' => basename($file),
                'date' => $dateStr,
                'name' => $champName,
                'count' => $count
            ];
        }
        usort($archives, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إعادة تعيين البطولة</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#1a56db', secondary: '#7e3af2' },
                    fontFamily: { sans: ['Cairo', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f3f4f6; color: #1f2937; margin: 0; padding: 0; padding-bottom: 80px; }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border-radius: 16px;
        }
        .container { max-width: 800px; margin: 20px auto; padding: 0 15px; }
        .box { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; }
        .btn { padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; font-size: 16px; font-weight: 600; transition: all 0.2s; }
        .btn-danger { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .btn-danger:hover { background: #fecaca; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        
        /* Add padding to the main content container to avoid being covered by the sticky navbar */
        .main-content {
            padding-bottom: 100px;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen relative pb-24">

    <!-- Top Header -->
    <header class="bg-gradient-to-r from-primary to-blue-800 text-white shadow-lg sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fas fa-sync text-2xl"></i>
                <h1 class="text-xl font-bold">إدارة البطولة</h1>
            </div>
            <a href="../login.php?logout=1" class="text-white hover:text-red-200 transition-colors" title="تسجيل الخروج">
                <i class="fas fa-sign-out-alt text-xl"></i>
            </a>
        </div>
    </header>

    <?php include '../include/navbar-custom.php'; ?>

    <div class="container main-content mt-6">
        <h1><i class="fa-solid fa-sync"></i> إدارة البطولة</h1>
        
        <?php if($message): ?>
            <div class="alert <?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="box">
            <h3>حالة البطولة الحالية</h3>
            <p>المسجلين حالياً: <strong><?= $total ?></strong></p>
        </div>

        <div class="box" style="border: 1px solid #dc3545;">
            <h3><i class="fa-solid fa-triangle-exclamation"></i> منطقة الخطر</h3>
            <p>بدء بطولة جديدة سيقوم بأرشفة البيانات الحالية ومسح جميع التسجيلات.</p>
            
            <form method="POST" onsubmit="return confirm('تأكيد نهائي: هل أنت متأكد؟');">
                <input type="hidden" name="action" value="start_new">
                <label>
                    <input type="checkbox" name="confirm" value="yes" required>
                    أؤكد رغبتي في بدء بطولة جديدة
                </label>
                <br><br>
                <button type="submit" class="btn btn-danger">بدء بطولة جديدة</button>
            </form>
        </div>

        <div class="box" style="border: 1px solid #ff7b00; margin-bottom: 20px;">
            <h3 style="color:#ff7b00;"><i class="fa-solid fa-broom"></i> مسح شامل للسجلات</h3>
            <p style="opacity:0.8;">سيقوم هذا الإجراء بمسح <b>جميع</b> سجلات الإجراءات, الواتساب, الدخول, الجولات, والملاحظات نهائياً في ضغطة واحدة.</p>
            
            <form method="POST" onsubmit="return confirm('تأكيد نهائي: مسح شامل لجميع السجلات؟ لا يمكن التراجع!');">
                <input type="hidden" name="action" value="clear_all_system_logs">
                <label>
                    <input type="checkbox" name="confirm_clear" value="yes" required>
                    أؤكد رغبتي في مسح وتنظيف جميع السجلات
                </label>
                <br><br>
                <button type="submit" class="btn" style="background:#ff7b00; color:white;"><i class="fa-solid fa-broom"></i> الجرد ومسح السجلات</button>
            </form>
        </div>

        <?php if($archives): ?>
        <div class="box">
            <h3><i class="fa-solid fa-box-archive"></i> أرشيف البطولات السابقة</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <?php foreach($archives as $a): ?>
                <div class="p-4 border rounded shadow-sm bg-gray-50 flex flex-col justify-between" style="border-right: 4px solid #1a56db;">
                    <div class="mb-3">
                        <h4 class="font-bold text-lg mb-1" style="color: #1a56db;"><?= htmlspecialchars($a['name']) ?></h4>
                        <p class="text-sm text-gray-600 mb-1"><i class="fa-regular fa-calendar ml-1"></i> تاريخ الأرشفة: <span dir="ltr" class="font-semibold"><?= $a['date'] ?></span></p>
                        <p class="text-sm text-gray-600"><i class="fa-solid fa-users ml-1"></i> عدد المشاركين: <span class="font-semibold"><?= $a['count'] ?></span></p>
                    </div>
                    <div class="flex gap-2">
                        <a href="view_archive.php?file=<?= $a['filename'] ?>" class="btn flex-1 flex items-center justify-center gap-2" style="background:#3b82f6; color:#fff; text-decoration: none;">
                            <i class="fa-solid fa-eye"></i> عرض
                        </a>
                        <a href="download_archive.php?file=<?= $a['filename'] ?>" class="btn flex-1 flex items-center justify-center gap-2" style="background:#ffc107; color:#000; text-decoration: none;">
                            <i class="fa-solid fa-download"></i> تحميل
                        </a>
                        <form method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا الأرشيف؟');" class="flex-1">
                            <input type="hidden" name="action" value="delete_archive">
                            <input type="hidden" name="filename" value="<?= $a['filename'] ?>">
                            <button type="submit" class="btn w-full flex items-center justify-center gap-2" style="background:#fee2e2; color:#dc2626; border: 1px solid #fca5a5;">
                                <i class="fa-solid fa-trash-can"></i> حذف
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        
    </div>
</body>
</html>


