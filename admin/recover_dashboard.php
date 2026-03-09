<?php
/**
 * RECOVER DASHBOARD DATA FROM DATABASE
 * =====================================
 * This script reads registrations + members from SQLite (app.db)
 * and rebuilds admin/data/data.json so the dashboard shows all entries.
 * 
 * It also rebuilds admin/data/members.json for the members page.
 * 
 * Images are NOT re-uploaded - they are already on the server in uploads/.
 * This script just restores the JSON files that point to those images.
 * 
 * SAFE: This script will NOT overwrite existing data if data.json already has entries.
 *       It will show a preview first and ask for confirmation.
 */

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/auth.php';

// Only allow root access
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']->username !== 'root') {
    die('⛔ Root access required. Please login as root first.');
}

$pdo = db();

// ============================================
// STEP 1: Read ALL data from SQLite
// ============================================

// Get all registrations with their member info
$stmt = $pdo->query("
    SELECT 
        r.id as reg_id,
        r.wasel,
        r.status,
        r.car_type,
        r.car_year,
        r.car_color,
        r.engine_size,
        r.participation_type,
        r.plate_number,
        r.plate_letter,
        r.plate_governorate,
        r.personal_photo as reg_personal_photo,
        r.front_image,
        r.side_image,
        r.back_image,
        r.edited_image,
        r.acceptance_image,
        r.session_badge_token,
        r.championship_name,
        r.created_at as registration_date,
        r.is_active,
        m.id as member_id,
        m.permanent_code,
        m.phone,
        m.name,
        m.governorate,
        m.personal_photo as member_personal_photo,
        m.national_id_front,
        m.national_id_back,
        m.instagram,
        m.created_at as member_created_at
    FROM registrations r
    JOIN members m ON r.member_id = m.id
    WHERE r.is_active = 1
    ORDER BY CAST(r.wasel AS INTEGER) ASC
");

$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// STEP 2: Check current state
// ============================================
$dataFile = __DIR__ . '/data/data.json';
$membersFile = __DIR__ . '/data/members.json';

$existingData = [];
if (file_exists($dataFile)) {
    $existingData = json_decode(file_get_contents($dataFile), true) ?? [];
}

$existingMembers = [];
if (file_exists($membersFile)) {
    $existingMembers = json_decode(file_get_contents($membersFile), true) ?? [];
}

// ============================================
// STEP 3: Handle confirmation
// ============================================
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';

// Load participation type labels
$regSettingsFile = __DIR__ . '/data/registration_settings.json';
$participationLabels = [];
$engineLabels = [
    '8_cylinder_natural' => '8 سلندر تنفس طبيعي',
    '8_cylinder_boost' => '8 سلندر بوست',
    '6_cylinder_natural' => '6 سلندر تنفس طبيعي',
    '6_cylinder_boost' => '6 سلندر بوست',
    '4_cylinder' => '4 سلندر',
    '4_cylinder_boost' => '4 سلندر بوست',
    'other' => 'أخرى'
];
if (file_exists($regSettingsFile)) {
    $regSettings = json_decode(file_get_contents($regSettingsFile), true) ?? [];
    foreach ($regSettings['participation_types'] ?? [] as $pt) {
        $participationLabels[$pt['id']] = $pt['label'];
    }
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>استعادة بيانات الداشبورد</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #1a1a2e; color: #eee; padding: 20px; }
        .container { max-width: 1200px; }
        .card { background: #16213e; border-radius: 10px; padding: 20px; margin-bottom: 20px; border: 1px solid #0f3460; }
        .stat { display: inline-block; background: #0f3460; padding: 15px 25px; border-radius: 10px; margin: 5px; text-align: center; }
        .stat h3 { margin: 0; color: #e94560; font-size: 28px; }
        .stat p { margin: 5px 0 0; color: #aaa; font-size: 14px; }
        table { width: 100%; }
        table th { background: #0f3460; color: #fff; padding: 8px; }
        table td { padding: 8px; border-bottom: 1px solid #1a1a3e; font-size: 13px; }
        .btn-recover { background: #28a745; color: #fff; padding: 15px 40px; font-size: 18px; border: none; border-radius: 10px; cursor: pointer; }
        .btn-recover:hover { background: #218838; }
        .btn-danger-custom { background: #dc3545; color: #fff; padding: 10px 30px; font-size: 14px; border: none; border-radius: 8px; }
        .warning-box { background: #ff9800; color: #000; padding: 15px; border-radius: 10px; margin: 15px 0; }
        .success-box { background: #28a745; color: #fff; padding: 15px; border-radius: 10px; margin: 15px 0; }
        .img-check { width: 30px; height: 30px; object-fit: cover; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔄 استعادة بيانات الداشبورد من قاعدة البيانات</h1>
    
    <div class="card">
        <h3>📊 الحالة الحالية</h3>
        <div class="stat">
            <h3><?= count($existingData) ?></h3>
            <p>تسجيلات في الداشبورد (data.json)</p>
        </div>
        <div class="stat">
            <h3><?= count($existingMembers) ?></h3>
            <p>أعضاء في members.json</p>
        </div>
        <div class="stat">
            <h3><?= count($registrations) ?></h3>
            <p>تسجيلات في قاعدة البيانات (SQLite)</p>
        </div>
    </div>

<?php if (count($registrations) === 0): ?>
    <div class="warning-box">
        <h3>⚠️ لا توجد بيانات في قاعدة البيانات!</h3>
        <p>لا يمكن الاستعادة لأن جدول registrations فارغ.</p>
    </div>
<?php elseif (!$confirmed): ?>
    
    <?php if (count($existingData) > 0): ?>
    <div class="warning-box">
        <h3>⚠️ تنبيه: الداشبورد فيه بيانات بالفعل!</h3>
        <p>الداشبورد حالياً فيه <?= count($existingData) ?> تسجيل. الاستعادة هتضيف التسجيلات الناقصة بدون تكرار.</p>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>📋 معاينة البيانات اللي هيتم استعادتها (<?= count($registrations) ?> تسجيل)</h3>
        <div style="overflow-x: auto; max-height: 500px; overflow-y: auto;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الكود</th>
                    <th>الاسم</th>
                    <th>الهاتف</th>
                    <th>المحافظة</th>
                    <th>السيارة</th>
                    <th>اللوحة</th>
                    <th>الحالة</th>
                    <th>الصور</th>
                    <th>التاريخ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($registrations as $reg): 
                $personalPhoto = $reg['reg_personal_photo'] ?: $reg['member_personal_photo'];
                $hasImages = !empty($personalPhoto) || !empty($reg['front_image']) || !empty($reg['back_image']);
            ?>
                <tr>
                    <td><?= $reg['wasel'] ?? '-' ?></td>
                    <td><code style="background:#ffc107;color:#000;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($reg['session_badge_token'] ?? $reg['permanent_code'] ?? '-') ?></code></td>
                    <td><strong><?= htmlspecialchars($reg['name'] ?? '-') ?></strong></td>
                    <td dir="ltr"><?= htmlspecialchars($reg['phone'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($reg['governorate'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($reg['car_type'] ?? '-') ?> <?= $reg['car_year'] ?? '' ?></td>
                    <td><span style="background:#333;color:#fff;padding:2px 6px;border-radius:4px;font-family:monospace;"><?= htmlspecialchars(($reg['plate_governorate'] ?? '') . ' ' . ($reg['plate_letter'] ?? '') . ' ' . ($reg['plate_number'] ?? '')) ?></span></td>
                    <td>
                        <?php
                        $statusColors = ['pending' => '#ffc107', 'approved' => '#28a745', 'rejected' => '#dc3545'];
                        $statusLabels = ['pending' => '⏳ قيد المراجعة', 'approved' => '✅ مقبول', 'rejected' => '❌ مرفوض'];
                        $st = $reg['status'] ?? 'pending';
                        ?>
                        <span style="background:<?= $statusColors[$st] ?? '#999' ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px;"><?= $statusLabels[$st] ?? $st ?></span>
                    </td>
                    <td>
                        <?php if ($hasImages): ?>
                            ✅ <?= !empty($personalPhoto) ? '👤' : '' ?><?= !empty($reg['front_image']) ? '🚗' : '' ?><?= !empty($reg['back_image']) ? '🔙' : '' ?>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><small><?= $reg['registration_date'] ?? '-' ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card" style="text-align: center;">
        <h3>هل تريد استعادة هذه البيانات إلى الداشبورد؟</h3>
        <p>سيتم إعادة بناء data.json و members.json من قاعدة البيانات مع ربط الصور الموجودة</p>
        <br>
        <a href="?confirm=yes" class="btn-recover" onclick="return confirm('هل أنت متأكد من استعادة البيانات؟')">
            ✅ نعم، استعد البيانات الآن
        </a>
        &nbsp;&nbsp;
        <a href="../dashboard.php" class="btn-danger-custom">❌ إلغاء</a>
    </div>

<?php else: ?>
    <?php
    // ============================================
    // STEP 4: REBUILD data.json
    // ============================================
    
    // First, collect existing registration codes to avoid duplicates
    $existingCodes = [];
    $existingPhones = [];
    foreach ($existingData as $item) {
        if (!empty($item['registration_code'])) {
            $existingCodes[$item['registration_code']] = true;
        }
        if (!empty($item['phone'])) {
            $normalizedPhone = preg_replace('/\D/', '', $item['phone']);
            $normalizedPhone = substr($normalizedPhone, -10);
            $existingPhones[$normalizedPhone] = true;
        }
    }
    
    $newEntries = [];
    $skipped = 0;
    $recovered = 0;
    
    foreach ($registrations as $reg) {
        $regCode = $reg['session_badge_token'] ?? $reg['permanent_code'] ?? '';
        $phone = $reg['phone'] ?? '';
        $normalizedPhone = substr(preg_replace('/\D/', '', $phone), -10);
        
        // Skip if already exists in data.json (by code OR phone)
        if (isset($existingCodes[$regCode]) || isset($existingPhones[$normalizedPhone])) {
            $skipped++;
            continue;
        }
        
        // Get personal photo (prefer registration, fallback to member)
        $personalPhoto = $reg['reg_personal_photo'] ?: $reg['member_personal_photo'] ?: '';
        
        // Build images array
        $images = [];
        if (!empty($personalPhoto)) $images['personal_photo'] = $personalPhoto;
        if (!empty($reg['front_image'])) $images['front_image'] = $reg['front_image'];
        if (!empty($reg['side_image'])) $images['side_image'] = $reg['side_image'];
        if (!empty($reg['back_image'])) $images['back_image'] = $reg['back_image'];
        if (!empty($reg['edited_image'])) $images['edited_image'] = $reg['edited_image'];
        if (!empty($reg['acceptance_image'])) $images['acceptance_image'] = $reg['acceptance_image'];
        if (!empty($reg['national_id_front'])) $images['id_front'] = $reg['national_id_front'];
        if (!empty($reg['national_id_back'])) $images['id_back'] = $reg['national_id_back'];
        
        // Build the data entry (matching process.php format)
        $entry = [
            'wasel' => strval($reg['wasel'] ?? (count($existingData) + count($newEntries) + 1)),
            'inchage_status' => $reg['status'] ?? 'pending',
            'instagram' => $reg['instagram'] ?? '',
            'badge_id' => bin2hex(random_bytes(16)),
            'registration_code' => $regCode,
            'register_type' => 'returning',
            'register_type_label' => 'مستعاد من الداتابيز',
            'participation_type' => $reg['participation_type'] ?? '',
            'participation_type_label' => $participationLabels[$reg['participation_type'] ?? ''] ?? ($reg['participation_type'] ?? ''),
            'full_name' => $reg['name'] ?? '',
            'phone' => $phone,
            'country_code' => '+964',
            'governorate' => $reg['governorate'] ?? '',
            'car_type' => $reg['car_type'] ?? '',
            'car_year' => intval($reg['car_year'] ?? 0),
            'car_color' => $reg['car_color'] ?? '',
            'engine_size' => $reg['engine_size'] ?? '',
            'engine_size_label' => $engineLabels[$reg['engine_size'] ?? ''] ?? ($reg['engine_size'] ?? ''),
            'plate_letter' => $reg['plate_letter'] ?? '',
            'plate_number' => $reg['plate_number'] ?? '',
            'plate_governorate' => $reg['plate_governorate'] ?? '',
            'plate_full' => ($reg['plate_letter'] ?? '') . ' ' . ($reg['plate_number'] ?? '') . ' - ' . ($reg['plate_governorate'] ?? ''),
            'images' => $images,
            'personal_photo' => $personalPhoto,
            'status' => $reg['status'] ?? 'pending',
            'registration_date' => $reg['registration_date'] ?? date('Y-m-d H:i:s'),
            'approved_date' => ($reg['status'] === 'approved') ? ($reg['registration_date'] ?? null) : null,
            'approved_by' => null
        ];
        
        $newEntries[] = $entry;
        $recovered++;
    }
    
    // Merge: existing data + new recovered entries
    $finalData = array_merge($existingData, $newEntries);
    
    // Save data.json
    $dataBackup = $dataFile . '.bak_' . date('Y-m-d_H-i-s');
    if (count($existingData) > 0) {
        copy($dataFile, $dataBackup);
    }
    
    file_put_contents($dataFile, json_encode($finalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // ============================================
    // STEP 5: REBUILD members.json
    // ============================================
    
    $allMembers = $pdo->query("
        SELECT 
            m.*,
            r.car_type, r.car_year, r.car_color, r.engine_size,
            r.participation_type, r.plate_number, r.plate_letter, r.plate_governorate,
            r.personal_photo as reg_personal_photo,
            r.front_image, r.side_image, r.back_image, r.edited_image, r.acceptance_image,
            r.status as reg_status
        FROM members m
        LEFT JOIN registrations r ON r.member_id = m.id AND r.is_active = 1
        WHERE m.is_active = 1
        ORDER BY m.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $membersJson = $existingMembers; // Start with existing
    $membersRecovered = 0;
    
    foreach ($allMembers as $member) {
        $code = $member['permanent_code'] ?? '';
        if (empty($code) || $code === 'TEMP') continue;
        
        // Skip if already exists
        if (isset($membersJson[$code])) continue;
        
        $personalPhoto = $member['reg_personal_photo'] ?: $member['personal_photo'] ?: '';
        
        $membersJson[$code] = [
            'name' => $member['name'] ?? '',
            'full_name' => $member['name'] ?? '',
            'phone' => $member['phone'] ?? '',
            'governorate' => $member['governorate'] ?? '',
            'instagram' => $member['instagram'] ?? '',
            'participation_type' => $member['participation_type'] ?? '',
            'plate_letter' => $member['plate_letter'] ?? '',
            'plate_number' => $member['plate_number'] ?? '',
            'plate_governorate' => $member['plate_governorate'] ?? '',
            'registration_code' => $code,
            'car_type' => $member['car_type'] ?? '',
            'status' => $member['reg_status'] ?? 'approved',
            'personal_photo' => $personalPhoto,
            'images' => array_filter([
                'personal_photo' => $personalPhoto,
                'front_image' => $member['front_image'] ?? '',
                'side_image' => $member['side_image'] ?? '',
                'back_image' => $member['back_image'] ?? '',
                'edited_image' => $member['edited_image'] ?? '',
                'acceptance_image' => $member['acceptance_image'] ?? '',
            ]),
            'front_image' => $member['front_image'] ?? '',
            'side_image' => $member['side_image'] ?? '',
            'back_image' => $member['back_image'] ?? '',
            'edited_image' => $member['edited_image'] ?? '',
            'acceptance_image' => $member['acceptance_image'] ?? '',
        ];
        $membersRecovered++;
    }
    
    // Backup members.json
    $membersBackup = $membersFile . '.bak_' . date('Y-m-d_H-i-s');
    if (count($existingMembers) > 0) {
        copy($membersFile, $membersBackup);
    }
    
    file_put_contents($membersFile, json_encode($membersJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    ?>
    
    <div class="success-box">
        <h2>✅ تمت الاستعادة بنجاح!</h2>
    </div>
    
    <div class="card">
        <h3>📊 نتائج الاستعادة</h3>
        <div class="stat">
            <h3><?= $recovered ?></h3>
            <p>تسجيلات تم استعادتها للداشبورد</p>
        </div>
        <div class="stat">
            <h3><?= $skipped ?></h3>
            <p>تسجيلات تم تخطيها (موجودة بالفعل)</p>
        </div>
        <div class="stat">
            <h3><?= count($finalData) ?></h3>
            <p>إجمالي التسجيلات في الداشبورد الآن</p>
        </div>
        <div class="stat">
            <h3><?= $membersRecovered ?></h3>
            <p>أعضاء تم استعادتهم</p>
        </div>
        <div class="stat">
            <h3><?= count($membersJson) ?></h3>
            <p>إجمالي الأعضاء الآن</p>
        </div>
    </div>
    
    <div class="card" style="text-align: center;">
        <a href="../dashboard.php" class="btn-recover">🔙 العودة للداشبورد</a>
    </div>

<?php endif; ?>

</div>
</body>
</html>
