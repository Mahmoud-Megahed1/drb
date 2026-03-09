<?php
/**
 * Import Members from Excel with Dry-Run & Audit Logging
 * Supports Standard Mode & Google Forms Mode (Smart Mapping)
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// PHP 7.x Compatibility: Polyfill for str_contains (PHP 8.0+)
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

session_start();
require_once '../include/db.php';
require_once '../include/helpers.php';
require_once '../services/MemberService.php';
require_once '../services/BadgeCacheService.php';

// Auth Check
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}
$currentUser = $_SESSION['user'];
$isRoot = ($currentUser->username ?? '') === 'root' || ($currentUser->role ?? '') === 'root' || ($currentUser->role ?? '') === 'admin';

if (!$isRoot) {
    header('Location: ../dashboard.php');
    exit;
}

$step = $_POST['step'] ?? 'upload'; // upload | preview | commit
$message = '';
$error = '';
$previewData = [];
$stats = ['new' => 0, 'returning' => 0, 'updated' => 0, 'skipped' => 0, 'warnings' => 0];

// -- HANDLE PREVIEW (STEP 1) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'preview' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    $importMode = $_POST['import_mode'] ?? 'standard'; // standard | google_forms
    $importDestination = $_POST['import_destination'] ?? 'event'; // event | members_only
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'خطأ في رفع الملف';
        $step = 'upload';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
            $error = 'صيغة الملف غير مدعومة';
            $step = 'upload';
        } else {
            // Move to temp
            $tempPath = '../cache/imports/' . uniqid() . '.' . $ext;
            if (!is_dir(dirname($tempPath))) mkdir(dirname($tempPath), 0755, true);
            
            if (move_uploaded_file($file['tmp_name'], $tempPath)) {
                try {
                    $previewData = parseImportFile($tempPath, $ext, $importMode);
                    $_SESSION['import_preview'] = $previewData;
                    $_SESSION['import_mode'] = $importMode;
                    $_SESSION['import_destination'] = $importDestination;
                    $_SESSION['temp_file'] = $tempPath;
                    
                    // Stats
                    foreach($previewData as $row) {
                        if ($row['status'] === 'skipped' || $row['status'] === 'invalid') {
                            $stats['skipped']++;
                        } else {
                            if ($row['status'] === 'new') $stats['new']++;
                            if ($row['status'] === 'returning') $stats['returning']++;
                            if ($row['status'] === 'updated') $stats['updated']++;
                            if (!empty($row['warnings'])) $stats['warnings']++;
                        }
                    }
                    
                    $step = 'commit'; // Move to next step UI
                    
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    $step = 'upload';
                    @unlink($tempPath);
                }
            } else {
                $error = 'فشل نقل الملف المرفوع';
            }
        }
    }
}

// -- HANDLE COMMIT (STEP 2) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'commit_action') {
    if (isset($_SESSION['import_preview']) && !empty($_SESSION['import_preview'])) {
        $mode = $_SESSION['import_mode'] ?? 'standard';
        
        try {
            // Generate Secure Batch ID
            $batchId = bin2hex(random_bytes(16));
            $source = ($mode === 'google_forms') ? MemberService::SOURCE_IMPORT_GOOGLE : MemberService::SOURCE_IMPORT_STANDARD;
            
            // Get message preferences (from checkboxes)
            $messagePrefs = [
                'send_registration' => isset($_POST['send_registration']) ? 1 : 0,
                'send_acceptance' => isset($_POST['send_acceptance']) ? 1 : 0,
                'send_badge' => isset($_POST['send_badge']) ? 1 : 0
            ];
            
            // Filter only selected rows
            $selectedIndices = $_POST['selected_rows'] ?? [];
            $filteredPreview = [];
            foreach ($_SESSION['import_preview'] as $index => $row) {
                if (in_array($index, $selectedIndices) && $row['status'] !== 'skipped' && $row['status'] !== 'invalid') {
                    $filteredPreview[] = $row;
                }
            }
            
            // Import only filtered/selected members (with message preferences)
            $destination = $_SESSION['import_destination'] ?? 'event';
            $importResults = commitImport($filteredPreview, $source, $batchId, $messagePrefs, $destination);
            
            auditLog('import', 'members', null, null, "Imported {$importResults['imported']}, Updated {$importResults['updated']} (Dest: $destination)", $currentUser->id ?? null);
            
            // Store results for confirmation page
            $_SESSION['import_success'] = [
                'imported' => $importResults['imported'],
                'updated' => $importResults['updated'],
                'batch_id' => $batchId
            ];
            $step = 'success'; // Show confirmation page
            
            // Cleanup
            if (isset($_SESSION['temp_file'])) @unlink($_SESSION['temp_file']);
            unset($_SESSION['import_preview']);
            unset($_SESSION['import_mode']);
            unset($_SESSION['temp_file']);
            
        } catch (Exception $e) {
            $error = "خطأ أثناء الحفظ: " . $e->getMessage();
            $step = 'upload';
        }
    } else {
        $error = 'بيانات المعاينة مفقودة. يرجى إعادة رفع الملف.';
        $step = 'upload';
    }
}

/**
 * Find the best header row in the first few rows
 */
function findHeaderRow($rows) {
    $candidates = array_slice($rows, 0, 5); // Scan first 5 rows
    $bestRowIndex = 0;
    $maxScore = 0;
    
    // Keywords to look for
    $keywords = ['phone', 'name', 'mobile', 'gov', 'car', 'plate', 'الاسم', 'الهاتف', 'المحافظة', 'السيارة', 'اللوحة', 'email', 'timestamp'];
    
    foreach ($candidates as $index => $row) {
        $score = 0;
        foreach ($row as $cell) {
            if (empty($cell)) continue;
            $cellLower = strtolower(trim($cell));
            
            foreach ($keywords as $kw) {
                if (str_contains($cellLower, $kw)) {
                    $score++;
                    break; 
                }
            }
        }
        
        // Bonus for "Timestamp" or "????? ????????" (strong indicators)
        foreach ($row as $cell) {
            $c = trim($cell);
            if ($c === 'Timestamp' || $c === 'الطابع الزمني' || str_contains($c, 'وقت التسجيل')) {
                $score += 3;
            }
        }
        
        if ($score > $maxScore) {
            $maxScore = $score;
            $bestRowIndex = $index;
        }
    }
    
    return [
        'index' => $bestRowIndex,
        'headers' => $rows[$bestRowIndex],
        'score' => $maxScore
    ];
}

/**
 * Smart Mapping Logic
 */
