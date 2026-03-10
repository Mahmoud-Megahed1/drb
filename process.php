<?php
session_start();
// تضمين ملف WaSender للإرسال عبر WhatsApp
// تضمين ملف WaSender للإرسال عبر WhatsApp
require_once 'wasender.php';
require_once 'include/db.php';
require_once 'include/helpers.php';
require_once 'services/MemberService.php';
require_once 'include/RegistrationActionLogger.php';

// Load Settings Global
$settingsFile = 'admin/data/registration_settings.json';
$settings = [];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
}

// Load Form Fields Settings
$fieldSettingsFile = 'admin/data/form_fields_settings.json';
$fieldSettings = [
    'personal_photo_enabled' => true,
    'personal_photo_required' => true,
    'instagram_enabled' => true,
    'instagram_required' => false,
    'license_images_enabled' => false,
    'license_images_required' => false,
    'id_front_enabled' => true,
    'id_front_required' => true,
    'id_back_enabled' => true,
    'id_back_required' => true
];
if (file_exists($fieldSettingsFile)) {
    $loaded = json_decode(file_get_contents($fieldSettingsFile), true);
    if (is_array($loaded)) {
        $fieldSettings = array_merge($fieldSettings, $loaded);
    }
}

// Generate unique registration code (6 characters)
function generateRegistrationCode($existingData) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed confusing chars like 0,O,1,I
    $maxAttempts = 100;
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        // Check if code already exists
        $exists = false;
        foreach ($existingData as $item) {
            if (isset($item['registration_code']) && $item['registration_code'] === $code) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            return $code;
        }
    }
    
    // Fallback: use timestamp-based code
    return strtoupper(substr(md5(time() . rand()), 0, 6));
}

// Find registration by code
function findByRegistrationCode($code, $existingData) {
    foreach ($existingData as $item) {
        if (isset($item['registration_code']) && $item['registration_code'] === $code) {
            return $item;
        }
    }
    return null;
}

