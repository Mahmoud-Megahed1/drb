<?php
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

$currentUser = $_SESSION['user'];
$isRoot = (isset($currentUser->username) && $currentUser->username === 'root');

if (!$isRoot) {
    header('location:../dashboard.php');
    exit;
}

// Load settings
function loadSettings() {
    $file_path = 'data/registration_settings.json';
    if (!file_exists($file_path)) {
        $default = [
            'max_registrations' => 0,
            'max_tickets' => 0,
            'max_regular_tickets' => 0,
            'max_vip_tickets' => 0,
            'max_offer_tickets' => 0,
            'is_open' => true,
            'closed_message' => 'عذراً، انتهت صلاحية التسجيل',
            'support_number' => '9647736000096',
            'championship_date' => '',
            'championship_start_time' => '18:00',
            'time_slot_interval' => 10,
            'scheduling_enabled' => false
        ];
        file_put_contents($file_path, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $default;
    }
    
    $json_data = file_get_contents($file_path);
    $settings = json_decode($json_data, true);
    return is_array($settings) ? $settings : [];
}

// Save settings
function saveSettings($settings) {
    $file_path = 'data/registration_settings.json';
    file_put_contents($file_path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Get current registration count
function getRegistrationCount() {
    $file_path = 'data/data.json';
    if (!file_exists($file_path)) {
        return 0;
    }
    $json_data = file_get_contents($file_path);
    $data = json_decode($json_data, true);
    return is_array($data) ? count($data) : 0;
}

// Get current ticket count
function getTicketCount() {
    $file_path = 'data/data.json';
    if (!file_exists($file_path)) {
        return ['total' => 0, 'regular' => 0, 'vip' => 0, 'offer' => 0];
    }
    $json_data = file_get_contents($file_path);
    $data = json_decode($json_data, true);
    if (!is_array($data)) {
        return ['total' => 0, 'regular' => 0, 'vip' => 0, 'offer' => 0];
    }
    
    $totalRegular = 0;
    $totalVip = 0;
    $totalOffer = 0;
    
    foreach ($data as $item) {
        $regular = $item['regular_tickets'] ?? $item['tickets_count'] ?? 0;
        $vip = $item['vip_tickets'] ?? 0;
        $offer = $item['offer_tickets'] ?? 0;
        
        $totalRegular += $regular;
        $totalVip += $vip;
        $totalOffer += $offer;
    }
    
    return [
        'total' => $totalRegular + $totalVip + $totalOffer,
        'regular' => $totalRegular,
        'vip' => $totalVip,
        'offer' => $totalOffer
    ];
}

// Blacklist Functions
function loadBlacklist() {
    $file_path = 'data/blacklist.json';
    if (!file_exists($file_path)) {
        return ['phones' => [], 'plates' => []];
    }
    $data = json_decode(file_get_contents($file_path), true) ?? ['phones' => [], 'plates' => []];
    
    // Auto-migrate old format (string arrays) to new format (object arrays)
    $migrated = false;
    foreach (['phones', 'plates'] as $key) {
        if (!empty($data[$key]) && isset($data[$key][0]) && is_string($data[$key][0])) {
            $newArr = [];
            foreach ($data[$key] as $val) {
                $newArr[] = ['value' => $val, 'reason' => '', 'date' => date('Y-m-d H:i'), 'by' => 'admin'];
            }
            $data[$key] = $newArr;
            $migrated = true;
        }
    }
    if ($migrated) saveBlacklist($data);
    return $data;
}

function saveBlacklist($data) {
    file_put_contents('data/blacklist.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Helper: get value from blacklist entry (works with both old string and new object format)
function getBlacklistValue($entry) {
    return is_array($entry) ? ($entry['value'] ?? '') : (string)$entry;
}

// Participation Types Functions
function getParticipationTypes($settings) {
    return $settings['participation_types'] ?? [
        ['id' => 'free_show', 'label' => 'المشاركة بالاستعراض الحر', 'enabled' => true],
        ['id' => 'special_car', 'label' => 'المشاركة كسيارة مميزة فقط بدون استعراض', 'enabled' => true],
        ['id' => 'burnout', 'label' => 'المشاركة بفعالية Burnout', 'enabled' => true],
        ['id' => 'motorbikes', 'label' => 'دراجات', 'enabled' => true]
    ];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $settings = loadSettings();
    $blacklist = loadBlacklist();
    
    switch ($_POST['action']) {
        // ... Existing cases ...
        case 'update_max': // Keep existing
            $max = intval($_POST['max_registrations']);
            $settings['max_registrations'] = $max;
            saveSettings($settings);
            echo json_encode(['success' => true, 'message' => 'تم تحديث الحد الأقصى بنجاح']);
            exit;
            
        case 'update_max_tickets': // Keep existing
             $maxTickets = intval($_POST['max_tickets']);
            $settings['max_tickets'] = $maxTickets;
            saveSettings($settings);
            echo json_encode(['success' => true, 'message' => 'تم تحديث عدد التذاكر المتاحة بنجاح']);
            exit;
            
        case 'update_ticket_limits': // Keep existing
            $settings['max_regular_tickets'] = intval($_POST['max_regular'] ?? 0);
            $settings['max_vip_tickets'] = intval($_POST['max_vip'] ?? 0);
            $settings['max_offer_tickets'] = intval($_POST['max_offer'] ?? 0);
            saveSettings($settings);
            echo json_encode(['success' => true, 'message' => 'تم تحديث حدود التذاكر بنجاح']);
            exit;

        case 'toggle_registration': // Keep existing
             $currentStatus = isset($settings['is_open']) ? (bool)$settings['is_open'] : true;
            $settings['is_open'] = !$currentStatus;
            saveSettings($settings);
            
            // Sync frame settings
            $frameSettingsFile = 'data/frame_settings.json';
            if (file_exists($frameSettingsFile)) {
                $frameSettings = json_decode(file_get_contents($frameSettingsFile), true);
                if ($frameSettings) {
                    $frameSettings['form_settings']['is_open'] = $settings['is_open'];
                    file_put_contents($frameSettingsFile, json_encode($frameSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
            
            $status = $settings['is_open'] ? 'مفتوح' : 'مغلق';
            echo json_encode(['success' => true, 'message' => 'التسجيل الآن ' . $status, 'is_open' => $settings['is_open']]);
            exit;

        case 'update_message': // Keep existing
            $message = trim($_POST['message']);
            $settings['closed_message'] = $message;
            saveSettings($settings);
            echo json_encode(['success' => true, 'message' => 'تم تحديث الرسالة بنجاح']);
            exit;

        case 'update_group_link':
            $link = trim($_POST['group_link']);
            $settings['group_link'] = $link;
            saveSettings($settings);
            echo json_encode(['success' => true, 'message' => 'تم تحديث رابط المجموعة بنجاح']);
            exit;

        case 'update_support_number':
            $number = trim($_POST['support_number']);
            // Keep only numbers for clean storage
            $cleanNumber = preg_replace('/[^0-9]/', '', $number);
            $settings['support_number'] = $cleanNumber;
            saveSettings($settings);
            echo json_encode(['success' => true, 'message' => 'تم تحديث رقم الدعم بنجاح']);
            exit;

        case 'get_status': // Keep existing
             $count = getRegistrationCount();
            $ticketCounts = getTicketCount();
            echo json_encode([
                'success' => true,
                'settings' => $settings,
                'current_count' => $count,
                'current_tickets' => $ticketCounts['total'],
                'current_regular' => $ticketCounts['regular'],
                'current_vip' => $ticketCounts['vip'],
                'current_offer' => $ticketCounts['offer']
            ]);
            exit;

        case 'reset_counter': // Keep existing
             // Smart Reset: Save members, archive data, clear registrations
            $dataFile = 'data/data.json';
            $membersFile = 'data/members.json';
            $archiveDir = 'data/archives/';
            
            if (!file_exists($archiveDir)) {
                mkdir($archiveDir, 0777, true);
            }
            
            $currentData = [];
            if (file_exists($dataFile)) {
                $currentData = json_decode(file_get_contents($dataFile), true) ?? [];
            }
            
            $members = [];
            if (file_exists($membersFile)) {
                $members = json_decode(file_get_contents($membersFile), true) ?? [];
            }
            
            $newMembersCount = 0;
            foreach ($currentData as $reg) {
                $code = $reg['registration_code'] ?? null;
                
                // Only save approved registrations
                if (!$code || ($reg['status'] ?? '') !== 'approved') {
                    continue;
                }
                
                if (!isset($members[$code])) {
                    // New member - save complete data
                    $members[$code] = [
                        'registration_code' => $code,
                        'full_name' => $reg['full_name'] ?? $reg['name'] ?? '',
                        'phone' => $reg['phone'] ?? '',
                        'country_code' => $reg['country_code'] ?? '+964',
                        'governorate' => $reg['governorate'] ?? '',
                        'car_type' => $reg['car_type'] ?? '',
                        'car_year' => $reg['car_year'] ?? '',
                        'car_color' => $reg['car_color'] ?? '',
                        'engine_size' => $reg['engine_size'] ?? '',
                        'plate_letter' => $reg['plate_letter'] ?? '',
                        'plate_number' => $reg['plate_number'] ?? '',
                        'plate_governorate' => $reg['plate_governorate'] ?? '',
                        'participation_type' => $reg['participation_type'] ?? '',
                        'images' => $reg['images'] ?? [],
                        'first_registered' => $reg['registration_date'] ?? date('Y-m-d H:i:s'),
                        'championships_participated' => 1,
                        'last_active' => date('Y-m-d H:i:s')
                    ];
                    $newMembersCount++;
                } else {
                    // Existing member - update with latest data
                    $members[$code]['championships_participated'] = ($members[$code]['championships_participated'] ?? 0) + 1;
                    $members[$code]['last_active'] = date('Y-m-d H:i:s');
                    $members[$code]['full_name'] = $reg['full_name'] ?? $members[$code]['full_name'];
                    $members[$code]['phone'] = $reg['phone'] ?? $members[$code]['phone'];
                    $members[$code]['country_code'] = $reg['country_code'] ?? $members[$code]['country_code'] ?? '+964';
                    $members[$code]['governorate'] = $reg['governorate'] ?? $members[$code]['governorate'];
                    $members[$code]['car_type'] = $reg['car_type'] ?? $members[$code]['car_type'];
                    $members[$code]['car_year'] = $reg['car_year'] ?? $members[$code]['car_year'] ?? '';
                    $members[$code]['car_color'] = $reg['car_color'] ?? $members[$code]['car_color'] ?? '';
                    $members[$code]['engine_size'] = $reg['engine_size'] ?? $members[$code]['engine_size'] ?? '';
                    $members[$code]['plate_letter'] = $reg['plate_letter'] ?? $members[$code]['plate_letter'] ?? '';
                    $members[$code]['plate_number'] = $reg['plate_number'] ?? $members[$code]['plate_number'] ?? '';
                    $members[$code]['plate_governorate'] = $reg['plate_governorate'] ?? $members[$code]['plate_governorate'] ?? '';
                    $members[$code]['participation_type'] = $reg['participation_type'] ?? $members[$code]['participation_type'] ?? '';
                    // Update images with latest
                    if (!empty($reg['images']) && is_array($reg['images'])) {
                        $members[$code]['images'] = $reg['images'];
                    }
                }
            }
            
            file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            if (count($currentData) > 0) {
                $archiveFile = $archiveDir . 'championship_' . date('Y-m-d_H-i-s') . '.json';
                $archiveData = [
                    'date' => date('Y-m-d H:i:s'),
                    'total_registrations' => count($currentData),
                    'data' => $currentData
                ];
                file_put_contents($archiveFile, json_encode($archiveData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            
            file_put_contents($dataFile, '[]');
            
            $settings['is_open'] = true;
            saveSettings($settings);
            
            $frameSettingsFile = 'data/frame_settings.json';
            if (file_exists($frameSettingsFile)) {
                $frameSettings = json_decode(file_get_contents($frameSettingsFile), true);
                if ($frameSettings) {
                    $frameSettings['form_settings']['is_open'] = true;
                    file_put_contents($frameSettingsFile, json_encode($frameSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
            
            $totalMembers = count($members);
            
            // Log to AdminLogger
            require_once dirname(__DIR__) . '/include/AdminLogger.php';
            $adminLogger = new AdminLogger();
            $adminLogger->log(
                AdminLogger::ACTION_CHAMPIONSHIP_RESET,
                $currentUser->username ?? 'unknown',
                'إعادة تعيين البطولة - أرشفة ' . count($currentData) . ' تسجيل وحفظ ' . $newMembersCount . ' عضو جديد',
                [
                    'archived_count' => count($currentData),
                    'new_members_saved' => $newMembersCount,
                    'total_members_after' => $totalMembers
                ]
            );
            
            echo json_encode([
                'success' => true, 
                'message' => "تم إعادة التعيين بنجاح!\n\n✅ تم حفظ $newMembersCount عضو جديد\n📊 إجمالي الأعضاء: $totalMembers\n📁 تم أرشفة " . count($currentData) . " تسجيل"
            ]);
            exit;

        case 'delete_all_data': // Keep existing
             $dataFile = 'data/data.json';
            $backupFile = 'data/data_backup_' . date('Y-m-d_H-i-s') . '.json';
            if (file_exists($dataFile)) {
                copy($dataFile, $backupFile);
            }
            file_put_contents($dataFile, '[]');
            echo json_encode(['success' => true, 'message' => 'تم حذف جميع البيانات (تم حفظ نسخة احتياطية)']);
            exit;

        // --- NEW HANDLERS ---
        
        case 'add_type':
            $label = trim($_POST['label']);
            $id = trim($_POST['id']);
            if (empty($id)) $id = 'type_' . time();
            
            $types = $settings['participation_types'] ?? [];
            $types[] = ['id' => $id, 'label' => $label, 'enabled' => true];
            $settings['participation_types'] = $types;
            saveSettings($settings);
            echo json_encode(['success' => true, 'message' => 'تم إضافة نوع المشاركة']);
            exit;
            
        case 'delete_type':
            $id = $_POST['id'];
            $types = $settings['participation_types'] ?? [];
            $settings['participation_types'] = array_values(array_filter($types, fn($t) => $t['id'] !== $id));
            saveSettings($settings);
            
            // Log to AdminLogger
            require_once dirname(__DIR__) . '/include/AdminLogger.php';
            $adminLogger = new AdminLogger();
            $adminLogger->log(
                AdminLogger::ACTION_SETTINGS_CHANGE,
                $currentUser->username ?? 'unknown',
                'حذف نوع مشاركة: ' . $id,
                ['type_id' => $id, 'action' => 'delete_type']
            );
            
            echo json_encode(['success' => true, 'message' => 'تم حذف النوع']);
            exit;
            
        case 'toggle_type':
            $id = $_POST['id'];
            $types = $settings['participation_types'] ?? [];
            foreach ($types as &$t) {
                if ($t['id'] === $id) $t['enabled'] = !($t['enabled'] ?? true);
            }
            $settings['participation_types'] = $types;
            saveSettings($settings);
            echo json_encode(['success' => true, 'message' => 'تم تغيير الحالة']);
            exit;
            
        case 'add_blacklist':
            $value = trim($_POST['value']);
            $reason = trim($_POST['reason'] ?? '');
            $type = $_POST['type']; // phone or plate
            
            $entry = [
                'value' => $value,
                'reason' => $reason,
                'date' => date('Y-m-d H:i'),
                'by' => $currentUser->username ?? 'admin'
            ];
            
            $key = ($type == 'phone') ? 'phones' : 'plates';
            // Check if already exists
            $exists = false;
            foreach ($blacklist[$key] as $existing) {
                if (getBlacklistValue($existing) === $value) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $blacklist[$key][] = $entry;
            }
            saveBlacklist($blacklist);
            
            // Log
            require_once dirname(__DIR__) . '/include/AdminLogger.php';
            $adminLogger = new AdminLogger();
            $adminLogger->log(
                AdminLogger::ACTION_SETTINGS_CHANGE,
                $currentUser->username ?? 'unknown',
                'إضافة للقائمة السوداء: ' . $value . ' (' . $type . ') - السبب: ' . ($reason ?: 'بدون'),
                ['value' => $value, 'type' => $type, 'reason' => $reason, 'action' => 'add_blacklist']
            );
            
            echo json_encode(['success' => true, 'message' => 'تمت الإضافة للقائمة السوداء']);
            exit;
            
        case 'remove_blacklist':
            $value = $_POST['value'];
            $type = $_POST['type'];
            $key = ($type == 'phone') ? 'phones' : 'plates';
            
            $blacklist[$key] = array_values(array_filter($blacklist[$key], function($entry) use ($value) {
                return getBlacklistValue($entry) !== $value;
            }));
            saveBlacklist($blacklist);
            
            // Log to AdminLogger
            require_once dirname(__DIR__) . '/include/AdminLogger.php';
            $adminLogger = new AdminLogger();
            $adminLogger->log(
                AdminLogger::ACTION_SETTINGS_CHANGE,
                $currentUser->username ?? 'unknown',
                'رفع حظر من القائمة السوداء: ' . $value . ' (' . $type . ')',
                ['value' => $value, 'type' => $type, 'action' => 'remove_blacklist']
            );
            
            echo json_encode(['success' => true, 'message' => 'تم رفع الحظر بنجاح']);
            exit;

        case 'update_schedule':
            $settings['championship_date'] = trim($_POST['championship_date'] ?? '');
            $settings['championship_start_time'] = trim($_POST['championship_start_time'] ?? '18:00');
            $settings['time_slot_interval'] = max(1, intval($_POST['time_slot_interval'] ?? 10));
            $settings['scheduling_enabled'] = !empty($_POST['scheduling_enabled']);
            saveSettings($settings);
            echo json_encode(['success' => true, 'message' => 'تم حفظ جدول البطولة بنجاح']);
            exit;

        case 'assign_all_times':
            date_default_timezone_set('Asia/Baghdad');
            $champDate = $settings['championship_date'] ?? '';
            $startTime = $settings['championship_start_time'] ?? '18:00';
            $interval = max(1, intval($settings['time_slot_interval'] ?? 10));

            if (empty($champDate) || empty($startTime)) {
                echo json_encode(['success' => false, 'message' => "يرجى تحديد تاريخ ووقت البطولة أولاً"]);
                exit;
            }

            $dataFile = 'data/data.json';
            if (!file_exists($dataFile)) {
                echo json_encode(['success' => false, 'message' => "لا يوجد ملف بيانات"]);
                exit;
            }

            $allData = json_decode(file_get_contents($dataFile), true) ?? [];
            $order = 0;
            $assignedCount = 0;

            foreach ($allData as &$reg) {
                if (($reg['status'] ?? '') === 'approved') {
                    $baseDateTime = new DateTime($champDate . ' ' . $startTime, new DateTimeZone('Asia/Baghdad'));
                    $baseDateTime->modify('+' . ($order * $interval) . ' minutes');

                    $reg['assigned_time'] = $baseDateTime->format('H:i');
                    $reg['assigned_date'] = $champDate;
                    $reg['assigned_order'] = $order + 1;
                    $reg['assigned_datetime'] = $baseDateTime->format('Y-m-d H:i');
                    $order++;
                    $assignedCount++;
                }
            }
            unset($reg);

            file_put_contents($dataFile, json_encode($allData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            echo json_encode(['success' => true, 'message' => "تم تخصيص مواعيد لـ " . $assignedCount . " مشترك بنجاح ✅"]);
            exit;
    }
}

$settings = loadSettings();
$blacklist = loadBlacklist();
$participationTypes = getParticipationTypes($settings);

// Update settings if types were missing (migration)
if (!isset($settings['participation_types'])) {
    $settings['participation_types'] = $participationTypes;
    saveSettings($settings);
}

$currentCount = getRegistrationCount();
$ticketCounts = getTicketCount();
$currentTickets = $ticketCounts['total'];
$currentRegular = $ticketCounts['regular'];
$currentVip = $ticketCounts['vip'];
$currentOffer = $ticketCounts['offer'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>إعدادات التسجيل</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; }
        .settings-card { 
            background: white; 
            border-radius: 10px; 
            padding: 25px; 
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 18px;
            padding: 10px 20px;
        }
        .big-number {
            font-size: 48px;
            font-weight: bold;
            color: #333;
        }
        .toggle-btn {
            padding: 15px 30px;
            font-size: 18px;
        }
        .count-display {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
        }
        .count-display h3 {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
        }
        .count-display .number {
            font-size: 60px;
            font-weight: bold;
            margin: 10px 0;
        }
        .danger-action {
            background: #fff5f5;
            border: 1px solid #ffcccc;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 10px;
        }
        .danger-action h5 {
            color: #d9534f;
            margin-bottom: 10px;
        }
        
        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
    </style>
</head>
<body>
<!-- Navbar -->
<?php include '../include/navbar.php'; ?>
<div class="container">
    <h2><i class="fa-solid fa-cogs"></i> إعدادات التسجيل</h2>
    <br>
    
    <div class="row">
        <!-- Current Registrations -->
        <div class="col-md-4">
            <div class="count-display">
                <h3>عدد التسجيلات</h3>
                <div class="number" id="currentCount"><?= $currentCount ?></div>
                <span id="maxDisplay">
                    <?php if ($settings['max_registrations'] > 0): ?>
                        من <?= $settings['max_registrations'] ?>
                    <?php else: ?>
                        (بدون حد أقصى)
                    <?php endif; ?>
                </span>
            </div>
        </div>
        
        <!-- Registration Status -->
        <div class="col-md-8">
            <div class="settings-card">
                <h4><i class="fa-solid fa-power-off"></i> حالة التسجيل</h4>
                <hr>
                <div class="text-center">
                    <p>
                        <span id="statusBadge" class="label status-badge <?= $settings['is_open'] ? 'label-success' : 'label-danger' ?>">
                            <?= $settings['is_open'] ? '<i class="fa-solid fa-check"></i> التسجيل مفتوح' : '<i class="fa-solid fa-times"></i> التسجيل مغلق' ?>
                        </span>
                    </p>
                    <br>
                    <button id="toggleBtn" class="btn toggle-btn <?= $settings['is_open'] ? 'btn-danger' : 'btn-success' ?>" onclick="toggleRegistration()">
                        <?= $settings['is_open'] ? 'إغلاق التسجيل' : 'فتح التسجيل' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Max Registrations -->
        <div class="col-md-12">
            <div class="settings-card">
                <h4><i class="fa-solid fa-sort-amount-up"></i> الحد الأقصى للتسجيلات</h4>
                <hr>
                <p class="text-muted">عند الوصول لهذا العدد، سيتم إغلاق التسجيل تلقائياً. اكتب 0 لإلغاء الحد.</p>
                <form id="maxForm" class="form-inline">
                    <div class="form-group">
                        <input type="number" class="form-control input-lg" id="maxRegistrations" 
                               value="<?= $settings['max_registrations'] ?>" min="0" style="width: 150px;">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg">حفظ</button>
                </form>
                <div id="maxMessage" class="alert" style="display: none; margin-top: 15px;"></div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Closed Message -->
        <div class="col-md-12">
            <div class="settings-card">
                <h4><i class="fa-solid fa-comment-dots"></i> رسالة إغلاق التسجيل</h4>
                <hr>
                <p class="text-muted">الرسالة التي ستظهر للعملاء عند إغلاق التسجيل</p>
                <form id="messageForm">
                    <div class="form-group">
                        <textarea class="form-control" id="closedMessage" rows="3"><?= htmlspecialchars($settings['closed_message']) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">حفظ الرسالة</button>
                </form>
                <div id="msgMessage" class="alert" style="display: none; margin-top: 15px;"></div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Group Link -->
        <div class="col-md-12">
            <div class="settings-card">
                <h4><i class="fa-solid fa-link"></i> رابط مجموعة الواتساب/تيليجرام</h4>
                <hr>
                <p class="text-muted">الرابط الذي يظهر للعميل بعد إتمام التسجيل</p>
                <form id="groupLinkForm">
                    <div class="form-group">
                        <input type="text" class="form-control" id="groupLink" 
                               value="<?= htmlspecialchars($settings['group_link'] ?? 'https://chat.whatsapp.com/BkV9UgvH01m1MzPTEpVlqJ') ?>" 
                               placeholder="https://chat.whatsapp.com/..." dir="ltr">
                    </div>
                    <button type="submit" class="btn btn-primary">حفظ الرابط</button>
                </form>
                <div id="groupLinkMessage" class="alert" style="display: none; margin-top: 15px;"></div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Support Number -->
        <div class="col-md-12">
            <div class="settings-card">
                <h4><i class="fa-solid fa-headset"></i> رقم الدعم الفني (الواتساب)</h4>
                <hr>
                <p class="text-muted">الرقم الذي سيظهر للعملاء للتواصل عند حدوث مشاكل</p>
                <form id="supportNumberForm">
                    <div class="form-group">
                        <input type="text" class="form-control" id="supportNumber" 
                               value="<?= htmlspecialchars($settings['support_number'] ?? '9647736000096') ?>" 
                               placeholder="96477..." dir="ltr">
                    </div>
                    <button type="submit" class="btn btn-primary">حفظ الرقم</button>
                </form>
                <div id="supportNumberMessage" class="alert" style="display: none; margin-top: 15px;"></div>
            </div>
        </div>
    </div>
    
    <!-- Championship Schedule -->
    <div class="row">
        <div class="col-md-12">
            <div class="settings-card" style="border: 2px solid #5bc0de;">
                <h4 style="color: #31708f;"><i class="fa-solid fa-calendar-check"></i> جدولة البطولة (مواعيد الدخول)</h4>
                <hr>
                <p class="text-muted">حدد تاريخ ووقت بدء البطولة والفاصل بين كل مشترك. عند قبول المشترك سيُخصص له معاد دخول تلقائياً.</p>
                <form id="scheduleForm">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fa-solid fa-calendar"></i> تاريخ البطولة</label>
                                <input type="date" class="form-control input-lg" id="championshipDate" 
                                       value="<?= htmlspecialchars($settings['championship_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fa-solid fa-clock"></i> ساعة البداية</label>
                                <input type="time" class="form-control input-lg" id="championshipStartTime" 
                                       value="<?= htmlspecialchars($settings['championship_start_time'] ?? '18:00') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fa-solid fa-stopwatch"></i> الفاصل (دقائق)</label>
                                <input type="number" class="form-control input-lg" id="timeSlotInterval" 
                                       value="<?= intval($settings['time_slot_interval'] ?? 10) ?>" min="1" max="60">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><i class="fa-solid fa-toggle-on"></i> حالة الجدولة</label>
                                <div class="checkbox">
                                    <label style="font-size: 1.2em; font-weight: bold;">
                                        <input type="checkbox" id="schedulingEnabled" style="width: 20px; height: 20px;" 
                                               <?= !empty($settings['scheduling_enabled']) ? 'checked' : '' ?>> 
                                        تفعيل الجدولة التلقائية
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 10px;">
                        <button type="submit" class="btn btn-info btn-lg"><i class="fa-solid fa-save"></i> حفظ إعدادات الجدول</button>
                        <button type="button" class="btn btn-warning btn-lg" style="margin-right: 10px;" onclick="assignAllTimes()">
                            <i class="fa-solid fa-user-clock"></i> تخصيص مواعيد للمشتركين الحاليين
                        </button>
                        <span style="margin-right: 15px; color: #888;"><i class="fa-solid fa-globe"></i> التوقيت: بغداد (UTC+3)</span>
                    </div>
                </form>
                <div id="scheduleMessage" class="alert" style="display: none; margin-top: 15px;"></div>
                <?php if (!empty($settings['championship_date'])): ?>
                <div style="margin-top: 15px; padding: 15px; background: #eaf6fb; border-radius: 8px;">
                    <strong><i class="fa-solid fa-info-circle"></i> المعاد الحالي:</strong>
                    البطولة يوم <strong><?= $settings['championship_date'] ?></strong> 
                    تبدأ الساعة <strong><?= $settings['championship_start_time'] ?? '18:00' ?></strong> 
                    بفاصل <strong><?= $settings['time_slot_interval'] ?? 10 ?></strong> دقائق بين كل مشترك
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Participation Types -->
    <div class="row">
        <div class="col-md-12">
            <div class="settings-card">
                <h4><i class="fa-solid fa-list-ul"></i> أنواع المشاركة</h4>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <form id="addTypeForm" class="form-inline" style="margin-bottom: 20px;">
                            <input type="text" class="form-control" name="label" placeholder="اسم النوع (مثال: استعراض حر)" required style="width: 250px;">
                            <input type="text" class="form-control" name="id" placeholder="ID (اختياري: free_show)" style="width: 150px;">
                            <button type="submit" class="btn btn-success">إضافة نوع</button>
                        </form>
                    </div>
                </div>
                
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>النوع</th>
                            <th>ID</th>
                            <th>الحالة</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participationTypes as $type): ?>
                        <tr>
                            <td><?= htmlspecialchars($type['label']) ?></td>
                            <td><code><?= htmlspecialchars($type['id']) ?></code></td>
                            <td>
                                <span class="label <?= ($type['enabled'] ?? true) ? 'label-success' : 'label-default' ?>">
                                    <?= ($type['enabled'] ?? true) ? 'مفعل' : 'معطل' ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-warning" onclick="toggleType('<?= $type['id'] ?>')">
                                    <?= ($type['enabled'] ?? true) ? 'تعطيل' : 'تفعيل' ?>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteType('<?= $type['id'] ?>')">حذف</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Blacklist -->
    <div class="row">
        <div class="col-md-6">
            <div class="settings-card">
                <h4><i class="fa-solid fa-ban"></i> القائمة السوداء (أرقام الهواتف)</h4>
                <hr>
                <form onsubmit="addToBlacklist(event, 'phone')" style="margin-bottom: 15px;">
                    <div class="row">
                        <div class="col-xs-4">
                            <input type="text" class="form-control" id="blockPhone" placeholder="رقم الهاتف" required>
                        </div>
                        <div class="col-xs-5">
                            <input type="text" class="form-control" id="blockPhoneReason" placeholder="سبب الحظر (اختياري)">
                        </div>
                        <div class="col-xs-3">
                            <button type="submit" class="btn btn-danger btn-block">حظر</button>
                        </div>
                    </div>
                </form>
                <?php if (!empty($blacklist['phones'])): ?>
                <table class="table table-condensed table-bordered" style="font-size:12px;">
                    <thead><tr style="background:#fff5f5;"><th>الرقم</th><th>السبب</th><th>التاريخ</th><th>بواسطة</th><th style="width:40px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($blacklist['phones'] as $entry):
                        $val = is_array($entry) ? ($entry['value'] ?? '') : $entry;
                        $reason = is_array($entry) ? ($entry['reason'] ?? '') : '';
                        $date = is_array($entry) ? ($entry['date'] ?? '') : '';
                        $by = is_array($entry) ? ($entry['by'] ?? '') : '';
                    ?>
                    <tr>
                        <td dir="ltr" style="font-weight:bold;"><?= htmlspecialchars($val) ?></td>
                        <td><?= htmlspecialchars($reason ?: '—') ?></td>
                        <td style="font-size:11px;"><?= htmlspecialchars($date) ?></td>
                        <td><?= htmlspecialchars($by) ?></td>
                        <td><button class="btn btn-xs btn-success" onclick="removeFromBlacklist('<?= htmlspecialchars($val, ENT_QUOTES) ?>', 'phone')" title="رفع الحظر"><i class="fa-solid fa-unlock"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-center text-muted" style="padding:20px;">لا يوجد أرقام محظورة</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="settings-card">
                <h4><i class="fa-solid fa-ban"></i> القائمة السوداء (أرقام اللوحات)</h4>
                <hr>
                <form onsubmit="addToBlacklist(event, 'plate')" style="margin-bottom: 15px;">
                    <div class="row">
                        <div class="col-xs-4">
                            <input type="text" class="form-control" id="blockPlate" placeholder="رقم اللوحة" required>
                        </div>
                        <div class="col-xs-5">
                            <input type="text" class="form-control" id="blockPlateReason" placeholder="سبب الحظر (اختياري)">
                        </div>
                        <div class="col-xs-3">
                            <button type="submit" class="btn btn-danger btn-block">حظر</button>
                        </div>
                    </div>
                </form>
                <?php if (!empty($blacklist['plates'])): ?>
                <table class="table table-condensed table-bordered" style="font-size:12px;">
                    <thead><tr style="background:#fff5f5;"><th>اللوحة</th><th>السبب</th><th>التاريخ</th><th>بواسطة</th><th style="width:40px"></th></tr></thead>
                    <tbody>
                    <?php foreach ($blacklist['plates'] as $entry):
                        $val = is_array($entry) ? ($entry['value'] ?? '') : $entry;
                        $reason = is_array($entry) ? ($entry['reason'] ?? '') : '';
                        $date = is_array($entry) ? ($entry['date'] ?? '') : '';
                        $by = is_array($entry) ? ($entry['by'] ?? '') : '';
                    ?>
                    <tr>
                        <td style="font-weight:bold;"><?= htmlspecialchars($val) ?></td>
                        <td><?= htmlspecialchars($reason ?: '—') ?></td>
                        <td style="font-size:11px;"><?= htmlspecialchars($date) ?></td>
                        <td><?= htmlspecialchars($by) ?></td>
                        <td><button class="btn btn-xs btn-success" onclick="removeFromBlacklist('<?= htmlspecialchars($val, ENT_QUOTES) ?>', 'plate')" title="رفع الحظر"><i class="fa-solid fa-unlock"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-center text-muted" style="padding:20px;">لا يوجد لوحات محظورة</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Danger Zone -->
    <div class="row">
        <div class="col-md-12">
            <div class="settings-card" style="border: 2px solid #d9534f;">
                <h4 style="color: #d9534f;"><i class="fa-solid fa-exclamation-triangle"></i> منطقة الخطر</h4>
                <hr>
                <div class="danger-action">
                    <h5><i class="fa-solid fa-sync"></i> إعادة تعيين البطولة</h5>
                    <p class="text-muted">سيتم أرشفة جميع التسجيلات الحالية وحفظ الأعضاء المعتمدين، ثم إعادة العداد إلى صفر وفتح التسجيل من جديد</p>
                    <button class="btn btn-danger btn-lg" onclick="resetCounter()">
                        <i class="fa-solid fa-sync"></i> إعادة تعيين وبدء من جديد
                    </button>
                </div>
                <div id="dangerMessage" class="alert" style="display: none; margin-top: 15px;"></div>
            </div>
        </div>
    </div>
    
    <!-- Info Box -->
    <div class="alert alert-info">
        <strong><i class="fa-solid fa-info-circle"></i> معلومات:</strong>
        <ul style="margin-top: 10px;">
            <li>التسجيل يُغلق تلقائياً عند الوصول للحد الأقصى</li>
            <li>يمكنك إغلاق/فتح التسجيل يدوياً في أي وقت</li>
            <li>البيانات المحفوظة لن تتأثر عند إغلاق التسجيل</li>
            <li>اكتب 0 في الحد الأقصى لإزالة الحد (تسجيلات غير محدودة)</li>
            <li><strong>إعادة التعيين:</strong> تؤرشف البيانات الحالية وتحفظ الأعضاء ثم تبدأ بطولة جديدة</li>
        </ul>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
<script>
function showMessage(elementId, message, type) {
    $('#' + elementId).removeClass('alert-success alert-danger')
        .addClass('alert-' + type)
        .html(message)
        .fadeIn();
    
    setTimeout(() => $('#' + elementId).fadeOut(), 3000);
}

function resetCounter() {
    if (!confirm('⚠️ تحذير!\n\nهل أنت متأكد من إعادة تعيين العداد؟\nسيتم حذف جميع التسجيلات وإعادة العداد إلى صفر!')) return;
    if (!confirm('⚠️ تأكيد نهائي!\n\nاضغط OK للمتابعة أو Cancel للإلغاء')) return;
    
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'reset_counter' },
        success: function(response) {
            if (response.success) {
                showMessage('dangerMessage', response.message, 'success');
                $('#currentCount').text('0');
                $('#statusBadge').removeClass('label-danger').addClass('label-success').html('<i class="fa-solid fa-check"></i> التسجيل مفتوح');
                $('#toggleBtn').removeClass('btn-success').addClass('btn-danger').html('إغلاق التسجيل');
            } else {
                showMessage('dangerMessage', response.message || 'حدث خطأ', 'danger');
            }
        },
        error: function() {
            showMessage('dangerMessage', 'حدث خطأ في الاتصال', 'danger');
        }
    });
}



function toggleRegistration() {
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'toggle_registration' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                if (response.is_open) {
                    $('#statusBadge').removeClass('label-danger').addClass('label-success').html('✓ التسجيل مفتوح');
                    $('#toggleBtn').removeClass('btn-success').addClass('btn-danger').html('إغلاق التسجيل');
                } else {
                    $('#statusBadge').removeClass('label-success').addClass('label-danger').html('✗ التسجيل مغلق');
                    $('#toggleBtn').removeClass('btn-danger').addClass('btn-success').html('فتح التسجيل');
                }
                alert(response.message);
            } else {
                alert('حدث خطأ: ' + (response.message || 'غير معروف'));
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error, xhr.responseText);
            alert('حدث خطأ في الاتصال بالخادم');
        }
    });
}

$('#maxForm').on('submit', function(e) {
    e.preventDefault();
    const max = $('#maxRegistrations').val();
    
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'update_max', max_registrations: max },
        success: function(response) {
            if (response.success) {
                showMessage('maxMessage', response.message, 'success');
                if (parseInt(max) > 0) {
                    $('#maxDisplay').html('من ' + max);
                } else {
                    $('#maxDisplay').html('(بدون حد أقصى)');
                }
            }
        }
    });
});

$('#messageForm').on('submit', function(e) {
    e.preventDefault();
    const message = $('#closedMessage').val();
    
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'update_message', message: message },
        success: function(response) {
            if (response.success) {
                showMessage('msgMessage', response.message, 'success');
            }
        }
    });
});

$('#groupLinkForm').on('submit', function(e) {
    e.preventDefault();
    const link = $('#groupLink').val();
    
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'update_group_link', group_link: link },
        success: function(response) {
            if (response.success) {
                showMessage('groupLinkMessage', response.message, 'success');
            } else {
                showMessage('groupLinkMessage', response.message || 'حدث خطأ', 'danger');
            }
        },
        error: function() {
            showMessage('groupLinkMessage', 'حدث خطأ في الاتصال', 'danger');
        }
    });
});

$('#supportNumberForm').on('submit', function(e) {
    e.preventDefault();
    const number = $('#supportNumber').val();
    
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'update_support_number', support_number: number },
        success: function(response) {
            if (response.success) {
                showMessage('supportNumberMessage', response.message, 'success');
            } else {
                showMessage('supportNumberMessage', response.message || 'حدث خطأ', 'danger');
            }
        },
        error: function() {
            showMessage('supportNumberMessage', 'حدث خطأ في الاتصال', 'danger');
        }
    });
});