function getColumnMapping($headers, $mode) {
    // 0:Phone, 1:Name, 2:Gov, 3:CarType, 4:Year, 5:Color, 6:Engine, 7:PlateGov, 8:PlateLetter, 9:PlateNum, 10:Participation
    
    // Standard Mode (Legacy Positional)
    if ($mode !== 'google_forms') {
        return [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]; // Direct mapping
    }
    
    // Google Forms Mode: Smart Scoring
    $fields = [
        'name' => [
            'matches' => ['اسمك الثلاثي باللغة العربية', 'الاسم الرباعي', 'الاسم', 'الاسم الرباعي واللقب', 'الاسم الثلاثي واللقب'], 
            'partial' => ['اسمك', 'اسم']
        ],
        'phone' => [
            'matches' => ['رقم الهاتف', 'الهاتف', 'رقم الموبايل'], 
            'partial' => ['هاتف', 'موبايل'],
            'exclude' => ['اللوحة', 'السيارة', 'التعريفي', 'سري', 'التسلسل', 'تسلسل'] // DO NOT match "الرقم التعريفي" or "رقم السيارة"
        ],
        'gov' => [
            'matches' => ['محافظة المشترك', 'المحافظة', 'المدينة', 'محافظة السكن', 'الولاية'], 
            'partial' => ['محافظة', 'المشترك'],
            'exclude' => ['رقم', 'لوحة', 'plate', 'سيارة'] // Critical: Don't match 'محافظة لوحة السيارة'
        ],
        'plate_gov' => [
            'matches' => ['محافظة لوحة السيارة', 'محافظة اللوحة', 'المحافظة باللوحة'], 
            'partial' => ['لوحة', 'اللوحة'], 
            'exclude' => ['رقم', 'حرف', 'الحرف', 'المشترك', 'سكن'] // Don't match 'رقم لوحة السيارة'
        ],
        'plate_letter' => [
            'matches' => ['رمز لوحة السيارة', 'حرف اللوحة', 'حرف اللوحه', 'الحرف', 'حرف'], 
            'partial' => ['حرف', 'رمز']
        ],
        'plate_num' => [
            'matches' => ['رقم السيارة', 'رقم اللوحة', 'رقم'],
            'partial' => ['رقم السيارة', 'رقم لوحة'],
            'exclude' => ['محافظة', 'نوع', 'سنة', 'حرف', 'رمز', 'هاتف', 'موبايل', 'التعريفي']
        ],
        'car_type' => ['matches' => ['نوع السيارة'], 'partial' => ['سيارة', 'نوع'], 'exclude' => ['رقم', 'لوحة', 'حرف', 'موديل', 'سنة', 'محافظة', 'صنع', 'لون', 'محرك', 'حجم']],
        'car_year' => ['matches' => ['سنة صنع السيارة', 'سنة الصنع', 'الموديل'], 'partial' => ['سنة', 'موديل', 'صنع']],
        'car_color' => ['matches' => ['لون السيارة'], 'partial' => ['لون']],
        'engine' => ['matches' => ['حجم محرك السيارة', 'سعة المحرك', 'حجم المحرك'], 'partial' => ['مكينة', 'cc', 'حجم', 'محرك']],
        'participation' => ['matches' => ['هل مشاركتك في التجمع القادم لغرض', 'نوع المشاركة', 'المشاركة بالاستعراض الحر', 'المشاركة كسيارة مميزة'], 'partial' => ['مشاركة', 'مشاركتك', 'لغرض', 'استعراض']],
        'registration_code' => ['matches' => ['الرقم التعريفي'], 'partial' => ['تعريفي', 'سري']]
    ];
    
    $mapping = [];
    $usedIndexes = [];
    
    // Pass 1: Exact Matches (High Priority)
    foreach ($fields as $key => $rules) {
        foreach ($headers as $index => $header) {
            if (in_array($index, $usedIndexes)) continue;
            
            $header = trim($header);
            if (in_array($header, $rules['matches'])) {
                $mapping[$key] = $index;
                $usedIndexes[] = $index;
                break;
            }
        }
    }
    
    // Pass 2: Partial/Contains Matches (Lower Priority)
    foreach ($fields as $key => $rules) {
        if (isset($mapping[$key])) continue;
        
        foreach ($headers as $index => $header) {
            if (in_array($index, $usedIndexes)) continue;
            
            // Check Exclusions First
            if (isset($rules['exclude'])) {
                foreach ($rules['exclude'] as $excluded) {
                    if (str_contains($header, $excluded)) continue 2; // Skip this header
                }
            }
            
            foreach ($rules['partial'] as $keyword) {
                 if (str_contains($header, $keyword)) {
                     $mapping[$key] = $index;
                     $usedIndexes[] = $index;
                     break 2;
                 }
            }
        }
    }
    
    // Validation: Did we find Core Fields?
    if (!isset($mapping['name']) || !isset($mapping['phone'])) {
        // Fallback: Positional IF >= 13 columns
        if (count($headers) >= 13) {
            return [
                 'phone' => 3, // D
                 'name' => 2, // C
                 'gov' => 4, // E
                 'car_type' => 5, // F
                 'car_year' => 6, // G
                 'car_color' => 7, // H
                 'engine' => 8, // I
                 'plate_gov' => 9, // J
                 'plate_letter' => 10, // K
                 'plate_num' => 11, // L
                 'participation' => 12 // M
            ];
        }
        throw new Exception("لم نتمكن من التعرف على أعمدة الملف. تأكد من وجود عمودي 'الاسم' و 'رقم الهاتف'.");
    }
    
    return $mapping;
}

/**
 * Parse File
 */