function removeItemById($file, $target_id) {
    $file_path = "admin/data/" . $file . ".json";
    $json_data = file_get_contents($file_path);
    $data = json_decode($json_data, true);
    
    // Remove the item
    foreach ($data as $key => $item) {
        if ($item['wasel'] == $target_id) {
            unset($data[$key]);
            break;
        }
    }
    
    // Reindex array keys only (keep original IDs)
    $data = array_values($data);
    
    // STOP RE-INDEXING WASEL IDs to prevent log mismatch
    // (Old code removed: foreach... $item['wasel'] = index+1)
    
    $updated_json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($file_path, $updated_json_data);
    
    // Clean up Round Logs for this ID
    $logsFile = 'admin/data/round_logs.json';
    if (file_exists($logsFile)) {
        $logs = json_decode(file_get_contents($logsFile), true) ?? [];
        $originalCount = count($logs);
        
        $logs = array_filter($logs, function($log) use ($target_id) {
            return ($log['participant_id'] != $target_id);
        });
        
        if (count($logs) !== $originalCount) {
             file_put_contents($logsFile, json_encode(array_values($logs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}

function removeItemById_archive($file, $target_id) {
    $file_path = "admin/data/" . $file . ".json";
    $json_data = file_get_contents($file_path);
    $data = json_decode($json_data, true);
    
    foreach ($data as $key => $item) {
        if ($item['wasel'] == $target_id) {
            $data[$key]['remove'] = 1;
        }
    }
    
    $data = array_values($data);
    $updated_json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($file_path, $updated_json_data);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["action"]) && $_POST["action"] === 'remove') {
    $file = $_POST["file"];
    $wasel = $_POST["wasel"];
    removeItemById($file, $wasel);
    
    // Log to AdminLogger
    require_once __DIR__ . '/include/AdminLogger.php';
    $adminLogger = new AdminLogger();
    $username = isset($_SESSION['user']) ? ($_SESSION['user']->username ?? 'unknown') : 'unknown';
    $adminLogger->log(
        AdminLogger::ACTION_PARTICIPANT_DELETE,
        $username,
        'حذف تسجيل من الداشبورد - واصل: ' . $wasel,
        ['wasel' => $wasel, 'source' => 'dashboard']
    );
    
    echo 'تم حذف العنصر بنجاح.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["action"]) && $_POST["action"] === 'archive') {
    $file = $_POST["file"];
    $wasel = $_POST["wasel"];
    removeItemById_archive($file, $wasel);
    
    // Log to AdminLogger
    require_once __DIR__ . '/include/AdminLogger.php';
    $adminLogger = new AdminLogger();
    $username = isset($_SESSION['user']) ? ($_SESSION['user']->username ?? 'unknown') : 'unknown';
    $adminLogger->log(
        AdminLogger::ACTION_PARTICIPANT_DELETE,
        $username,
        'أرشفة تسجيل من الداشبورد - واصل: ' . $wasel,
        ['wasel' => $wasel, 'source' => 'dashboard', 'type' => 'archive']
    );
    
    echo 'تم أرشفة العنصر بنجاح.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Car Registration Processing
    
    // Validate required fields
    $requiredFields = ['participation_type', 'full_name', 'phone', 'governorate', 
                       'car_type', 'car_year', 'car_color', 'engine_size',
                       'plate_letter', 'plate_number', 'plate_governorate'];
                       
    if ($fieldSettings['instagram_enabled'] && $fieldSettings['instagram_required']) {
        $requiredFields[] = 'instagram';
    }
    
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo 'يرجى ملء جميع الحقول المطلوبة: ' . $field;
            exit;
        }
    }
    
    // Phone Normalization & Validation
    $rawPhone = $_POST['phone'];
    $phone = preg_replace('/\D/', '', $rawPhone); // Remove non-digits
    $countryCode = $_POST['country_code'] ?? '+964';
    
    // IRAQ (+964) Validation Rules
    if ($countryCode === '+964') {
        // Handle +964 or 964 prefix if user typed it
        if (str_starts_with($phone, '964')) {
            $phone = substr($phone, 3);
        }
        
        // If it starts with 07 and is 11 digits, strip the 0 to make it 10 digits
        if (strlen($phone) === 11 && str_starts_with($phone, '07')) {
            $phone = substr($phone, 1);
        }
        
        // Validate: must be exactly 10 digits starting with 7
        if (strlen($phone) !== 10) {
            http_response_code(400);
            echo 'رقم الهاتف العراقي يجب أن يكون 10 أرقام بالضبط (مثال: 780xxxxxxx)';
            exit;
        }
        if (!str_starts_with($phone, '7')) {
            http_response_code(400);
            echo 'رقم الهاتف العراقي يجب أن يبدأ بـ 7';
            exit;
        }
    } else {
        // International Numbers Validation
        // Allow 8 to 15 digits, just ensure it's numeric
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            http_response_code(400);
            echo 'رقم الهاتف يبدو غير صحيح (يجب أن يكون بين 8 و 15 رقم)';
            exit;
        }
    }
    
    // Update POST with normalized phone
    $_POST['phone'] = $phone;
    
    // Check Blacklist
    $blacklistFile = 'admin/data/blacklist.json';
    if (file_exists($blacklistFile)) {
        $blacklist = json_decode(file_get_contents($blacklistFile), true) ?? ['phones' => [], 'plates' => []];
        
        // Check Phone
        $phoneToCheck = str_replace([' ', '-'], '', $_POST['phone']);
        foreach ($blacklist['phones'] ?? [] as $bPhone) {
            if (!empty($bPhone) && strpos($phoneToCheck, $bPhone) !== false) {
                 http_response_code(400);
                 echo "عذراً، لا يمكن إتمام التسجيل.<br>يرجى مراجعة الإدارة: <a href='https://wa.me/" . ($settings['support_number'] ?? '9647736000096') . "' target='_blank' style='color:#fff;text-decoration:underline;'>تواصل معنا عبر الواتساب</a>";
                 exit;
             }
        }

        // Check Plate Blacklist
        $plateNumber = trim($_POST['plate_number'] ?? '');
        $plateLetter = trim($_POST['plate_letter'] ?? '');
        $plateGov = trim($_POST['plate_governorate'] ?? '');
        
        // Construct common formats for checking
        $plateFull1 = $plateGov . ' ' . $plateLetter . ' ' . $plateNumber; // Baghdad A 123456
        $plateFull2 = $plateLetter . ' ' . $plateNumber . ' ' . $plateGov; // A 123456 Baghdad
        $plateFull3 = $plateLetter . ' ' . $plateNumber; // A 123456
        
        foreach ($blacklist['plates'] ?? [] as $bPlate) {
            if (!empty($bPlate)) {
                $bPlate = trim($bPlate);
                // Check against number ONLY or Full String
                if ($bPlate === $plateNumber || 
                    strpos($plateFull1, $bPlate) !== false || 
                    strpos($plateFull2, $bPlate) !== false ||
                    strpos($plateFull3, $bPlate) !== false) {
                     http_response_code(400);
                     echo "عذراً، هذه المركبة محظورة من التسجيل.<br>يرجى مراجعة الإدارة: <a href='https://wa.me/" . ($settings['support_number'] ?? '9647736000096') . "' target='_blank' style='color:#fff;text-decoration:underline;'>تواصل معنا عبر الواتساب</a>";
                     exit;
                }
            }
        }
    }

    // Get previous images if using quick registration
    $previousImages = [];
    if (!empty($_POST['previous_images'])) {
        $previousImages = json_decode($_POST['previous_images'], true) ?? [];
        
        // Normalize old keys to new standard keys to prevent duplicates
        if (isset($previousImages['national_id_front']) && !isset($previousImages['id_front'])) {
            $previousImages['id_front'] = $previousImages['national_id_front'];
        }
        if (isset($previousImages['national_id_back']) && !isset($previousImages['id_back'])) {
            $previousImages['id_back'] = $previousImages['national_id_back'];
        }
        
        // Unset old duplicate keys to keep array clean
        unset($previousImages['national_id_front']);
        unset($previousImages['national_id_back']);
    }
    
    // Validate required files - check dynamic settings
    $requiredFiles = []; // All files we expect to process (optional or required)
    $mandatoryFiles = []; // Files that MUST be present

    // Always require car images
    $requiredFiles[] = 'front_image';
    $requiredFiles[] = 'back_image';
    $mandatoryFiles[] = 'front_image';
    $mandatoryFiles[] = 'back_image';

    // Conditional Files Validation setup
    if ($fieldSettings['id_front_enabled'] && $fieldSettings['id_front_required']) {
        $mandatoryFiles[] = 'id_front';
    }
    if ($fieldSettings['id_back_enabled'] && $fieldSettings['id_back_required']) {
        $mandatoryFiles[] = 'id_back';
    }
    if ($fieldSettings['personal_photo_enabled'] && $fieldSettings['personal_photo_required']) {
        $mandatoryFiles[] = 'personal_photo';
    }
    if ($fieldSettings['license_images_enabled'] && $fieldSettings['license_images_required']) {
        $mandatoryFiles[] = 'license_front';
        $mandatoryFiles[] = 'license_back';
    }

    foreach ($mandatoryFiles as $fileField) {
        $hasNewFile = isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK;
        $hasPreviousImage = isset($previousImages[$fileField]) && !empty($previousImages[$fileField]);
        
        if (!$hasNewFile && !$hasPreviousImage) {
            http_response_code(400);
            echo 'يرجى رفع جميع الصور المطلوبة: ' . $fileField;
            exit;
        }
    }
    
    // Create upload directory
    $uploadDir = 'uploads/' . date('Y-m') . '/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique ID first
    $data_file_location = 'admin/data/data.json';
    
    // Create directory if it doesn't exist
    if (!file_exists('admin/data')) {
        mkdir('admin/data', 0777, true);
    }
    
    // Read existing data
    $existingData = [];
    if (file_exists($data_file_location)) {
        $existingData = json_decode(file_get_contents($data_file_location), true);
        if (!is_array($existingData)) {
            $existingData = [];
        }
    }
    
    // Check for duplicate plate number (prevent same car registering twice)
    // Skip this check if using quick registration code (user is updating their registration)
    $usedRegCode = $_POST['used_registration_code'] ?? '';
    $newPlate = trim($_POST['plate_letter']) . ' ' . trim($_POST['plate_number']) . ' - ' . trim($_POST['plate_governorate']);
    $normalizedPhone = $_POST['phone']; // Already normalized above
    
    foreach ($existingData as $key => $item) {
        // Skip if updating own registration
        if (!empty($usedRegCode) && isset($item['registration_code']) && $item['registration_code'] === $usedRegCode) {
            // User is updating their registration - remove old entry
            unset($existingData[$key]);
            $existingData = array_values($existingData); // Re-index array
            break;
        }
        
        // Check phone uniqueness
        $existingPhone = preg_replace('/\D/', '', $item['phone'] ?? '');
        
        // Normalize existing phone to 10 digits starting with 7
        if (str_starts_with($existingPhone, '964')) {
            $existingPhone = substr($existingPhone, 3);
        }
        if (strlen($existingPhone) === 11 && str_starts_with($existingPhone, '07')) {
            $existingPhone = substr($existingPhone, 1);
        }
        if (strlen($existingPhone) === 9 && str_starts_with($existingPhone, '7')) {
            // This case shouldn't happen for Iraqi mobiles but just in case
        }
        
        if ($existingPhone === $normalizedPhone) {
            http_response_code(400);
            echo "🛑 رقم الهاتف مسجّل مسبقاً في هذه البطولة!";
            if (!empty($item['registration_code'])) {
                echo "<br><br>كود التسجيل الخاص بك هو: <strong>" . $item['registration_code'] . "</strong>";
                echo "<br>جاري جلب بياناتك السابقة تلقائياً للتعديل عليها...";
                echo "<script>
                        document.getElementById('registration_code').value = '" . $item['registration_code'] . "'; 
                        window.scrollTo({top: document.getElementById('registration_code').offsetTop - 100, behavior: 'smooth'}); 
                        setTimeout(lookupCode, 800);
                      </script>";
            }
            exit;
        }
        
        // Check plate uniqueness (robust check by individual fields)
        $plateMatch = false;
        if (isset($item['plate_full']) && $item['plate_full'] === $newPlate) {
            $plateMatch = true;
        } elseif (
            isset($item['plate_number']) && isset($item['plate_letter']) && isset($item['plate_governorate']) &&
            $item['plate_number'] === trim($_POST['plate_number']) &&
            $item['plate_letter'] === trim($_POST['plate_letter']) &&
            $item['plate_governorate'] === trim($_POST['plate_governorate'])
        ) {
            $plateMatch = true;
        }

        if ($plateMatch) {
            http_response_code(400);
            echo "🚗 عذراً، هذه السيارة (رقم اللوحة) مسجلة بالفعل في هذه البطولة!";
            if (!empty($item['registration_code'])) {
                 echo "<br><br>كود التسجيل الخاص بك هو: <strong>" . $item['registration_code'] . "</strong>";
                 echo "<br>جاري جلب بياناتك السابقة تلقائياً للتعديل عليها...";
                 echo "<script>
                        document.getElementById('registration_code').value = '" . $item['registration_code'] . "'; 
                        window.scrollTo({top: document.getElementById('registration_code').offsetTop - 100, behavior: 'smooth'}); 
                        setTimeout(lookupCode, 800);
                      </script>";
            }
            exit;
        }
    }
    
    // Generate registration ID - find max existing ID and add 1
    $maxWasel = 0;
    foreach ($existingData as $item) {
        $currentWasel = intval($item['wasel'] ?? 0);
        if ($currentWasel > $maxWasel) {
            $maxWasel = $currentWasel;
        }
    }
    $wasel = $maxWasel + 1;
    
    // Process AND upload ALL possible image fields (even if not mandatory)
    $imagePaths = [];
    $maxFileSize = 100 * 1024 * 1024; // 100MB
    $allPossibleFiles = ['front_image', 'back_image', 'id_front', 'id_back', 'personal_photo', 'license_front', 'license_back'];
    
    // DEBUG: Log what files PHP received
    $debugLog = date('Y-m-d H:i:s') . " | Wasel: $wasel | Files received: " . count($_FILES) . "\n";
    foreach ($allPossibleFiles as $f) {
        if (isset($_FILES[$f])) {
            $debugLog .= "  $f: error=" . $_FILES[$f]['error'] . " size=" . $_FILES[$f]['size'] . " name=" . $_FILES[$f]['name'] . "\n";
        } else {
            $hasPrev = isset($previousImages[$f]) && !empty($previousImages[$f]);
            $debugLog .= "  $f: NOT IN \$_FILES" . ($hasPrev ? " (has previous)" : " (NO previous)") . "\n";
        }
    }
    $debugLog .= "  fieldSettings: " . json_encode($fieldSettings) . "\n";
    $debugLog .= "  mandatoryFiles: " . json_encode($mandatoryFiles) . "\n";
    $debugLog .= "---\n";
    file_put_contents('admin/data/upload_debug.log', $debugLog, FILE_APPEND);
    
    foreach ($allPossibleFiles as $fileField) {
        // Check if new file was uploaded
        if (isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$fileField];
            
            // Check file size
            if ($file['size'] > $maxFileSize) {
                http_response_code(400);
                echo 'حجم الملف كبير جداً: ' . $fileField . ' (الحد الأقصى 100MB)';
                exit;
            }
            
            // Get file extension
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'];
            
            if (!in_array($ext, $allowedExts)) {
                http_response_code(400);
                echo 'نوع الملف غير مدعوم: ' . $fileField;
                exit;
            }
            
            // Generate unique filename
            $filename = $wasel . '_' . $fileField . '_' . time() . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            // Move uploaded file with compression
            require_once 'include/image_utils.php';
            
            // Try to compress (Quality 60, Max Width 1200px for good balance)
            if (compressImage($file['tmp_name'], $filepath, 60, 1200)) {
                 $imagePaths[$fileField] = $filepath;
            } elseif (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Fallback to normal move if compression fails (e.g. for unsupported formats)
                $imagePaths[$fileField] = $filepath;
            } else {
                http_response_code(500);
                echo 'فشل في رفع الملف: ' . $fileField;
                exit;
            }
        } elseif (isset($previousImages[$fileField]) && !empty($previousImages[$fileField])) {
            // Use previous image path
            $imagePaths[$fileField] = $previousImages[$fileField];
        }
    }
    
    // Participation type labels
    $participationLabels = [];
    $settingsFile = 'admin/data/registration_settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        if (!empty($settings['participation_types'])) {
            foreach ($settings['participation_types'] as $pt) {
                $participationLabels[$pt['id']] = $pt['label'];
            }
        }
    }
    // Fallback if empty
    if (empty($participationLabels)) {
         $participationLabels = [
            'free_show' => 'المشاركة بالاستعراض الحر',
            'special_car' => 'سيارة مميزة بدون استعراض',
            'burnout' => 'فعالية Burnout'
        ];
    }
    
    // Engine size labels
    $engineLabels = [
        '8_cylinder_natural' => '8 سلندر تنفس طبيعي',
        '8_cylinder_boost' => '8 سلندر بوست',
        '6_cylinder_natural' => '6 سلندر تنفس طبيعي',
        '6_cylinder_boost' => '6 سلندر بوست',
        '4_cylinder' => '4 سلندر',
        '4_cylinder_boost' => '4 سلندر بوست',
        'other' => 'أخرى'
    ];
    
    // Smart Check: If usedRegCode is empty, try to find existing member by PLATE
    // This ensures returning users keep their code even if they didn't use quick register
    if (empty($usedRegCode)) {
        $membersFile = 'admin/data/members.json';
        if (file_exists($membersFile)) {
            $members = json_decode(file_get_contents($membersFile), true) ?? [];
            $checkPlate = trim($_POST['plate_letter']) . ' ' . trim($_POST['plate_number']) . ' - ' . trim($_POST['plate_governorate']);
            
            foreach ($members as $mCode => $member) {
                $mPlate = trim($member['plate_letter'] ?? '') . ' ' . trim($member['plate_number'] ?? '') . ' - ' . trim($member['plate_governorate'] ?? '');
                
                // Match by Plate
                if ($mPlate === $checkPlate && !empty($checkPlate)) {
                    $usedRegCode = $mCode; // Found user! Use their code.
                    break;
                }
                
                // Fallback: Match by Name AND Phone (if plate is missing or changed)
                $mName = trim($member['full_name'] ?? '');
                $checkName = trim($_POST['full_name'] ?? '');
                
                // Normalize phones (take last 10 digits to ignore country code diffs)
                $p1 = substr(preg_replace('/[^0-9]/', '', $member['phone'] ?? ''), -10);
                $p2 = substr(preg_replace('/[^0-9]/', '', $_POST['phone'] ?? ''), -10);
                
                if ($mName === $checkName && $p1 === $p2 && !empty($p1)) {
                    $usedRegCode = $mCode; // Found user! Use their code.
                    break;
                }
            }
        }
    }

    // Generate or preserve registration code
    // If using quick registration with previous code, KEEP THE OLD CODE
    // This maintains the link with members.json for returning users
    if (!empty($usedRegCode)) {
        // User is registering with their old code - keep it
        $registrationCode = $usedRegCode;
    } else {
        // New user - generate new code
        $registrationCode = generateRegistrationCode($existingData);
    }
    
    // Generate encrypted badge ID (32 characters, cannot be guessed)
    $badgeId = bin2hex(random_bytes(16));
    
    // Build new data entry
    // Determine if this is a new or returning user
    $isReturningUser = !empty($usedRegCode);
    $registerType = $isReturningUser ? 'returning' : 'new';
    $registerTypeLabel = $isReturningUser ? 'مسجل قديم' : 'جديد';
    
    $newData = [
        'wasel' => strval($wasel),
        'inchage_status' => 'pending', // Default status for new registrations
        'instagram' => isset($_POST['instagram']) ? trim($_POST['instagram']) : '',
        'badge_id' => $badgeId,
        'registration_code' => $registrationCode,
        'register_type' => $registerType,
        'register_type_label' => $registerTypeLabel,
        'participation_type' => htmlspecialchars(trim($_POST['participation_type']), ENT_QUOTES, 'UTF-8'),
        'participation_type_label' => $participationLabels[$_POST['participation_type']] ?? $_POST['participation_type'],
        'full_name' => htmlspecialchars(trim($_POST['full_name']), ENT_QUOTES, 'UTF-8'),
        'phone' => htmlspecialchars(trim($_POST['phone']), ENT_QUOTES, 'UTF-8'),
        'country_code' => htmlspecialchars(trim($_POST['country_code'] ?? '+964'), ENT_QUOTES, 'UTF-8'),
        'governorate' => htmlspecialchars(trim($_POST['governorate']), ENT_QUOTES, 'UTF-8'),
        'car_type' => htmlspecialchars(trim($_POST['car_type']), ENT_QUOTES, 'UTF-8'),
        'car_year' => intval($_POST['car_year']),
        'car_color' => htmlspecialchars(trim($_POST['car_color']), ENT_QUOTES, 'UTF-8'),
        'engine_size' => htmlspecialchars(trim($_POST['engine_size']), ENT_QUOTES, 'UTF-8'),
        'engine_size_label' => $engineLabels[$_POST['engine_size']] ?? $_POST['engine_size'],
        'plate_letter' => htmlspecialchars(trim($_POST['plate_letter']), ENT_QUOTES, 'UTF-8'),
        'plate_number' => htmlspecialchars(trim($_POST['plate_number']), ENT_QUOTES, 'UTF-8'),
        'plate_governorate' => htmlspecialchars(trim($_POST['plate_governorate']), ENT_QUOTES, 'UTF-8'),
        'plate_full' => $_POST['plate_letter'] . ' ' . $_POST['plate_number'] . ' - ' . $_POST['plate_governorate'],
        'images' => $imagePaths,
        'status' => 'pending', // pending, approved, rejected
        'registration_date' => date('Y-m-d H:i:s'),
        'approved_date' => null,
        'approved_by' => null
    ];
    
    // Add new registration
    $existingData[] = $newData;
    
    // Save to file
    $newJsonString = json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($data_file_location, $newJsonString)) {
        
        auditLog('create', 'registrations', $wasel, null, 'Public Registration', null);
        
        // Log to registration actions archive
        $actionType = $isReturningUser ? 're_registered' : 'registered';
        $actionDetails = $isReturningUser ? 'إعادة تسجيل (تعديل بيانات)' : 'تسجيل جديد';
        RegistrationActionLogger::log($actionType, $newData, $actionDetails, 'public');
        
        // === AUTO-ADD TO MEMBERS PAGE (SQLite + members.json) ===
        try {
            $pdo = db();
            $champId = getCurrentChampionshipId();
            $cleanPhone = normalizePhone($newData['phone']);
            $memberName = html_entity_decode($newData['full_name'], ENT_QUOTES, 'UTF-8');
            $memberGov = html_entity_decode($newData['governorate'], ENT_QUOTES, 'UTF-8');
            
            // Create or find the member in SQLite
            $sqlMember = MemberService::getOrCreateMember($cleanPhone, $memberName, $memberGov);
            $memberId = $sqlMember['id'];
            
            // Update member profile with permanent code and car data
            $pdo->prepare("
                UPDATE members SET 
                    permanent_code = COALESCE(NULLIF(permanent_code, 'TEMP'), ?),
                    name = ?,
                    governorate = ?,
                    instagram = COALESCE(NULLIF(instagram, ''), ?),
                    personal_photo = COALESCE(NULLIF(personal_photo, ''), ?),
                    national_id_front = COALESCE(NULLIF(national_id_front, ''), ?),
                    national_id_back = COALESCE(NULLIF(national_id_back, ''), ?),
                    last_car_type = ?,
                    last_car_year = ?,
                    last_car_color = ?,
                    last_engine_size = ?,
                    last_plate_letter = ?,
                    last_plate_number = ?,
                    last_plate_governorate = ?,
                    last_participation_type = ?
                WHERE id = ?
            ")->execute([
                $registrationCode,
                $memberName,
                $memberGov,
                $newData['instagram'] ?? '',
                $imagePaths['personal_photo'] ?? '',
                $imagePaths['id_front'] ?? $imagePaths['national_id_front'] ?? '',
                $imagePaths['id_back'] ?? $imagePaths['national_id_back'] ?? '',
                html_entity_decode($newData['car_type'], ENT_QUOTES, 'UTF-8'),
                $newData['car_year'],
                html_entity_decode($newData['car_color'], ENT_QUOTES, 'UTF-8'),
                html_entity_decode($newData['engine_size'], ENT_QUOTES, 'UTF-8'),
                html_entity_decode($newData['plate_letter'], ENT_QUOTES, 'UTF-8'),
                html_entity_decode($newData['plate_number'], ENT_QUOTES, 'UTF-8'),
                html_entity_decode($newData['plate_governorate'], ENT_QUOTES, 'UTF-8'),
                html_entity_decode($newData['participation_type'], ENT_QUOTES, 'UTF-8'),
                $memberId
            ]);
            
            // Create registration record in SQLite (if not already exists)
            $stmtChk = $pdo->prepare("SELECT id FROM registrations WHERE member_id = ? AND championship_id = ?");
            $stmtChk->execute([$memberId, $champId]);
            if (!$stmtChk->fetchColumn()) {
                // Get championship name from frame settings
                $champName = 'البطولة الحالية';
                $frameSettingsFile = 'admin/data/frame_settings.json';
                if (file_exists($frameSettingsFile)) {
                    $fs = json_decode(file_get_contents($frameSettingsFile), true);
                    if (!empty($fs['form_titles']['sub_title'])) {
                        $champName = $fs['form_titles']['sub_title'];
                    }
                }
                
                $pdo->prepare("
                    INSERT INTO registrations (
                        member_id, championship_id, wasel, status,
                        car_type, car_year, car_color, engine_size, participation_type,
                        plate_number, plate_letter, plate_governorate,
                        personal_photo, front_image, side_image, back_image, edited_image,
                        session_badge_token, championship_name, created_at, is_active
                    ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+3 hours'), 1)
                ")->execute([
                    $memberId, $champId, $wasel,
                    html_entity_decode($newData['car_type'], ENT_QUOTES, 'UTF-8'),
                    $newData['car_year'],
                    html_entity_decode($newData['car_color'], ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($newData['engine_size'], ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($newData['participation_type'], ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($newData['plate_number'], ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($newData['plate_letter'], ENT_QUOTES, 'UTF-8'),
                    html_entity_decode($newData['plate_governorate'], ENT_QUOTES, 'UTF-8'),
                    $imagePaths['personal_photo'] ?? '',
                    $imagePaths['front_image'] ?? '',
                    $imagePaths['side_image'] ?? $imagePaths['side_image'] ?? '',
                    $imagePaths['back_image'] ?? '',
                    $imagePaths['edited_image'] ?? '',
                    $registrationCode,
                    $champName
                ]);
            }
            
            // Sync to members.json
            MemberService::syncToJson($memberId);
            
        } catch (Exception $memberErr) {
            // Don't block the registration if member sync fails
            error_log('Auto-add to members failed: ' . $memberErr->getMessage());
        }
        
        // إرسال إشعار WhatsApp للعميل
        try {
            $wasender = new WaSender();
            $wasender->sendRegistrationReceived($newData);
        } catch (Exception $e) {
            // لا نوقف العملية إذا فشل الإرسال
            error_log('WhatsApp send failed: ' . $e->getMessage());
        }
        
        echo '✅ تم تسجيل طلبك بنجاح!' . "\n";
        echo 'رقم التسجيل: ' . $wasel . "\n";
        echo 'كود التسجيل: ' . $registrationCode . "\n";
        echo 'سيتم مراجعة طلبك وإرسال رسالة لك عند القبول';
    } else {
        http_response_code(500);
        echo 'حدث خطأ أثناء حفظ الطلب. يرجى المحاولة مرة أخرى.';
    }
} else {
    http_response_code(405);
    echo "طريقة الطلب غير صالحة!";
}
?>