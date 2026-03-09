<?php
/**
 * WhatsApp Broadcast - إرسال إشعارات واتساب جماعية
 * Enhanced version with filters, categories, and time estimates
 */
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

$currentUser = $_SESSION['user'];
$isRoot = (isset($currentUser->username) && $currentUser->username === 'root');
$userRole = $currentUser->role ?? ($isRoot ? 'root' : 'viewer');
if ($isRoot) $userRole = 'root';

$canSendWhatsapp = in_array($userRole, ['root', 'whatsapp']);

if (!$canSendWhatsapp) {
    header('location:../dashboard.php');
    exit;
}

require_once '../include/db.php'; // Required for token lookup
require_once '../wasender.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $wasender = new WaSender();
    $pdo = db(); // Get DB Connection
    
    switch ($_POST['action']) {
        case 'get_numbers':
            $filter = $_POST['filter'] ?? 'approved';
            $numbers = [];
            $addedPhones = []; // Track added phones to avoid duplicates
            
            // === SOURCE 1: data.json (current championship registrations) ===
            if (in_array($filter, ['all', 'approved', 'pending'])) {
                $dataFile = __DIR__ . '/data/data.json';
                if (file_exists($dataFile)) {
                    $dataJson = json_decode(file_get_contents($dataFile), true) ?? [];
                    foreach ($dataJson as $item) {
                        $itemStatus = $item['status'] ?? 'pending';
                        if ($filter === 'approved' && $itemStatus !== 'approved') continue;
                        if ($filter === 'pending' && $itemStatus !== 'pending') continue;
                        
                        $phone = $item['phone'] ?? '';
                        if (empty($phone)) continue;
                        
                        $normPhone = preg_replace('/\D/', '', $phone);
                        $normPhone = substr($normPhone, -10);
                        if (isset($addedPhones[$normPhone])) continue;
                        $addedPhones[$normPhone] = true;
                        
                        $numbers[] = [
                            'phone' => $phone,
                            'country_code' => $item['country_code'] ?? '+964',
                            'name' => $item['full_name'] ?? $item['name'] ?? 'مشترك',
                            'wasel' => $item['wasel'] ?? '',
                            'car_type' => $item['car_type'] ?? '',
                            'token' => $item['registration_code'] ?? $item['badge_token'] ?? ''
                        ];
                    }
                }
            }
            
            // === SOURCE 2: SQLite (for all filters, merges with data.json results) ===
            // Pre-load data.json lookup map for phone resolution
            $dataJsonLookup = [];
            $dataFile2 = __DIR__ . '/data/data.json';
            if (file_exists($dataFile2)) {
                $djItems = json_decode(file_get_contents($dataFile2), true) ?? [];
                foreach ($djItems as $dji) {
                    $w = $dji['wasel'] ?? '';
                    if (!empty($w)) $dataJsonLookup['wasel_' . $w] = $dji;
                    $t = $dji['badge_token'] ?? ($dji['registration_code'] ?? '');
                    if (!empty($t)) $dataJsonLookup['token_' . $t] = $dji;
                }
            }
            
            try {
                $sql = "";
                $baseQuery = "
                    SELECT COALESCE(m.name, '') as name, COALESCE(m.phone, '') as phone, 
                           COALESCE(m.permanent_code, r.session_badge_token, '') as permanent_code, 
                           r.wasel, r.car_type, r.session_badge_token
                    FROM registrations r
                    LEFT JOIN members m ON r.member_id = m.id
                ";
                
                switch ($filter) {
                    case 'all':
                        $sql = $baseQuery . " WHERE r.is_active = 1";
                        break;
                    case 'members_page':
                        $sql = "SELECT name, phone, permanent_code, '' as wasel, '' as car_type, '' as session_badge_token FROM members";
                        break;
                    case 'approved':
                        $sql = $baseQuery . " WHERE r.status = 'approved' AND r.is_active = 1";
                        break;
                    case 'pending':
                        $sql = $baseQuery . " WHERE r.status = 'pending' AND r.is_active = 1";
                        break;
                    case 'not_entered':
                        $sql = $baseQuery . " WHERE r.status = 'approved' AND r.is_active = 1 AND (r.checkin_time IS NULL)";
                        break;
                    case 'entered':
                        $sql = $baseQuery . " WHERE r.status = 'approved' AND r.is_active = 1 AND (r.checkin_time IS NOT NULL)";
                        break;
                }
                
                if ($sql) {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($rows as $row) {
                        $phone = $row['phone'] ?? '';
                        $name = $row['name'] ?? '';
                        
                        // If phone is empty, try to resolve from data.json using wasel or token
                        if (empty($phone)) {
                            $resolved = null;
                            $w = $row['wasel'] ?? '';
                            $t = $row['session_badge_token'] ?? '';
                            if (!empty($w) && isset($dataJsonLookup['wasel_' . $w])) {
                                $resolved = $dataJsonLookup['wasel_' . $w];
                            } elseif (!empty($t) && isset($dataJsonLookup['token_' . $t])) {
                                $resolved = $dataJsonLookup['token_' . $t];
                            }
                            if ($resolved) {
                                $phone = $resolved['phone'] ?? '';
                                if (empty($name)) $name = $resolved['full_name'] ?? $resolved['name'] ?? '';
                            }
                        }
                        
                        if (empty($phone)) continue;
                        
                        // Dedup against data.json results
                        $normPhone = preg_replace('/\D/', '', $phone);
                        $normPhone = substr($normPhone, -10);
                        if (isset($addedPhones[$normPhone])) continue;
                        $addedPhones[$normPhone] = true;
                        
                        $numbers[] = [
                            'phone' => $phone,
                            'country_code' => '+964',
                            'name' => $name ?: 'مشترك',
                            'wasel' => $row['wasel'] ?? '',
                            'car_type' => $row['car_type'] ?? '',
                            'token' => $row['permanent_code'] ?? '' 
                        ];
                    }
                }
            } catch (Exception $e) {
                // Ignore errors
            }
            
            echo json_encode([
                'success' => true,
                'count' => count($numbers),
                'numbers' => $numbers
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'get_stats':
            $stats = [
                'all' => 0,
                'members_page' => 0,
                'approved' => 0,
                'not_entered' => 0,
                'pending' => 0,
                'entered' => 0
            ];
            
            try {
                // Queries from SQLite
                $stats['all'] = $pdo->query("SELECT COUNT(*) FROM registrations WHERE is_active=1")->fetchColumn();
                $stats['members_page'] = $pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
                $stats['approved'] = $pdo->query("SELECT COUNT(*) FROM registrations WHERE status='approved' AND is_active=1")->fetchColumn();
                $stats['pending'] = $pdo->query("SELECT COUNT(*) FROM registrations WHERE status='pending' AND is_active=1")->fetchColumn();
                try {
                     $stats['entered'] = $pdo->query("SELECT COUNT(*) FROM registrations WHERE status='approved' AND checkin_time IS NOT NULL AND is_active=1")->fetchColumn();
                     $stats['not_entered'] = $stats['approved'] - $stats['entered'];
                } catch(Exception $e) { }
                
            } catch (Exception $e) { }
            
            // === ALWAYS compare with data.json and use the HIGHER value ===
            $dataFile = __DIR__ . '/data/data.json';
            if (file_exists($dataFile)) {
                $dataJson = json_decode(file_get_contents($dataFile), true) ?? [];
                $djAll = count($dataJson);
                $djPending = 0;
                $djApproved = 0;
                foreach ($dataJson as $item) {
                    $s = $item['status'] ?? 'pending';
                    if ($s === 'pending') $djPending++;
                    if ($s === 'approved') $djApproved++;
                }
                // Always use whichever source has more data
                if ($djAll > $stats['all']) $stats['all'] = $djAll;
                if ($djPending > $stats['pending']) $stats['pending'] = $djPending;
                if ($djApproved > $stats['approved']) $stats['approved'] = $djApproved;
            }
            
            // Get Custom Stats
            try {
                $customCount = $pdo->query("SELECT COUNT(*) FROM public_contacts")->fetchColumn();
                $stats['custom'] = $customCount;
            } catch (Exception $e) { }
            
            echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
            exit;

        case 'save_custom_numbers':
            $numbers = json_decode($_POST['numbers'] ?? '[]', true);
            if (empty($numbers)) {
                echo json_encode(['success' => false, 'error' => 'لا توجد أرقام للحفظ']);
                exit;
            }
            
            $added = 0;
            try {
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO public_contacts (phone, name) VALUES (?, ?)");
                foreach ($numbers as $n) {
                    $phone = $n['phone'] ?? '';
                    $name = $n['name'] ?? 'مشترك';
                    if ($phone) {
                        $stmt->execute([$phone, $name]);
                        if ($stmt->rowCount() > 0) $added++;
                    }
                }
                echo json_encode(['success' => true, 'message' => "تم حفظ $added رقم جديد (تم تجاهل المكارات)"]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'فشل الحفظ: ' . $e->getMessage()]);
            }
            exit;

        case 'get_custom_numbers':
            try {
                $stmt = $pdo->query("SELECT name, phone FROM public_contacts ORDER BY created_at DESC");
                $numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Format for frontend
                $formatted = [];
                foreach($numbers as $row) {
                    $formatted[] = [
                        'name' => $row['name'],
                        'phone' => $row['phone'],
                        'country_code' => '+964'
                    ];
                }
                
                echo json_encode(['success' => true, 'numbers' => $formatted], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'لا توجد قائمة محفوظة أو الجدول غير موجود']);
            }
            exit;
            
        case 'send_broadcast':
            $message = trim($_POST['message'] ?? '');
            $phones = json_decode($_POST['phones'] ?? '[]', true);
            $delay = intval($_POST['delay'] ?? 3);
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'الرسالة فارغة']);
                exit;
            }
            
            if (empty($phones)) {
                echo json_encode(['success' => false, 'error' => 'لا توجد أرقام']);
                exit;
            }
            
            // Prevent script timeout and release session to prevent hanging
            set_time_limit(0);
            session_write_close();
            
            // Limit delay
            $delay = max(2, min($delay, 10));
            
            $results = [];
            $successCount = 0;
            $failCount = 0;
            
            // Send to queue instantly
            foreach ($phones as $index => $phoneData) {
                $phone = is_array($phoneData) ? ($phoneData['phone'] ?? '') : $phoneData;
                $countryCode = is_array($phoneData) ? ($phoneData['country_code'] ?? '+964') : '+964';
                $name = is_array($phoneData) ? ($phoneData['name'] ?? 'مشترك') : 'مشترك';
                
                if (empty($phone)) continue;
                
                $token = is_array($phoneData) ? ($phoneData['token'] ?? '') : '';

                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $link = $protocol . "://" . $host . "/badge.php?token=" . $token;
                
                // Replace placeholders in message
                $personalMessage = str_replace(
                    ['{name}', '{link}', '{token}'], 
                    [$name, $link, $token], 
                    $message
                );
                
                $result = $wasender->sendMessage($phone, $personalMessage, $countryCode);
                
                if ($result['success'] ?? false) {
                    $successCount++;
                } else {
                    $failCount++;
                }
                
                $results[] = [
                    'phone' => $phone,
                    'name' => $name,
                    'success' => ($result['success'] ?? false),
                    'error' => $result['error'] ?? ($result['message'] ?? null)
                ];
            }
            
            echo json_encode([
                'success' => true,
                'total' => count($phones),
                'sent' => $successCount,
                'failed' => $failCount,
                'details' => $results,
                'message' => 'تم وضع الرسائل في طابور الخلفية وسيتم إرسالها تباعاً بدون التأثير على المتصفح'
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'send_single':
            $phone = trim($_POST['phone'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $countryCode = $_POST['country_code'] ?? '+964';
            
            if (empty($phone) || empty($message)) {
                echo json_encode(['success' => false, 'error' => 'بيانات ناقصة']);
                exit;
            }
            
            $result = $wasender->sendMessage($phone, $message, $countryCode);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'إجراء غير معروف']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>إشعارات واتساب</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; }
        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
        .container { max-width: 1000px; margin-top: 20px; }
        .card { background: #fff; border-radius: 15px; padding: 25px; margin-bottom: 20px; box-shadow: 0 2px 15px rgba(0,0,0,0.1); }
        .card h3 { margin-top: 0; margin-bottom: 20px; color: #25D366; }
        textarea { min-height: 150px; resize: vertical; }
        .numbers-preview { max-height: 200px; overflow-y: auto; background: #f8f9fa; border-radius: 8px; padding: 10px; margin-top: 10px; }
        .number-tag { display: inline-block; background: #e9ecef; padding: 3px 10px; border-radius: 15px; margin: 3px; font-size: 12px; }
        .progress-container { display: none; margin-top: 20px; }
        .stat-box { text-align: center; padding: 15px; border-radius: 10px; margin-bottom: 10px; cursor: pointer; transition: transform 0.2s; }
        .stat-box:hover { transform: scale(1.02); }
        .stat-box.active { box-shadow: 0 0 0 3px #333; }
        .stat-box h2 { margin: 0; font-size: 32px; }
        .stat-box p { margin: 5px 0 0; font-size: 13px; }
        .upload-area { border: 2px dashed #ddd; border-radius: 10px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .upload-area:hover { border-color: #25D366; background: #f8fff8; }
        .format-example { background: #f0f0f0; border-radius: 8px; padding: 15px; margin-top: 15px; }
        .format-example pre { background: #333; color: #0f0; padding: 10px; border-radius: 5px; font-size: 12px; }
        .time-estimate { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 15px; border-radius: 10px; text-align: center; margin: 15px 0; }
        .time-estimate h3 { margin: 0; font-size: 24px; }
        .time-estimate p { margin: 5px 0 0; opacity: 0.9; }
        .source-tabs { margin-bottom: 20px; }
        .source-tabs .btn { padding: 12px 20px; font-size: 14px; }
        .source-tabs .btn.active { background: #25D366 !important; color: white !important; }
        .filter-select { padding: 10px; border-radius: 8px; border: 2px solid #ddd; font-size: 14px; width: 100%; }
        .filter-select:focus { border-color: #25D366; outline: none; }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container">
    <h2><i class="fa-brands fa-whatsapp"></i> إرسال إشعارات واتساب</h2>
    <p class="text-muted">أرسل رسائل جماعية للمتسابقين أو الجمهور أو أرقام مخصصة</p>
    
    <!-- Stats Cards -->
    <div class="row" style="margin-bottom: 20px;">
        <!-- REMOVED All Registered Card -->
        
        <div class="col-md-2" style="width: 16.66%;">
             <div class="stat-box" style="background: linear-gradient(135deg, #007bff, #0056b3); color: white;" onclick="selectFilter('members_page')" id="box-members_page">
                <h2 id="stat-members_page">-</h2>
                <p><i class="fa-solid fa-users"></i> صفحة الأعضاء</p>
            </div>
        </div>
        <div class="col-md-2" style="width: 16.66%;">
            <div class="stat-box" style="background: linear-gradient(135deg, #28a745, #20c997); color: white;" onclick="selectFilter('approved')" id="box-approved">
                <h2 id="stat-approved">-</h2>
                <p><i class="fa-solid fa-check-circle"></i> مقبولين</p>
            </div>
        </div>
        <div class="col-md-2" style="width: 16.66%;">
            <div class="stat-box" style="background: linear-gradient(135deg, #ffc107, #ff9800); color: #333;" onclick="selectFilter('not_entered')" id="box-not_entered">
                <h2 id="stat-not_entered">-</h2>
                <p><i class="fa-solid fa-ban"></i> لم يدخلوا</p>
            </div>
        </div>
        <div class="col-md-2" style="width: 16.66%;">
            <div class="stat-box" style="background: linear-gradient(135deg, #17a2b8, #138496); color: white;" onclick="selectFilter('entered')" id="box-entered">
                <h2 id="stat-entered">-</h2>
                <p><i class="fa-solid fa-walking"></i> دخلوا</p>
            </div>
        </div>
        <div class="col-md-2" style="width: 16.66%;">
            <div class="stat-box" style="background: linear-gradient(135deg, #6c757d, #495057); color: white;" onclick="selectFilter('pending')" id="box-pending">
                <h2 id="stat-pending">-</h2>
                <p><i class="fa-solid fa-hourglass-half"></i> قيد المراجعة</p>
            </div>
        </div>
         <div class="col-md-2" style="width: 16.66%;">
            <div class="stat-box" style="background: linear-gradient(135deg, #e83e8c, #c21e56); color: white;" id="box-custom">
                <h2 id="stat-custom">0</h2>
                <p><i class="fa-solid fa-users-viewfinder"></i> أرقام الجمهور</p>
            </div>
        </div>
    </div>
    
    <!-- Source Selection -->
    <div class="card">
        <h3>📋 اختر مصدر الأرقام</h3>
        
        <div class="source-tabs btn-group btn-group-justified" style="margin-bottom: 20px;">
            <div class="btn-group">
                <button class="btn btn-lg btn-default" onclick="selectSource('database')" id="btn-database">
                    <i class="fa-solid fa-database"></i> من قاعدة البيانات
                </button>
            </div>
            <div class="btn-group">
                <button class="btn btn-lg btn-default" onclick="selectSource('custom')" id="btn-custom">
                    <i class="fa-solid fa-users-viewfinder"></i> أرقام الجمهور (أرقام مخصصة)
                </button>
            </div>
        </div>
        
        <!-- Database Section -->
        <div id="database-section" style="display: none;">
            <div class="row">
                <div class="col-md-6">
                    <label><strong>اختر الفئة:</strong></label>
                    <select class="filter-select" id="number-filter" onchange="loadNumbersByFilter()">
                        <option value="approved">✅ المقبولين فقط</option>
                        <!-- Created Removed "All Registered" -->
                        <option value="members_page">👥 جميع الأعضاء (كل البطولات)</option>
                        <option value="not_entered">🚫 مقبولين لم يدخلوا</option>
                        <option value="entered">✅ مقبولين دخلوا</option>
                        <option value="pending">⏳ قيد المراجعة</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label>&nbsp;</label>
                    <button class="btn btn-success btn-block" onclick="loadNumbersByFilter()"><i class="fa-solid fa-sync"></i> تحميل الأرقام</button>
                </div>
            </div>
            <div class="numbers-preview" id="database-preview" style="margin-top: 15px;"></div>
        </div>
        
        <!-- Custom Numbers Section -->
        <div id="custom-section" style="display: none;">
            <div class="row">
                <div class="col-md-6">
                    <div class="upload-area" onclick="document.getElementById('file-input').click()">
                        <p style="font-size: 40px; margin: 0;"><i class="fa-solid fa-file-text"></i></p>
                        <p><strong>اضغط أو اسحب ملف text هنا</strong></p>
                        <p class="text-muted" style="font-size: 12px;">كل رقم في سطر منفصل</p>
                    </div>
                    <input type="file" id="file-input" accept=".txt,.csv" style="display: none;" onchange="handleFileUpload(this)">
                </div>
                <div class="col-md-6">
                    <div class="format-example">
                        <h5><i class="fa-solid fa-info-circle"></i> مثال التنسيق:</h5>
                        <p><strong>طريقة 1:</strong> أرقام فقط</p>
                        <pre>07701234567
07802345678
07903456789</pre>
                        <p><strong>طريقة 2:</strong> مع الأسماء (فاصلة)</p>
                        <pre>احمد,07701234567
محمد,07802345678
علي,07903456789</pre>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 15px;">
                <label><strong>أو أدخل الأرقام يدوياً:</strong></label>
                <textarea class="form-control" id="custom-numbers" rows="4" placeholder="07701234567&#10;احمد,07802345678&#10;محمد,07903456789"></textarea>
                <button class="btn btn-primary" style="margin-top: 10px;" onclick="parseCustomNumbers()"><i class="fa-solid fa-download"></i> تحميل الأرقام</button>
                <div class="btn-group" style="margin-top: 10px; float: left;">
                     <button class="btn btn-success" onclick="saveCustomList()" title="حفظ القائمة الحالية"><i class="fa-solid fa-save"></i> حفظ كأرقام جمهور</button>
                     <button class="btn btn-warning" onclick="loadCustomList()" title="استرجاع أرقام الجمهور"><i class="fa-solid fa-users"></i> استرجاع أرقام الجمهور</button>
                </div>
            </div>
            
            <div class="numbers-preview" id="custom-preview" style="margin-top: 15px;"></div>
        </div>
    </div>
    
    <!-- Time Estimate -->
    <div class="time-estimate" id="time-estimate" style="display: none;">
        <h3><i class="fa-solid fa-stopwatch"></i> <span id="est-time">0</span> دقيقة</h3>
        <p>الوقت المقدر لإرسال جميع الرسائل (<span id="est-count">0</span> رقم × <span id="est-delay">3</span> ثواني)</p>
    </div>
    
    <!-- Message -->
    <div class="card">
        <h3><i class="fa-solid fa-pen"></i> نص الرسالة</h3>
        <p class="text-muted">المتغيرات المتاحة: <code>{name}</code> اسم المشترك، <code>{link}</code> رابط البادج، <code>{token}</code> كود البادج</p>
        
        <textarea class="form-control" id="message" placeholder="اكتب رسالتك هنا...

مثال:
مرحباً {name}! 👋
نود إعلامكم بأن الحفل سيبدأ الساعة 8 مساءً.
نراكم هناك! 🏎️"></textarea>
        
        <div class="row" style="margin-top: 15px;">
            <div class="col-md-12">
                <div class="alert alert-info">
                    <i class="fa-solid fa-info-circle"></i> يتم الآن إرسال الرسائل عبر <strong>طابور المعالجة في الخلفية</strong> وبفاصل 15 جزء من الثانية بشكل تلقائي لتجنب الحظر، مما يضمن أمان وسلامة الرقم وسرعة تنفيذ المهام من الشاشة.
                </div>
            </div>
        </div>
    </div>
    
    <!-- Send Button -->
    <div class="card">
        <button class="btn btn-success btn-lg btn-block" onclick="sendBroadcast()" id="send-btn">
            <i class="fa-solid fa-paper-plane"></i> إرسال الرسائل (<span id="send-count">0</span> رقم)
        </button>
        
        <div class="progress-container" id="progress-container">
            <div class="progress" style="height: 25px;">
                <div class="progress-bar progress-bar-success progress-bar-striped active" id="progress-bar" style="width: 0%; line-height: 25px;"></div>
            </div>
            <p class="text-center" id="progress-text" style="margin-top: 10px;">جاري الإرسال...</p>
        </div>
        
        <div id="results-container" style="display: none; margin-top: 20px;">
            <h4><i class="fa-solid fa-chart-pie"></i> النتائج:</h4>
            <div class="row">
                <div class="col-xs-4 text-center">
                    <span class="label label-success" style="font-size: 24px; padding: 10px 20px; cursor: pointer;" id="result-success" onclick="showResultDetails('success')" title="اضغط لعرض التفاصيل">0</span>
                    <p style="margin-top: 5px;"><i class="fa-solid fa-check"></i> نجح</p>
                </div>
                <div class="col-xs-4 text-center">
                    <span class="label label-danger" style="font-size: 24px; padding: 10px 20px; cursor: pointer;" id="result-failed" onclick="showResultDetails('failed')" title="اضغط لعرض التفاصيل">0</span>
                    <p style="margin-top: 5px;"><i class="fa-solid fa-times"></i> فشل</p>
                </div>
                <div class="col-xs-4 text-center">
                    <span class="label label-info" style="font-size: 24px; padding: 10px 20px;" id="result-total">0</span>
                    <p style="margin-top: 5px;"><i class="fa-solid fa-layer-group"></i> المجموع</p>
                </div>
            </div>
            <div id="result-details" style="display: none; margin-top: 15px; border: 1px solid #ddd; border-radius: 8px; padding: 15px; max-height: 300px; overflow-y: auto;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h5 id="result-details-title" style="margin: 0;"></h5>
                    <div>
                        <button class="btn btn-warning btn-sm" id="retry-failed-btn" style="display: none;" onclick="retryFailed()"><i class="fa-solid fa-redo"></i> إعادة إرسال الفاشلة</button>
                        <button class="btn btn-default btn-sm" onclick="$('#result-details').hide()"><i class="fa-solid fa-times"></i></button>
                    </div>
                </div>
                <table class="table table-condensed table-striped" style="margin: 0;">
                    <thead><tr><th>#</th><th>الاسم</th><th>الرقم</th><th>الحالة</th></tr></thead>
                    <tbody id="result-details-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
<script>
let selectedNumbers = [];
let currentSource = null;
let successNumbers = [];
let failedNumbers = [];

function selectSource(source) {
    currentSource = source;
    $('#btn-database, #btn-custom').removeClass('active').addClass('btn-default');
    $('#database-section, #custom-section').hide();
    
    if (source === 'database') {
        $('#btn-database').removeClass('btn-default').addClass('active');
        $('#database-section').show();
    } else {
        $('#btn-custom').removeClass('btn-default').addClass('active');
        $('#custom-section').show();
    }
}

function selectFilter(filter) {
    $('.stat-box').removeClass('active');
    $('#box-' + filter).addClass('active');
    $('#number-filter').val(filter);
    loadNumbersByFilter();
    selectSource('database');
}

function loadNumbersByFilter() {
    const filter = $('#number-filter').val();
    $('#database-preview').html('<p class="text-center"><i class="fa-solid fa-spinner fa-spin"></i> جاري التحميل...</p>');
    
    $.post('', { action: 'get_numbers', filter: filter }, function(res) {
        if (res.success) {
            selectedNumbers = res.numbers;
            renderNumbers('database-preview', selectedNumbers);
            updateCount();
            updateTimeEstimate();
        }
    }, 'json');
}

function parseCustomNumbers() {
    const text = $('#custom-numbers').val();
    const lines = text.split('\n').filter(l => l.trim());
    
    selectedNumbers = lines.map(line => {
        // Check if format is "name,phone"
        if (line.includes(',')) {
            const parts = line.split(',');
            return {
                name: parts[0].trim(),
                phone: parts[1].trim().replace(/[\s\-]/g, ''),
                country_code: '+964'
            };
        } else {
            return {
                phone: line.trim().replace(/[\s\-]/g, ''),
                country_code: '+964',
                name: 'مشترك'
            };
        }
    });
    
    $('#stat-custom').text(selectedNumbers.length);
    renderNumbers('custom-preview', selectedNumbers);
    updateCount();
    updateTimeEstimate();
}

function saveCustomList() {
    if (selectedNumbers.length === 0) {
        alert('لا توجد أرقام للحفظ! قم بتحميل الأرقام أولاً.');
        return;
    }
    
    // Original button text
    const btn = event.target;
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الحفظ...';
    btn.disabled = true;

    $.post('', { action: 'save_custom_numbers', numbers: JSON.stringify(selectedNumbers) }, function(res) {
        btn.innerHTML = oldText;
        btn.disabled = false;
        
        if (res.success) {
            alert('✅ ' + res.message);
        } else {
            alert('❌ ' + (res.error || 'فشل الحفظ'));
        }
    }, 'json');
}

function loadCustomList() {
    const btn = event.target;
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري التحميل...';
    btn.disabled = true;

    $.post('', { action: 'get_custom_numbers' }, function(res) {
        btn.innerHTML = oldText;
        btn.disabled = false;
        
        if (res.success && res.numbers.length > 0) {
            selectedNumbers = res.numbers;
            $('#stat-custom').text(selectedNumbers.length);
            renderNumbers('custom-preview', selectedNumbers);
            updateCount();
            updateTimeEstimate();
            
            // Allow editing in textarea too
            let text = '';
            selectedNumbers.forEach(n => {
                text += (n.name && n.name !== 'مشترك' ? n.name + ',' : '') + n.phone + '\n';
            });
            $('#custom-numbers').val(text);
            
            alert('✅ تم استرجاع ' + selectedNumbers.length + ' رقم');
        } else {
            alert('❌ ' + (res.error || 'لا توجد بيانات محفوظة'));
        }
    }, 'json');
}

function handleFileUpload(input) {
    const file = input.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = function(e) {
        $('#custom-numbers').val(e.target.result);
        parseCustomNumbers();
    };
    reader.readAsText(file);
}

function renderNumbers(containerId, numbers) {
    const container = $('#' + containerId);
    if (numbers.length === 0) {
        container.html('<p class="text-center text-muted">لا توجد أرقام</p>');
        return;
    }
    container.html(numbers.slice(0, 50).map(n => 
        '<span class="number-tag">' + (n.name || 'مشترك') + ' - ' + (n.phone || '').substr(0, 4) + '****</span>'
    ).join('') + (numbers.length > 50 ? '<span class="number-tag">... و ' + (numbers.length - 50) + ' آخرين</span>' : ''));
}

function updateCount() {
    $('#send-count').text(selectedNumbers.length);
    $('#est-count').text(selectedNumbers.length);
}

function updateTimeEstimate() {
    const count = selectedNumbers.length;
    // Removed client side delay dependency
    // Fallback static calculation for estimate info only
    const delay = 15; 
    const totalSeconds = count * delay;
    const minutes = Math.ceil(totalSeconds / 60);
    
    $('#est-time').text(minutes);
    $('#est-delay').text(delay);
    
    if (count > 0) {
        $('#time-estimate').show();
    } else {
        $('#time-estimate').hide();
    }
}

function sendBroadcast() {
    const message = $('#message').val().trim();
    if (!message) {
        alert('يرجى كتابة الرسالة');
        return;
    }
    
    if (selectedNumbers.length === 0) {
        alert('يرجى تحديد الأرقام أولاً');
        return;
    }
    
    // Fallback static calculation for estimate info only
    const delay = 15; 
    const totalSeconds = selectedNumbers.length * delay;
    const minutes = Math.ceil(totalSeconds / 60);
    
    if (!confirm('سيتم وضع إرسال ' + selectedNumbers.length + ' رسالة في طابور الخلفية.\n\nالوقت المقدر لانتهاء الإرسال: ' + minutes + ' دقيقة\n\n✅ ستبقى الصفحة متاحة ولن تتوقف.')) {
        return;
    }
    
    $('#send-btn').prop('disabled', true);
    $('#progress-container').show();
    $('#results-container').hide();
    $('#progress-bar').css('width', '50%').text('جاري التنفيذ...');
    $('#progress-text').text('جاري نقل البيانات إلى عامل الخلفية...');
    
    // Single AJAX call since the backend now queues it all instantly
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            action: 'send_broadcast',
            message: message,
            phones: JSON.stringify(selectedNumbers),
            delay: delay
        },
        dataType: 'json',
        success: function(res) {
            $('#send-btn').prop('disabled', false);
            
            if (res && res.success) {
                // Add artificial progress bar jump over 1 second for visual satisfaction
                let pct = 50;
                let pInterval = setInterval(() => {
                    pct += 10;
                    if (pct >= 100) {
                        $('#progress-bar').css('width', '100%').text('100%');
                        $('#progress-text').text(res.message || '✅ تم وضع جميع الرسائل في الطابور بنجاح.');
                        clearInterval(pInterval);
                        
                        // Show "success" stats
                        $('#results-container').show();
                        $('#result-details').hide();
                        $('#result-success').text(res.sent || res.total || selectedNumbers.length);
                        $('#result-failed').text(res.failed || 0);
                        $('#result-total').text(res.total || selectedNumbers.length);
                    } else {
                        $('#progress-text').text('نقل الدفعة...');
                        $('#progress-bar').css('width', pct + '%').text(pct + '%');
                    }
                }, 100);
                
                successNumbers = res.details ? res.details.filter(d => d.success) : [];
                failedNumbers = res.details ? res.details.filter(d => !d.success) : [];
            } else {
                $('#progress-text').text('❌ حدث خطأ أثناء الإرسال للطابور.');
                $('#progress-bar').addClass('progress-bar-danger');
            }
        },
        error: function() {
            $('#send-btn').prop('disabled', false);
            $('#progress-text').text('❌ فشل الاتصال بالخادم.');
            $('#progress-bar').addClass('progress-bar-danger');
            alert('حدث خطأ في الاتصال. يرجى المحاولة مرة أخرى.');
        }
    });
}

function showResultDetails(type) {
    const list = type === 'success' ? successNumbers : failedNumbers;
    const title = type === 'success' ? '✅ الأرقام الناجحة' : '❌ الأرقام الفاشلة';
    const color = type === 'success' ? '#27ae60' : '#e74c3c';
    
    $('#result-details-title').html('<span style="color:' + color + '">' + title + ' (' + list.length + ')</span>');
    $('#retry-failed-btn').toggle(type === 'failed' && list.length > 0);
    
    let html = '';
    list.forEach(function(n, i) {
        const statusHtml = type === 'success' 
            ? '<span style="color: #27ae60;">✅ نجح</span>' 
            : '<span style="color: #e74c3c;">❌ ' + (n.error || 'فشل') + '</span>';
        html += '<tr><td>' + (i+1) + '</td><td>' + n.name + '</td><td dir="ltr">' + n.phone + '</td><td>' + statusHtml + '</td></tr>';
    });
    
    if (list.length === 0) {
        html = '<tr><td colspan="4" class="text-center">لا توجد بيانات</td></tr>';
    }
    
    $('#result-details-body').html(html);
    $('#result-details').show();
}

function retryFailed() {
    if (failedNumbers.length === 0) {
        alert('لا توجد أرقام فاشلة لإعادة الإرسال');
        return;
    }
    
    if (!confirm('هل تريد إعادة إرسال ' + failedNumbers.length + ' رسالة فاشلة؟')) return;
    
    // Set failed numbers as the new selected numbers and resend
    selectedNumbers = failedNumbers.map(function(n) {
        return { name: n.name, phone: n.phone };
    });
    
    updateCount();
    updateTimeEstimate();
    renderNumbers('db-preview', selectedNumbers);
    $('#result-details').hide();
    $('#results-container').hide();
    
    sendBroadcast();
}

// Load initial stats
$(function() {
    $.post('', { action: 'get_stats' }, function(res) {
        if (res.success && res.stats) {
            $('#stat-all').text(res.stats.all);
            $('#stat-members_page').text(res.stats.members_page);
            $('#stat-approved').text(res.stats.approved);
            $('#stat-not_entered').text(res.stats.not_entered);
            $('#stat-entered').text(res.stats.entered);
            $('#stat-pending').text(res.stats.pending);
        }
    }, 'json');
});
</script>
</body>
</html>