function parseImportFile($filePath, $ext, $mode) {
    // Check for XLSX signature (PK..) disguised as CSV
    $handle = fopen($filePath, 'r');
    $headerBytes = fread($handle, 4);
    fclose($handle);
    
    if (strpos($headerBytes, 'PK') === 0) {
        throw new Exception("??? ????? ???? ???? Excel (XLSX). ?????? ?? ???? XLSX ??????. <br>???? ??? ????? ?? Excel ????? ?? <b>CSV (Comma delimited)</b> ?? ???????? ??? ????.");
    }

    if ($ext === 'csv') $rows = readCSV($filePath);
    else $rows = readExcel($filePath);
    
    if (empty($rows)) throw new Exception("الملف فارغ");
    
    // --- SMART HEADER DETECTION ---
    // Instead of assuming Row 0 is header, scan for it.
    $headerInfo = findHeaderRow($rows);
    $headers = $headerInfo['headers'];
    
    // If we found a good header row (score > 1), assume data starts after it.
    // Otherwise fall back to Row 0.
    $dataStartIndex = ($headerInfo['score'] > 0) ? $headerInfo['index'] + 1 : 1;
    
    // --- AUTO-DETECT MODE ---
    // If headers contain known Google Forms keywords, force 'google_forms' mode
    $googleKeywords = ['وقت التسجيل', 'Timestamp', 'الطابع الزمني', 'نوع المشاركة', 'الاسم الرباعي', 'رقم الموبايل', 'محافظة السكن', 'سنة الصنع', 'رقم اللوحة'];
    foreach ($headers as $h) {
        foreach ($googleKeywords as $kw) {
            if (str_contains(trim($h), $kw)) {
                $mode = 'google_forms';
                break 2;
            }
        }
    }
    
    $map = getColumnMapping($headers, $mode);
    
    $parsed = [];
    $pdo = db();
    
    // --- LOAD EXISTING MEMBERS (JSON) FOR DUPLICATE CHECK ---
    $membersFile = __DIR__ . '/data/members.json';
    $existingMembers = [];
    $phoneToCode = [];
    $plateToCode = [];
    
    if (file_exists($membersFile)) {
        $existingMembers = json_decode(file_get_contents($membersFile), true) ?? [];
        
        // Build lookup indexes
        foreach ($existingMembers as $code => $member) {
            // Phone index (normalized)
            $memberPhone = $member['phone'] ?? '';
            if ($memberPhone) {
                // Normalize phone for comparison
                $normalizedPhone = preg_replace('/[^0-9]/', '', $memberPhone);
                $normalizedPhone = ltrim($normalizedPhone, '0');
                if (strlen($normalizedPhone) > 9) {
                    $normalizedPhone = substr($normalizedPhone, -10);
                }
                $phoneToCode[$normalizedPhone] = $code;
            }
            
            // Plate index (combine letter + number)
            $plateLetter = trim($member['plate_letter'] ?? '');
            $plateNumber = trim($member['plate_number'] ?? '');
            if ($plateLetter && $plateNumber) {
                $plateKey = strtolower($plateLetter . '_' . $plateNumber);
                $plateToCode[$plateKey] = $code;
            }
        }
    }
    
    // --- LOAD CURRENT DATA.JSON TO CHECK CURRENT REGISTRATIONS ---
    $dataFile = __DIR__ . '/data/data.json';
    $currentData = [];
    $currentPhones = [];
    if (file_exists($dataFile)) {
        $currentData = json_decode(file_get_contents($dataFile), true) ?? [];
        foreach ($currentData as $reg) {
            $regPhone = preg_replace('/[^0-9]/', '', $reg['phone'] ?? '');
            $regPhone = ltrim($regPhone, '0');
            if (strlen($regPhone) > 9) $regPhone = substr($regPhone, -10);
            if ($regPhone) $currentPhones[$regPhone] = true;
        }
    }
    
    $seenPhones = [];
    for ($i = $dataStartIndex; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (empty(array_filter($row))) continue;
        
        $data = [];
        $status = 'invalid';
        $itemNotes = [];
        $warnings = [];
        $existingCode = null;
        
        try {
            // Extraction
            if ($mode === 'google_forms' && isset($map['phone'])) {
                $rawPhone = isset($map['phone']) ? ($row[$map['phone']] ?? '') : '';
                // NEW: Convert Arabic numerals to English before any processing
                if (function_exists('convertArabicToEnglishDigits')) {
                    $rawPhone = convertArabicToEnglishDigits($rawPhone);
                } else {
                    // Quick inline fallback if helper not loaded yet
                    $rawPhone = str_replace(
                        ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
                        range(0, 9),
                        $rawPhone
                    );
                }
                
                $name = isset($map['name']) ? trim($row[$map['name']] ?? '') : '';
                $gov = isset($map['gov']) ? trim($row[$map['gov']] ?? '') : '';
                $carType = isset($map['car_type']) ? trim($row[$map['car_type']] ?? '') : '';
                $carYear = isset($map['car_year']) ? trim($row[$map['car_year']] ?? '') : '';
                $carColor = isset($map['car_color']) ? trim($row[$map['car_color']] ?? '') : '';
                $engine = isset($map['engine']) ? trim($row[$map['engine']] ?? '') : '';
                $plateGov = isset($map['plate_gov']) ? trim($row[$map['plate_gov']] ?? '') : '';
                $plateLtr = isset($map['plate_letter']) ? trim($row[$map['plate_letter']] ?? '') : '';
                $plateNum = isset($map['plate_num']) ? trim($row[$map['plate_num']] ?? '') : '';
                $partType = isset($map['participation']) ? trim($row[$map['participation']] ?? '') : '';
                $registrationCode = isset($map['registration_code']) ? trim($row[$map['registration_code']] ?? '') : '';
            } else {
                $rawPhone = $row[0] ?? '';
                // NEW: Convert Arabic numerals to English
                if (function_exists('convertArabicToEnglishDigits')) {
                    $rawPhone = convertArabicToEnglishDigits($rawPhone);
                } else {
                    $rawPhone = str_replace(
                        ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
                        range(0, 9),
                        $rawPhone
                    );
                }

                $name = trim($row[1] ?? '');
                $gov = trim($row[2] ?? '');
                $carType = trim($row[3] ?? '');
                $carYear = trim($row[4] ?? '');
                $carColor = trim($row[5] ?? '');
                $engine = trim($row[6] ?? '');
                $plateGov = trim($row[7] ?? '');
                $plateLtr = trim($row[8] ?? '');
                $plateNum = trim($row[9] ?? '');
                $partType = trim($row[10] ?? '');
                $registrationCode = trim($row[11] ?? '');
            }
            
            // --- VALIDATION ---
            
            // 1. Phone
            try {
                $phone = normalizePhone($rawPhone);
            } catch (Exception $e) {
                throw new Exception("رقم هاتف غير صالح: $rawPhone");
            }
            
            // --- AUTO-FIX: SWAP LETTER/NUMBER IF NEEDED ---
            // If Letter is digits (e.g. 9022) AND Number is alpha (e.g. A) -> Swap them
            if (is_numeric($plateLtr) && !is_numeric($plateNum) && mb_strlen($plateNum) < 4) {
                $temp = $plateLtr;
                $plateLtr = $plateNum;
                $plateNum = $temp;
                $itemNotes[] = "تم تبديل الحرف/الرقم للوحة";
            }
            
            // Normalize for lookup
            $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
            $normalizedPhone = ltrim($normalizedPhone, '0');
            if (strlen($normalizedPhone) > 9) {
                $normalizedPhone = substr($normalizedPhone, -10);
            }

            // Check duplicate inside file
            if (in_array($normalizedPhone, $seenPhones)) {
                $status = 'skipped';
                $itemNotes[] = 'مكرر داخل الملف';
            } else {
                $seenPhones[] = $normalizedPhone;
            }

            // 2. Name
            if (empty($name)) {
                $warnings[] = "الاسم مفقود";
            } elseif (count(explode(' ', trim($name))) < 2) {
                // Using explode space is safer for Arabic than str_word_count
                $warnings[] = "الاسم قصير جداً";
            }
            
            // Prepare Data
            $data = [
                'phone'       => $phone,
                'name'        => $name,
                'governorate' => $gov,
                'registration_code' => $registrationCode,
                'car' => [
                    'type'   => $carType,
                    'year'   => $carYear,
                    'color'  => $carColor,
                    'engine' => $engine,
                    'plate_gov' => $plateGov,
                    'plate_ltr' => $plateLtr,
                    'plate_num' => $plateNum,
                    'participation' => $partType
                ]
            ];
            
            if ($status === 'skipped') {
                // Already marked as skipped
            } else {
                // --- CHECK EXISTING MEMBER BY PHONE (members.json) ---
                if (isset($phoneToCode[$normalizedPhone])) {
                    $existingCode = $phoneToCode[$normalizedPhone];
                    $data['existing_code'] = $existingCode;
                    $existingMember = $existingMembers[$existingCode] ?? null;
                    
                    // Check if already registered in current championship
                    if (isset($currentPhones[$normalizedPhone])) {
                        $status = 'skipped';
                        $itemNotes[] = "تم تخطيه (مسجل من قبل) (كود: $existingCode)";
                    } else {
                        $status = 'returning';
                        $itemNotes[] = "مسجل مسبقاً (كود: $existingCode)";
                        if ($existingMember) {
                            $championships = $existingMember['championships_participated'] ?? 0;
                            $itemNotes[] = "شارك في $championships جولات";
                        }
                    }
                }
                // --- CHECK BY PLATE NUMBER ---
                elseif ($plateLtr && $plateNum) {
                    $plateKey = strtolower($plateLtr . '_' . $plateNum);
                    if (isset($plateToCode[$plateKey])) {
                        $existingCode = $plateToCode[$plateKey];
                        $data['existing_code'] = $existingCode;
                        
                        if (isset($currentPhones[$normalizedPhone])) {
                            $status = 'skipped';
                            $itemNotes[] = "مسجل بالبطولة الحالية (كود: $existingCode)";
                        } else {
                            $status = 'returning';
                            $itemNotes[] = "مسجل بالشبكة ولكن غير مسجل للفعالية (كود: $existingCode)";
                        }
                    } else {
                        // New member
                        $status = 'new';
                        $itemNotes[] = 'عضو جديد تماماً';
                    }
                }
                else {
                    // Fallback: Check SQL database
                    $stmt = $pdo->prepare("SELECT id FROM members WHERE phone = ?");
                    $stmt->execute([$phone]);
                    $exists = $stmt->fetch();
                    
                    if ($exists) {
                        $status = 'updated';
                        $itemNotes[] = 'موجود في SQL (بدون ملف JSON)';
                    } else {
                        $status = 'new';
                        $itemNotes[] = 'عضو جديد تماماً';
                    }
                }
            }
        } catch (Exception $e) {
            $status = 'skipped';
            $itemNotes[] = $e->getMessage();
            if (empty($data)) {
                $data = ['phone' => $rawPhone ?? 'Error', 'name' => $name ?? 'Error'];
            }
        }
        
        $parsed[] = [
            'data' => $data,
            'status' => $status,
            'notes' => implode(', ', $itemNotes),
            'warnings' => $warnings,
            'row' => $i + 2
        ];
    }
    return $parsed;
}