$('#scheduleForm').on('submit', function(e) {
    e.preventDefault();
    $.ajax({
        url: '', type: 'POST',
        data: {
            action: 'update_schedule',
            scheduling_enabled: $('#schedulingEnabled').is(':checked') ? 1 : 0,
            championship_date: $('#championshipDate').val(),
            championship_start_time: $('#championshipStartTime').val(),
            time_slot_interval: $('#timeSlotInterval').val()
        },
        success: function(r) {
            if (r.success) {
                showMessage('scheduleMessage', r.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showMessage('scheduleMessage', r.message || 'حدث خطأ', 'danger');
            }
        }
    });
});

function assignAllTimes() {
    if (!confirm('سيتم إعادة توزيع المواعيد على جميع المشتركين المقبولين حالياً بناءً على الإعدادات الجديدة. هل أنت متأكد؟')) return;
    
    $.ajax({
        url: '', type: 'POST',
        data: { action: 'assign_all_times' },
        success: function(r) {
            if (r.success) {
                showMessage('scheduleMessage', r.message, 'success');
            } else {
                showMessage('scheduleMessage', r.message || 'حدث خطأ', 'danger');
            }
        }
    });
}

// Auto refresh count every 30 seconds
setInterval(function() {
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'get_status' },
        success: function(response) {
            if (response.success) {
                $('#currentCount').text(response.current_count);
                $('#currentTickets').text(response.current_tickets);
                $('#currentRegular').text(response.current_regular);
                $('#currentVip').text(response.current_vip);
                $('#currentOffer').text(response.current_offer);
            }
        }
    });
}, 30000);
$('#addTypeForm').on('submit', function(e) {
    e.preventDefault();
    $.ajax({
        url: '', type: 'POST',
        data: { action: 'add_type', label: this.label.value, id: this.id.value },
        success: function(r) { location.reload(); }
    });
});

function toggleType(id) {
    $.ajax({
        url: '', type: 'POST',
        data: { action: 'toggle_type', id: id },
        success: function(r) { location.reload(); }
    });
}

function deleteType(id) {
    if(!confirm('حذف هذا النوع؟')) return;
    $.ajax({
        url: '', type: 'POST',
        data: { action: 'delete_type', id: id },
        success: function(r) { location.reload(); }
    });
}

function addToBlacklist(e, type) {
    e.preventDefault();
    const val = type === 'phone' ? $('#blockPhone').val() : $('#blockPlate').val();
    const reason = type === 'phone' ? $('#blockPhoneReason').val() : $('#blockPlateReason').val();
    if (!val.trim()) { alert('أدخل القيمة أولاً'); return; }
    $.ajax({
        url: '', type: 'POST',
        data: { action: 'add_blacklist', type: type, value: val, reason: reason },
        success: function(r) { location.reload(); }
    });
}

function removeFromBlacklist(val, type) {
    if(!confirm('هل تريد رفع الحظر عن هذا العنصر؟')) return;
    $.ajax({
        url: '', type: 'POST',
        data: { action: 'remove_blacklist', type: type, value: val },
        success: function(r) { location.reload(); }
    });
}
</script>
</body>
</html>
