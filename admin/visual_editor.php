<?php
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

// Get registration data
$wasel = $_GET['wasel'] ?? '';
$imageType = $_GET['img'] ?? 'personal'; // 'personal' or 'car'
$dataFile = 'data/data.json';
$registration = null;

if (!empty($wasel) && file_exists($dataFile)) {
    $data = json_decode(file_get_contents($dataFile), true);
    foreach ($data as $item) {
        if ($item['wasel'] == $wasel) {
            $registration = $item;
            break;
        }
    }
}

// Load frame settings - PRIORITY: Use registration's saved settings first!
$frameSettingsFile = 'data/frame_settings.json';
$frameSettings = [
    'frame_image' => 'images/acceptance_frame.png',
    'elements' => [
        'personal_photo' => ['enabled' => true, 'x' => 50, 'y' => 30, 'width' => 35, 'height' => 35, 'shape' => 'circle', 'border_color' => '#FFD700', 'border_width' => 4],
        'participant_name' => ['enabled' => true, 'x' => 50, 'y' => 70, 'font_size' => 28, 'color' => '#FFD700'],
        'registration_id' => ['enabled' => true, 'x' => 50, 'y' => 80, 'font_size' => 20, 'color' => '#FFFFFF'],
        'car_type' => ['enabled' => true, 'x' => 50, 'y' => 85, 'font_size' => 18, 'color' => '#FFD700'],
        'car_image' => ['enabled' => false, 'x' => 50, 'y' => 50, 'width' => 30, 'height' => 20],
        'plate_number' => ['enabled' => true, 'x' => 50, 'y' => 90, 'font_size' => 18, 'color' => '#FFFFFF'],
        'governorate' => ['enabled' => true, 'x' => 50, 'y' => 95, 'font_size' => 18, 'color' => '#FFFFFF']
    ]
];

// *** FIXED: Use registration's saved_frame_settings FIRST if available ***
if (!empty($registration['saved_frame_settings'])) {
    $frameSettings = array_merge($frameSettings, $registration['saved_frame_settings']);
} elseif (file_exists($frameSettingsFile)) {
    $frameSettings = array_merge($frameSettings, json_decode(file_get_contents($frameSettingsFile), true));
}

// Get frame image - search in multiple locations
$frameImagePath = $frameSettings['frame_image'] ?? 'images/acceptance_frame.png';
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

// Fallback to site settings if frame not found
if (!$frameImage) {
    $siteSettingsFile = 'data/site_settings.json';
    if (file_exists($siteSettingsFile)) {
        $siteSettings = json_decode(file_get_contents($siteSettingsFile), true);
        if (!empty($siteSettings['frame_url'])) {
            $frameImage = '../' . $siteSettings['frame_url'];
        }
    }
}

// Final fallback
if (!$frameImage || !file_exists($frameImage)) {
    $frameImage = '../images/acceptance_frame.png';
}

// Get selected image based on type
$selectedImage = '';
$carImage = '';
if ($registration) {
    if (!empty($registration['images']['personal_photo'])) {
        $selectedImage = '../' . $registration['images']['personal_photo'];
    } elseif (!empty($registration['images']['front_image'])) {
        $selectedImage = '../' . $registration['images']['front_image'];
    }
    
    if (!empty($registration['images']['front_image'])) {
        $carImage = '../' . $registration['images']['front_image'];
    }
}

$imageTypeLabel = $imageType === 'car' ? '🚗 صورة السيارة' : '👤 صورة شخصية';

// Prepare registration data for JavaScript
$regData = [
    'name' => $registration['full_name'] ?? '',
    'id' => $registration['wasel'] ?? '',
    'plate' => $registration['plate_full'] ?? '',
    'phone' => $registration['phone'] ?? '',
    'car_type' => $registration['car_type'] ?? '',
    'governorate' => $registration['governorate'] ?? ''
];

