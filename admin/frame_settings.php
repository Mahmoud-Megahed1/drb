<?php
/**
 * Frame Settings - إعدادات الفريم
 * محرر مرئي لتحديد مواضع العناصر على الفريم
 */
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

$settingsFile = 'data/frame_settings.json';
$message = '';
$messageType = '';

// Load current settings
$settings = [
    'frame_image' => 'images/acceptance_frame.png',
    'elements' => [
        'personal_photo' => ['enabled' => true, 'x' => 50, 'y' => 30, 'width' => 25, 'height' => 25, 'shape' => 'circle', 'border_color' => '#FFD700', 'border_width' => 4],
        'participant_name' => ['enabled' => true, 'x' => 50, 'y' => 60, 'font_size' => 28, 'color' => '#FFD700'],
        'registration_id' => ['enabled' => true, 'x' => 50, 'y' => 70, 'font_size' => 20, 'color' => '#FFFFFF'],
        'car_type' => ['enabled' => true, 'x' => 50, 'y' => 75, 'font_size' => 18, 'color' => '#FFFFFF'],
        'car_image' => ['enabled' => false, 'x' => 50, 'y' => 45, 'width' => 30, 'height' => 20],
        'plate_number' => ['enabled' => true, 'x' => 50, 'y' => 80, 'font_size' => 18, 'color' => '#FFFFFF'],
        'governorate' => ['enabled' => true, 'x' => 50, 'y' => 95, 'font_size' => 18, 'color' => '#FFFFFF']
    ],
    'form_settings' => ['is_open' => true, 'open_date' => null, 'close_date' => null, 'link_expiry_days' => 5]
];

if (file_exists($settingsFile)) {
    $loaded = json_decode(file_get_contents($settingsFile), true);
    if ($loaded) {
        $settings = array_replace_recursive($settings, $loaded);
    }
}