/**
 * Commit Import
 * Handles both "Event" and "Members Only" destinations
 */
function commitImport($parsedData, $source, $batchId, $messagePrefs = [], $destination = 'event') {
    $results = ['imported' => 0, 'updated' => 0, 'returning' => 0];
    
    // --- BRANCH 1: MEMBERS ONLY IMPORT ---
    if ($destination === 'members_only') {
        $membersFile = __DIR__ . '/data/members.json';
        $membersData = [];
        if (file_exists($membersFile)) {
            $membersData = json_decode(file_get_contents($membersFile), true) ?? [];
        }
        
        // Build Index for fast lookup (by existing_code or phone)
        $phoneIndex = [];
        foreach ($membersData as $code => $m) {
            if (!empty($m['phone'])) $phoneIndex[$m['phone']] = $code;
        }

        foreach ($parsedData as $item) {
            if ($item['status'] === 'invalid') continue;

            $d = $item['data'];
            $phone = $d['phone'] ?? '';
            if (empty($phone)) continue;

            // Determine Code: Existing or New
            $code = $d['existing_code'] ?? $phoneIndex[$phone] ?? null;
            $isNew = false;

            if (!$code) {
                // Generate new code
                $code = strtoupper(substr(md5($phone . time() . rand(1000, 9999)), 0, 6));
                $isNew = true;
            }

            // Prepare Member Record
            $memberRecord = $membersData[$code] ?? [
                'registration_code' => $code,
                'first_registered' => date('Y-m-d H:i:s'),
                'championships_participated' => 0,
                'images' => []
            ];

            // Update Fields
            $memberRecord['full_name'] = $d['name']; 
            $memberRecord['phone'] = $phone;
            $memberRecord['governorate'] = $d['governorate'];
            $memberRecord['country_code'] = '+964';
            
            // Car Info
            $memberRecord['car_type'] = $d['car']['type'];
            $memberRecord['car_year'] = $d['car']['year'];
            $memberRecord['car_color'] = $d['car']['color'];
            $memberRecord['engine_size'] = $d['car']['engine'];
            
            // Plate
            $memberRecord['plate_governorate'] = $d['car']['plate_gov'];
            $memberRecord['plate_letter'] = $d['car']['plate_letter'] ?? $d['car']['plate_ltr'] ?? '';
            $memberRecord['plate_number'] = $d['car']['plate_num'];
            
            // Participation Type
            $memberRecord['participation_type'] = $d['car']['participation'];
            
            // Metadata
            $memberRecord['last_active'] = date('Y-m-d H:i:s');
            
            // Save to Array
            $membersData[$code] = $memberRecord;
            
            if ($isNew) {
                $results['imported']++;
            } else {
                $results['updated']++;
            }
        }

        // Save Members File
        file_put_contents($membersFile, json_encode($membersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        
        // --- SYNC TO SQLITE (Unified) ---
        try {
            $pdo = db();
            foreach ($membersData as $code => $reg) {
                $phone = $reg['phone'] ?? '';
                if (empty($phone)) continue;
                
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($cleanPhone) < 10) continue;
                
                $name = $reg['full_name'] ?? 'Unknown';
                $gov = $reg['governorate'] ?? '';
                
                $member = MemberService::getOrCreateMember($cleanPhone, $name, $gov);
                
                // Keep permanent code in sync
                if (!empty($code) && ($member['permanent_code'] ?? 'TEMP') === 'TEMP') {
                    $pdo->prepare("UPDATE members SET permanent_code = ? WHERE id = ?")->execute([$code, $member['id']]);
                }
            }
        } catch (Exception $e) {
            error_log("Members sync error: " . $e->getMessage());
        }

        return $results;
    }
    
    // --- BRANCH 2: EVENT (STANDARD) IMPORT (Default) ---
    // Handles registrations for current event
    
    // Default message preferences
    $defaultPrefs = [
        'send_registration' => $messagePrefs['send_registration'] ?? 1,
        'send_acceptance' => $messagePrefs['send_acceptance'] ?? 1,
        'send_badge' => $messagePrefs['send_badge'] ?? 1
    ];
    
    // Load existing data.json
    $dataFile = __DIR__ . '/data/data.json';
    $existingData = [];
    if (file_exists($dataFile)) {
        $existingData = json_decode(file_get_contents($dataFile), true) ?? [];
    }
    
    // Find highest wasel number
    $maxWasel = 0;
    foreach ($existingData as $item) {
        if (isset($item['wasel']) && is_numeric($item['wasel'])) {
            $maxWasel = max($maxWasel, (int)$item['wasel']);
        }
    }
    
    // Create index of existing phones
    $existingPhones = [];
    foreach ($existingData as $index => $item) {
        $phone = $item['phone'] ?? '';
        if ($phone) $existingPhones[$phone] = $index;
    }
    
    foreach ($parsedData as $item) {
        if ($item['status'] === 'skipped' || $item['status'] === 'invalid') continue;
        
        $d = $item['data'];
        $phone = $d['phone'] ?? '';
        
        // Build plate_full
        $plateParts = [];
        if (!empty($d['car']['plate_gov'])) $plateParts[] = $d['car']['plate_gov'];
        if (!empty($d['car']['plate_letter'])) $plateParts[] = $d['car']['plate_letter'];
        if (!empty($d['car']['plate_num'])) $plateParts[] = $d['car']['plate_num'];
        $plateFull = implode(' - ', $plateParts);
        
        // --- USE EXISTING CODE IF RETURNING MEMBER ---
        if (!empty($d['existing_code'])) {
            $regCode = $d['existing_code'];
            $registerType = 'returning';
            $registerTypeLabel = 'عضو مسبق';
        } else {
            // Generate new registration code
            $regCode = strtoupper(substr(md5($phone . time() . rand(1000, 9999)), 0, 6));
            $registerType = 'imported';
            $registerTypeLabel = 'استيراد Excel';
        }
        
        // Build registration record
        $registration = [
            'wasel' => ++$maxWasel,
            'registration_code' => $regCode,
            'full_name' => $d['name'] ?? '',
            'phone' => $phone,
            'country_code' => '+964',
            'governorate' => $d['governorate'] ?? '',
            'car_type' => $d['car']['type'] ?? '',
            'car_year' => $d['car']['year'] ?? '',
            'car_color' => $d['car']['color'] ?? '',
            'engine_size' => $d['car']['engine'] ?? '',
            'plate_governorate' => $d['car']['plate_gov'] ?? '',
            'plate_letter' => $d['car']['plate_letter'] ?? $d['car']['plate_ltr'] ?? '', // fix key mismatch
            'plate_number' => $d['car']['plate_num'] ?? '',
            'plate_full' => $plateFull,
            'participation_type' => $d['car']['participation'] ?? '',
            'status' => 'pending',
            'registration_date' => date('Y-m-d H:i:s'),
            'import_source' => $source,
            'import_batch' => $batchId,
            'register_type' => $registerType,
            'register_type_label' => $registerTypeLabel,
            'images' => [],
            'message_prefs' => $defaultPrefs
        ];
        
        // Check if phone already exists (update vs new)
        if (isset($existingPhones[$phone])) {
            $existingIndex = $existingPhones[$phone];
            $registration['wasel'] = $existingData[$existingIndex]['wasel'];
            $maxWasel--;
            $existingData[$existingIndex] = array_merge($existingData[$existingIndex], $registration);
            $results['updated']++;
        } else {
            $existingData[] = $registration;
            $existingPhones[$phone] = count($existingData) - 1;
            
            if ($item['status'] === 'returning') {
                $results['returning']++;
            } else {
                $results['imported']++;
            }
        }
    }
    
    // Save back to data.json
    file_put_contents($dataFile, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // ============ SYNC ALL IMPORTED MEMBERS TO members.json + SQLite ============
    // Only execute this if destination is 'both'
    // If 'event', we wait until the end of championship to sync.
    if ($destination === 'both') {
        try {
        // 1. Sync to members.json
        $membersFile = __DIR__ . '/data/members.json';
        $membersData = [];
        if (file_exists($membersFile)) {
            $membersData = json_decode(file_get_contents($membersFile), true) ?? [];
        }
        
        foreach ($existingData as $reg) {
            $regCode = $reg['registration_code'] ?? '';
            if (empty($regCode)) continue;
            
            $memberRecord = $membersData[$regCode] ?? [
                'registration_code' => $regCode,
                'first_registered' => date('Y-m-d H:i:s'),
                'championships_participated' => 0,
                'images' => []
            ];
            
            $memberRecord['full_name'] = $reg['full_name'] ?? $memberRecord['full_name'] ?? '';
            $memberRecord['phone'] = $reg['phone'] ?? $memberRecord['phone'] ?? '';
            $memberRecord['country_code'] = $reg['country_code'] ?? '+964';
            $memberRecord['governorate'] = $reg['governorate'] ?? $memberRecord['governorate'] ?? '';
            $memberRecord['car_type'] = $reg['car_type'] ?? $memberRecord['car_type'] ?? '';
            $memberRecord['car_year'] = $reg['car_year'] ?? $memberRecord['car_year'] ?? '';
            $memberRecord['car_color'] = $reg['car_color'] ?? $memberRecord['car_color'] ?? '';
            $memberRecord['engine_size'] = $reg['engine_size'] ?? $memberRecord['engine_size'] ?? '';
            $memberRecord['plate_governorate'] = $reg['plate_governorate'] ?? $memberRecord['plate_governorate'] ?? '';
            $memberRecord['plate_letter'] = $reg['plate_letter'] ?? $memberRecord['plate_letter'] ?? '';
            $memberRecord['plate_number'] = $reg['plate_number'] ?? $memberRecord['plate_number'] ?? '';
            $memberRecord['plate_full'] = $reg['plate_full'] ?? $memberRecord['plate_full'] ?? '';
            $memberRecord['participation_type'] = $reg['participation_type'] ?? $memberRecord['participation_type'] ?? '';
            $memberRecord['wasel'] = $reg['wasel'] ?? $memberRecord['wasel'] ?? '';
            $memberRecord['badge_token'] = $reg['badge_token'] ?? $memberRecord['badge_token'] ?? '';
            $memberRecord['last_active'] = date('Y-m-d H:i:s');
            
            $membersData[$regCode] = $memberRecord;
        }
        
        file_put_contents($membersFile, json_encode($membersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        
        // 2. Sync to SQLite (Members + Registrations)
        $pdo = db();
        $siteSettingsFile = __DIR__ . '/data/site_settings.json';
        $siteSettings = file_exists($siteSettingsFile) ? json_decode(file_get_contents($siteSettingsFile), true) : [];
        $champId = $siteSettings['current_championship_id'] ?? date('Y') . '_default';
        
        foreach ($existingData as $reg) {
            try {
                $phone = $reg['phone'] ?? '';
                if (empty($phone)) continue;
                
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($cleanPhone) < 10) continue;
                
                $name = $reg['full_name'] ?? 'Unknown';
                $gov = $reg['governorate'] ?? '';
                
                $member = MemberService::getOrCreateMember($cleanPhone, $name, $gov);
                
                // ALWAYS sync registration_code as permanent_code so dashboard & members page match
                $regCode = $reg['registration_code'] ?? '';
                if (!empty($regCode)) {
                    $pdo->prepare("UPDATE members SET permanent_code = ? WHERE id = ?")->execute([$regCode, $member['id']]);
                }
                
                // Persist car data and PHOTO to members table (survives championship resets)
                try {
                    $pdo->prepare("UPDATE members SET last_car_type=?, last_car_year=?, last_car_color=?, last_plate_number=?, last_plate_letter=?, last_plate_governorate=?, last_engine_size=?, last_participation_type=?, personal_photo=? WHERE id=?")
                        ->execute([
                            $reg['car_type'] ?? '', $reg['car_year'] ?? '', $reg['car_color'] ?? '',
                            $reg['plate_number'] ?? '', $reg['plate_letter'] ?? '', $reg['plate_governorate'] ?? '',
                            $reg['engine_size'] ?? $reg['engine'] ?? '',
                            $reg['participation_type'] ?? '',
                            $reg['personal_photo'] ?? $reg['images']['personal_photo'] ?? '',
                            $member['id']
                        ]);
                } catch(Exception $e) { /* columns may not exist yet */ }
                
                // Create registration record (pending) so member appears on members page
                $stmtCheck = $pdo->prepare("SELECT id FROM registrations WHERE member_id = ? AND championship_id = ?");
                $stmtCheck->execute([$member['id'], $champId]);
                if (!$stmtCheck->fetchColumn()) {
                    try {
                        $pdo->prepare("
                            INSERT INTO registrations (
                                member_id, championship_id, wasel,
                                car_type, car_year, car_color,
                                plate_governorate, plate_letter, plate_number,
                                participation_type, engine_size, session_badge_token,
                                status, created_at, is_active,
                                personal_photo, front_image, side_image, back_image, acceptance_image
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 1, ?, ?, ?, ?, ?)
                        ")->execute([
                            $member['id'], $champId, $reg['wasel'] ?? 0,
                            $reg['car_type'] ?? '', $reg['car_year'] ?? '', $reg['car_color'] ?? '',
                            $reg['plate_governorate'] ?? '', $reg['plate_letter'] ?? '', $reg['plate_number'] ?? '',
                            $reg['participation_type'] ?? '',
                            $reg['engine_size'] ?? $reg['engine'] ?? '',
                            $reg['badge_token'] ?? $reg['session_badge_token'] ?? '',
                            date('Y-m-d H:i:s'),
                            $reg['personal_photo'] ?? $reg['images']['personal_photo'] ?? null,
                            $reg['images']['front_image'] ?? null,
                            $reg['images']['side_image'] ?? null,
                            $reg['images']['back_image'] ?? null,
                            $reg['acceptance_image'] ?? null
                        ]);
                    } catch(Exception $dbE) {
                        // In case of Unique Constraint (e.g. wasel), try again with a random wasel or higher wasel
                        if (strpos($dbE->getMessage(), 'UNIQUE') !== false) {
                            $randomWasel = $reg['wasel'] . rand(100, 999);
                            $pdo->prepare("
                                INSERT INTO registrations (
                                    member_id, championship_id, wasel,
                                    car_type, car_year, car_color,
                                    plate_governorate, plate_letter, plate_number,
                                    participation_type, engine_size, session_badge_token,
                                    status, created_at, is_active,
                                    personal_photo, front_image, side_image, back_image, acceptance_image
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 1, ?, ?, ?, ?, ?)
                            ")->execute([
                                $member['id'], $champId, $randomWasel,
                                $reg['car_type'] ?? '', $reg['car_year'] ?? '', $reg['car_color'] ?? '',
                                $reg['plate_governorate'] ?? '', $reg['plate_letter'] ?? '', $reg['plate_number'] ?? '',
                                $reg['participation_type'] ?? '',
                                $reg['engine_size'] ?? $reg['engine'] ?? '',
                                $reg['badge_token'] ?? $reg['session_badge_token'] ?? '',
                                date('Y-m-d H:i:s'),
                                $reg['personal_photo'] ?? $reg['images']['personal_photo'] ?? null,
                                $reg['images']['front_image'] ?? null,
                                $reg['images']['side_image'] ?? null,
                                $reg['images']['back_image'] ?? null,
                                $reg['acceptance_image'] ?? null
                            ]);
                        } else {
                            throw $dbE;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to sync member to SQLite ({$phone}): " . $e->getMessage());
            }
        }
        } catch (Exception $outerE) {
            error_log("Event import sync error: " . $outerE->getMessage());
        }
    }
    // ============ END SYNC ============
    
    return $results;
}

// Read Helpers (CSV/Excel) - Same as before
function readCSV($f) {
    $rows = [];
    if (($h = fopen($f, "r")) !== FALSE) {
        while (($d = fgetcsv($h, 1000, ",")) !== FALSE) $rows[] = $d;
        fclose($h);
    }
    return $rows;
}
function readExcel($f) {
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $iox = '\PhpOffice\PhpSpreadsheet\IOFactory';
        if (class_exists($iox)) {
            try {
                $s = $iox::load($f);
                return $s->getActiveSheet()->toArray();
            } catch (Exception $e) {}
        }
    }
    // Native Fallback
    if (!class_exists('ZipArchive')) {
        throw new Exception("مكتبة ZipArchive غير مفعلة. يرجى حفظ الملف بصيغة CSV (Comma Delimited) والمحاولة مرة أخرى.");
    }

    $zip = new ZipArchive();
    if ($zip->open($f) !== TRUE) throw new Exception("فشل فتح ملف (Native XLSX)");
    
    $sharedStrings = [];
    if ($xml = simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'))) {
        foreach ($xml->si as $si) $sharedStrings[] = (string)($si->t ?? $si->r->t ?? '');
    }
    
    $rows = [];
    if ($xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'))) {
        foreach ($xml->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                $val = (string)$c->v;
                if (isset($c['t']) && (string)$c['t'] === 's') $val = $sharedStrings[(int)$val] ?? $val;
                $r[] = $val;
            }
            $rows[] = $r;
        }
    }
    $zip->close();
    return $rows;
}
?>

<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استيراد الأعضاء | نادي بلاد الرافدين</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#b91c1c',
                        secondary: '#1c1917',
                        accent: '#f59e0b'
                    },
                    fontFamily: {
                        sans: ['Cairo', 'sans-serif'],
                    }
                }
            }
        }
    </script>