// Load previous editor state if exists
$editorStateFile = 'data/editor_states/' . $wasel . '.json';
$savedState = null;
if (file_exists($editorStateFile)) {
    $savedState = json_decode(file_get_contents($editorStateFile), true);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>محرر الصورة - <?= $wasel ?></title>
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Cairo', sans-serif; 
            background: #1a1a2e; 
            color: #fff;
            min-height: 100vh;
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
        
        .header h2 { color: #ffc107; font-size: 18px; }
        .header .badge { background: #007bff; padding: 5px 12px; border-radius: 15px; font-size: 12px; }
        
        .btn {
            padding: 10px 25px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .btn-back { background: #6c757d; color: #fff; }
        .btn-generate { background: linear-gradient(135deg, #28a745, #20c997); color: #fff; }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }
        
        .editor-container { display: flex; gap: 20px; flex-wrap: wrap; }
        
        .canvas-container {
            flex: 2;
            min-width: 400px;
            background: rgba(0,0,0,0.3);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        #editorCanvas {
            max-width: 100%;
            border-radius: 10px;
            cursor: move;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        
        .tools-panel {
            flex: 1;
            min-width: 280px;
            background: rgba(255,255,255,0.05);
            border-radius: 15px;
            padding: 20px;
            max-height: calc(100vh - 100px);
            overflow-y: auto;
        }
        
        .tool-section {
            margin-bottom: 18px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .tool-section:last-child { border-bottom: none; }
        .tool-section h4 { color: #ffc107; margin-bottom: 10px; font-size: 13px; }
        
        .shape-buttons { display: flex; gap: 8px; }
        
        .shape-btn {
            flex: 1;
            padding: 10px;
            border: 2px solid rgba(255,255,255,0.2);
            background: transparent;
            color: #fff;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
        }
        
        .shape-btn:hover { border-color: #ffc107; }
        .shape-btn.active { border-color: #ffc107; background: rgba(255,193,7,0.2); }
        
        .form-group { margin-bottom: 10px; }
        .form-group label { display: block; margin-bottom: 4px; color: #aaa; font-size: 11px; }
        
        .form-control {
            width: 100%;
            padding: 8px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-radius: 6px;
            font-family: inherit;
            font-size: 12px;
        }
        
        .form-control:focus { outline: none; border-color: #ffc107; }
        
        .size-row { display: flex; gap: 8px; }
        .size-row .form-group { flex: 1; }
        
        .slider-group { display: flex; align-items: center; gap: 6px; }
        .slider-group input[type="range"] { flex: 1; }
        
        .slider-group .value {
            min-width: 40px;
            text-align: center;
            background: #ffc107;
            color: #000;
            padding: 2px 6px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 11px;
        }
        
        .color-picker { display: flex; gap: 6px; flex-wrap: wrap; }
        
        .color-option {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .color-option:hover, .color-option.active { border-color: #fff; transform: scale(1.1); }
        
        .instructions {
            background: rgba(255,193,7,0.1);
            border: 1px solid rgba(255,193,7,0.3);
            border-radius: 8px;
            padding: 10px;
            font-size: 11px;
        }
        
        .instructions h5 { color: #ffc107; margin-bottom: 6px; font-size: 12px; }
        .instructions ul { list-style: none; color: #999; }
        .instructions li { margin-bottom: 3px; }
        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h2><i class="fa-solid fa-palette"></i> محرر صورة القبول - رقم <?= htmlspecialchars($wasel) ?></h2>
        <span class="badge"><?= $imageTypeLabel ?></span>
    </div>
    <div>
        <button class="btn btn-back" onclick="window.location.href='../dashboard.php'"><i class="fa-solid fa-arrow-right"></i> رجوع</button>
        <button class="btn btn-generate" id="saveBtn" onclick="saveImage()" disabled style="opacity: 0.5;"><i class="fa-solid fa-spinner fa-spin"></i> جاري تحميل الصور...</button>
    </div>
</div>

<div class="editor-container">
    <div class="canvas-container">
        <div id="loadingOverlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 100;">
            <div style="text-align: center; color: #fff;">
                <div style="font-size: 40px; margin-bottom: 10px;"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <div>جاري تحميل الصور...</div>
            </div>
        </div>
        <canvas id="editorCanvas" width="800" height="800"></canvas>
        <p style="margin-top: 10px; color: #666; font-size: 11px;">اسحب الصورة أو النص • عجلة الماوس للتكبير • إصبعين للتكبير/التصغير</p>
    </div>
    
    <div class="tools-panel">
        <!-- Image Shape -->
        <!-- Image Shape -->
        <div class="tool-section">
            <h4><i class="fa-solid fa-shapes"></i> شكل الصورة</h4>
            <div class="shape-buttons">
                <button class="shape-btn active" data-shape="circle" onclick="setShape('circle')"><i class="fa-regular fa-circle"></i> دائري</button>
                <button class="shape-btn" data-shape="square" onclick="setShape('square')"><i class="fa-regular fa-square"></i> مربع</button>
            </div>
        </div>
        
        <!-- Border Color -->
        <div class="tool-section">
            <h4><i class="fa-solid fa-paint-brush"></i> لون الإطار</h4>
            <div class="color-picker">
                <div class="color-option active" style="background: #FFD700" onclick="setBorderColor('#FFD700')"></div>
                <div class="color-option" style="background: #FFFFFF" onclick="setBorderColor('#FFFFFF')"></div>
                <div class="color-option" style="background: #FF0000" onclick="setBorderColor('#FF0000')"></div>
                <div class="color-option" style="background: #00FF00" onclick="setBorderColor('#00FF00')"></div>
                <div class="color-option" style="background: #00BFFF" onclick="setBorderColor('#00BFFF')"></div>
                <div class="color-option" style="background: #FF69B4" onclick="setBorderColor('#FF69B4')"></div>
                <div class="color-option" style="background: #000000; border: 1px solid #444;" onclick="setBorderColor('#000000')"></div>
                <div class="color-option" style="background: transparent; border: 2px dashed #666;" onclick="setBorderColor('none')" title="بدون إطار">✕</div>
            </div>
        </div>
        
        <!-- Border Width -->
        <div class="tool-section">
            <h4><i class="fa-solid fa-ruler"></i> سمك الإطار</h4>
            <div class="slider-group">
                <input type="range" id="borderWidth" min="0" max="15" value="4" onchange="updateBorderWidth()">
                <span class="value" id="borderWidthValue">4px</span>
            </div>
        </div>
        
        <!-- Image Size -->
        <div class="tool-section">
            <h4><i class="fa-solid fa-expand"></i> حجم الصورة</h4>
            <div class="size-row">
                <div class="form-group">
                    <label>العرض:</label>
                    <div class="slider-group">
                        <input type="range" id="imageWidth" min="10" max="80" value="35" onchange="updateImageSize()">
                        <span class="value" id="widthValue">35%</span>
                    </div>
                </div>
            </div>
            <div class="size-row" style="margin-top: 8px;">
                <div class="form-group">
                    <label>الارتفاع:</label>
                    <div class="slider-group">
                        <input type="range" id="imageHeight" min="10" max="80" value="35" onchange="updateImageSize()">
                        <span class="value" id="heightValue">35%</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Custom Text -->
        <div class="tool-section">
            <h4><i class="fa-solid fa-pen"></i> نص مخصص</h4>
            <div class="form-group">
                <input type="text" class="form-control" id="customText" placeholder="اكتب النص هنا..." onkeyup="updateCanvas()">
            </div>
            <div class="form-group">
                <label>حجم الخط:</label>
                <div class="slider-group">
                    <input type="range" id="textSize" min="12" max="72" value="28" onchange="updateCanvas()">
                    <span class="value" id="textSizeValue">28px</span>
                </div>
            </div>
            <div class="form-group">
                <label>لون النص:</label>
                <div class="color-picker" id="textColorPicker">
                    <div class="color-option active" style="background: #FFD700" onclick="setTextColor('#FFD700')"></div>
                    <div class="color-option" style="background: #FFFFFF" onclick="setTextColor('#FFFFFF')"></div>
                    <div class="color-option" style="background: #FF0000" onclick="setTextColor('#FF0000')"></div>
                    <div class="color-option" style="background: #00FF00" onclick="setTextColor('#00FF00')"></div>
                    <div class="color-option" style="background: #00BFFF" onclick="setTextColor('#00BFFF')"></div>
                </div>
            </div>
            <p style="color: #666; font-size: 10px; margin-top: 5px;">💡 اسحب النص بالماوس لتحريكه</p>
        </div>
        
        <!-- Auto Data Display -->
        <div class="tool-section">
            <h4><i class="fa-solid fa-list-check"></i> البيانات التلقائية</h4>
            <p style="color: #888; font-size: 10px; margin-bottom: 10px;">عرض بيانات المتسابق تلقائياً</p>
            
            <div class="form-group" style="display: flex; align-items: center; justify-content: space-between;">
                <label style="margin: 0;"><i class="fa-solid fa-user"></i> اسم المتسابق</label>
                <input type="checkbox" id="showName" onchange="updateCanvas()" <?= ($frameSettings['elements']['participant_name']['enabled'] ?? true) ? 'checked' : '' ?>>
            </div>
            <div class="form-group" style="display: flex; align-items: center; justify-content: space-between;">
                <label style="margin: 0;"><i class="fa-solid fa-fingerprint"></i> رقم التسجيل</label>
                <input type="checkbox" id="showId" onchange="updateCanvas()" <?= ($frameSettings['elements']['registration_id']['enabled'] ?? true) ? 'checked' : '' ?>>
            </div>
            <div class="form-group" style="display: flex; align-items: center; justify-content: space-between;">
                <label style="margin: 0;"><i class="fa-solid fa-closed-captioning"></i> رقم اللوحة</label>
                <input type="checkbox" id="showPlate" onchange="updateCanvas()" <?= ($frameSettings['elements']['plate_number']['enabled'] ?? true) ? 'checked' : '' ?>>
            </div>
            <div class="form-group" style="display: flex; align-items: center; justify-content: space-between;">
                <label style="margin: 0;"><i class="fa-solid fa-car"></i> نوع السيارة</label>
                <input type="checkbox" id="showCarType" onchange="updateCanvas()" <?= ($frameSettings['elements']['car_type']['enabled'] ?? true) ? 'checked' : '' ?>>
            </div>
            <div class="form-group" style="display: flex; align-items: center; justify-content: space-between;">
                <label style="margin: 0;"><i class="fa-solid fa-map-marker-alt"></i> المحافظة</label>
                <input type="checkbox" id="showGovernorate" onchange="updateCanvas()" <?= ($frameSettings['elements']['governorate']['enabled'] ?? true) ? 'checked' : '' ?>>
            </div>
        </div>
        
        <div class="instructions">
            <h5><i class="fa-solid fa-info-circle"></i> التعليمات</h5>
            <ul>
                <li>• اسحب الصورة بالماوس لتحريكها</li>
                <li>• اسحب النص لتحريكه</li>
                <li>• عجلة الماوس للتكبير/التصغير</li>
            </ul>
        </div>
    </div>
</div>

<script>
const canvas = document.getElementById('editorCanvas');
const ctx = canvas.getContext('2d');

// Read settings from frame_settings
const frameSettings = <?= json_encode($frameSettings['elements']) ?>;
const photoSettings = frameSettings.personal_photo || {};

// Initialize state with defaults
let state = {
    shape: photoSettings.shape || 'circle',
    imageX: photoSettings.x || 50,
    imageY: photoSettings.y || 50,
    imageWidth: photoSettings.width || 35,
    imageHeight: photoSettings.height || 35,
    borderColor: photoSettings.border_color || '#FFD700',
    borderWidth: photoSettings.border_width || 4,
    text: '',
    textX: 50,
    textY: 85,
    textSize: 28,
    textColor: '#FFD700'
};

// Initialize element positions from global settings
let elementPositions = {
    participant_name: { x: frameSettings.participant_name?.x || 18, y: frameSettings.participant_name?.y || 6 },
    registration_id: { x: frameSettings.registration_id?.x || 49, y: frameSettings.registration_id?.y || 87 },
    car_type: { x: frameSettings.car_type?.x || 81, y: frameSettings.car_type?.y || 95 },
    plate_number: { x: frameSettings.plate_number?.x || 84, y: frameSettings.plate_number?.y || 6 },
    governorate: { x: frameSettings.governorate?.x || 11, y: frameSettings.governorate?.y || 95 }
};

// Debug Data
console.log("Registration Data:", <?= json_encode($regData) ?>);
console.log("Frame Settings (JS):", frameSettings);
console.log("Initial Element Positions:", elementPositions);

// Apply Saved State if exists
const savedState = <?= $savedState ? json_encode($savedState) : 'null' ?>;
if (savedState) {
    console.log("Loading saved state:", savedState);
    // Merge basic state
    state = { ...state, ...savedState };
    
    // Merge element positions if they exist in saved state
    // Note: older saved states might not have elementPositions
    if (savedState.elementPositions) {
        elementPositions = { ...elementPositions, ...savedState.elementPositions };
    } else {
        // Migration: If saved state exists but no element positions, 
        // check if they were saved in the root (legacy) or just keep defaults
        // For now we keep defaults from frameSettings unless specifically overridden
    }
}

let frameImg = new Image();
let personImg = new Image();
let isDraggingImage = false;
let isDraggingText = false;
let isDraggingElement = null; // 'participant_name', 'registration_id', 'plate_number', 'governorate'
let dragOffset = { x: 0, y: 0 };

// Image loading state
let frameLoaded = false;
let personLoaded = false;

function checkImagesLoaded() {
    if (frameLoaded && personLoaded) {
        // Hide loading overlay
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) overlay.style.display = 'none';
        
        // Enable save button
        const saveBtn = document.getElementById('saveBtn');
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.style.opacity = '1';
            saveBtn.innerHTML = '<i class="fa-solid fa-save"></i> حفظ الصورة';
        }
    }
    render();
}

// Enable CORS for images to prevent Canvas Taint
frameImg.crossOrigin = "anonymous";
personImg.crossOrigin = "anonymous";

frameImg.onload = function() {
    frameLoaded = true;
    checkImagesLoaded();
};

frameImg.onerror = function() {
    console.error("Failed to load frame image");
    frameLoaded = true; // Proceed anyway
    checkImagesLoaded();
};

personImg.onload = function() {
    personLoaded = true;
    checkImagesLoaded();
};

personImg.onerror = function() {
    console.error("Failed to load person image");
    personLoaded = true; // Proceed anyway
    checkImagesLoaded();
};

// Start loading images with cache busting to force reload
// Use absolute path logic if needed, but relative ../ should likely work if on same domain
// Adding timestamps to avoid browser caching issues with CORS
frameImg.src = '<?= $frameImage ?>?t=' + new Date().getTime();
personImg.src = '<?= $selectedImage ?>?t=' + new Date().getTime();

// Fallback timeout - enable save after 5 seconds even if images fail
setTimeout(function() {
    if (!frameLoaded || !personLoaded) {
        console.warn("Image load timeout - forcing ready state");
        frameLoaded = true;
        personLoaded = true;
        checkImagesLoaded();
    }
}, 5000);

function render() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Draw frame
    if (frameImg.complete && frameImg.naturalWidth > 0) {
        ctx.drawImage(frameImg, 0, 0, canvas.width, canvas.height);
    }
    
    // Draw person/car image
    if (personImg.complete && personImg.naturalWidth > 0) {
        const w = canvas.width * (state.imageWidth / 100);
        const h = canvas.height * (state.imageHeight / 100);
        const x = canvas.width * (state.imageX / 100) - w / 2;
        const y = canvas.height * (state.imageY / 100) - h / 2;
        
        ctx.save();
        
        if (state.shape === 'circle') {
            ctx.beginPath();
            ctx.ellipse(x + w/2, y + h/2, w/2, h/2, 0, 0, Math.PI * 2);
            ctx.closePath();
            ctx.clip();
        }
        
        ctx.drawImage(personImg, x, y, w, h);
        ctx.restore();
        
        // Draw border
        if (state.borderColor !== 'none' && state.borderWidth > 0) {
            ctx.strokeStyle = state.borderColor;
            ctx.lineWidth = state.borderWidth;
            if (state.shape === 'circle') {
                ctx.beginPath();
                ctx.ellipse(x + w/2, y + h/2, w/2, h/2, 0, 0, Math.PI * 2);
                ctx.stroke();
            } else {
                ctx.strokeRect(x, y, w, h);
            }
        }
    }
    
    // Draw custom text
    if (state.text) {
        const textX = canvas.width * (state.textX / 100);
        const textY = canvas.height * (state.textY / 100);
        
        ctx.font = `bold ${state.textSize}px Cairo, Arial`;
        ctx.fillStyle = state.textColor;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.shadowColor = 'rgba(0,0,0,0.5)';
        ctx.shadowBlur = 4;
        ctx.shadowOffsetX = 2;
        ctx.shadowOffsetY = 2;
        ctx.fillText(state.text, textX, textY);
        ctx.shadowColor = 'transparent';
    }
    
    // Draw auto data
    const regData = <?= json_encode($regData) ?>;
    const frameSettings = <?= json_encode($frameSettings['elements']) ?>;
    
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.shadowColor = 'rgba(0,0,0,0.5)';
    ctx.shadowBlur = 3;
    ctx.shadowOffsetX = 1;
    ctx.shadowOffsetY = 1;
    
    // Show name (draggable)
    if (document.getElementById('showName').checked && regData.name) {
        const nameSettings = frameSettings.participant_name;
        ctx.font = `bold ${nameSettings.font_size}px Cairo, Arial`;
        ctx.fillStyle = nameSettings.color;
        const nameX = canvas.width * (elementPositions.participant_name.x / 100);
        const nameY = canvas.height * (elementPositions.participant_name.y / 100);
        ctx.fillText(regData.name, nameX, nameY);
    }
    
    // Show ID (draggable)
    if (document.getElementById('showId').checked && regData.id) {
        const idSettings = frameSettings.registration_id;
        ctx.font = `bold ${idSettings.font_size}px Cairo, Arial`;
        ctx.fillStyle = idSettings.color;
        const idX = canvas.width * (elementPositions.registration_id.x / 100);
        const idY = canvas.height * (elementPositions.registration_id.y / 100);
        ctx.fillText(regData.id, idX, idY);
    }
    
    // Show plate (draggable)
    if (document.getElementById('showPlate').checked && regData.plate) {
        const plateSettings = frameSettings.plate_number;
        ctx.font = `bold ${plateSettings.font_size}px Cairo, Arial`;
        ctx.fillStyle = plateSettings.color;
        const plateX = canvas.width * (elementPositions.plate_number.x / 100);
        const plateY = canvas.height * (elementPositions.plate_number.y / 100);
        ctx.fillText(regData.plate, plateX, plateY);
    }
    
    // Show car type (draggable)
    if (document.getElementById('showCarType') && document.getElementById('showCarType').checked && regData.car_type) {
        const carTypeSettings = frameSettings.car_type || { font_size: 18, color: '#FFD700' };
        ctx.font = `bold ${carTypeSettings.font_size}px Cairo, Arial`;
        ctx.fillStyle = carTypeSettings.color;
        const carTypeX = canvas.width * (elementPositions.car_type.x / 100);
        const carTypeY = canvas.height * (elementPositions.car_type.y / 100);
        ctx.fillText(regData.car_type, carTypeX, carTypeY);
    }

    // Show governorate (draggable)
    if (document.getElementById('showGovernorate') && document.getElementById('showGovernorate').checked && regData.governorate) {
        const govSettings = frameSettings.governorate || { font_size: 18, color: '#FFFFFF' };
        ctx.font = `bold ${govSettings.font_size}px Cairo, Arial`;
        ctx.fillStyle = govSettings.color;
        const govX = canvas.width * (elementPositions.governorate.x / 100);
        const govY = canvas.height * (elementPositions.governorate.y / 100);
        
        // Clean governorate name (remove numbers if present e.g. "Basra (14)" -> "Basra")
        let govText = regData.governorate.replace(/\s*\([0-9]+\)/, '');
        ctx.fillText(govText, govX, govY);
    }
    
    ctx.shadowColor = 'transparent';
}

// Mouse events
canvas.addEventListener('mousedown', function(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const mouseX = (e.clientX - rect.left) * scaleX;
    const mouseY = (e.clientY - rect.top) * scaleY;
    
    // Check auto-data text elements first
    const regData = <?= json_encode($regData) ?>;
    
    // Check name
    if (document.getElementById('showName').checked && regData.name) {
        const nameSettings = frameSettings.participant_name;
        ctx.font = `bold ${nameSettings.font_size}px Cairo, Arial`;
        const nameX = canvas.width * (elementPositions.participant_name.x / 100);
        const nameY = canvas.height * (elementPositions.participant_name.y / 100);
        const textWidth = ctx.measureText(regData.name).width;
        
        if (mouseX >= nameX - textWidth/2 - 20 && mouseX <= nameX + textWidth/2 + 20 &&
            mouseY >= nameY - nameSettings.font_size/2 - 10 && mouseY <= nameY + nameSettings.font_size/2 + 10) {
            isDraggingElement = 'participant_name';
            dragOffset.x = mouseX - nameX;
            dragOffset.y = mouseY - nameY;
            canvas.style.cursor = 'grabbing';
            render();
            return;
        }
    }
    
    // Check ID
    if (document.getElementById('showId').checked && regData.id) {
        const idSettings = frameSettings.registration_id;
        ctx.font = `bold ${idSettings.font_size}px Cairo, Arial`;
        const idX = canvas.width * (elementPositions.registration_id.x / 100);
        const idY = canvas.height * (elementPositions.registration_id.y / 100);
        const textWidth = ctx.measureText(regData.id).width;
        
        if (mouseX >= idX - textWidth/2 - 20 && mouseX <= idX + textWidth/2 + 20 &&
            mouseY >= idY - idSettings.font_size/2 - 10 && mouseY <= idY + idSettings.font_size/2 + 10) {
            isDraggingElement = 'registration_id';
            dragOffset.x = mouseX - idX;
            dragOffset.y = mouseY - idY;
            canvas.style.cursor = 'grabbing';
            render();
            return;
        }
    }
    
    // Check plate
    if (document.getElementById('showPlate').checked && regData.plate) {
        const plateSettings = frameSettings.plate_number;
        ctx.font = `bold ${plateSettings.font_size}px Cairo, Arial`;
        const plateX = canvas.width * (elementPositions.plate_number.x / 100);
        const plateY = canvas.height * (elementPositions.plate_number.y / 100);
        const textWidth = ctx.measureText(regData.plate).width;
        
        if (mouseX >= plateX - textWidth/2 - 20 && mouseX <= plateX + textWidth/2 + 20 &&
            mouseY >= plateY - plateSettings.font_size/2 - 10 && mouseY <= plateY + plateSettings.font_size/2 + 10) {
            isDraggingElement = 'plate_number';
            dragOffset.x = mouseX - plateX;
            dragOffset.y = mouseY - plateY;
            canvas.style.cursor = 'grabbing';
            render();
            return;
        }
    }
    
    // Check car_type
    if (document.getElementById('showCarType') && document.getElementById('showCarType').checked && regData.car_type) {
        const carTypeSettings = frameSettings.car_type || { font_size: 18 };
        ctx.font = `bold ${carTypeSettings.font_size}px Cairo, Arial`;
        const carTypeX = canvas.width * (elementPositions.car_type.x / 100);
        const carTypeY = canvas.height * (elementPositions.car_type.y / 100);
        const textWidth = ctx.measureText(regData.car_type).width;
        
        if (mouseX >= carTypeX - textWidth/2 - 20 && mouseX <= carTypeX + textWidth/2 + 20 &&
            mouseY >= carTypeY - carTypeSettings.font_size/2 - 10 && mouseY <= carTypeY + carTypeSettings.font_size/2 + 10) {
            isDraggingElement = 'car_type';
            dragOffset.x = mouseX - carTypeX;
            dragOffset.y = mouseY - carTypeY;
            canvas.style.cursor = 'grabbing';
            render();
            return;
        }
    }
    
    // Check custom text
    if (state.text) {
        const textX = canvas.width * (state.textX / 100);
        const textY = canvas.height * (state.textY / 100);
        ctx.font = `bold ${state.textSize}px Cairo, Arial`;
        const textWidth = ctx.measureText(state.text).width;
        
        if (mouseX >= textX - textWidth/2 - 20 && mouseX <= textX + textWidth/2 + 20 &&
            mouseY >= textY - state.textSize/2 - 10 && mouseY <= textY + state.textSize/2 + 10) {
            isDraggingText = true;
            dragOffset.x = mouseX - textX;
            dragOffset.y = mouseY - textY;
            return;
        }
    }
    
    // Check image
    const w = canvas.width * (state.imageWidth / 100);
    const h = canvas.height * (state.imageHeight / 100);
    const imgX = canvas.width * (state.imageX / 100) - w / 2;
    const imgY = canvas.height * (state.imageY / 100) - h / 2;
    
    if (mouseX >= imgX && mouseX <= imgX + w && mouseY >= imgY && mouseY <= imgY + h) {
        isDraggingImage = true;
        dragOffset.x = mouseX - canvas.width * (state.imageX / 100);
        dragOffset.y = mouseY - canvas.height * (state.imageY / 100);
    }
});

canvas.addEventListener('mousemove', function(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const mouseX = (e.clientX - rect.left) * scaleX;
    const mouseY = (e.clientY - rect.top) * scaleY;
    
    // Handle element dragging (name, ID, plate)
    if (isDraggingElement) {
        const newX = Math.max(5, Math.min(95, ((mouseX - dragOffset.x) / canvas.width) * 100));
        const newY = Math.max(5, Math.min(95, ((mouseY - dragOffset.y) / canvas.height) * 100));
        elementPositions[isDraggingElement].x = Math.round(newX);
        elementPositions[isDraggingElement].y = Math.round(newY);
        render();
        return;
    }
    
    if (isDraggingImage) {
        state.imageX = Math.max(10, Math.min(90, ((mouseX - dragOffset.x) / canvas.width) * 100));
        state.imageY = Math.max(10, Math.min(90, ((mouseY - dragOffset.y) / canvas.height) * 100));
        render();
    }
    
    if (isDraggingText) {
        state.textX = Math.max(10, Math.min(90, ((mouseX - dragOffset.x) / canvas.width) * 100));
        state.textY = Math.max(5, Math.min(95, ((mouseY - dragOffset.y) / canvas.height) * 100));
        render();
    }
});

canvas.addEventListener('mouseup', () => { 
    isDraggingImage = false; 
    isDraggingText = false; 
    isDraggingElement = null;
    canvas.style.cursor = 'crosshair';
});
canvas.addEventListener('mouseleave', () => { 
    isDraggingImage = false; 
    isDraggingText = false; 
    isDraggingElement = null;
    canvas.style.cursor = 'crosshair';
});

canvas.addEventListener('wheel', function(e) {
    e.preventDefault();
    const delta = e.deltaY > 0 ? -2 : 2;
    state.imageWidth = Math.max(10, Math.min(80, state.imageWidth + delta));
    state.imageHeight = Math.max(10, Math.min(80, state.imageHeight + delta));
    document.getElementById('imageWidth').value = state.imageWidth;
    document.getElementById('imageHeight').value = state.imageHeight;
    document.getElementById('widthValue').textContent = state.imageWidth + '%';
    document.getElementById('heightValue').textContent = state.imageHeight + '%';
    render();
});

function setShape(shape) {
    state.shape = shape;
    document.querySelectorAll('.shape-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[data-shape="${shape}"]`).classList.add('active');
    render();
}

function setBorderColor(color) {
    state.borderColor = color;
    document.querySelectorAll('.tool-section:nth-child(2) .color-option').forEach(el => el.classList.remove('active'));
    if (event && event.target) event.target.classList.add('active');
    render();
}

function updateBorderWidth() {
    state.borderWidth = parseInt(document.getElementById('borderWidth').value);
    document.getElementById('borderWidthValue').textContent = state.borderWidth + 'px';
    render();
}

function updateImageSize() {
    state.imageWidth = parseInt(document.getElementById('imageWidth').value);
    state.imageHeight = parseInt(document.getElementById('imageHeight').value);
    document.getElementById('widthValue').textContent = state.imageWidth + '%';
    document.getElementById('heightValue').textContent = state.imageHeight + '%';
    render();
}

function updateCanvas() {
    state.text = document.getElementById('customText').value;
    state.textSize = parseInt(document.getElementById('textSize').value);
    document.getElementById('textSizeValue').textContent = state.textSize + 'px';
    render();
}

function setTextColor(color) {
    state.textColor = color;
    document.querySelectorAll('#textColorPicker .color-option').forEach(el => el.classList.remove('active'));
    if (event && event.target) event.target.classList.add('active');
    render();
}

function saveImage() {
    const saveBtn = document.getElementById('saveBtn');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري المعالجة...';

    try {
        // Attempt to get data URL - this will throw if canvas is tainted
        const canvasData = canvas.toDataURL('image/png');
        
        fetch('save_edited_image.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                wasel: '<?= $wasel ?>',
                state: { ...state, elementPositions: elementPositions },
                canvas_data: canvasData
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Server returned ' + response.status);
            }
            if (!response.ok) {
                throw new Error('Server returned ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                alert('✅ تم حفظ الصورة بنجاح!\n\nللموافقة وإرسال الرسالة، اضغط على زر "قبول" من لوحة التحكم.');
                window.location.href = '../dashboard.php';
            } else {
                alert('❌ حدث خطأ من السيرفر: ' + (data.error || 'خطأ غير معروف'));
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalText;
            }
        })
        .catch(error => {
            alert('❌ خطأ في الاتصال بالسيرفر: ' + error.message);
            console.error(error);
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
        });
        
    } catch (e) {
        console.error("Canvas Export Error:", e);
        alert('⚠️ خطأ أمني في المتصفح (CORS):\n\nلا يمكن حفظ الصورة لأن المتصفح يعتبر الصور المستخدمة "غير آمنة".\n\nحاول استخدام متصفح آخر أو تأكد من أن الصور مرفوعة على نفس الدومين.');
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

// ==========================================
// Touch Events Support for Mobile
// ==========================================

let touchStartDistance = 0;
let initialWidth = 0;
let initialHeight = 0;

function getTouchPosition(touch) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return {
        x: (touch.clientX - rect.left) * scaleX,
        y: (touch.clientY - rect.top) * scaleY
    };
}

function getTouchDistance(touches) {
    const dx = touches[0].clientX - touches[1].clientX;
    const dy = touches[0].clientY - touches[1].clientY;
    return Math.sqrt(dx * dx + dy * dy);
}

canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    
    // Pinch to zoom (resize) - two fingers
    if (e.touches.length === 2) {
        touchStartDistance = getTouchDistance(e.touches);
        initialWidth = state.imageWidth;
        initialHeight = state.imageHeight;
        return;
    }
    
    // Single touch - drag
    const touch = e.touches[0];
    const pos = getTouchPosition(touch);
    
    // Check if touching the image
    const imageX = (state.imageX / 100) * canvas.width;
    const imageY = (state.imageY / 100) * canvas.height;
    const imageW = (state.imageWidth / 100) * canvas.width;
    const imageH = (state.imageHeight / 100) * canvas.height;
    
    const distance = Math.sqrt(Math.pow(pos.x - imageX, 2) + Math.pow(pos.y - imageY, 2));
    const hitRadius = Math.max(imageW, imageH) / 2 + 30;
    
    if (distance < hitRadius) {
        isDraggingImage = true;
        dragOffset.x = pos.x - imageX;
        dragOffset.y = pos.y - imageY;
        return;
    }
    
    // Check text elements
    const regData = <?= json_encode($regData) ?>;
    
    if (document.getElementById('showName').checked && regData.name) {
        const nameX = canvas.width * (elementPositions.participant_name.x / 100);
        const nameY = canvas.height * (elementPositions.participant_name.y / 100);
        if (Math.abs(pos.x - nameX) < 100 && Math.abs(pos.y - nameY) < 30) {
            isDraggingElement = 'participant_name';
            dragOffset.x = pos.x - nameX;
            dragOffset.y = pos.y - nameY;
            render();
            return;
        }
    }
    
    if (document.getElementById('showId').checked && regData.id) {
        const idX = canvas.width * (elementPositions.registration_id.x / 100);
        const idY = canvas.height * (elementPositions.registration_id.y / 100);
        if (Math.abs(pos.x - idX) < 80 && Math.abs(pos.y - idY) < 30) {
            isDraggingElement = 'registration_id';
            dragOffset.x = pos.x - idX;
            dragOffset.y = pos.y - idY;
            render();
            return;
        }
    }
    
    if (document.getElementById('showPlate').checked && regData.plate) {
        const plateX = canvas.width * (elementPositions.plate_number.x / 100);
        const plateY = canvas.height * (elementPositions.plate_number.y / 100);
        if (Math.abs(pos.x - plateX) < 100 && Math.abs(pos.y - plateY) < 30) {
            isDraggingElement = 'plate_number';
            dragOffset.x = pos.x - plateX;
            dragOffset.y = pos.y - plateY;
            render();
            return;
        }
    }
    
    // Check custom text
    if (state.text) {
        const textX = canvas.width * (state.textX / 100);
        const textY = canvas.height * (state.textY / 100);
        if (Math.abs(pos.x - textX) < 100 && Math.abs(pos.y - textY) < 30) {
            isDraggingText = true;
            dragOffset.x = pos.x - textX;
            dragOffset.y = pos.y - textY;
            return;
        }
    }
}, { passive: false });

canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    
    // Pinch to zoom - resize image
    if (e.touches.length === 2 && touchStartDistance > 0) {
        const currentDistance = getTouchDistance(e.touches);
        const scale = currentDistance / touchStartDistance;
        
        state.imageWidth = Math.max(10, Math.min(80, Math.round(initialWidth * scale)));
        state.imageHeight = Math.max(10, Math.min(80, Math.round(initialHeight * scale)));
        
        document.getElementById('imageWidth').value = state.imageWidth;
        document.getElementById('imageHeight').value = state.imageHeight;
        document.getElementById('widthValue').textContent = state.imageWidth + '%';
        document.getElementById('heightValue').textContent = state.imageHeight + '%';
        
        render();
        return;
    }
    
    if (e.touches.length !== 1) return;
    
    const touch = e.touches[0];
    const pos = getTouchPosition(touch);
    
    // Drag image
    if (isDraggingImage) {
        state.imageX = Math.max(5, Math.min(95, ((pos.x - dragOffset.x) / canvas.width) * 100));
        state.imageY = Math.max(5, Math.min(95, ((pos.y - dragOffset.y) / canvas.height) * 100));
        render();
        return;
    }
    
    // Drag elements
    if (isDraggingElement) {
        const newX = Math.max(5, Math.min(95, ((pos.x - dragOffset.x) / canvas.width) * 100));
        const newY = Math.max(5, Math.min(95, ((pos.y - dragOffset.y) / canvas.height) * 100));
        elementPositions[isDraggingElement].x = newX;
        elementPositions[isDraggingElement].y = newY;
        render();
        return;
    }
    
    // Drag text
    if (isDraggingText) {
        state.textX = Math.max(5, Math.min(95, ((pos.x - dragOffset.x) / canvas.width) * 100));
        state.textY = Math.max(5, Math.min(95, ((pos.y - dragOffset.y) / canvas.height) * 100));
        render();
        return;
    }
}, { passive: false });

canvas.addEventListener('touchend', function(e) {
    isDraggingImage = false;
    isDraggingText = false;
    isDraggingElement = null;
    touchStartDistance = 0;
});

canvas.addEventListener('touchcancel', function(e) {
    isDraggingImage = false;
    isDraggingText = false;
    isDraggingElement = null;
    touchStartDistance = 0;
});

render();
</script>
</body>
</html>