// Handle AJAX save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_positions') {
        $elements = json_decode($_POST['elements'], true);
        if ($elements) {
            foreach ($elements as $key => $data) {
                if (isset($settings['elements'][$key])) {
                    $settings['elements'][$key] = array_merge($settings['elements'][$key], $data);
                }
            }
        }
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // IMPORTANT: Reload the file to get the complete updated settings
        $freshSettings = json_decode(file_get_contents($settingsFile), true);
        
        // AUTO-SYNC: Update all registrations with NEW frame settings
        $dataFile = 'data/data.json';
        if (file_exists($dataFile)) {
            $data = json_decode(file_get_contents($dataFile), true);
            if (is_array($data)) {
                foreach ($data as $index => $reg) {
                    if (($reg['status'] ?? '') === 'approved') {
                        $data[$index]['saved_frame_settings'] = $freshSettings;
                    }
                }
                file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'تم حفظ المواضع وتحديث التسجيلات']);
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_frame') {
        if (!empty($_FILES['frame_file']['tmp_name'])) {
            $uploadDir = '../images/';
            $filename = 'acceptance_frame_' . time() . '.' . pathinfo($_FILES['frame_file']['name'], PATHINFO_EXTENSION);
            $targetPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['frame_file']['tmp_name'], $targetPath)) {
                $settings['frame_image'] = 'images/' . $filename;
                $message = 'تم رفع الفريم بنجاح';
                $messageType = 'success';
                
                // Also update site_settings.json to sync with dashboard
                $siteSettingsFile = 'data/site_settings.json';
                $siteSettings = [];
                if (file_exists($siteSettingsFile)) {
                    $siteSettings = json_decode(file_get_contents($siteSettingsFile), true) ?? [];
                }
                $siteSettings['frame_url'] = 'images/' . $filename;
                $siteSettings['updated_at'] = date('Y-m-d H:i:s');
                file_put_contents($siteSettingsFile, json_encode($siteSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $message = 'فشل رفع الملف';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'save_form_settings') {
        // $settings['form_settings']['is_open'] = isset($_POST['is_open']);
        // $settings['form_settings']['open_date'] = !empty($_POST['open_date']) ? $_POST['open_date'] : null;
        // $settings['form_settings']['close_date'] = !empty($_POST['close_date']) ? $_POST['close_date'] : null;
        // $settings['form_settings']['link_expiry_days'] = (int)($_POST['link_expiry_days'] ?? 5);
        
        // Save form titles
        $settings['form_titles'] = [
            'main_title' => trim($_POST['main_title'] ?? 'استمارة تسجيل'),
            'sub_title' => trim($_POST['sub_title'] ?? '')
        ];
        
        $message = 'تم حفظ إعدادات الاستمارة';
        $messageType = 'success';
    }
    
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // IMPORTANT: Reload the file to get the complete updated settings
    $freshSettings = json_decode(file_get_contents($settingsFile), true);
    
    // AUTO-SYNC: Update all registrations with NEW frame settings
    $dataFile = 'data/data.json';
    if (file_exists($dataFile)) {
        $regData = json_decode(file_get_contents($dataFile), true);
        if (is_array($regData)) {
            foreach ($regData as $index => $reg) {
                if (($reg['status'] ?? '') === 'approved') {
                    $regData[$index]['saved_frame_settings'] = $freshSettings;
                }
            }
            file_put_contents($dataFile, json_encode($regData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}

// Get frame image - search in multiple locations
$frameImagePath = $settings['frame_image'] ?? 'images/acceptance_frame.png';
$frameCandidates = [
    $frameImagePath,
    '../' . $frameImagePath,
    '../images/settings/' . basename($frameImagePath),
    'images/settings/' . basename($frameImagePath),
    '../images/acceptance_frame.png',
    'images/acceptance_frame.png'
];

$frameImage = null;
foreach ($frameCandidates as $candidate) {
    if (file_exists($candidate)) {
        $frameImage = $candidate;
        break;
    }
}

// Final fallback
if (!$frameImage || !file_exists($frameImage)) {
    $frameImage = '../images/acceptance_frame.png';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات الفريم - محرر مرئي</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .header h1 { color: #ffc107; font-size: 22px; }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-back { background: #6c757d; color: #fff; }
        .btn-success { background: linear-gradient(135deg, #28a745, #20c997); color: #fff; }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }
        
        .main-container {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .canvas-section {
            flex: 2;
            min-width: 400px;
        }
        
        .canvas-container {
            background: rgba(0,0,0,0.3);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        #editorCanvas {
            max-width: 100%;
            border-radius: 10px;
            cursor: crosshair;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        
        .canvas-info {
            margin-top: 10px;
            color: #888;
            font-size: 12px;
        }
        
        .controls-section {
            flex: 1;
            min-width: 300px;
        }
        
        .card {
            background: rgba(255,255,255,0.05);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .card h3 {
            color: #ffc107;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .element-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .element-toggle:hover {
            background: rgba(255,193,7,0.1);
        }
        
        .element-toggle.active {
            background: rgba(40,167,69,0.2);
            border: 1px solid #28a745;
        }
        
        .element-toggle.selected {
            background: rgba(0,123,255,0.2);
            border: 1px solid #007bff;
        }
        
        .element-toggle span {
            font-size: 14px;
            color: #fff;
        }
        
        .toggle-switch {
            position: relative;
            width: 44px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #444;
            transition: 0.4s;
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #28a745;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }
        
        .element-props {
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
            display: none;
        }
        
        .element-props.show {
            display: block;
        }
        
        .prop-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .prop-row .form-group {
            flex: 1;
        }
        
        .form-group label {
            display: block;
            font-size: 11px;
            color: #888;
            margin-bottom: 4px;
        }
        
        .form-control {
            width: 100%;
            padding: 8px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-radius: 6px;
            font-size: 12px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #ffc107;
        }
        
        .message {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        
        .message.success {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid #28a745;
            color: #28a745;
        }
        
        .message.error {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid #dc3545;
            color: #dc3545;
        }
        
        .instructions {
            background: rgba(255,193,7,0.1);
            border: 1px solid rgba(255,193,7,0.3);
            border-radius: 10px;
            padding: 12px;
            font-size: 11px;
            color: #ccc;
        }
        
        .instructions h4 {
            color: #ffc107;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .instructions ul {
            list-style: none;
            padding: 0;
        }
        
        .instructions li {
            margin-bottom: 4px;
        }
        
        .upload-btn {
            display: block;
            width: 100%;
            padding: 12px;
            border: 2px dashed rgba(255,193,7,0.5);
            background: transparent;
            color: #ffc107;
            border-radius: 10px;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            transition: all 0.3s;
            margin-bottom: 15px;
        }
        
        .upload-btn:hover {
            background: rgba(255,193,7,0.1);
            border-color: #ffc107;
        }
        
        .upload-btn input {
            display: none;
        }

        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
    </style>
</head>
<body style="padding-top: 60px;">
    <!-- Navbar -->
    <?php include '../include/navbar-custom.php'; ?>
<div class="header">
        <h1><i class="fa-solid fa-palette"></i> إعدادات الفريم - محرر مرئي</h1>
        <div>
            <button class="btn btn-success" onclick="saveAllSettings()"><i class="fa-solid fa-save"></i> حفظ الإعدادات</button>
        </div>
    </div>
    
    <?php if ($message): ?>
    <div class="message <?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>
    
    <div id="saveMessage" class="message success" style="display: none;"></div>
    
    <div class="main-container">
        <div class="canvas-section">
            <div class="canvas-container">
                <canvas id="editorCanvas" width="600" height="600"></canvas>
                <div class="canvas-info">
                    اسحب العناصر بالماوس • عجلة الماوس للتكبير/التصغير
                </div>
            </div>
        </div>
        
        <div class="controls-section">
            <!-- Frame Upload -->
            <!-- Frame Upload -->
            <div class="card">
                <h3><i class="fa-solid fa-image"></i> صورة الفريم</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_frame">
                    <label class="upload-btn">
                        <i class="fa-solid fa-upload"></i> رفع فريم جديد
                        <input type="file" name="frame_file" accept="image/*" onchange="this.form.submit()">
                    </label>
                </form>
            </div>
            
            <!-- Elements -->
            <!-- Elements -->
            <div class="card">
                <h3><i class="fa-solid fa-layer-group"></i> عناصر الفريم</h3>
                
                <!-- Personal Photo -->
                <div class="element-toggle" data-element="personal_photo" onclick="selectElement('personal_photo')">
                    <span><i class="fa-solid fa-user"></i> صورة شخصية</span>
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" id="toggle_personal_photo" onchange="toggleElement('personal_photo')" <?= $settings['elements']['personal_photo']['enabled'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                
                <!-- Name -->
                <div class="element-toggle" data-element="participant_name" onclick="selectElement('participant_name')">
                    <span><i class="fa-solid fa-font"></i> اسم المتسابق</span>
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" id="toggle_participant_name" onchange="toggleElement('participant_name')" <?= $settings['elements']['participant_name']['enabled'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                
                <!-- ID -->
                <div class="element-toggle" data-element="registration_id" onclick="selectElement('registration_id')">
                    <span><i class="fa-solid fa-fingerprint"></i> رقم التسجيل</span>
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" id="toggle_registration_id" onchange="toggleElement('registration_id')" <?= $settings['elements']['registration_id']['enabled'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                
                <!-- Plate -->
                <div class="element-toggle" data-element="plate_number" onclick="selectElement('plate_number')">
                    <span><i class="fa-solid fa-closed-captioning"></i> رقم اللوحة</span>
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" id="toggle_plate_number" onchange="toggleElement('plate_number')" <?= $settings['elements']['plate_number']['enabled'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                
                <!-- Governorate -->
                <div class="element-toggle" data-element="governorate" onclick="selectElement('governorate')">
                    <span><i class="fa-solid fa-map-marker-alt"></i> المحافظة</span>
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" id="toggle_governorate" onchange="toggleElement('governorate')" <?= ($settings['elements']['governorate']['enabled'] ?? true) ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                
                <!-- Car Type -->
                <div class="element-toggle" data-element="car_type" onclick="selectElement('car_type')">
                    <span><i class="fa-solid fa-car"></i> نوع السيارة</span>
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" id="toggle_car_type" onchange="toggleElement('car_type')" <?= ($settings['elements']['car_type']['enabled'] ?? false) ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                
                <!-- Car Image -->
                <div class="element-toggle" data-element="car_image" onclick="selectElement('car_image')">
                    <span><i class="fa-solid fa-camera"></i> صورة السيارة</span>
                    <label class="toggle-switch" onclick="event.stopPropagation()">
                        <input type="checkbox" id="toggle_car_image" onchange="toggleElement('car_image')" <?= $settings['elements']['car_image']['enabled'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                
                <!-- Properties Panel -->
                <div id="propsPanel" class="element-props">
                    <h4 style="color: #ffc107; font-size: 13px; margin-bottom: 10px;">خصائص العنصر</h4>
                    <div class="prop-row">
                        <div class="form-group">
                            <label>X %</label>
                            <input type="number" class="form-control" id="prop_x" min="0" max="100" onchange="updateProp('x')">
                        </div>
                        <div class="form-group">
                            <label>Y %</label>
                            <input type="number" class="form-control" id="prop_y" min="0" max="100" onchange="updateProp('y')">
                        </div>
                    </div>
                    <div class="prop-row" id="sizeProps">
                        <div class="form-group">
                            <label>العرض % <span id="widthValue" style="color: #ffc107;">25</span></label>
                            <input type="range" class="form-control" id="prop_width" min="5" max="80" style="padding: 0;" oninput="updateProp('width'); document.getElementById('widthValue').textContent = this.value;">
                        </div>
                        <div class="form-group">
                            <label>الارتفاع % <span id="heightValue" style="color: #ffc107;">25</span></label>
                            <input type="range" class="form-control" id="prop_height" min="5" max="80" style="padding: 0;" oninput="updateProp('height'); document.getElementById('heightValue').textContent = this.value;">
                        </div>
                    </div>
                    <!-- Shape and Border for Images -->
                    <div class="prop-row" id="imageShapeProps" style="display: none;">
                        <div class="form-group">
                            <label>الشكل</label>
                            <select class="form-control" id="prop_shape" onchange="updateProp('shape')">
                                <option value="circle">⭕ دائري</option>
                                <option value="square">⬜ مربع</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>لون الإطار</label>
                            <input type="color" class="form-control" id="prop_border_color" style="height: 32px; padding: 2px;" onchange="updateProp('border_color')">
                        </div>
                    </div>
                    <div class="prop-row" id="borderWidthProps" style="display: none;">
                        <div class="form-group">
                            <label>سمك الإطار <span id="borderWidthValue" style="color: #ffc107;">4</span>px</label>
                            <input type="range" class="form-control" id="prop_border_width" min="0" max="15" style="padding: 0;" oninput="updateProp('border_width'); document.getElementById('borderWidthValue').textContent = this.value;">
                        </div>
                        <div class="form-group">
                            <label>القيمة</label>
                            <span id="borderWidthValue" style="color: #ffc107; font-weight: bold;">4px</span>
                        </div>
                    </div>
                    <div class="prop-row" id="fontProps">
                        <div class="form-group">
                            <label>حجم الخط</label>
                            <input type="number" class="form-control" id="prop_font_size" min="10" max="72" onchange="updateProp('font_size')">
                        </div>
                        <div class="form-group">
                            <label>اللون</label>
                            <input type="color" class="form-control" id="prop_color" style="height: 32px; padding: 2px;" onchange="updateProp('color')">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Form Titles Settings -->
            <div class="card">
                <h3><i class="fa-solid fa-edit"></i> إعدادات عناوين الاستمارة</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="save_form_settings">
                    <div class="form-group">
                        <label>العنوان الرئيسي</label>
                        <input type="text" class="form-control" name="main_title" value="<?= htmlspecialchars($settings['form_titles']['main_title'] ?? 'استمارة تسجيل') ?>" placeholder="استمارة تسجيل">
                    </div>
                    <div class="form-group">
                        <label>العنوان الفرعي</label>
                        <input type="text" class="form-control" name="sub_title" value="<?= htmlspecialchars($settings['form_titles']['sub_title'] ?? '') ?>" placeholder="حفلة رأس السنة على حلبة نادي بلاد الرافدين">
                    </div>
                    <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 10px;"><i class="fa-solid fa-save"></i> حفظ العناوين</button>
                </form>
            </div>
            
            <!-- Rules Management Link -->
            <div class="card">
                <h3><i class="fa-solid fa-gavel"></i> إدارة الشروط والقوانين</h3>
                <p style="color: #888; font-size: 12px; margin-bottom: 15px;">إضافة وتعديل وحذف وترتيب الشروط والقوانين</p>
                <a href="rules_settings.php" class="btn btn-primary" style="width: 100%; text-align: center;"><i class="fa-solid fa-clipboard-list"></i> إدارة الشروط</a>
            </div>
            
            <!-- Instructions -->
            <div class="instructions">
                <h4><i class="fa-solid fa-info-circle"></i> التعليمات</h4>
                <ul>
                    <li>• اضغط على العنصر لتحديده</li>
                    <li>• اسحب العنصر بالماوس لتحريكه</li>
                    <li>• استخدم المفاتيح لضبط الموضع بدقة</li>
                    <li>• Toggle لتفعيل/إيقاف العنصر</li>
                </ul>
            </div>
        </div>
    </div>
    
    <script>
    const canvas = document.getElementById('editorCanvas');
    const ctx = canvas.getContext('2d');
    
    // Elements data from PHP
    let elements = <?= json_encode($settings['elements']) ?>;
    
    // Dummy data for preview
    const dummyData = {
        personal_photo: { label: '👤', text: 'صورة' },
        participant_name: { text: 'محمد أحمد العلي' },
        registration_id: { text: '12345' },
        car_type: { text: 'BMW M4 2024' },
        plate_number: { text: 'أ ب ج 1234 - بغداد' },
        plate_number: { text: 'أ ب ج 1234 - بغداد' },
        governorate: { text: 'بغداد' },
        car_image: { label: '🚗', text: 'سيارة' }
    };
    
    let frameImg = new Image();
    let selectedElement = null;
    let isDragging = false;
    let isResizing = false;
    let resizeEdge = null;
    let dragOffset = { x: 0, y: 0 };
    let frameLoaded = false;
    
    frameImg.onload = function() {
        frameLoaded = true;
        
        // تغيير حجم Canvas ليتناسب مع نسبة الصورة الأصلية
        const maxWidth = 450;  // الحد الأقصى للعرض
        const maxHeight = 700; // الحد الأقصى للارتفاع
        
        const imgRatio = frameImg.naturalWidth / frameImg.naturalHeight;
        let newWidth, newHeight;
        
        if (imgRatio > 1) {
            // Landscape - عرضية
            newWidth = Math.min(maxWidth, frameImg.naturalWidth);
            newHeight = newWidth / imgRatio;
        } else {
            // Portrait - طولية (مثل الصورة المطلوبة)
            newHeight = Math.min(maxHeight, frameImg.naturalHeight);
            newWidth = newHeight * imgRatio;
            
            // تأكد إن العرض مش أكبر من الحد الأقصى
            if (newWidth > maxWidth) {
                newWidth = maxWidth;
                newHeight = newWidth / imgRatio;
            }
        }
        
        canvas.width = Math.round(newWidth);
        canvas.height = Math.round(newHeight);
        
        document.getElementById('editorCanvas').style.opacity = '1';
        render();
    };
    
    frameImg.onerror = function() {
        frameLoaded = true;
        canvas.width = 400;
        canvas.height = 600;
        document.getElementById('editorCanvas').style.opacity = '1';
        render();
    };
    
    // Set loading state
    // Set loading state
    document.getElementById('editorCanvas').style.opacity = '0.5';
    
    // Wait for fonts to load to ensure 'Cairo' is available for Canvas
    document.fonts.ready.then(function() {
        frameImg.src = '<?= $frameImage ?>';
    });
    
    // Fallback timeout
    setTimeout(function() {
        if (!frameLoaded) {
            frameLoaded = true;
            document.getElementById('editorCanvas').style.opacity = '1';
            render();
        }
    }, 5000);
    
    function render() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        // Draw frame
        if (frameImg.complete && frameImg.naturalWidth > 0) {
            ctx.drawImage(frameImg, 0, 0, canvas.width, canvas.height);
        } else {
            ctx.fillStyle = '#2a2a4a';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#666';
            ctx.font = '20px Cairo';
            ctx.textAlign = 'center';
            ctx.fillText('لا يوجد فريم - ارفع صورة', canvas.width/2, canvas.height/2);
        }
        
        // Draw elements
        for (let key in elements) {
            if (!elements[key].enabled) continue;
            
            const el = elements[key];
            const x = canvas.width * (el.x / 100);
            const y = canvas.height * (el.y / 100);
            
            const isSelected = selectedElement === key;
            
            if (key === 'personal_photo') {
                // Draw photo placeholder (circle or square with width/height)
                const w = canvas.width * ((el.width || 25) / 100);
                const h = canvas.height * ((el.height || 25) / 100);
                const shape = el.shape || 'circle';
                const borderWidth = el.border_width || 4;
                
                ctx.save();
                
                if (shape === 'circle') {
                    // Draw ellipse (allows different width/height)
                    ctx.beginPath();
                    ctx.ellipse(x, y, w/2, h/2, 0, 0, Math.PI * 2);
                    ctx.fillStyle = isSelected ? 'rgba(0,123,255,0.3)' : 'rgba(255,255,255,0.2)';
                    ctx.fill();
                    
                    // Border
                    ctx.strokeStyle = el.border_color || '#FFD700';
                    ctx.lineWidth = borderWidth;
                    ctx.stroke();
                } else {
                    // Square/Rectangle
                    ctx.fillStyle = isSelected ? 'rgba(0,123,255,0.3)' : 'rgba(255,255,255,0.2)';
                    ctx.fillRect(x - w/2, y - h/2, w, h);
                    
                    // Border
                    ctx.strokeStyle = el.border_color || '#FFD700';
                    ctx.lineWidth = borderWidth;
                    ctx.strokeRect(x - w/2, y - h/2, w, h);
                }
                
                ctx.fillStyle = '#fff';
                ctx.font = `${Math.min(w, h)/2}px Cairo`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('👤', x, y);
                ctx.restore();
                
            } else if (key === 'car_image') {
                // Draw car image placeholder with shape support
                const w = canvas.width * ((el.width || 30) / 100);
                const h = canvas.height * ((el.height || 20) / 100);
                const shape = el.shape || 'square';
                const borderWidth = el.border_width || 2;
                
                ctx.save();
                
                if (shape === 'circle') {
                    // Draw ellipse
                    ctx.beginPath();
                    ctx.ellipse(x, y, w/2, h/2, 0, 0, Math.PI * 2);
                    ctx.fillStyle = isSelected ? 'rgba(0,123,255,0.3)' : 'rgba(255,255,255,0.2)';
                    ctx.fill();
                    
                    ctx.strokeStyle = el.border_color || '#ffc107';
                    ctx.lineWidth = borderWidth;
                    ctx.stroke();
                } else {
                    // Draw rectangle
                    ctx.fillStyle = isSelected ? 'rgba(0,123,255,0.3)' : 'rgba(255,255,255,0.2)';
                    ctx.fillRect(x - w/2, y - h/2, w, h);
                    
                    ctx.strokeStyle = el.border_color || '#ffc107';
                    ctx.lineWidth = borderWidth;
                    ctx.strokeRect(x - w/2, y - h/2, w, h);
                }
                
                ctx.fillStyle = '#fff';
                ctx.font = `${Math.min(w, h)/2}px Cairo`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('🚗', x, y);
                ctx.restore();
                
            } else {
                // Draw text elements
                const fontSize = el.font_size || 24;
                ctx.font = `bold ${fontSize}px Cairo`;
                ctx.fillStyle = el.color || '#FFD700';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                
                ctx.shadowColor = 'rgba(0,0,0,0.5)';
                ctx.shadowBlur = 4;
                ctx.shadowOffsetX = 2;
                ctx.shadowOffsetY = 2;
                
                ctx.fillText(dummyData[key].text, x, y);
                ctx.shadowColor = 'transparent';
                
                // Selection indicator
                if (isSelected) {
                    const textWidth = ctx.measureText(dummyData[key].text).width;
                    ctx.strokeStyle = '#007bff';
                    ctx.lineWidth = 2;
                    ctx.setLineDash([5, 3]);
                    ctx.strokeRect(x - textWidth/2 - 5, y - fontSize/2 - 5, textWidth + 10, fontSize + 10);
                    ctx.setLineDash([]);
                }
            }
        }
    }
    
    function selectElement(key) {
        selectedElement = key;
        
        // Update UI
        document.querySelectorAll('.element-toggle').forEach(el => el.classList.remove('selected'));
        document.querySelector(`[data-element="${key}"]`).classList.add('selected');
        
        // Show properties panel
        const propsPanel = document.getElementById('propsPanel');
        propsPanel.classList.add('show');
        
        const el = elements[key];
        document.getElementById('prop_x').value = el.x;
        document.getElementById('prop_y').value = el.y;
        
        // Show/hide size or font props
        const isImage = key === 'personal_photo' || key === 'car_image';
        
        document.getElementById('sizeProps').style.display = isImage ? 'flex' : 'none';
        document.getElementById('fontProps').style.display = isImage ? 'none' : 'flex';
        document.getElementById('imageShapeProps').style.display = isImage ? 'flex' : 'none';
        document.getElementById('borderWidthProps').style.display = isImage ? 'flex' : 'none';
        
        if (isImage) {
            document.getElementById('prop_width').value = el.width || 25;
            document.getElementById('prop_height').value = el.height || 25;
            document.getElementById('widthValue').textContent = el.width || 25;
            document.getElementById('heightValue').textContent = el.height || 25;
            document.getElementById('prop_shape').value = el.shape || 'circle';
            document.getElementById('prop_border_color').value = el.border_color || '#FFD700';
            document.getElementById('prop_border_width').value = el.border_width || 4;
            document.getElementById('borderWidthValue').textContent = el.border_width || 4;
        }
        
        if (!isImage) {
            document.getElementById('prop_font_size').value = el.font_size || 24;
            document.getElementById('prop_color').value = el.color || '#FFD700';
        }
        
        render();
    }
    
    function toggleElement(key) {
        elements[key].enabled = document.getElementById(`toggle_${key}`).checked;
        render();
    }
    
    function updateProp(prop) {
        if (!selectedElement) return;
        
        let value;
        if (prop === 'color' || prop === 'border_color' || prop === 'shape') {
            value = document.getElementById(`prop_${prop}`).value;
        } else {
            value = parseInt(document.getElementById(`prop_${prop}`).value);
        }
        
        elements[selectedElement][prop] = value;
        
        // Update border width display
        if (prop === 'border_width') {
            document.getElementById('borderWidthValue').textContent = value + 'px';
        }
        
        render();
    }
    
    // Mouse events for dragging
    canvas.addEventListener('mousedown', function(e) {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const mouseX = (e.clientX - rect.left) * scaleX;
        const mouseY = (e.clientY - rect.top) * scaleY;
        
        // First check if clicking on selected element's edge for resize
        if (selectedElement && (selectedElement === 'personal_photo' || selectedElement === 'car_image')) {
            const el = elements[selectedElement];
            const elX = canvas.width * (el.x / 100);
            const elY = canvas.height * (el.y / 100);
            const w = canvas.width * ((el.width || 25) / 100);
            const h = canvas.height * ((el.height || 25) / 100);
            
            const edgeSize = 20; // pixels from edge to trigger resize
            
            // Check if on any horizontal edge (left or right) for width resize
            const onLeftEdge = Math.abs(mouseX - (elX - w/2)) < edgeSize && Math.abs(mouseY - elY) < h/2 + edgeSize;
            const onRightEdge = Math.abs(mouseX - (elX + w/2)) < edgeSize && Math.abs(mouseY - elY) < h/2 + edgeSize;
            const onHorizontalEdge = onLeftEdge || onRightEdge;
            
            // Check if on any vertical edge (top or bottom) for height resize
            const onTopEdge = Math.abs(mouseY - (elY - h/2)) < edgeSize && Math.abs(mouseX - elX) < w/2 + edgeSize;
            const onBottomEdge = Math.abs(mouseY - (elY + h/2)) < edgeSize && Math.abs(mouseX - elX) < w/2 + edgeSize;
            const onVerticalEdge = onTopEdge || onBottomEdge;
            
            // Check corners
            const onCorner = (onHorizontalEdge && onVerticalEdge);
            
            if (onCorner) {
                isResizing = true;
                resizeEdge = 'corner';
                canvas.style.cursor = 'nwse-resize';
                return;
            } else if (onHorizontalEdge) {
                isResizing = true;
                resizeEdge = 'right';
                canvas.style.cursor = 'ew-resize';
                return;
            } else if (onVerticalEdge) {
                isResizing = true;
                resizeEdge = 'bottom';
                canvas.style.cursor = 'ns-resize';
                return;
            }
        }
        
        // Find element under mouse - get the one closest to click point
        let closestElement = null;
        let closestDistance = Infinity;
        
        const checkOrder = ['governorate', 'plate_number', 'car_type', 'registration_id', 'participant_name', 'car_image', 'personal_photo'];
        
        for (let key of checkOrder) {
            if (!elements[key] || !elements[key].enabled) continue;
            
            const el = elements[key];
            const elX = canvas.width * (el.x / 100);
            const elY = canvas.height * (el.y / 100);
            
            let hitSize = 40;
            if (key === 'personal_photo' || key === 'car_image') {
                const w = canvas.width * ((el.width || 25) / 100);
                const h = canvas.height * ((el.height || 25) / 100);
                hitSize = Math.max(w, h) / 2 + 10;
            }
            
            const distance = Math.sqrt(Math.pow(mouseX - elX, 2) + Math.pow(mouseY - elY, 2));
            
            if (distance < hitSize && distance < closestDistance) {
                closestDistance = distance;
                closestElement = key;
            }
        }
        
        if (closestElement) {
            selectElement(closestElement);
            isDragging = true;
            const el = elements[closestElement];
            const elX = canvas.width * (el.x / 100);
            const elY = canvas.height * (el.y / 100);
            dragOffset.x = mouseX - elX;
            dragOffset.y = mouseY - elY;
            canvas.style.cursor = 'grabbing';
        }
    });
    
    canvas.addEventListener('mousemove', function(e) {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const mouseX = (e.clientX - rect.left) * scaleX;
        const mouseY = (e.clientY - rect.top) * scaleY;
        
        // Handle resize mode
        if (isResizing && selectedElement) {
            const el = elements[selectedElement];
            const elX = canvas.width * (el.x / 100);
            const elY = canvas.height * (el.y / 100);
            
            if (resizeEdge === 'right' || resizeEdge === 'corner') {
                const distFromCenter = Math.abs(mouseX - elX);
                const newWidth = Math.max(5, Math.min(80, (distFromCenter * 2 / canvas.width) * 100));
                el.width = Math.round(newWidth);
                document.getElementById('prop_width').value = Math.round(newWidth);
                document.getElementById('widthValue').textContent = Math.round(newWidth);
            }
            
            if (resizeEdge === 'bottom' || resizeEdge === 'corner') {
                const distFromCenter = Math.abs(mouseY - elY);
                const newHeight = Math.max(5, Math.min(80, (distFromCenter * 2 / canvas.height) * 100));
                el.height = Math.round(newHeight);
                document.getElementById('prop_height').value = Math.round(newHeight);
                document.getElementById('heightValue').textContent = Math.round(newHeight);
            }
            
            render();
            return;
        }
        
        // Handle drag mode
        if (!isDragging || !selectedElement) return;
        
        const newX = Math.max(5, Math.min(95, ((mouseX - dragOffset.x) / canvas.width) * 100));
        const newY = Math.max(5, Math.min(95, ((mouseY - dragOffset.y) / canvas.height) * 100));
        
        elements[selectedElement].x = Math.round(newX);
        elements[selectedElement].y = Math.round(newY);
        
        document.getElementById('prop_x').value = Math.round(newX);
        document.getElementById('prop_y').value = Math.round(newY);
        
        render();
    });
    
    canvas.addEventListener('mouseup', function() {
        isDragging = false;
        isResizing = false;
        canvas.style.cursor = 'crosshair';
    });
    
    canvas.addEventListener('mouseleave', function() {
        isDragging = false;
        isResizing = false;
        canvas.style.cursor = 'crosshair';
    });
    
    // Mouse wheel for resize
    canvas.addEventListener('wheel', function(e) {
        if (!selectedElement) return;
        e.preventDefault();
        
        const el = elements[selectedElement];
        const isImage = selectedElement === 'personal_photo' || selectedElement === 'car_image';
        
        if (isImage) {
            // Resize image
            const delta = e.deltaY > 0 ? -2 : 2;
            el.width = Math.max(5, Math.min(80, (el.width || 25) + delta));
            el.height = el.width; // Keep square
            
            document.getElementById('prop_width').value = el.width;
            document.getElementById('prop_height').value = el.height;
        } else {
            // Resize text
            const delta = e.deltaY > 0 ? -2 : 2;
            el.font_size = Math.max(10, Math.min(72, (el.font_size || 24) + delta));
            
            document.getElementById('prop_font_size').value = el.font_size;
        }
        
        render();
    });
    
    // Keyboard controls
    document.addEventListener('keydown', function(e) {
        if (!selectedElement) return;
        
        let moved = false;
        const step = e.shiftKey ? 5 : 1;
        
        switch (e.key) {
            case 'ArrowUp':
                elements[selectedElement].y = Math.max(0, elements[selectedElement].y - step);
                moved = true;
                break;
            case 'ArrowDown':
                elements[selectedElement].y = Math.min(100, elements[selectedElement].y + step);
                moved = true;
                break;
            case 'ArrowLeft':
                elements[selectedElement].x = Math.max(0, elements[selectedElement].x - step);
                moved = true;
                break;
            case 'ArrowRight':
                elements[selectedElement].x = Math.min(100, elements[selectedElement].x + step);
                moved = true;
                break;
        }
        
        if (moved) {
            e.preventDefault();
            document.getElementById('prop_x').value = elements[selectedElement].x;
            document.getElementById('prop_y').value = elements[selectedElement].y;
            render();
        }
    });
    
    // Save all settings
    function saveAllSettings() {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'save_positions');
        formData.append('elements', JSON.stringify(elements));
        
        fetch('frame_settings.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            const msg = document.getElementById('saveMessage');
            msg.textContent = data.message + ' - جاري التحديث...';
            msg.style.display = 'block';
            
            // Reload page after 1 second to get fresh values from server
            setTimeout(() => {
                location.reload();
            }, 1000);
        });
    }
    
    // ==========================================
    // Touch Events Support for Mobile
    // ==========================================
    
    function getTouchPosition(touch) {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        return {
            x: (touch.clientX - rect.left) * scaleX,
            y: (touch.clientY - rect.top) * scaleY
        };
    }
    
    // ==========================================
    // Touch Events with Pinch-to-Zoom Support
    // ==========================================
    
    let touchStartDistance = 0;
    let initialWidth = 0;
    let initialHeight = 0;
    
    function getTouchDistance(touches) {
        const dx = touches[0].clientX - touches[1].clientX;
        const dy = touches[0].clientY - touches[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }
    
    canvas.addEventListener('touchstart', function(e) {
        e.preventDefault();
        
        // Pinch to zoom (resize) - two fingers
        if (e.touches.length === 2 && selectedElement && (selectedElement === 'personal_photo' || selectedElement === 'car_image')) {
            touchStartDistance = getTouchDistance(e.touches);
            initialWidth = elements[selectedElement].width || 25;
            initialHeight = elements[selectedElement].height || 25;
            return;
        }
        
        const touch = e.touches[0];
        const pos = getTouchPosition(touch);
        
        // Find closest element
        let closestElement = null;
        let closestDistance = Infinity;
        
        for (let key in elements) {
            if (!elements[key].enabled) continue;
            
            const el = elements[key];
            const elX = canvas.width * (el.x / 100);
            const elY = canvas.height * (el.y / 100);
            
            let hitSize = 50; // Larger hit area for touch
            if (key === 'personal_photo' || key === 'car_image') {
                const w = canvas.width * ((el.width || 25) / 100);
                const h = canvas.height * ((el.height || 25) / 100);
                hitSize = Math.max(w, h) / 2 + 20;
            }
            
            const distance = Math.sqrt(Math.pow(pos.x - elX, 2) + Math.pow(pos.y - elY, 2));
            
            if (distance < hitSize && distance < closestDistance) {
                closestDistance = distance;
                closestElement = key;
            }
        }
        
        if (closestElement) {
            selectElement(closestElement);
            isDragging = true;
            const el = elements[closestElement];
            const elX = canvas.width * (el.x / 100);
            const elY = canvas.height * (el.y / 100);
            dragOffset.x = pos.x - elX;
            dragOffset.y = pos.y - elY;
        }
    }, { passive: false });
    
    canvas.addEventListener('touchmove', function(e) {
        e.preventDefault();
        
        // Pinch to zoom - resize image
        if (e.touches.length === 2 && touchStartDistance > 0 && selectedElement && (selectedElement === 'personal_photo' || selectedElement === 'car_image')) {
            const currentDistance = getTouchDistance(e.touches);
            const scale = currentDistance / touchStartDistance;
            
            const newWidth = Math.max(5, Math.min(80, Math.round(initialWidth * scale)));
            const newHeight = Math.max(5, Math.min(80, Math.round(initialHeight * scale)));
            
            elements[selectedElement].width = newWidth;
            elements[selectedElement].height = newHeight;
            
            document.getElementById('prop_width').value = newWidth;
            document.getElementById('prop_height').value = newHeight;
            document.getElementById('widthValue').textContent = newWidth;
            document.getElementById('heightValue').textContent = newHeight;
            
            render();
            return;
        }
        
        if (!isDragging || !selectedElement || e.touches.length !== 1) return;
        
        const touch = e.touches[0];
        const pos = getTouchPosition(touch);
        
        const newX = Math.max(5, Math.min(95, ((pos.x - dragOffset.x) / canvas.width) * 100));
        const newY = Math.max(5, Math.min(95, ((pos.y - dragOffset.y) / canvas.height) * 100));
        
        elements[selectedElement].x = Math.round(newX);
        elements[selectedElement].y = Math.round(newY);
        
        document.getElementById('prop_x').value = Math.round(newX);
        document.getElementById('prop_y').value = Math.round(newY);
        
        render();
    }, { passive: false });
    
    canvas.addEventListener('touchend', function(e) {
        isDragging = false;
        isResizing = false;
        touchStartDistance = 0;
    });
    
    canvas.addEventListener('touchcancel', function(e) {
        isDragging = false;
        isResizing = false;
        touchStartDistance = 0;
    });
    
    // Initial render
    setTimeout(render, 100);
    </script>
</body>
</html>



