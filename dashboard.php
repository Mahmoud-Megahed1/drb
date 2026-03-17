<?php
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:login.php');
    exit;
}

$currentUser = $_SESSION['user'];
$currentUser = $_SESSION['user'];
$isRoot = (isset($currentUser->username) && $currentUser->username === 'root');

// Role & Permissions
$userRole = $currentUser->role ?? ($isRoot ? 'root' : 'viewer');
if ($isRoot) $userRole = 'root';

$canApprove = in_array($userRole, ['root', 'admin', 'approver']);
$canDelete = in_array($userRole, ['root', 'admin']);
$canManageSettings = in_array($userRole, ['root', 'admin']);
$canSendWhatsapp = in_array($userRole, ['root', 'admin', 'whatsapp']);

// Redirect Simplified Users to their respective tools
if ($userRole === 'gate') {
    header('Location: gate.php');
    exit;
} elseif ($userRole === 'rounds') {
    header('Location: admin/rounds_scanner.php');
    exit;
} elseif ($userRole === 'notes') {
    header('Location: admin/notes_scanner.php');
    exit;
}

// Redirect Scanner User (Legacy)
if (isset($currentUser->username) && $currentUser->username === 'scanner') {
    header("location:admin/scanner_dashboard.php");
    exit;
}

$filterValue = $_GET['filterValue'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';

// Handle resend message request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_message') {
    header('Content-Type: application/json');
    require_once 'wasender.php';
    
    $wasel = $_POST['wasel'] ?? '';
    $messageType = $_POST['message_type'] ?? '';
    
    $file_path = 'admin/data/data.json';
    if (file_exists($file_path)) {
        $data = json_decode(file_get_contents($file_path), true);
        if (is_array($data)) {
            foreach ($data as $item) {
                if ($item['wasel'] === $wasel) {
                    try {
                        $result = null;
                        $success = false;
                        if ($messageType === 'welcome') {
                            $result = sendRegistrationReceivedWhatsApp($item);
                            $success = $result['success'] ?? false;
                            $message = 'تم إعادة إرسال رسالة الترحيب';
                            if (!$success && isset($result['error'])) {
                                $message = 'خطأ: ' . $result['error'];
                            }
                            echo json_encode(['success' => $success, 'message' => $message]);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'نوع الرسالة غير صحيح']);
                        }
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ: ' . $e->getMessage()]);
                    }
                    exit;
                }
            }
            echo json_encode(['success' => false, 'message' => 'الطلب غير موجود']);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'حدث خطأ']);
    exit;
}

// Load data
function loadJsonData($file, $filterValue, $statusFilter) {
    $file_path = 'admin/data/' . $file . '.json';
    if (!file_exists($file_path)) return [];

    $json_data = file_get_contents($file_path);
    $data = json_decode($json_data, true);
    if (!is_array($data)) return [];

    // Filter by archive status
    if ($filterValue === 'simple') {
        $data = array_filter($data, fn($item) => !isset($item['remove']) || $item['remove'] != 1);
    } elseif ($filterValue === 'archive') {
        $data = array_filter($data, fn($item) => isset($item['remove']) && $item['remove'] == 1);
    }
    
    // Filter by approval status
    if ($statusFilter !== 'all') {
        $data = array_filter($data, fn($item) => ($item['status'] ?? 'pending') === $statusFilter);
    }
    
    return $data;
}

$inputs = loadJsonData('data', $filterValue, $statusFilter);

// Get site settings
$siteSettings = [];
$siteSettingsFile = 'admin/data/site_settings.json';
if (file_exists($siteSettingsFile)) {
    $siteSettings = json_decode(file_get_contents($siteSettingsFile), true) ?? [];
}

// Load global badge/message preferences from DATABASE (More reliable)
require_once 'include/db.php';
$pdo = db();

// Badge Status
$stmt = $pdo->query("SELECT value FROM system_settings WHERE key = 'badge_enabled'");
$val = $stmt->fetchColumn();
$badgesEnabled = ($val === false) ? true : ($val === 'true' || $val === '1');

// QR Mode Status
$stmt = $pdo->query("SELECT value FROM system_settings WHERE key = 'qr_only_mode'");
$valQr = $stmt->fetchColumn();
$qrOnlyMode = ($valQr === false) ? false : ($valQr === 'true' || $valQr === '1');

// Build global message prefs (used for Quick Approve)
$globalMsgPrefs = [
    'send_registration' => 0,  // Usually already sent for form registrations
    'send_acceptance' => 1,    // Always send acceptance
    'send_badge' => $badgesEnabled && !$qrOnlyMode ? 1 : 0,  // Send badge if enabled and not QR-only
    'send_qr_only' => $qrOnlyMode ? 1 : 0  // Send QR only if QR mode is on
];
$globalMsgPrefsJson = json_encode($globalMsgPrefs, JSON_HEX_APOS | JSON_HEX_QUOT);