</head>
<body class="bg-gray-50 text-secondary">
    <?php include '../include/navbar-custom.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="bg-white rounded-xl shadow-lg p-6 max-w-5xl mx-auto">
            
            <div class="flex items-center justify-between mb-8 pb-4 border-b">
                <div>
                    <h1 class="text-2xl font-bold mb-2">استيراد الأعضاء</h1>
                    <p class="text-gray-600">استيراد بيانات الأعضاء (يفضل استخدام صيغة <b>CSV</b> لأن الخادم لا يدعم XLSX حالياً)</p>
                </div>
                <a href="../dashboard.php" class="bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded-lg transition">
                    <i class="fas fa-arrow-right ml-2"></i> عودة
                </a>
            </div>

            <!-- Messages -->
            <?php if ($error): ?>
                <div class="bg-red-50 text-red-700 p-4 rounded-lg mb-6 flex items-center border border-red-200">
                    <i class="fas fa-times-circle text-2xl ml-3"></i>
                    <div>
                        <h3 class="font-bold">حدث خطأ</h3>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="bg-green-50 text-green-700 p-4 rounded-lg mb-6 flex items-center border border-green-200">
                    <i class="fas fa-check-circle text-2xl ml-3"></i>
                    <div>
                        <h3 class="font-bold">تم بنجاح</h3>
                        <p><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- STEP 1: UPLOAD -->
            <?php if ($step === 'upload'): ?>
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="step" value="preview">
                <input type="hidden" name="import_mode" value="google_forms">

                <div class="border-2 border-dashed border-gray-300 rounded-xl p-10 text-center hover:bg-gray-50 transition relative group">
                    <input type="file" name="excel_file" required accept=".xlsx,.xls,.csv" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                    <div class="text-gray-400 group-hover:text-primary transition">
                        <i class="fas fa-cloud-upload-alt text-6xl mb-4"></i>
                        <p class="font-medium text-lg">اختر ملف Excel أو CSV</p>
                        <p class="text-sm mt-2 text-gray-400">يدعم صيغ .xlsx, .csv</p>
                    </div>
                </div>

                <div class="bg-yellow-50 border border-yellow-200 p-4 rounded-lg mb-4">
                    <label class="block text-sm font-bold text-gray-800 mb-2"><i class="fas fa-bullseye ml-1 text-yellow-600"></i> خيارات وجهة الاستيراد</label>
                    <select name="import_destination" class="block w-full p-3 bg-white border border-gray-300 rounded-lg shadow-sm focus:ring-primary focus:border-primary font-bold">
                        <option value="both" selected>للفعالية وقاعدة الأعضاء معاً (توصية المطور)</option>
                        <option value="event">للفعالية الحالية فقط</option>
                        <option value="members_only">لقاعدة الأعضاء الدائمة فقط</option>
                    </select>
                    <p class="text-xs text-yellow-700 mt-3 leading-relaxed">
                        <i class="fas fa-info-circle ml-1"></i> <b>توضيح مهام الاستيراد:</b><br>
                        - <b>للفعالية وقاعدة الأعضاء معاً:</b> سيتم إدخال المتسابقين في البطولة مباشرة، وسيتم إضافة ملفاتهم كأعضاء دائمين في لوحة الأعضاء فوراً.<br>
                        - <b>للفعالية الحالية فقط:</b> ينضمون للبطولة الحالية فقط ليتمكنوا من السباق (وبنهاية البطولة يتم ترحيلهم آلياً للقائمة الدائمة).<br>
                        - <b>لقاعدة الأعضاء الدائمة فقط:</b> يتم إضافة بياناتهم للقائمة الدائمة لتخزينها فقط، دون أن يتم تسجيلهم للمشاركة في الفعالية المفتوحة حالياً.
                    </p>
                </div>

                <button type="submit" class="w-full bg-primary hover:bg-red-800 text-white font-bold py-4 rounded-xl shadow-lg transition transform hover:-translate-y-1">
                    <i class="fas fa-search ml-2"></i> معاينة الملف
                </button>
            </form>
            <?php endif; ?>

            <!-- STEP 2: PREVIEW -->
            <?php if ($step === 'commit'): ?>
            <div class="space-y-6">
                
                <!-- SUMMARY BAR -->
                <div class="grid grid-cols-5 gap-4 p-4 bg-gray-50 rounded-lg border">
                    <div class="text-center border-l border-gray-200">
                        <span class="block text-2xl font-bold text-blue-600"><?php echo $stats['new']; ?></span>
                        <span class="text-sm text-gray-500">🆕 جديد</span>
                    </div>
                    <div class="text-center border-l border-gray-200">
                        <span class="block text-2xl font-bold text-purple-600"><?php echo $stats['returning']; ?></span>
                        <span class="text-sm text-gray-500">🔄 مسجل مسبقاً</span>
                    </div>
                    <div class="text-center border-l border-gray-200">
                        <span class="block text-2xl font-bold text-green-600"><?php echo $stats['updated']; ?></span>
                        <span class="text-sm text-gray-500">✅ تم التحديث</span>
                    </div>
                    <div class="text-center border-l border-gray-200">
                        <span class="block text-2xl font-bold text-yellow-600"><?php echo $stats['warnings']; ?></span>
                        <span class="text-sm text-gray-500">⚠️ يوجد تحذير</span>
                    </div>
                    <div class="text-center">
                        <span class="block text-2xl font-bold text-red-600"><?php echo $stats['skipped']; ?></span>
                        <span class="text-sm text-gray-500">🚫 تم التجاهل (خطأ)</span>
                    </div>
                </div>

                <form method="POST" id="importForm" class="space-y-4">
                    <input type="hidden" name="step" value="commit_action">
                
                <div class="overflow-x-auto rounded-lg border">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-100 text-gray-700">
                            <tr>
                                <th class="p-3 text-center w-12">
                                    <input type="checkbox" id="selectAll" checked class="w-5 h-5" onchange="toggleAllRows(this.checked)" title="تحديد/إلغاء الكل">
                                </th>
                                <th class="p-3 text-right">#</th>
                                <th class="p-3 text-right">الحالة</th>
                                <th class="p-3 text-right">الاسم</th>
                                <th class="p-3 text-right">الهاتف</th>
                                <th class="p-3 text-right">السيارة</th>
                                <th class="p-3 text-right">الملاحظات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php $rowIndex = 0; foreach ($previewData as $row): ?>
                            <?php $isSkipped = ($row['status'] === 'skipped' || $row['status'] === 'invalid'); ?>
                            <tr class="<?php echo $isSkipped ? 'bg-red-50 opacity-60' : ''; ?>">
                                <td class="p-3 text-center">
                                    <?php if (!$isSkipped): ?>
                                    <input type="checkbox" name="selected_rows[]" value="<?php echo $rowIndex; ?>" checked class="row-select w-5 h-5">
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3"><?php echo $row['row']; ?></td>
                                <td class="p-3">
                                    <?php if ($row['status'] === 'new'): ?>
                                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-bold">جديد</span>
                                    <?php elseif ($row['status'] === 'returning'): ?>
                                        <span class="bg-purple-100 text-purple-800 px-2 py-1 rounded text-xs font-bold">مسجل مسبقاً</span>
                                    <?php elseif ($row['status'] === 'updated'): ?>
                                        <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs font-bold">تم التحديث</span>
                                    <?php else: ?>
                                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs font-bold">تجاهل</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 font-medium">
                                    <?php echo htmlspecialchars($row['data']['name'] ?? '-'); ?>
                                    <?php if (!empty($row['warnings'])): ?>
                                        <i class="fas fa-exclamation-triangle text-yellow-500 mr-2" title="<?php echo implode("\n", $row['warnings']); ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 ltr text-left"><?php echo htmlspecialchars($row['data']['phone'] ?? '-'); ?></td>
                                <td class="p-3">
                                    <?php echo htmlspecialchars($row['data']['car']['type'] ?? ''); ?> 
                                    <span class="text-gray-400 text-xs">(<?php echo htmlspecialchars($row['data']['car']['plate_num'] ?? ''); ?>)</span>
                                </td>
                                <td class="p-3 text-xs text-gray-500 max-w-xs truncate">
                                    <?php 
                                        echo $row['notes']; 
                                        if (!empty($row['warnings'])) echo ' | ' . implode(', ', $row['warnings']);
                                    ?>
                                </td>
                            </tr>
                            <?php $rowIndex++; endforeach; ?>
                        </tbody>
                    </table>
                    
                    <script>
                    function toggleAllRows(checked) {
                        document.querySelectorAll('.row-select').forEach(cb => cb.checked = checked);
                        updateSelectedCount();
                    }
                    
                    function updateSelectedCount() {
                        const total = document.querySelectorAll('.row-select').length;
                        const selected = document.querySelectorAll('.row-select:checked').length;
                        document.getElementById('selectedCount').textContent = selected + ' / ' + total;
                    }
                    
                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.row-select').forEach(cb => {
                            cb.addEventListener('change', updateSelectedCount);
                        });
                        updateSelectedCount();
                    });
                    </script>
                    
                    <div class="mt-2 text-sm text-gray-600">
                        العناصر المحددة: <strong id="selectedCount">-</strong>
                    </div>
                </div>

                <!-- Message Selection Options -->
                <div class="p-4 bg-green-50 rounded-lg border border-green-200 mb-4">
                    <h3 class="font-bold text-green-800 mb-3"><i class="fab fa-whatsapp ml-2"></i> خيارات إرسال رسائل واتساب:</h3>
                    <p class="text-sm text-gray-600 mb-3">حدد نوع الرسائل التي ترغب في إرسالها إلى الأعضاء</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <label class="flex items-center gap-2 p-3 bg-white rounded-lg border cursor-pointer hover:bg-green-100 transition">
                            <input type="checkbox" name="send_registration" value="1" checked class="w-5 h-5 text-green-600">
                            <div>
                                <span class="font-medium">رسالة إتمام التسجيل</span>
                                <p class="text-xs text-gray-500">رسالة ترحيب بالعضو الجديد</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-2 p-3 bg-white rounded-lg border cursor-pointer hover:bg-green-100 transition">
                            <input type="checkbox" name="send_acceptance" value="1" checked class="w-5 h-5 text-green-600">
                            <div>
                                <span class="font-medium">رسالة القبول</span>
                                <p class="text-xs text-gray-500">رسالة تفيد بقبول العضو</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-2 p-3 bg-white rounded-lg border cursor-pointer hover:bg-green-100 transition">
                            <input type="checkbox" name="send_badge" value="1" checked class="w-5 h-5 text-green-600">
                            <div>
                                <span class="font-medium">رسالة الباج/QR</span>
                                <p class="text-xs text-gray-500">رابط الباج مع رمز QR</p>
                            </div>
                        </label>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="button" onclick="selectAllMessages(true)" class="text-sm text-green-700 hover:underline">تحديد الكل</button>
                        <span class="text-gray-300">|</span>
                        <button type="button" onclick="selectAllMessages(false)" class="text-sm text-red-600 hover:underline">إلغاء الكل</button>
                    </div>
                </div>
                
                <script>
                function selectAllMessages(checked) {
                    document.querySelectorAll('input[name^="send_"]').forEach(cb => cb.checked = checked);
                }
                </script>

                <!-- Submit Buttons -->
                    <div class="flex gap-4 mt-4">
                        <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl shadow-lg">
                            <i class="fas fa-save ml-2"></i> تأكيد وحفظ التغييرات
                        </button>
                        <a href="import_members.php" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-3 px-8 rounded-xl">
                            إلغاء
                        </a>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($step === 'success' && isset($_SESSION['import_success'])): ?>
            <!-- SUCCESS PAGE -->
            <div class="glass-card p-8 text-center">
                <div class="mb-6">
                    <div class="w-24 h-24 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-600 text-5xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-green-700 mb-2">تم الاستيراد بنجاح! 🎉</h2>
                    <p class="text-gray-600">لقد تم حفظ التغييرات بنجاح</p>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-8 max-w-md mx-auto">
                    <div class="bg-blue-50 p-4 rounded-xl">
                        <div class="text-3xl font-bold text-blue-600"><?php echo $_SESSION['import_success']['imported']; ?></div>
                        <div class="text-sm text-gray-600">مسجل تم رفعه</div>
                    </div>
                    <div class="bg-green-50 p-4 rounded-xl">
                        <div class="text-3xl font-bold text-green-600"><?php echo $_SESSION['import_success']['updated']; ?></div>
                        <div class="text-sm text-gray-600">تم تحديثه</div>
                    </div>
                </div>
                
                <div class="flex flex-col gap-3 max-w-md mx-auto">
                    <a href="../dashboard.php" class="bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-bold py-4 px-8 rounded-xl shadow-lg transition-all">
                        <i class="fas fa-home ml-2"></i> العودة للوحة التحكم
                    </a>
                    <a href="import_members.php" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-3 px-8 rounded-xl">
                        <i class="fas fa-upload ml-2"></i> استيراد ملف آخر
                    </a>
                    <a href="members.php" class="text-purple-600 hover:text-purple-800 font-medium py-2">
                        <i class="fas fa-users ml-2"></i> عرض قائمة الأعضاء
                    </a>
                </div>
            </div>
            <?php unset($_SESSION['import_success']); ?>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>
