<?php
/**
 * Export Members to Excel/CSV
 * Download members data as spreadsheet
 * Fixed: Uses JSON data files instead of SQL
 */

session_start();
require_once '../include/db.php';
require_once '../include/helpers.php';
require_once '../include/AdminLogger.php';

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

// Load data from DATABASE
function loadMembersData($search = '') {
    $pdo = db();
    $where = "1=1";
    $params = [];
    
    if (!empty($search)) {
        $where .= " AND (m.name LIKE ? OR m.phone LIKE ? OR m.permanent_code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $stmt = $pdo->prepare("
        SELECT m.*, 
        (COALESCE(m.championships_participated, 0) + (SELECT COUNT(*) FROM registrations r WHERE r.member_id = m.id AND r.status='approved')) as total_championships,
        (SELECT car_type FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_car_type,
        (SELECT car_year FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_car_year,
        (SELECT car_color FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_car_color,
        (SELECT plate_number FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_plate_number,
        (SELECT plate_letter FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_plate_letter,
        (SELECT plate_governorate FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_plate_gov,
        (SELECT engine_size FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_engine_size,
        (SELECT participation_type FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_participation_type,
        (SELECT wasel FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as last_wasel
        FROM members m
        WHERE $where
        ORDER BY m.created_at DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- DATA RECONCILIATION ENGINE ---
$globalStats = []; // Key: Phone or Code => Count

function aggregateJsonStats($file, &$stats) {
    if (!file_exists($file)) return;
    $raw = json_decode(file_get_contents($file), true);
    $rows = isset($raw['data']) ? $raw['data'] : $raw; // Handle archive wrapper
    if (!is_array($rows)) return;

    // Track unique championships per member in this file
    // Heuristic: If it's a single file, it's 1 championship entry
    $seenInFile = []; 

    foreach ($rows as $r) {
        $code = trim($r['registration_code'] ?? $r['permanent_code'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $r['phone'] ?? '');
        
        $keys = array_filter([$code, $phone]);
        foreach ($keys as $k) {
            if (empty($k)) continue;
            if (!isset($seenInFile[$k])) {
                $stats[$k] = ($stats[$k] ?? 0) + 1;
                $seenInFile[$k] = true;
            }
        }
    }
}

// 1. Load from members.json (Baseline)
$mJsonFile = 'data/members.json';
$fullMembersJson = [];
if (file_exists($mJsonFile)) {
    $fullMembersJson = json_decode(file_get_contents($mJsonFile), true) ?? [];
    foreach ($fullMembersJson as $code => $m) {
        $count = (int)($m['championships_participated'] ?? $m['total_championships'] ?? 1);
        $globalStats[$code] = $count;
        $cleanPhone = preg_replace('/[^0-9]/', '', $m['phone'] ?? '');
        if ($cleanPhone) $globalStats[$cleanPhone] = $count;
    }
}

// 2. Load from Archives
$archives = glob('data/archives/*.json');
foreach ($archives as $arch) {
    aggregateJsonStats($arch, $globalStats);
}

// 3. Load from Current data.json
aggregateJsonStats('data/data.json', $globalStats);

// --- END DATA RECONCILIATION ---

// Handle export
if (isset($_GET['download'])) {
    $source = $_GET['source'] ?? 'dashboard';
    $format = $_GET['format'] ?? 'csv';
    
    // ------------------------------------------------------------------
    // UNIFIED EXPORT HEADERS
    // These match EXACTLY the keywords expected by import_members.php
    // allowing exported files to be re-imported flawlessly.
    // ------------------------------------------------------------------
    $headers = [
        'رقم الواصل',              // 0: Wasel
        'رقم العضو الدائم',        // 1: Member ID (SQLite members.id - matches upload file prefix)
        'الرقم التعريفي',          // 2: Registration Code
        'الاسم',                   // 3: Name
        'رقم الهاتف',              // 4: Phone
        'المحافظة',                // 5: Governorate
        'محافظة اللوحة',         // 6: Plate Gov
        'حرف اللوحة',            // 7: Plate Letter
        'رقم اللوحة',            // 8: Plate Number
        'اللوحة كاملة',          // 9: Full Plate String
        'نوع السيارة',             // 10: Car Type
        'سنة الصنع',               // 11: Car Year
        'لون السيارة',             // 12: Car Color
        'حجم المحرك',              // 13: Engine Size
        'نوع المشاركة',            // 14: Participation Type
        'الحالة',                  // 15: Status (pending/approved/rejected)
        'تاريخ التسجيل',           // 16: Registration Date
        'عدد المشاركات'            // 17: Championships Participated
    ];

    header('Content-Type: text/csv; charset=UTF-8');
    $filename = ($source === 'dashboard') ? "dashboard_export_" : "members_db_";
    header('Content-Disposition: attachment; filename="'.$filename.date('Y-m-d_H-i').'.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    
    $statusLabels = ['approved' => 'مقبول', 'pending' => 'قيد المراجعة', 'rejected' => 'مرفوض'];
    
    if ($source === 'dashboard') {
        // EXPORT FROM data.json (matches dashboard exactly)
        $dataJsonFile = 'data/data.json';
        $exportData = file_exists($dataJsonFile) ? json_decode(file_get_contents($dataJsonFile), true) ?? [] : [];
        
        foreach ($exportData as $reg) {
            $phone = preg_replace('/[^0-9]/', '', $reg['phone'] ?? '');
            $code = $reg['registration_code'] ?? $reg['permanent_code'] ?? '';
            
            // Reconcile championships count
            $totalCount = isset($globalStats[$code]) ? $globalStats[$code] : (isset($globalStats[$phone]) ? $globalStats[$phone] : 1);
            
            // Smart Plate Swapping & Normalization
            $plateLtr = trim($reg['plate_letter'] ?? $reg['car']['plate_letter'] ?? '');
            $plateNum = trim($reg['plate_number'] ?? $reg['car']['plate_num'] ?? '');
            
            // Logic: If 'Letter' is purely numeric and 'Number' is non-numeric or longer/empty, swap them
            $letterIsNumeric = preg_match('/^\d+$/', $plateLtr);
            $numberIsNumeric = preg_match('/^\d+$/', $plateNum);
            
            if ($letterIsNumeric && (!$numberIsNumeric || (strlen($plateLtr) > 3 && strlen($plateNum) <= 3))) {
                $temp = $plateLtr; $plateLtr = $plateNum; $plateNum = $temp;
            }

            fputcsv($output, [
                $reg['wasel'] ?? '',                                        // رقم الواصل
                $reg['member_id'] ?? '',                                    // رقم العضو الدائم
                '="' . $code . '"',                                         // الرقم التعريفي
                $reg['full_name'] ?? $reg['name'] ?? '',                    // الاسم
                '="' . $phone . '"',                                        // رقم الهاتف
                $reg['governorate'] ?? '',                                  // المحافظة
                $reg['plate_governorate'] ?? $reg['car']['plate_gov'] ?? '',             // محافظة اللوحة
                '="' . $plateLtr . '"',                                     // حرف اللوحة
                '="' . $plateNum . '"',                                     // رقم اللوحة
                $reg['plate_full'] ?? '',                                   // اللوحة كاملة
                $reg['car_type'] ?? $reg['car']['type'] ?? '',              // نوع السيارة
                $reg['car_year'] ?? $reg['car']['year'] ?? '',              // سنة الصنع
                $reg['car_color'] ?? $reg['car']['color'] ?? '',            // لون السيارة
                $reg['engine_size_label'] ?? $reg['engine_size'] ?? $reg['car']['engine'] ?? '', // حجم المحرك
                $reg['participation_type_label'] ?? $reg['participation_type'] ?? '', // نوع المشاركة
                $statusLabels[$reg['status'] ?? ''] ?? ($reg['status'] ?? ''), // الحالة
                $reg['registration_date'] ?? $reg['order_date'] ?? $reg['submitted_at'] ?? $reg['created_at'] ?? '', // تاريخ التسجيل
                $totalCount                                                 // عدد المشاركات
            ]);
        }
        
        try {
            $exportLogger = new AdminLogger();
            $exportLogger->log(AdminLogger::ACTION_EXPORT, $currentUser->username ?? 'root', 'تصدير بيانات الداشبورد (' . count($exportData) . ' تسجيل)', ['count' => count($exportData), 'source' => 'data.json']);
        } catch (Exception $e) {}
        
    } else {
        // EXPORT FROM DATABASE (legacy members)
        $search = $_GET['search'] ?? '';
        $members = loadMembersData($search);
        
        foreach ($members as $m) {
            $pCode = trim($m['permanent_code'] ?? '');
            $phone = preg_replace('/[^0-9]/', '', $m['phone'] ?? '');
            
            // --- BACKFILL FROM JSON ---
            // The "Members Only" import saves car data to members.json but not to SQL members table.
            $jsonData = $fullMembersJson[$pCode] ?? [];
            if (empty($jsonData) && $phone) {
                 foreach($fullMembersJson as $jItem) {
                      if (preg_replace('/[^0-9]/', '', $jItem['phone'] ?? '') === $phone) {
                           $jsonData = $jItem; break;
                      }
                 }
            }
            // --------------------------
            
            if (isset($m['manual_championships_count']) && $m['manual_championships_count'] !== null) {
                $totalCount = (int)$m['manual_championships_count'];
            } else {
                $dbCount = (int)($m['total_championships'] ?? 0);
                $jsonCount = max($globalStats[$pCode] ?? 0, $globalStats[$phone] ?? 0);
                $totalCount = max($dbCount, $jsonCount, 1);
            }
            
            $numField = trim($m['display_plate_number'] ?? '');
            if (!$numField) $numField = trim($jsonData['plate_number'] ?? '');
            
            $letField = trim($m['display_plate_letter'] ?? '');
            if (!$letField) $letField = trim($jsonData['plate_letter'] ?? '');
            
            $gov = trim($m['display_plate_gov'] ?? '');
            if (!$gov) $gov = trim($jsonData['plate_governorate'] ?? '');
            
            // Cleanup plate parts if they are combined incorrectly
            if ($gov) {
                $numField = trim(str_ireplace($gov, '', $numField));
                $letField = trim(str_ireplace($gov, '', $letField));
            }
            
            // Smart Plate Swapping for Database Source
            $letterIsNumeric = preg_match('/^\d+$/', $letField);
            $numberIsNumeric = preg_match('/^\d+$/', $numField);
            
            if ($letterIsNumeric && (!$numberIsNumeric || (strlen($letField) > 3 && strlen($numField) <= 3))) {
                $temp = $letField; $letField = $numField; $numField = $temp;
            }
            
            $plateFull = implode(' - ', array_filter([$gov, $letField, $numField]));
            
            $carType = $m['display_car_type'] ?: ($jsonData['car_type'] ?? '');
            $carYear = $m['display_car_year'] ?: ($jsonData['car_year'] ?? '');
            $carColor = $m['display_car_color'] ?: ($jsonData['car_color'] ?? '');
            $engineSize = $m['display_engine_size'] ?: ($jsonData['engine_size'] ?? '');
            $partType = $m['display_participation_type'] ?: ($jsonData['participation_type'] ?? '');
            
            $wasel = $m['last_wasel'] ?: ($jsonData['wasel'] ?? '');
            if ($wasel == $pCode || $wasel == $phone || strlen($wasel) > 5) $wasel = '';
            
            fputcsv($output, [
                $wasel ? '="' . $wasel . '"' : '',                          // رقم الواصل
                $m['id'],                                                   // رقم العضو الدائم
                '="' . $pCode . '"',                                        // الرقم التعريفي
                $m['name'],                                                 // الاسم
                '="' . $phone . '"',                                        // رقم الهاتف
                $m['governorate'],                                          // المحافظة
                $gov,                                                       // محافظة اللوحة
                '="' . $letField . '"',                                     // حرف اللوحة
                '="' . $numField . '"',                                     // رقم اللوحة
                $plateFull,                                                 // اللوحة كاملة
                $carType,                                                   // نوع السيارة
                $carYear,                                                   // سنة الصنع
                $carColor,                                                  // لون السيارة
                $engineSize,                                                // حجم المحرك
                $partType,                                                  // نوع المشاركة
                ($m['account_activated'] ? 'مفعل' : 'غير مفعل'),             // الحالة
                $m['created_at'],                                           // تاريخ التسجيل
                $totalCount                                                 // عدد المشاركات
            ]);
        }
        
        try {
            $exportLogger = new AdminLogger();
            $exportLogger->log(AdminLogger::ACTION_EXPORT, $currentUser->username ?? 'root', 'تصدير أعضاء قاعدة البيانات (' . count($members) . ' عضو)', ['count' => count($members), 'source' => 'database']);
        } catch (Exception $e) {}
    }
    
    fclose($output);
    exit;
}

// Get stats for display (From DB - for export)
$members = loadMembersData();
$totalMembers = count($members);
$activatedMembers = count(array_filter($members, fn($m) => $m['account_activated']));

// Get stats from data.json (to match dashboard)
$dataJsonFile = 'data/data.json';
$dataJson = file_exists($dataJsonFile) ? json_decode(file_get_contents($dataJsonFile), true) ?? [] : [];
$dashTotal = count($dataJson);
$dashApproved = count(array_filter($dataJson, fn($d) => ($d['status'] ?? '') === 'approved'));
$dashPending = count(array_filter($dataJson, fn($d) => ($d['status'] ?? '') === 'pending'));
$dashRejected = count(array_filter($dataJson, fn($d) => ($d['status'] ?? '') === 'rejected'));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📤 تصدير الأعضاء</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
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
        .header a { color: #aaa; text-decoration: none; margin-right: 15px; }
        .header a:hover { color: #fff; }
        
        .container { padding: 20px; max-width: 900px; margin: 0 auto; }
        
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
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: rgba(0,0,0,0.2);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-card .number { font-size: 28px; font-weight: bold; }
        .stat-card .label { font-size: 13px; opacity: 0.7; margin-top: 5px; }
        .stat-card.blue .number { color: #17a2b8; }
        .stat-card.green .number { color: #28a745; }
        .stat-card.yellow .number { color: #ffc107; }
        .stat-card.orange .number { color: #fd7e14; }
        
        .export-btn {
            display: block;
            width: 100%;
            padding: 25px;
            border: none;
            border-radius: 12px;
            font-size: 20px;
            font-weight: bold;
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            text-align: center;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            transition: transform 0.2s;
        }
        .export-btn:hover { transform: scale(1.02); }
        .export-btn i { margin-left: 10px; }
        
        .info-box {
            background: rgba(255,193,7,0.2);
            border: 1px solid #ffc107;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            font-size: 14px;
        }
        
        @media (max-width: 600px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="header">
        <h1><i class="fa-solid fa-file-export"></i> تصدير الأعضاء</h1>
        <div>
            <a href="import_members.php" style="color: #28a745;"><i class="fa-solid fa-file-import"></i> استيراد</a>
            <a href="members.php"><i class="fa-solid fa-users"></i> الأعضاء</a>
            
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <h2><i class="fa-solid fa-chart-pie"></i> إحصائيات الأعضاء</h2>
            <h3 style="font-size:15px;margin-bottom:15px;opacity:0.7;">📊 إحصائيات التسجيلات (الداشبورد)</h3>
            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="number"><?= number_format($dashTotal) ?></div>
                    <div class="label">إجمالي التسجيلات</div>
                </div>
                <div class="stat-card green">
                    <div class="number"><?= number_format($dashApproved) ?></div>
                    <div class="label">مقبولين</div>
                </div>
                <div class="stat-card orange">
                    <div class="number"><?= number_format($dashPending) ?></div>
                    <div class="label">قيد المراجعة</div>
                </div>
                <div class="stat-card" style="background:rgba(220,53,69,0.2);">
                    <div class="number" style="color:#dc3545;"><?= number_format($dashRejected) ?></div>
                    <div class="label">مرفوضين</div>
                </div>
            </div>
            <h3 style="font-size:15px;margin:15px 0 15px;opacity:0.7;">👥 إحصائيات قاعدة البيانات (التصدير)</h3>
            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="number"><?= number_format($totalMembers) ?></div>
                    <div class="label">إجمالي الأعضاء (DB)</div>
                </div>
                <div class="stat-card yellow">
                    <div class="number"><?= number_format($activatedMembers) ?></div>
                    <div class="label">حسابات مفعّلة</div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2><i class="fa-solid fa-download"></i> تحميل الملف</h2>
            
            <a href="?download=1&source=dashboard&format=csv" class="export-btn" style="margin-bottom:15px;">
                <i class="fa-solid fa-gauge-high"></i>
                📥 تصدير بيانات الداشبورد (<?= number_format($dashTotal) ?> تسجيل)
            </a>
            
            <div class="info-box" style="margin-top:0;margin-bottom:20px;">
                <i class="fa-solid fa-circle-info"></i> 
                <strong>يحتوي على:</strong> نفس البيانات الظاهرة في لوحة التحكم بالضبط (الاسم، اللوحة، السيارة، الحالة، الواصل، الشارة)
            </div>
            
            <a href="?download=1&source=db&format=csv" class="export-btn" style="background:linear-gradient(135deg,#6c757d,#495057);font-size:16px;padding:18px;">
                <i class="fa-solid fa-database"></i>
                تصدير أعضاء قاعدة البيانات (<?= number_format($totalMembers) ?> عضو)
            </a>
            <div class="info-box" style="background:rgba(108,117,125,0.2);border-color:#6c757d;">
                <i class="fa-solid fa-circle-info"></i> 
                <strong>يحتوي على:</strong> الأعضاء المسجلين + المستوردين من البطولات السابقة (كود العضوية، عدد البطولات)
            </div>
        </div>
    </div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
</body>
</html>