// Load participation types from registration settings
$regSettingsFile = 'admin/data/registration_settings.json';
$participationLabels = [];
if (file_exists($regSettingsFile)) {
    $regSettings = json_decode(file_get_contents($regSettingsFile), true) ?? [];
    $participationTypes = $regSettings['participation_types'] ?? [];
    foreach ($participationTypes as $pt) {
        $participationLabels[$pt['id']] = $pt['label'];
    }
}
// Fallback to defaults if not loaded
if (empty($participationLabels)) {
    $participationLabels = [
        'free_show' => 'استعراض حر',
        'special_car' => 'سيارة مميزة',
        'burnout' => 'Burnout'
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>لوحة التحكم - تسجيل السيارات</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; }
        .navbar { margin-bottom: 20px; }
        .stat-card { 
            padding: 20px; 
            border-radius: 10px; 
            text-align: center; 
            color: white; 
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .stat-card h4 { margin: 0; font-size: 16px; }
        .stat-card h2 { margin: 10px 0; font-size: 32px; font-weight: bold; }
        .stat-card p { margin: 0; font-size: 12px; }
        
        .status-badge { padding: 5px 10px; border-radius: 15px; font-size: 12px; }
        .status-pending { background: #ffc107; color: #000; }
        .status-approved { background: #28a745; color: #fff; }
        .status-rejected { background: #dc3545; color: #fff; }
        
        .btn-approve { background: #28a745; color: #fff; border: none; }
        .btn-approve:hover { background: #218838; color: #fff; }
        .btn-reject { background: #dc3545; color: #fff; border: none; }
        .btn-reject:hover { background: #c82333; color: #fff; }
        
        .image-thumb { 
            width: 50px; 
            height: 50px; 
            object-fit: cover; 
            border-radius: 5px; 
            cursor: pointer;
            transition: transform 0.2s;
        }
        .image-thumb:hover { transform: scale(1.1); }
        
        .modal-image { max-width: 100%; max-height: 80vh; }
        
        .settings-section {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .settings-section h4 { 
            margin-bottom: 15px; 
            padding-bottom: 10px; 
            border-bottom: 2px solid #e0e0e0;
        }
        
        .current-image {
            max-width: 200px;
            max-height: 150px;
            border-radius: 10px;
            margin: 10px 0;
        }
        
        .filter-row {
            background: #fff;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .car-info { font-size: 12px; line-height: 1.6; }
        .plate-badge { 
            background: #333; 
            color: #fff; 
            padding: 3px 8px; 
            border-radius: 5px; 
            font-family: monospace;
        }
        
        /* Icons spacing */
        .fa-solid, .fa-brands, .fa-regular { margin-left: 5px; }
    </style>
</head>
<body>
<!-- Navbar -->
<?php include 'include/navbar.php'; ?>

<div class="container-fluid">
    <?php
    // Statistics
    $totalRegistrations = count($inputs);
    $pendingCount = 0;
    $approvedCount = 0;
    $rejectedCount = 0;
    
    foreach ($inputs as $input) {
        $status = $input['status'] ?? 'pending';
        if ($status === 'pending') $pendingCount++;
        elseif ($status === 'approved') $approvedCount++;
        elseif ($status === 'rejected') $rejectedCount++;
    }
    
    // Member Statistics from SQLite Database
    require_once 'include/db.php';
    $pdo = db();
    
    // Retrieve members and past participation count using efficient JOIN (no correlated subquery)
    $champId = $pdo->query("SELECT value FROM system_settings WHERE key = 'current_championship_id'")->fetchColumn() ?: '1';
    
    $stmt = $pdo->prepare("
        SELECT m.permanent_code, 
               COALESCE(r_count.past_count, 0) as past_championships
        FROM members m
        LEFT JOIN (
            SELECT member_id, COUNT(*) as past_count 
            FROM registrations 
            WHERE status='approved' AND championship_id != ? AND is_active = 1
            GROUP BY member_id
        ) r_count ON r_count.member_id = m.id
    ");
    $stmt->execute([$champId]);
    $dbMembers = [];
    while($r = $stmt->fetch()) {
        $dbMembers[$r['permanent_code']] = (int)$r['past_championships'];
    }
    
    $totalMembers = count($dbMembers);
    
    // Load Rounds Config
    $roundsConfigFile = 'admin/data/rounds_config.json';
    $currentRoundsCount = 3;
    if (file_exists($roundsConfigFile)) {
        $rConf = json_decode(file_get_contents($roundsConfigFile), true);
        $currentRoundsCount = $rConf['total_rounds'] ?? 3;
    }
    
    // Count returning vs new members in current registrations
    $returningMembers = 0;
    $newMembers = 0;
    $currentCodes = [];
    
    foreach ($inputs as $input) {
        $code = $input['registration_code'] ?? null;
        if ($code && !isset($currentCodes[$code])) {
            $currentCodes[$code] = true;
            
            $isReturning = false;
            // First check if input explicitly flags them as returning
            if (($input['register_type'] ?? '') === 'returning') {
                $isReturning = true;
            } 
            // Otherwise check SQL past championships > 0
            elseif (isset($dbMembers[$code]) && $dbMembers[$code] > 0) {
                $isReturning = true;
            }
            
            if ($isReturning) {
                $returningMembers++;
            } else {
                $newMembers++;
            }
        }
    }
    
    // Count members who haven't registered yet in this championship
    $notRegisteredMembers = 0;
    foreach ($dbMembers as $code => $pastCount) {
        if (!isset($currentCodes[$code])) {
            $notRegisteredMembers++;
        }
    }
    ?>
    
    <!-- WhatsApp Status Banner -->
    <div id="waStatusBanner" style="display:none; padding:12px 20px; border-radius:10px; margin-bottom:15px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 8px rgba(0,0,0,0.1); cursor:pointer;" onclick="window.location='admin/pending_messages.php'">
        <span id="waStatusDot" style="width:14px;height:14px;border-radius:50%;display:inline-block;animation:waPulse 2s infinite;"></span>
        <div style="flex:1;">
            <strong id="waStatusText" style="font-size:14px;"></strong>
            <span id="waPendingBadge" style="display:none; background:#dc3545; color:#fff; padding:2px 8px; border-radius:10px; font-size:12px; margin-right:8px;"></span>
        </div>
        <a href="admin/pending_messages.php" class="btn btn-sm btn-default" onclick="event.stopPropagation();">
            <i class="fa-solid fa-envelope"></i> الرسائل المعلقة
        </a>
    </div>
    <style>@keyframes waPulse { 0%,100%{opacity:1} 50%{opacity:0.4} }</style>
    <script>
    function checkWaHealth() {
        fetch('api/whatsapp_health.php?action=status')
            .then(r => r.json())
            .then(data => {
                const banner = document.getElementById('waStatusBanner');
                const dot = document.getElementById('waStatusDot');
                const text = document.getElementById('waStatusText');
                const badge = document.getElementById('waPendingBadge');
                
                banner.style.display = 'flex';
                
                if (data.status === 'connected') {
                    banner.style.background = 'linear-gradient(135deg, #d4edda, #c3e6cb)';
                    banner.style.border = '2px solid #28a745';
                    dot.style.background = '#28a745';
                    text.textContent = '🟢 WhatsApp متصل';
                    text.style.color = '#155724';
                } else if (data.status === 'disconnected') {
                    banner.style.background = 'linear-gradient(135deg, #f8d7da, #f5c6cb)';
                    banner.style.border = '2px solid #dc3545';
                    dot.style.background = '#dc3545';
                    text.textContent = '🔴 WhatsApp غير متصل - الرسائل لا يتم إرسالها!';
                    text.style.color = '#721c24';
                } else {
                    banner.style.background = 'linear-gradient(135deg, #fff3cd, #ffeaa7)';
                    banner.style.border = '2px solid #ffc107';
                    dot.style.background = '#ffc107';
                    text.textContent = '🟡 حالة WhatsApp غير معروفة';
                    text.style.color = '#856404';
                }
                
                if (data.pending_count > 0) {
                    badge.style.display = 'inline';
                    badge.textContent = data.pending_count + ' رسالة معلقة';
                } else {
                    badge.style.display = 'none';
                }
            })
            .catch(() => {});
    }
    // Check on load and every 60 seconds
    document.addEventListener('DOMContentLoaded', function() {
        checkWaHealth();
        setInterval(checkWaHealth, 60000);
    });
    </script>
    
    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <h4>📋 إجمالي التسجيلات</h4>
                <h2><?= number_format($totalRegistrations) ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);">
                <h4>⏳ قيد المراجعة</h4>
                <h2><?= number_format($pendingCount) ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                <h4>✅ مقبول</h4>
                <h2><?= number_format($approvedCount) ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                <h4>❌ مرفوض</h4>
                <h2><?= number_format($rejectedCount) ?></h2>
            </div>
        </div>
    </div>
    
    <!-- Member Statistics -->
    <?php if ($totalMembers > 0 && $canApprove): ?>
    <div class="row" style="margin-top: 10px;">
        <div class="col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
                <h4>👥 إجمالي الأعضاء</h4>
                <h2><?= number_format($totalMembers) ?></h2>
                <p>قاعدة بيانات الأعضاء الدائمة</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);">
                <h4>🔄 أعضاء قدامى رجعوا</h4>
                <h2><?= number_format($returningMembers) ?></h2>
                <p>سجلوا في هذه البطولة</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #e83e8c 0%, #c21e56 100%);">
                <h4>⏸️ لم يسجلوا بعد</h4>
                <h2><?= number_format($notRegisteredMembers) ?></h2>
                <p>أعضاء قدامى لم يسجلوا</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #20c997 0%, #17a2b8 100%);">
                <h4>🆕 أعضاء جدد</h4>
                <h2><?= number_format($newMembers) ?></h2>
                <p>أول مرة يسجلون</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Widget -->
    <div class="row">
        <div class="col-md-12">
            <?php include 'include/participation_stats_widget.php'; ?>
        </div>
    </div>
    
    <!-- Badge Control Section -->
    <?php if ($isRoot): ?>
    <div class="row" style="margin-bottom: 20px;">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h4 style="margin: 0;">📱 إدارة الباجات والدخول</h4>
                </div>
                <div class="panel-body">
                    <div class="row">
                        <div class="col-md-3">
                           <button class="btn btn-primary" onclick="window.open('admin/qr_scanner.php', '_blank')">
                            <i class="fa-solid fa-camera"></i> ماسح البوابة
                        </button>
                        <button id="toggleBadgesBtn" class="btn btn-success" onclick="toggleBadges()">
                            🔓 تفعيل الباجات
                        </button>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-warning btn-lg btn-block" onclick="resetEntries()">
                                🔄 إعادة تعيين الدخول
                            </button>
                        </div>
                        <div class="col-md-3">
                            <div id="entryStats" class="well text-center" style="margin: 0; padding: 10px;">
                                <div><strong>دخلوا:</strong> <span id="enteredCount">-</span></div>
                                <div><strong>لم يدخلوا:</strong> <span id="remainingCount">-</span></div>
                            </div>
                        </div>
                    </div>
                    

                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="filter-row">
        <div class="row">
            <div class="col-md-3">
                <label>حالة الأرشفة:</label>
                <select id="filterValue" class="form-control">
                    <option value="all" <?= $filterValue === 'all' ? 'selected' : '' ?>>الكل</option>
                    <option value="simple" <?= $filterValue === 'simple' ? 'selected' : '' ?>>النشطة</option>
                    <option value="archive" <?= $filterValue === 'archive' ? 'selected' : '' ?>>المؤرشفة</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>حالة الطلب:</label>
                <select id="statusFilter" class="form-control">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>الكل</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>قيد المراجعة</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>مقبول</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>مرفوض</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>&nbsp;</label>
                <button class="btn btn-primary form-control" onclick="applyFilters()">🔍 تطبيق الفلتر</button>
            </div>
        </div>
    </div>
    
    <!-- Data Table -->
    <?php if (count($inputs) > 0): ?>
    <div class="panel panel-default">
        <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>📋 تسجيلات السيارات</strong>
                <span class="badge"><?= $totalRegistrations ?></span>
            </div>
            <a href="admin/import_excel.php" class="btn btn-success btn-sm">
                <i class="fa-solid fa-file-excel"></i> استيراد Excel
            </a>
            <?php if ($approvedCount > 0): ?>
            <a href="print_qr.php?all=1" target="_blank" class="btn btn-info btn-sm">
                🖨️ طباعة QR لجميع المقبولين (<?= $approvedCount ?>)
            </a>
            <?php endif; ?>
        </div>
        <div class="panel-body" style="overflow-x: auto;">
            <table id="dataTable" class="table table-bordered table-striped table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>🔑 الكود</th>
                        <th>👤 النوع</th>
                        <th>الحالة</th>
                        <th>نوع المشاركة</th>
                        <th>الاسم</th>
                        <th>الهاتف</th>
                        <th>انستجرام</th>
                        <th>المحافظة</th>
                        <th>نوع السيارة</th>
                        <th>سنة الصنع</th>
                        <th>اللون</th>
                        <th>حجم المحرك</th>
                        <th>اللوحة</th>
                        <th>الصور</th>
                        <th>التاريخ</th>
                        <th>المصدر</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // PERFORMANCE FIX: Pre-load rounds.json ONCE before the loop
                    $roundsDataFile = 'admin/data/rounds.json';
                    $preloadedActiveRounds = [];
                    if (file_exists($roundsDataFile)) {
                        $allRounds = json_decode(file_get_contents($roundsDataFile), true) ?? [];
                        foreach($allRounds as $rand) {
                            if(isset($rand['is_active']) && $rand['is_active'] == 1) {
                                $preloadedActiveRounds[] = $rand;
                            }
                        }
                        usort($preloadedActiveRounds, function($a, $b) { return $a['round_number'] - $b['round_number']; });
                    }
                    ?>
                    <?php foreach ($inputs as $index => $input): 
                        $status = $input['status'] ?? 'pending';
                        $statusClass = 'status-' . $status;
                        $statusLabels = [
                            'pending' => '⏳ قيد المراجعة',
                            'approved' => '✅ مقبول',
                            'rejected' => '❌ مرفوض'
                        ];
                        // $participationLabels is now loaded globally from registration_settings.json
                        $engineLabels = [
                            '8_cylinder_natural' => '8 سلندر تنفس طبيعي',
                            '8_cylinder_boost' => '8 سلندر بوست',
                            '6_cylinder_natural' => '6 سلندر تنفس طبيعي',
                            '6_cylinder_boost' => '6 سلندر بوست',
                            '4_cylinder' => '4 سلندر',
                            '4_cylinder_boost' => '4 سلندر بوست',
                            'other' => 'أخرى'
                        ];
                    ?>
                    <tr id="row_<?= $input['wasel'] ?>">
                        <td><?= $input['wasel'] ?></td>
                        <td><code style="background: #ffc107; color: #000; padding: 2px 6px; border-radius: 4px; font-weight: bold;"><?= htmlspecialchars($input['registration_code'] ?? '-') ?></code></td>
                        <td>
                            <?php 
                            // Use saved register_type_label if available, otherwise calculate
                            if (!empty($input['register_type_label'])): ?>
                                <?php if ($input['register_type'] === 'returning'): ?>
                                    <span class="label label-info" title="مسجل قديم">🔄 <?= htmlspecialchars($input['register_type_label']) ?></span>
                                <?php else: ?>
                                    <span class="label label-success" title="جديد">🆕 <?= htmlspecialchars($input['register_type_label']) ?></span>
                                <?php endif; ?>
                            <?php else:
                                // Fallback for old records: check members database
                                $memberCode = $input['registration_code'] ?? null;
                                $isReturning = $memberCode && isset($members[$memberCode]) && ($members[$memberCode]['championships_participated'] ?? 0) > 0;
                                if ($isReturning): ?>
                                    <span class="label label-info" title="مسجل قديم">🔄 مسجل قديم</span>
                                <?php else: ?>
                                    <span class="label label-success" title="جديد">🆕 جديد</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge <?= $statusClass ?>">
                                <?= $statusLabels[$status] ?? $status ?>
                            </span>
                        </td>
                        <td><?= $participationLabels[$input['participation_type'] ?? ''] ?? ($input['participation_type_label'] ?? ($input['participation_type'] ?? '-')) ?></td>
                        <td><strong><?= htmlspecialchars($input['full_name'] ?? $input['name'] ?? '-') ?></strong></td>
                        <td dir="ltr"><?= htmlspecialchars(($input['country_code'] ?? '') . ($input['phone'] ?? '')) ?></td>
                        <td dir="ltr"><?= htmlspecialchars($input['instagram'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($input['governorate'] ?? '-') ?></td>
                        <td><strong><?= htmlspecialchars($input['car_type'] ?? '-') ?></strong></td>
                        <td><?= $input['car_year'] ?? '-' ?></td>
                        <td><?= htmlspecialchars($input['car_color'] ?? '-') ?></td>
                        <td><small><?= $engineLabels[$input['engine_size'] ?? ''] ?? ($input['engine_size_label'] ?? $input['engine_size'] ?? '-') ?></small></td>
                        <td>
                            <span class="plate-badge" dir="rtl">
                                <?= htmlspecialchars($input['plate_governorate'] ?? '') ?> - 
                                <?= htmlspecialchars($input['plate_letter'] ?? '') ?> 
                                <?= htmlspecialchars($input['plate_number'] ?? '') ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $renderImages = $input['images'] ?? [];
                            if (empty($renderImages['personal_photo']) && !empty($input['personal_photo'])) {
                                $renderImages['personal_photo'] = $input['personal_photo'];
                            }
                            
                            // Prevent duplicate display of ID fields
                            if (isset($renderImages['id_front']) || isset($renderImages['id_back'])) {
                                unset($renderImages['national_id_front']);
                                unset($renderImages['national_id_back']);
                            }
                            
                            if (!empty($renderImages)): 
                            ?>
                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                <?php foreach ($renderImages as $type => $path): ?>
                                    <?php 
                                    if (!empty($path)) {
                                        $cleanPath = ltrim($path, './');
                                    ?>
                                    <img src="<?= $cleanPath ?>" class="image-thumb" 
                                         loading="lazy"
                                         onclick="showImage('<?= $cleanPath ?>', '<?= $type ?>')"
                                         title="<?= $type ?>"
                                         onerror="this.style.display='none'">
                                    <?php 
                                    }
                                    ?>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">لا توجد صور</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= $input['registration_date'] ?? $input['order_date'] ?? '-' ?></small></td>
                        <td>
                            <?php 
                            $isImported = !empty($input['import_source']) || ($input['register_type'] ?? '') === 'imported';
                            if ($isImported): ?>
                                <span class="label label-warning" title="مستورد من Excel">📥 مستورد</span>
                            <?php else: ?>
                                <span class="label label-primary" title="مسجل من الاستمارة">📝 استمارة</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group-vertical btn-group-sm">
                                <?php if ($canApprove && $status === 'pending'): 
                                    // FORCE usage of global settings for pending requests
                                    // This ignores any saved prefs from import/history to ensure
                                    // the buttons always reflect the current admin settings
                                    $msgPrefs = [
                                        'send_registration' => $isImported ? 1 : 0, 
                                        'send_acceptance' => 1,
                                        'send_badge' => $globalMsgPrefs['send_badge'],
                                        'send_qr_only' => $globalMsgPrefs['send_qr_only']
                                    ];
                                    $msgPrefsJson = json_encode($msgPrefs, JSON_HEX_APOS | JSON_HEX_QUOT);
                                ?>
                                <a href="admin/visual_editor.php?wasel=<?= $input['wasel'] ?>" class="btn btn-primary btn-sm" style="margin-bottom: 3px;">
                                    🎨 تعديل الصورة
                                </a>
                                <a href="admin/member_details.php?id=<?= urlencode($input['registration_code'] ?? $input['member_id'] ?? '') ?>" class="btn btn-default btn-sm" style="margin-bottom: 3px;" target="_blank">
                                    👤 الملف الشخصي
                                </a>
                                <button class="btn btn-approve" onclick='approveRegistration("<?= $input['wasel'] ?>", <?= $globalMsgPrefsJson ?>)'>
                                    ✅ قبول سريع
                                </button>
                                <button class="btn btn-info btn-sm" onclick='openApproveModal("<?= $input['wasel'] ?>", <?= $msgPrefsJson ?>, "<?= $status ?>")' style="margin-top:2px">
                                    ⚙️ قبول مخصص
                                </button>
                                <button class="btn btn-reject" onclick="rejectRegistration('<?= $input['wasel'] ?>')">
                                    ❌ رفض
                                </button>
                                <?php elseif ($status === 'approved' && ($canApprove || $canSendWhatsapp)): ?>
                                <a href="admin/member_details.php?id=<?= urlencode($input['registration_code'] ?? $input['member_id'] ?? '') ?>" class="btn btn-default btn-sm" style="margin-bottom: 3px;" target="_blank">
                                    👤 الملف الشخصي
                                </a>
                                <button class="btn btn-success btn-sm" onclick="resendApproval('<?= $input['wasel'] ?>')">
                                    🔄 إعادة إرسال القبول
                                </button>
                                <a href="print_qr.php?wasel=<?= $input['wasel'] ?>" target="_blank" class="btn btn-info btn-sm" style="margin-top: 3px;">
                                    🖨️ طباعة QR
                                </a>
                                <div class="btn-group" style="margin-top: 3px; width: 100%;">
                                    <button type="button" class="btn btn-warning btn-sm dropdown-toggle w-100" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="width: 100%;">
                                        <i class="fa-solid fa-rotate-left"></i> إعادة تعيين جولة <span class="caret"></span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-right" style="font-size: 13px;">
                                        <?php 
                                        // Use pre-loaded rounds data (not re-read per row)
                                        if (empty($preloadedActiveRounds)) {
                                            echo '<li><a href="#">لا توجد جولات مفعلة</a></li>';
                                        } else {
                                            foreach ($preloadedActiveRounds as $arnd) {
                                                echo '<li><a href="#" onclick="resetRoundParticipant(\'' . $input['wasel'] . '\', \'' . $arnd['id'] . '\', \'' . htmlspecialchars($input['full_name'] ?? '') . '\')"><i class="fa-solid fa-flag-checkered"></i> ' . htmlspecialchars($arnd['round_name']) . '</a></li>';
                                            }
                                        }
                                        ?>
                                    </ul>
                                </div>
                                <?php elseif ($status === 'rejected' && $canApprove): ?>
                                    <button class="btn btn-warning btn-sm" style="margin-bottom: 3px;" onclick="undoRejection('<?= $input['wasel'] ?>')">
                                        ↩️ تراجع عن الرفض
                                    </button>
                                    <button class="btn btn-info btn-sm" style="margin-bottom: 3px;" onclick="editRejectionReason('<?= $input['wasel'] ?>', '<?= htmlspecialchars(addslashes($input['rejection_reason'] ?? '')) ?>')">
                                        📝 تعديل سبب الرفض
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($canDelete): ?>
                                <button class="btn btn-danger btn-sm" onclick="deleteRegistration('<?= $input['wasel'] ?>')">
                                    🗑️ حذف
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info text-center">
        <h4>لا توجد تسجيلات</h4>
        <p>لم يتم العثور على أي تسجيلات بالفلتر المحدد</p>
    </div>
    <?php endif; ?>
    
    <!-- Settings Section -->
    <?php if ($canManageSettings): ?>
    <div id="settingsSection" class="settings-section">
        <h4>⚙️ إعدادات الموقع</h4>
        
        <div class="row">
            <!-- Banner Settings -->
            <div class="col-md-6">
                <div class="panel panel-default">
                    <div class="panel-heading">🖼️ صورة البانر الرئيسية</div>
                    <div class="panel-body">
                        <?php 
                        $currentBanner = $siteSettings['banner_url'] ?? 'images/redbull_logos.png';
                        if (file_exists($currentBanner)):
                        ?>
                        <img src="<?= $currentBanner ?>" class="current-image" alt="Current Banner">
                        <?php endif; ?>
                        
                        <form id="bannerForm" enctype="multipart/form-data">
                            <input type="hidden" name="setting_type" value="banner">
                            <div class="form-group">
                                <input type="file" name="image" class="form-control" accept="image/*" required>
                            </div>
                            <button type="submit" class="btn btn-primary">📤 تحديث البانر</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Frame Settings -->
            <div class="col-md-6">
                <div class="panel panel-default">
                    <div class="panel-heading">🎨 صورة Frame القبول</div>
                    <div class="panel-body">
                        <?php 
                        // Load frame settings for authoritative image source
                        $frameConfigFile = 'admin/data/frame_settings.json';
                        $currentFrame = 'images/acceptance_frame.png';
                        
                        if (file_exists($frameConfigFile)) {
                            $frameConfig = json_decode(file_get_contents($frameConfigFile), true);
                            if (!empty($frameConfig['frame_image'])) {
                                $currentFrame = $frameConfig['frame_image'];
                            }
                        }
                        
                        if (file_exists($currentFrame)):
                        ?>
                        <img src="<?= $currentFrame ?>" class="current-image" alt="Current Frame">
                        <?php endif; ?>
                        
                        <form id="frameForm" enctype="multipart/form-data">
                            <input type="hidden" name="setting_type" value="frame">
                            <div class="form-group">
                                <input type="file" name="image" class="form-control" accept="image/*" required>
                            </div>
                            <button type="submit" class="btn btn-primary">📤 تحديث الـ Frame</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- WhatsApp Messages Settings -->
        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-success">
                    <div class="panel-heading">💬 إعدادات رسائل الواتساب</div>
                    <div class="panel-body">
                        <?php
                        // Load message templates
                        $messagesFile = 'admin/data/whatsapp_messages.json';
                        $defaultMessages = [
                            'registration_message' => "🏎️ *تسجيل سيارات الاستعراض الحر*\n━━━━━━━━━━━━━━━\n📋 *تم حجز طلبك بنجاح!*\n\n🔢 *رقم الطلب:* {wasel}\n👤 *الاسم:* {name}\n🚗 *السيارة:* {car_type}\n\n⏳ *سيتم التواصل معك قريباً لتأكيد الطلب*\n━━━━━━━━━━━━━━━",
                            'acceptance_message' => "🏎️ *تم تأكيد اشتراكك في البطولة!*\n\n🔢 *رقم التسجيل:* {wasel}\n👤 *الاسم:* {name}\n🚗 *السيارة:* {car_type}\n\n✅ مبروك! تم قبول سيارتك للمشاركة\n📍 يرجى الالتزام بالقوانين والتعليمات"
                        ];
                        $whatsappMessages = $defaultMessages;
                        if (file_exists($messagesFile)) {
                            $loaded = json_decode(file_get_contents($messagesFile), true);
                            if (is_array($loaded)) {
                                $whatsappMessages = array_merge($defaultMessages, $loaded);
                            }
                        }
                        ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><strong>📨 رسالة التسجيل (عند تقديم الطلب)</strong></label>
                                    <p class="text-muted small">المتغيرات المتاحة: {wasel} = رقم الطلب, {name} = الاسم, {car_type} = نوع السيارة</p>
                                    <textarea class="form-control" id="registration_message" rows="8" style="direction: rtl;"><?= htmlspecialchars($whatsappMessages['registration_message']) ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><strong>✅ رسالة القبول (عند الموافقة)</strong></label>
                                    <p class="text-muted small">المتغيرات المتاحة: {wasel} = رقم الطلب, {name} = الاسم, {car_type} = نوع السيارة</p>
                                    <textarea class="form-control" id="acceptance_message" rows="8" style="direction: rtl;"><?= htmlspecialchars($whatsappMessages['acceptance_message']) ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-success btn-lg" onclick="saveWhatsAppMessages()">
                            💾 حفظ رسائل الواتساب
                        </button>
                        <div id="whatsappMsgResult" class="alert" style="display: none; margin-top: 15px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" id="imageModalTitle">عرض الصورة</h4>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" class="modal-image">
            </div>
        </div>
    </div>
</div>

<!-- Custom Approval Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #28a745 0%, #218838 100%); color: white;">
                <button type="button" class="close" data-dismiss="modal" style="color:white">&times;</button>
                <h4 class="modal-title">⚙️ خيارات القبول</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="approveWasel">
                <p class="text-muted">اختر الرسائل التي تريد إرسالها للمشترك:</p>
                
                <div class="checkbox">
                    <label style="font-size:16px">
                        <input type="checkbox" id="opt_registration"> 
                        📋 رسالة استلام التسجيل
                    </label>
                </div>
                
                <div class="checkbox">
                    <label style="font-size:16px">
                        <input type="checkbox" id="opt_acceptance"> 
                        ✅ رسالة القبول (مع صورة Frame)
                    </label>
                </div>
                
                <div class="checkbox">
                    <label style="font-size:16px">
                        <input type="checkbox" id="opt_badge"> 
                        🎫 رسالة الباج الكامل
                    </label>
                </div>
                
                <hr>
                
                <div class="checkbox" style="background:#e3f2fd;padding:10px;border-radius:5px">
                    <label style="font-size:16px;color:#1565c0">
                        <input type="checkbox" id="opt_qr_only"> 
                        📲 <strong>QR فقط</strong> (كود دخول سريع بدون باج كامل)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-success btn-lg" onclick="confirmCustomApproval()">
                    ✅ قبول وإرسال
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white; border-radius: 6px 6px 0 0;">
                <button type="button" class="close" data-dismiss="modal" style="color: white; opacity: 1;">&times;</button>
                <h4 class="modal-title">🔄 رفض التسجيل (مع طلب مراجعة)</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rejectWasel">
                <div class="alert alert-info" style="font-size: 13px; margin-bottom: 15px;">
                    <i class="fa-solid fa-circle-info"></i>
                    <strong>ملاحظة:</strong> سيتم إرسال رسالة للمشترك تطلب منه مراجعة وتعديل بياناته وإعادة التسجيل.
                </div>
                <div class="form-group">
                    <label><strong>سبب الرفض / ملاحظات التعديل المطلوبة:</strong></label>
                    <textarea id="rejectReason" class="form-control" rows="4" placeholder="مثال: يرجى تعديل صورة السيارة لتكون أوضح...
أو: البيانات غير مكتملة، يرجى إعادة التسجيل مع إضافة صورة الهوية"></textarea>
                </div>
                <div class="form-group">
                    <div class="checkbox" style="background: #f8f9fa; padding: 10px; border-radius: 8px;">
                        <label>
                            <input type="checkbox" id="rejectAllowReregister" checked>
                            إضافة رسالة "يمكنك إعادة التسجيل بعد التعديل" في الرسالة
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-warning" onclick="confirmReject()" style="color: #fff;"><i class="fa-solid fa-rotate-left"></i> رفض مع طلب تعديل</button>
            </div>
        </div>
    </div>
</div>

<!-- Approval Modal with Message Selection -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #28a745, #20c997); color: white; border-radius: 15px 15px 0 0;">
                <button type="button" class="close" data-dismiss="modal" style="color: white; opacity: 1;">&times;</button>
                <h4 class="modal-title">✅ قبول التسجيل وإرسال الرسائل</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="approveWasel">
                <p class="text-muted">اختر الرسائل التي تريد إرسالها للمشترك:</p>
                
                <div class="checkbox" style="background: #f8f9fa; padding: 10px; border-radius: 8px; margin-bottom: 10px;">
                    <label style="font-weight: 600;">
                        <input type="checkbox" id="msg_registration" checked> 
                        📝 رسالة تأكيد التسجيل
                        <small class="text-muted" style="display: block; margin-right: 25px;">رسالة تأكيد استلام طلب التسجيل</small>
                    </label>
                </div>
                
                <div class="checkbox" style="background: #f8f9fa; padding: 10px; border-radius: 8px; margin-bottom: 10px;">
                    <label style="font-weight: 600;">
                        <input type="checkbox" id="msg_acceptance" checked> 
                        🎉 رسالة القبول مع الصورة
                        <small class="text-muted" style="display: block; margin-right: 25px;">صورة القبول ورسالة مبروك تم قبولك</small>
                    </label>
                </div>
                
                <div class="checkbox" style="background: #f8f9fa; padding: 10px; border-radius: 8px; margin-bottom: 10px;">
                    <label style="font-weight: 600;">
                        <input type="checkbox" id="msg_badge" checked> 
                        🎫 رسالة البادج/QR
                        <small class="text-muted" style="display: block; margin-right: 25px;">رابط البادج مع كود QR للدخول</small>
                    </label>
                </div>
                
                <div class="text-center" style="margin-top: 15px;">
                    <button type="button" class="btn btn-xs btn-link" onclick="$('#msg_registration, #msg_acceptance, #msg_badge').prop('checked', true)">تحديد الكل</button>
                    |
                    <button type="button" class="btn btn-xs btn-link text-danger" onclick="$('#msg_registration, #msg_acceptance, #msg_badge').prop('checked', false)">إلغاء الكل</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-success" onclick="confirmApprove()">
                    ✅ قبول وإرسال الرسائل
                </button>
            </div>
        </div>
    </div>
    </div>
</div>

<!-- Rounds Settings Modal -->


<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
$.fn.dataTable.ext.errMode = 'none';

$(document).ready(function() {
    $('#dataTable').DataTable({
        paging: true,
        ordering: true,
        order: [[0, 'desc']],
        pageLength: 25,
        language: {
            lengthMenu: "عرض _MENU_ سجلات",
            zeroRecords: "لا توجد بيانات",
            info: "عرض _PAGE_ من _PAGES_",
            infoEmpty: "لا توجد سجلات",
            infoFiltered: "(تم تصفيتها من _MAX_)",
            search: "بحث:",
            paginate: { first: "الأول", last: "الأخير", next: "التالي", previous: "السابق" }
        }
    });
    
    // Settings forms
    $('#bannerForm, #frameForm').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        var formData = new FormData(this);
        var btn = form.find('button[type="submit"]');
        var originalText = btn.text();
        btn.prop('disabled', true).text('جاري التحميل...');
        
        $.ajax({
            url: 'admin/update_settings.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(response) {
                btn.prop('disabled', false).text(originalText);
                if (response && response.success) {
                    alert('✅ تم التحديث بنجاح');
                    location.reload();
                } else {
                    alert('❌ ' + (response && response.message ? response.message : 'حدث خطأ غير معروف'));
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).text(originalText);
                console.log('Error:', status, error);
                console.log('Response:', xhr.responseText);
                alert('❌ حدث خطأ في الاتصال: ' + error);
            }
        });
    });
});

function applyFilters() {
    var filterValue = $('#filterValue').val();
    var statusFilter = $('#statusFilter').val();
    window.location.href = '?filterValue=' + filterValue + '&status=' + statusFilter;
}

function showImage(src, title) {
    $('#modalImage').attr('src', src);
    $('#imageModalTitle').text(title);
    $('#imageModal').modal('show');
}

function approveRegistration(wasel, savedPrefs) {
    // Quick approve - use default preferences
    var prefs = savedPrefs || {send_registration: 0, send_acceptance: 1, send_badge: 1, send_qr_only: 0};
    
    if (!confirm('هل تريد قبول هذا التسجيل؟')) return;
    
    var row = $('#row_' + wasel);
    row.css('opacity', '0.5');
    
    $.ajax({
        url: 'approve_registration.php',
        type: 'POST',
        data: { 
            action: 'approve', 
            wasel: wasel,
            send_registration: prefs.send_registration || 0,
            send_acceptance: prefs.send_acceptance || 1,
            send_badge: prefs.send_badge || 1,
            send_qr_only: prefs.send_qr_only || 0
        },
        dataType: 'json',
        success: function(response) {
            row.css('opacity', '1');
            if (response.success) {
                updateRowStatus(row, wasel, 'approved');
                showToast('✅ ' + response.message, 'success');
            } else {
                showToast('❌ ' + (response.message || 'حدث خطأ'), 'error');
            }
        },
        error: function(xhr) {
            row.css('opacity', '1');
            console.error(xhr.responseText);
            showToast('❌ حدث خطأ في الاتصال', 'error');
        }
    });
}



// Global settings from PHP
var CURRENT_ADMIN_SETTINGS = {
    send_badge: <?= $globalMsgPrefs['send_badge'] ?? 1 ?>,
    send_qr_only: <?= $globalMsgPrefs['send_qr_only'] ?? 0 ?>
};

// Open custom approval modal
function openApproveModal(wasel, savedPrefs, status) {
    $('#approveWasel').val(wasel);
    
    var prefs = savedPrefs || {};
    
    // For pending requests, ALWAYS enforce current admin settings for badge/QR
    // This overrides any saved history or defaults
    if (status === 'pending' || !savedPrefs) {
        prefs.send_badge = CURRENT_ADMIN_SETTINGS.send_badge;
        prefs.send_qr_only = CURRENT_ADMIN_SETTINGS.send_qr_only;
        
        // Ensure defaults for others if missing
        if (typeof prefs.send_registration === 'undefined') prefs.send_registration = 0;
        if (typeof prefs.send_acceptance === 'undefined') prefs.send_acceptance = 1;
    }
    
    $('#opt_registration').prop('checked', prefs.send_registration == 1);
    $('#opt_acceptance').prop('checked', prefs.send_acceptance == 1);
    $('#opt_badge').prop('checked', prefs.send_badge == 1);
    $('#opt_qr_only').prop('checked', prefs.send_qr_only == 1);
    
    $('#approveModal').modal('show');
}

// Confirm custom approval with selected options
function confirmCustomApproval() {
    var wasel = $('#approveWasel').val();
    var sendRegistration = $('#opt_registration').is(':checked') ? 1 : 0;
    var sendAcceptance = $('#opt_acceptance').is(':checked') ? 1 : 0;
    var sendBadge = $('#opt_badge').is(':checked') ? 1 : 0;
    var sendQrOnly = $('#opt_qr_only').is(':checked') ? 1 : 0;
    
    $('#approveModal').modal('hide');
    
    var row = $('#row_' + wasel);
    row.css('opacity', '0.5');
    
    $.ajax({
        url: 'approve_registration.php',
        type: 'POST',
        data: { 
            action: 'approve', 
            wasel: wasel,
            send_registration: sendRegistration,
            send_acceptance: sendAcceptance,
            send_badge: sendBadge,
            send_qr_only: sendQrOnly
        },
        dataType: 'json',
        success: function(response) {
            row.css('opacity', '1');
            if (response.success) {
                var msg = '✅ ' + response.message;
                if (response.messages_sent) {
                    msg += ' | 📱 تم إرسال ' + response.messages_sent + ' رسالة';
                }
                updateRowStatus(row, wasel, 'approved');
                showToast(msg, 'success');
            } else {
                showToast('❌ ' + (response.message || 'حدث خطأ'), 'error');
            }
        },
        error: function(xhr) {
            row.css('opacity', '1');
            console.error(xhr.responseText);
            showToast('❌ حدث خطأ في الاتصال', 'error');
        }
    });
}

// Legacy confirmApprove for backwards compatibility
function confirmApprove() {
    confirmCustomApproval();
}

function rejectRegistration(wasel) {
    $('#rejectWasel').val(wasel);
    $('#rejectReason').val('');
    $('#rejectModal').modal('show');
}

function confirmReject() {
    var wasel = $('#rejectWasel').val();
    var reason = $('#rejectReason').val();
    var allowReregister = $('#rejectAllowReregister').is(':checked') ? '1' : '0';
    
    $('#rejectModal').modal('hide');
    
    var row = $('#row_' + wasel);
    row.css('opacity', '0.5');
    
    $.ajax({
        url: 'approve_registration.php',
        type: 'POST',
        data: { action: 'reject', wasel: wasel, reason: reason, allow_reregister: allowReregister },
        dataType: 'json',
        success: function(response) {
            row.css('opacity', '1');
            if (response.success) {
                updateRowStatus(row, wasel, 'rejected');
                showToast('✅ ' + response.message, 'success');
            } else {
                showToast('❌ ' + (response.message || 'حدث خطأ'), 'error');
            }
        },
        error: function(xhr) {
            row.css('opacity', '1');
            console.error(xhr.responseText);
            showToast('❌ حدث خطأ في الاتصال', 'error');
        }
    });
}



function resendMessage(wasel) {
    if (!confirm('إعادة إرسال رسالة الترحيب؟')) return;
    
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'resend_message', wasel: wasel, message_type: 'welcome' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert('✅ ' + response.message);
            } else {
                alert('❌ ' + (response.message || 'فشل الإرسال'));
            }
        },
        error: function() {
            alert('❌ حدث خطأ');
        }
    });
}

// Undo Rejection
function undoRejection(wasel) {
    if (!confirm('هل أنت متأكد من التراجع عن رفض هذا المشترك وإعادته لحالة قيد المراجعة؟')) return;
    
    var row = $('#row_' + wasel);
    row.css('opacity', '0.5');
    
    $.ajax({
        url: 'approve_registration.php',
        type: 'POST',
        data: { action: 'undoreject', wasel: wasel },
        dataType: 'json',
        success: function(response) {
            row.css('opacity', '1');
            if (response.success) {
                // Refresh for undo to restore all complex pending buttons easily
                location.reload(); 
            } else {
                showToast('❌ ' + (response.message || 'حدث خطأ'), 'error');
            }
        },
        error: function(xhr) {
            row.css('opacity', '1');
            showToast('❌ حدث خطأ في الاتصال', 'error');
        }
    });
}

// Edit Rejection Reason
function editRejectionReason(wasel, currentReason) {
    var newReason = prompt('تعديل سبب الرفض (سيتم إرسال رسالة واتساب جديدة):', currentReason);
    if (newReason === null || newReason === currentReason) return;
    
    $.ajax({
        url: 'approve_registration.php',
        type: 'POST',
        data: { action: 'editreject', wasel: wasel, reason: newReason },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showToast('✅ ' + response.message, 'success');
                // Refresh to update the onclick attribute with the new reason
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                showToast('❌ ' + (response.message || 'حدث خطأ'), 'error');
            }
        },
        error: function(xhr) {
            showToast('❌ حدث خطأ في الاتصال', 'error');
        }
    });
}

function deleteRegistration(wasel) {
    if (!confirm('⚠️ هل أنت متأكد من حذف هذا التسجيل نهائياً؟\n\nلا يمكن التراجع عن هذا الإجراء!')) return;
    
    $.ajax({
        url: 'process.php',
        type: 'POST',
        data: { action: 'remove', file: 'data', wasel: wasel },
        success: function() {
            // Remove row with animation
            var row = $('#row_' + wasel);
            var table = $('#dataTable').DataTable();
            table.row(row).remove().draw(false);
            updateStatCounters('delete');
            showToast('✅ تم الحذف نهائياً', 'success');
        },
        error: function() {
            showToast('❌ حدث خطأ', 'error');
        }
    });
}

// Inject data via PHP to bypass 403 Forbidden
var registrationsData = <?php echo json_encode($inputs, JSON_UNESCAPED_UNICODE); ?>;

function exportExcel() {
    window.location.href = 'admin/export_members.php?download=1&source=dashboard&format=csv';
}

function saveWhatsAppMessages() {
    var registrationMsg = $('#registration_message').val();
    var acceptanceMsg = $('#acceptance_message').val();
    
    $.ajax({
        url: 'admin/save_whatsapp_messages.php',
        type: 'POST',
        data: {
            registration_message: registrationMsg,
            acceptance_message: acceptanceMsg
        },
        dataType: 'json',
        success: function(response) {
            if (response && response.success) {
                $('#whatsappMsgResult').removeClass('alert-danger').addClass('alert-success')
                    .html('✅ تم حفظ الرسائل بنجاح').fadeIn();
                setTimeout(function() {
                    $('#whatsappMsgResult').fadeOut();
                }, 3000);
            } else {
                $('#whatsappMsgResult').removeClass('alert-success').addClass('alert-danger')
                    .html('❌ ' + (response && response.message ? response.message : 'حدث خطأ')).fadeIn();
            }
        },
        error: function() {
            $('#whatsappMsgResult').removeClass('alert-success').addClass('alert-danger')
                .html('❌ حدث خطأ في الاتصال').fadeIn();
        }
    });
}

function resendApproval(wasel) {
    if (!confirm('هل تريد إعادة إرسال رسالة القبول وباج الدخول؟')) return;
    
    $.ajax({
        url: 'admin/resend_approval.php',
        type: 'POST',
        data: { wasel: wasel },
        dataType: 'json',
        beforeSend: function() {
            alert('جاري إرسال الرسائل...');
        },
        success: function(response) {
            console.log('Response:', response);
            if (response && response.success) {
                var msg = '✅ تم الإرسال!\n\n';
                msg += '📷 صورة القبول: ' + (response.results && response.results.acceptance && response.results.acceptance.success ? '✅' : '❌') + '\n';
                msg += '🎫 الباج: ' + (response.results && response.results.badge && response.results.badge.success ? '✅' : '❌');
                if (response.results && response.results.badge && !response.results.badge.success && response.results.badge.error) {
                    msg += '\n\n❌ خطأ الباج: ' + response.results.badge.error;
                }
                if (response.badge_url) {
                    msg += '\n\n🔗 URL: ' + response.badge_url;
                }
                alert(msg);
            } else {
                alert('❌ ' + (response && response.error ? response.error : 'حدث خطأ'));
            }
        },
        error: function(xhr, status, error) {
            console.error('Error:', xhr.responseText);
            alert('❌ حدث خطأ في الاتصال: ' + error + '\n\nتفاصيل: ' + xhr.responseText);
        }
    });
}

// Badge Control Functions
function loadBadgeStatus() {
    $.get('admin/api/badge_control.php?action=get_status', function(data) {
        if (data.success) {
            updateBadgeButton(data.badges_enabled);
            updateQrButton(data.qr_only_mode);
        }
    });
}

function updateBadgeButton(enabled) {
    var btn = $('#toggleBadgesBtn');
    if (enabled) {
        btn.removeClass('btn-success').addClass('btn-danger');
        btn.html('🔒 إيقاف الباجات');
    } else {
        btn.removeClass('btn-danger').addClass('btn-success');
        btn.html('🔓 تفعيل الباجات');
    }
}

function updateQrButton(enabled) {
    var btn = $('#toggleQrBtn');
    if (enabled) {
        btn.removeClass('btn-secondary').addClass('btn-primary');
        btn.html('📱 وضع QR فقط (مفعل)');
    } else {
        btn.removeClass('btn-primary').addClass('btn-secondary');
        btn.html('📱 وضع QR فقط (متوقف)');
    }
}

function toggleBadges() {
    if (!confirm('هل أنت متأكد من تغيير حالة الباجات؟')) return;
    
    $.post('admin/api/badge_control.php', { action: 'toggle_badges' }, function(data) {
        if (data.success) {
            updateBadgeButton(data.badges_enabled);
            // DEBUG removed after confirmation
            // alert("تم تغيير الحالة بنجاح إلى: " + (data.badges_enabled ? "مفعل (True)" : "متوقف (False)"));
        } else {
            alert('خطأ: ' + data.message);
        }
    });
}

function toggleQrMode() {
    $.post('admin/api/badge_control.php', { action: 'toggle_qr_mode' }, function(data) {
        if (data.success) {
            updateQrButton(data.qr_only_mode);
        } else {
            alert('خطأ: ' + data.message);
        }
    });
}

function resetEntries() {
    if (!confirm('⚠️ هل أنت متأكد؟\n\nسيتم إعادة تعيين حالة الدخول لجميع المشتركين.\nهذا الإجراء غير قابل للتراجع!')) return;
    
    $.post('admin/api/badge_control.php', { action: 'reset_entries' }, function(data) {
        if (data.success) {
            alert(data.message);
            loadEntryStats();
        } else {
            alert('خطأ: ' + data.message);
        }
    });
}

function loadEntryStats() {
    $.get('admin/api/entry_stats.php', function(data) {
        if (data.success) {
            $('#enteredCount').text(data.entered);
            $('#remainingCount').text(data.remaining);
        }
    });
}

// Reset Participant's Specific Round
function resetRoundParticipant(wasel, roundId, participantName) {
    if (!confirm('هل أنت متأكد من إعادة تعيين هذه الجولة للمتسابق: ' + participantName + '؟')) return;
    
    $.ajax({
        url: 'admin/ajax_reset_user_round.php',
        type: 'POST',
        data: { wasel: wasel, round_id: roundId },
        dataType: 'json',
        beforeSend: function() {
            // Optional visual feedback could go here
        },
        success: function(response) {
            if (response && response.success) {
                showToast('✅ ' + response.message, 'success');
            } else {
                showToast('❌ ' + (response && response.message ? response.message : 'حدث خطأ أثناء إعادة التعيين'), 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('Reset Error:', xhr.responseText);
            showToast('❌ حدث خطأ في الاتصال: ' + error, 'error');
        }
    });
}

// Send Activation WhatsApp for imported members
function sendActivation(wasel) {
    if (!confirm('هل تريد تفعيل حساب هذا العضو وإرسال رسالة التفعيل عبر واتساب؟')) return;
    
    var btn = event.target;
    var originalHtml = $(btn).html();
    $(btn).prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...');
    
    $.ajax({
        url: 'admin/api/send_activation.php',
        type: 'POST',
        data: { wasel: wasel },
        dataType: 'json',
        success: function(response) {
            if (response && response.success) {
                $(btn).removeClass('btn-warning').addClass('btn-success').html('<i class="fa-solid fa-check"></i> تم التفعيل');
                showToast('✅ ' + response.message, 'success');
            } else {
                showToast('❌ ' + (response && response.error ? response.error : 'حدث خطأ'), 'error');
                $(btn).prop('disabled', false).html(originalHtml);
            }
        },
        error: function(xhr, status, error) {
            showToast('❌ خطأ في الاتصال: ' + error, 'error');
            $(btn).prop('disabled', false).html(originalHtml);
        }
    });
}

// ============================================
// DYNAMIC UI UPDATE HELPERS (No Page Refresh)
// ============================================

// Update row status after approve/reject without reload
function updateRowStatus(row, wasel, newStatus) {
    var statusColors = { 'approved': '#28a745', 'rejected': '#dc3545', 'pending': '#ffc107' };
    var statusLabels = { 'approved': '✅ مقبول', 'rejected': '❌ مرفوض', 'pending': '⏳ قيد المراجعة' };
    var statusTextColors = { 'approved': '#fff', 'rejected': '#fff', 'pending': '#000' };
    
    // Update status badge (4th column, index 3)
    var statusCell = row.find('td').eq(3);
    statusCell.html(
        '<span class="status-badge" style="background:' + statusColors[newStatus] + 
        ';color:' + statusTextColors[newStatus] + ';padding:5px 10px;border-radius:15px;font-size:12px;">' + 
        statusLabels[newStatus] + '</span>'
    );
    
    // Update action buttons (last column)
    var actionsCell = row.find('td:last');
    if (newStatus === 'approved') {
        actionsCell.html(
            '<div class="btn-group-vertical btn-group-sm">' +
            '<button class="btn btn-success btn-sm" onclick="resendApproval(\'' + wasel + '\')">' +
            '🔄 إعادة إرسال القبول</button>' +
            '<a href="print_qr.php?wasel=' + wasel + '" target="_blank" class="btn btn-info btn-sm" style="margin-top:3px;">🖨️ طباعة QR</a>' +
            <?php if ($canDelete): ?>
            '<button class="btn btn-danger btn-sm" onclick="deleteRegistration(\'' + wasel + '\')" style="margin-top:3px;">🗑️ حذف</button>' +
            <?php endif; ?>
            '</div>'
        );
    } else if (newStatus === 'rejected') {
        actionsCell.html(
            '<div class="btn-group-vertical btn-group-sm">' +
            '<button class="btn btn-warning btn-sm" style="margin-bottom: 3px;" onclick="undoRejection(\'' + wasel + '\')">' +
            '↩️ تراجع عن الرفض</button>' +
            '<button class="btn btn-info btn-sm" style="margin-bottom: 3px;" onclick="editRejectionReason(\'' + wasel + '\', \'\')">' +
            '📝 تعديل سبب الرفض</button>' +
            <?php if ($canDelete): ?>
            '<button class="btn btn-danger btn-sm" onclick="deleteRegistration(\'' + wasel + '\')" style="margin-top:5px;">🗑️ حذف</button>' +
            <?php endif; ?>
            '</div>'
        );
    }
    
    // Flash animation on the row
    row.css('transition', 'background 0.5s');
    row.css('background', newStatus === 'approved' ? '#d4edda' : (newStatus === 'rejected' ? '#f8d7da' : '#fff3cd'));
    setTimeout(function() { row.css('background', ''); }, 2000);
    
    // Update stat counters
    updateStatCounters(newStatus === 'approved' ? 'approve' : 'reject');
}

// Update the stat counter cards dynamically
function updateStatCounters(action) {
    // Get current values from the stat cards
    var statCards = $('.stat-card h2');
    if (statCards.length < 4) return;
    
    var totalEl = statCards.eq(0);
    var pendingEl = statCards.eq(1);
    var approvedEl = statCards.eq(2);
    var rejectedEl = statCards.eq(3);
    
    var total = parseInt(totalEl.text().replace(/,/g, '')) || 0;
    var pending = parseInt(pendingEl.text().replace(/,/g, '')) || 0;
    var approved = parseInt(approvedEl.text().replace(/,/g, '')) || 0;
    var rejected = parseInt(rejectedEl.text().replace(/,/g, '')) || 0;
    
    if (action === 'approve') {
        pending = Math.max(0, pending - 1);
        approved++;
    } else if (action === 'reject') {
        pending = Math.max(0, pending - 1);
        rejected++;
    } else if (action === 'delete') {
        total = Math.max(0, total - 1);
        totalEl.text(total.toLocaleString());
    }
    
    pendingEl.text(pending.toLocaleString());
    approvedEl.text(approved.toLocaleString());
    rejectedEl.text(rejected.toLocaleString());
}

// Toast notification instead of alert()
function showToast(message, type) {
    // Remove existing toasts
    $('.dashboard-toast').remove();
    
    var bgColor = type === 'success' ? 'linear-gradient(135deg, #28a745, #20c997)' : 
                  type === 'error' ? 'linear-gradient(135deg, #dc3545, #c82333)' : 
                  'linear-gradient(135deg, #ffc107, #ff9800)';
    
    var toast = $('<div class="dashboard-toast" style="' +
        'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99999;' +
        'background:' + bgColor + ';color:#fff;padding:15px 30px;border-radius:12px;' +
        'box-shadow:0 8px 30px rgba(0,0,0,0.3);font-size:16px;font-family:Cairo,sans-serif;' +
        'min-width:300px;text-align:center;opacity:0;transition:all 0.4s ease;">' +
        message + '</div>');
    
    $('body').append(toast);
    
    // Animate in
    setTimeout(function() { toast.css('opacity', '1'); }, 50);
    
    // Auto-remove after 4 seconds
    setTimeout(function() {
        toast.css({ 'opacity': '0', 'transform': 'translateX(-50%) translateY(-20px)' });
        setTimeout(function() { toast.remove(); }, 400);
    }, 4000);
}

// Load on page ready
$(document).ready(function() {
    loadBadgeStatus();
    loadEntryStats();
});
</script>
</body>
</html>
