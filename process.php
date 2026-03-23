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

function normalizePlateStr($str) {
    if (empty($str)) return '';
    $str = trim((string)$str);
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $str = str_replace($arabic, $english, $str);
    return str_replace([' ', '-'], '', $str);
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
    // Check for upload exceeding post_max_size (which empties $_POST)
    if (empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        $maxPostSize = ini_get('post_max_size');
        http_response_code(400);
        echo "خطأ: مجموع حجم الملفات المرفوعة كبير جداً وتجاوز الحد الأقصى للسيرفر ($maxPostSize). يرجى تقليل مساحة الصور.";
        exit;
    }

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
        
        // Check Phone — normalize BOTH sides to same format for reliable matching
        $phoneToCheck = str_replace([' ', '-', '+'], '', $_POST['phone']);
        // Also get the last 10 digits as the canonical form
        $phoneLast10 = substr(preg_replace('/\D/', '', $_POST['phone']), -10);
        
        foreach ($blacklist['phones'] ?? [] as $bEntry) {
            $bPhone = is_array($bEntry) ? ($bEntry['value'] ?? '') : (string)$bEntry;
            $bReason = is_array($bEntry) ? ($bEntry['reason'] ?? '') : '';
            if (empty($bPhone)) continue;
            
            // Normalize the blacklisted phone too
            $bPhoneClean = preg_replace('/\D/', '', $bPhone); // strip non-digits
            if (str_starts_with($bPhoneClean, '964')) $bPhoneClean = substr($bPhoneClean, 3);
            if (strlen($bPhoneClean) == 11 && str_starts_with($bPhoneClean, '0')) $bPhoneClean = substr($bPhoneClean, 1);
            $bPhoneLast10 = substr($bPhoneClean, -10);
            
            // Match: exact last-10-digits comparison OR substring match
            if ($phoneLast10 === $bPhoneLast10 || 
                strpos($phoneToCheck, $bPhoneClean) !== false || 
                strpos($bPhoneClean, $phoneToCheck) !== false) {
                 $reasonMsg = !empty($bReason) ? "<br>السبب: <strong>{$bReason}</strong>" : '';
                 http_response_code(400);
                 echo "🚫 عذراً، أنت محظور من التسجيل.{$reasonMsg}<br>يرجى مراجعة الإدارة: <a href='https://wa.me/" . ($settings['support_number'] ?? '9647736000096') . "' target='_blank' style='color:#fff;text-decoration:underline;'>تواصل معنا عبر الواتساب</a>";
                 exit;
             }
        }

        // Check Plate Blacklist
        $plateNumber = trim($_POST['plate_number'] ?? '');
        $plateLetter = trim($_POST['plate_letter'] ?? '');
        $plateGov = trim($_POST['plate_governorate'] ?? '');
        
        // Construct common formats for checking
        $plateFull1 = $plateGov . ' ' . $plateLetter . ' ' . $plateNumber;
        $plateFull2 = $plateLetter . ' ' . $plateNumber . ' ' . $plateGov;
        $plateFull3 = $plateLetter . ' ' . $plateNumber;
        
        foreach ($blacklist['plates'] ?? [] as $bEntry) {
            $bPlate = is_array($bEntry) ? ($bEntry['value'] ?? '') : (string)$bEntry;
            $bReason = is_array($bEntry) ? ($bEntry['reason'] ?? '') : '';
            if (!empty($bPlate)) {
                $bPlate = trim($bPlate);
                if ($bPlate === $plateNumber || 
                    strpos($plateFull1, $bPlate) !== false || 
                    strpos($plateFull2, $bPlate) !== false ||
                    strpos($plateFull3, $bPlate) !== false) {
                     $reasonMsg = !empty($bReason) ? "<br>السبب: <strong>{$bReason}</strong>" : '';
                     http_response_code(400);
                     echo "🚫 عذراً، هذه المركبة محظورة من التسجيل.{$reasonMsg}<br>يرجى مراجعة الإدارة: <a href='https://wa.me/" . ($settings['support_number'] ?? '9647736000096') . "' target='_blank' style='color:#fff;text-decoration:underline;'>تواصل معنا عبر الواتساب</a>";
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
    
    // ===================================================================
    // CRITICAL FIX: File locking to prevent race-condition duplicates
    // This ensures only ONE registration can read+write data.json at a time
    // ===================================================================
    $lockFile = fopen('admin/data/data.lock', 'w');
    if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
        // Could not acquire lock immediately, wait up to 10 seconds
        $lockAcquired = false;
        for ($i = 0; $i < 20; $i++) {
            usleep(500000); // 0.5 seconds
            if (flock($lockFile, LOCK_EX | LOCK_NB)) {
                $lockAcquired = true;
                break;
            }
        }
        if (!$lockAcquired) {
            fclose($lockFile);
            http_response_code(503);
            echo 'النظام مشغول حالياً، يرجى المحاولة مرة أخرى بعد ثوانٍ.';
            exit;
        }
    }
    
    // Read existing data (inside lock)
    $existingData = [];
    if (file_exists($data_file_location)) {
        $existingData = json_decode(file_get_contents($data_file_location), true);
        if (!is_array($existingData)) {
            $existingData = [];
        }
    }
    
    // ===================================================================
    // UNIFIED IDENTITY DETECTION — UPDATE instead of REJECT
    // If same person registers again (by phone or plate), we UPDATE their
    // record and keep the same wasel/code/barcode. NOT a new registration.
    // ===================================================================
    $usedRegCode = $_POST['used_registration_code'] ?? '';
    $normalizedPhone = $_POST['phone']; // Already normalized above
    $np_num = normalizePlateStr($_POST['plate_number'] ?? '');
    $np_let = normalizePlateStr($_POST['plate_letter'] ?? '');
    $np_gov = normalizePlateStr($_POST['plate_governorate'] ?? '');
    
    // Track if we found an existing match
    $existingMatch = null;      // The matched entry from data.json
    $existingMatchKey = null;   // Its array index
    $isUpdateMode = false;      // Will be true if updating existing registration
    
    foreach ($existingData as $key => $item) {
        $matched = false;
        
        // Match 1: By registration code (from quick registration)
        if (!empty($usedRegCode) && isset($item['registration_code']) && $item['registration_code'] === $usedRegCode) {
            $matched = true;
        }
        
        // Match 2: By phone number (primary identity)
        if (!$matched) {
            $existingPhone = preg_replace('/\D/', '', $item['phone'] ?? '');
            if (str_starts_with($existingPhone, '964')) {
                $existingPhone = substr($existingPhone, 3);
            }
            if (strlen($existingPhone) === 11 && str_starts_with($existingPhone, '07')) {
                $existingPhone = substr($existingPhone, 1);
            }
            if ($existingPhone === $normalizedPhone && !empty($normalizedPhone)) {
                $matched = true;
            }
        }
        
        // Match 3: By plate number (secondary identity)
        if (!$matched && $np_num !== '' && $np_let !== '' && $np_gov !== '') {
            $ep_num = normalizePlateStr($item['plate_number'] ?? '');
            $ep_let = normalizePlateStr($item['plate_letter'] ?? '');
            $ep_gov = normalizePlateStr($item['plate_governorate'] ?? '');
            
            if ($ep_num !== '' && $ep_let !== '' && $ep_gov !== '' &&
                $ep_num === $np_num && $ep_let === $np_let && $ep_gov === $np_gov) {
                $matched = true;
            }
        }
        
        if ($matched) {
            $existingMatch = $item;
            $existingMatchKey = $key;
            $isUpdateMode = true;
            // Remove old entry — we'll insert the updated one later
            unset($existingData[$key]);
            $existingData = array_values($existingData);
            break;
        }
    }
    
    // Also check members.json for returning users (previous championships)
    if (!$isUpdateMode && empty($usedRegCode)) {
        $membersFile = 'admin/data/members.json';
        if (file_exists($membersFile)) {
            $members = json_decode(file_get_contents($membersFile), true) ?? [];
            foreach ($members as $mCode => $member) {
                // Match by phone
                $p1 = substr(preg_replace('/[^0-9]/', '', $member['phone'] ?? ''), -10);
                $p2 = substr(preg_replace('/[^0-9]/', '', $_POST['phone'] ?? ''), -10);
                if ($p1 === $p2 && !empty($p1)) {
                    $usedRegCode = $mCode;
                    break;
                }
                // Match by plate
                $mp_num = normalizePlateStr($member['plate_number'] ?? '');
                $mp_let = normalizePlateStr($member['plate_letter'] ?? '');
                $mp_gov = normalizePlateStr($member['plate_governorate'] ?? '');
                if ($mp_num !== '' && $mp_let !== '' && $mp_gov !== '' &&
                    $mp_num === $np_num && $mp_let === $np_let && $mp_gov === $np_gov) {
                    $usedRegCode = $mCode;
                    break;
                }
            }
        }
    }
    
    // ===================================================================
    // FIX: Use dedicated counter file to prevent ID jumps
    // This prevents imported/deleted records from inflating the counter
    // ===================================================================
    $counterFile = 'admin/data/wasel_counter.json';
    $maxWasel = 0;
    foreach ($existingData as $item) {
        $currentWasel = intval($item['wasel'] ?? 0);
        if ($currentWasel > $maxWasel) {
            $maxWasel = $currentWasel;
        }
    }
    
    // Read counter file (if exists, use the higher of counter or max)
    $counterNext = $maxWasel + 1;
    if (file_exists($counterFile)) {
        $counterData = json_decode(file_get_contents($counterFile), true);
        if (isset($counterData['next_wasel'])) {
            // Use counter only if it's reasonable (within 50 of the actual max)
            // This prevents a corrupted/old counter from creating huge gaps
            if ($counterData['next_wasel'] <= $maxWasel + 50) {
                $counterNext = max($counterData['next_wasel'], $maxWasel + 1);
            }
        }
    }
    $wasel = $counterNext;
    
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
        // Check if file was uploaded
        if (isset($_FILES[$fileField]) && $_FILES[$fileField]['name']) {
            $file = $_FILES[$fileField];
            
            // Handle PHP upload errors (e.g., file too large for server config)
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = 'حدث خطأ غير معروف أثناء رفع الملف.';
                if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                    $errorMsg = 'حجم الملف كبير جداً وتجاوز الحد المسموح به في السيرفر.';
                } elseif ($file['error'] === UPLOAD_ERR_PARTIAL) {
                    $errorMsg = 'تم رفع الملف بشكل جزئي، يرجى المحاولة مرة أخرى.';
                }
                http_response_code(400);
                echo "فشل في رفع الصورة ($fileField): $errorMsg";
                if (isset($lockFile)) { flock($lockFile, LOCK_UN); fclose($lockFile); }
                exit;
            }
            
            // Check file size limit
            if ($file['size'] > $maxFileSize) {
                http_response_code(400);
                echo 'حجم الملف كبير جداً: ' . $fileField . ' (الحد الأقصى 100MB)';
                if (isset($lockFile)) { flock($lockFile, LOCK_UN); fclose($lockFile); }
                exit;
            }
            
            // Get file extension
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'];
            
            if (!in_array($ext, $allowedExts)) {
                http_response_code(400);
                echo 'نوع الملف غير مدعوم (' . htmlspecialchars($ext) . '). الأنواع المسموحة: ' . implode(', ', $allowedExts) . ' — الحقل: ' . $fileField;
                if (isset($lockFile)) { flock($lockFile, LOCK_UN); fclose($lockFile); }
                exit;
            }
            
            // MIME type validation — reject files that pretend to be images
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($detectedMime, $allowedMimes)) {
                http_response_code(400);
                echo 'الملف ليس صورة حقيقية: ' . $fileField . ' (النوع المكتشف: ' . htmlspecialchars($detectedMime) . '). يرجى رفع صورة بصيغة JPG أو PNG أو WebP.';
                if (isset($lockFile)) { flock($lockFile, LOCK_UN); fclose($lockFile); }
                exit;
            }
            
            // Generate unique filename
            $filename = $wasel . '_' . $fileField . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            // Move uploaded file WITHOUT compression (Fast Track) to prevent 503 Server Timeouts
            // The compression process in GD requires too much CPU/RAM on Hostinger when multiple large files are uploaded.
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $imagePaths[$fileField] = $filepath;
            } else {
                http_response_code(500);
                echo 'فشل استثنائي في حفظ الملف على السيرفر: ' . $fileField;
                if (isset($lockFile)) { flock($lockFile, LOCK_UN); fclose($lockFile); }
                exit;
            }
        } elseif (isset($previousImages[$fileField]) && !empty($previousImages[$fileField])) {
            // Use previous image path
            $imagePaths[$fileField] = $previousImages[$fileField];
        }
    }
    
    // ===================================================================
    // FIX: Validate TOTAL image count — all registrations must be complete
    // Count how many images (new + previous) the user is providing
    // ===================================================================
    $totalImagesProvided = 0;
    foreach ($allPossibleFiles as $f) {
        $hasNewFile = isset($_FILES[$f]) && $_FILES[$f]['error'] === UPLOAD_ERR_OK;
        $hasPreviousImage = isset($previousImages[$f]) && !empty($previousImages[$f]);
        if ($hasNewFile || $hasPreviousImage) {
            $totalImagesProvided++;
        }
    }
    
    // Calculate minimum required based on settings
    $minImagesRequired = 2; // front_image + back_image always required
    if ($fieldSettings['id_front_enabled'] && $fieldSettings['id_front_required']) $minImagesRequired++;
    if ($fieldSettings['id_back_enabled'] && $fieldSettings['id_back_required']) $minImagesRequired++;
    if ($fieldSettings['personal_photo_enabled'] && $fieldSettings['personal_photo_required']) $minImagesRequired++;
    if ($fieldSettings['license_images_enabled'] && $fieldSettings['license_images_required']) $minImagesRequired += 2;
    
    if ($totalImagesProvided < $minImagesRequired) {
        // Release lock before exiting
        if (isset($lockFile)) { flock($lockFile, LOCK_UN); fclose($lockFile); }
        http_response_code(400);
        echo "يجب رفع $minImagesRequired صور على الأقل. تم تقديم $totalImagesProvided صور فقط.";
        exit;
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
    
    // ===================================================================
    // IDENTITY RESOLUTION: Determine wasel, code, and badge
    // If UPDATE MODE: reuse existing wasel/code/badge
    // If RETURNING USER (from members.json): reuse code, new wasel/badge
    // If NEW USER: generate everything fresh
    // ===================================================================
    if ($isUpdateMode && $existingMatch) {
        // UPDATE MODE — keep the same identity
        $wasel = $existingMatch['wasel'];
        $registrationCode = $existingMatch['registration_code'];
        $badgeId = $existingMatch['badge_id'] ?? bin2hex(random_bytes(16));
        $isReturningUser = true;
    } else {
        // Check members.json for returning users (usedRegCode set from earlier check)
        if (!empty($usedRegCode)) {
            $registrationCode = $usedRegCode;
            $isReturningUser = true;
        } else {
            $registrationCode = generateRegistrationCode($existingData);
            $isReturningUser = false;
        }
        $badgeId = bin2hex(random_bytes(16));
    }
    
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
        
        // Update wasel counter file
        file_put_contents($counterFile, json_encode(['next_wasel' => $wasel + 1], JSON_PRETTY_PRINT));
        
        // Release file lock — data is safely written
        if (isset($lockFile)) { flock($lockFile, LOCK_UN); fclose($lockFile); }
        
        // ===================================================================
        // CRITICAL FIX: Send response to browser IMMEDIATELY after data.json save.
        // All heavy operations (SQLite, members.json sync, WhatsApp) run AFTER
        // the browser receives the response. This prevents 503 timeout on Hostinger.
        // ===================================================================
        if ($isUpdateMode) {
            // UPDATE response — tell user their data was updated
            $responseText = 'UPDATE_MODE' . "\n";
            $responseText .= '✅ تم تحديث بياناتك لأنك مسجل مسبقاً!' . "\n";
            $responseText .= 'رقم التسجيل: ' . $wasel . "\n";
            $responseText .= 'كود التسجيل: ' . $registrationCode . "\n";
            $responseText .= 'تم تحديث جميع بياناتك وصورك بنجاح';
        } else {
            // NEW registration response
            $responseText = '✅ تم تسجيل طلبك بنجاح!' . "\n";
            $responseText .= 'رقم التسجيل: ' . $wasel . "\n";
            $responseText .= 'كود التسجيل: ' . $registrationCode . "\n";
            $responseText .= 'سيتم مراجعة طلبك وإرسال رسالة لك عند القبول';
        }
        
        // Send response early - close connection to browser
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: ' . (function_exists('mb_strlen') ? mb_strlen($responseText, '8bit') : strlen($responseText)));
        header('Connection: close');
        echo $responseText;
        
        // Flush all output buffers to send data to browser NOW
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        
        // If running under PHP-FPM (Hostinger), this instantly closes the connection
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // === BACKGROUND OPERATIONS (browser already received response) ===
        // Increase time limit for background work
        @set_time_limit(120);
        
        // Audit Log
        try {
            auditLog('create', 'registrations', $wasel, null, 'Public Registration', null);
        } catch (\Throwable $err) {
            error_log('Audit log failed: ' . $err->getMessage());
        }
        
        // Log to registration actions archive
        try {
            $actionType = $isReturningUser ? 're_registered' : 'registered';
            $actionDetails = $isReturningUser ? 'إعادة تسجيل (تعديل بيانات)' : 'تسجيل جديد';
            RegistrationActionLogger::log($actionType, $newData, $actionDetails, 'public');
        } catch (\Throwable $err) {
            error_log('Action logger failed: ' . $err->getMessage());
        }
        
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
                    last_participation_type = ?,
                    license_front = COALESCE(NULLIF(license_front, ''), ?),
                    license_back = COALESCE(NULLIF(license_back, ''), ?)
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
                $imagePaths['license_front'] ?? '',
                $imagePaths['license_back'] ?? '',
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
                        license_front, license_back,
                        session_badge_token, championship_name, created_at, is_active
                    ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+3 hours'), 1)
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
            
        } catch (\Throwable $memberErr) {
            // Don't block the registration if member sync fails
            error_log('Auto-add to members failed: ' . $memberErr->getMessage());
        }
        
        // ❌ DISABLED: WhatsApp auto-send on registration (saves WhatsApp credits)
        // To re-enable, uncomment the block below:
        // try {
        //     $wasender = new WaSender();
        //     $wasender->sendRegistrationReceived($newData);
        // } catch (\Throwable $e) {
        //     error_log('WhatsApp send failed: ' . $e->getMessage());
        // }
        
        // Response was already sent above - just exit cleanly
        exit;
    } else {
        if (isset($lockFile)) { flock($lockFile, LOCK_UN); fclose($lockFile); }
        http_response_code(500);
        echo 'حدث خطأ أثناء حفظ الطلب. يرجى المحاولة مرة أخرى.';
    }
} else {
    http_response_code(405);
    echo "طريقة الطلب غير صالحة!";
}
?>