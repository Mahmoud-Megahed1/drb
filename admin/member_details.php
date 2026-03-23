<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable display to prevent JSON corruption
session_start();
require_once '../include/db.php';
require_once '../include/helpers.php';
require_once '../include/AdminLogger.php';
require_once '../include/auth.php';
require_once '../include/RegistrationActionLogger.php';
require_once '../services/MemberService.php';
require_once '../services/BadgeCacheService.php';

// Auth Check
requireAuth('../login.php');

$currentUser = $_SESSION['user'];
$isRoot = (isset($currentUser->username) && $currentUser->username === 'root') || ($currentUser->role ?? '') === 'admin';
$userRole = $currentUser->role ?? ($isRoot ? 'admin' : 'viewer');
if ($isRoot) $userRole = 'admin';

// Access Check
if (!in_array($userRole, ['admin', 'root', 'approver', 'notes', 'gate', 'rounds'])) {
    header('location:../dashboard.php');
    exit;
}

$canEdit = in_array($userRole, ['admin', 'root', 'notes']);
$pdo = db();

// -- Get Member ID from URL --
$memberId = $_GET['id'] ?? '';
if (empty($memberId)) {
    header('location:members.php');
    exit;
}

// -- AJAX POST HANDLERS --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    try {
        // Edit Member Info (name, phone, governorate)
        if ($_POST['action'] === 'edit_member') {
            $mid = $_POST['member_id'];
            $field = $_POST['field'];
            $value = trim($_POST['value']);

            $allowed = ['name', 'phone', 'governorate', 'instagram'];
            if (!in_array($field, $allowed)) {
                throw new Exception('حقل غير مسموح');
            }

            if ($field === 'phone') {
                $value = normalizePhone($value);
            }

            $stmt = $pdo->prepare("UPDATE members SET $field = ? WHERE id = ?");
            $stmt->execute([$value, $mid]);

            // Sync to JSON
            MemberService::syncToJson($mid);

            auditLog('edit', 'member', $mid, null, "$field => $value", $currentUser->id ?? null);
            $response = ['success' => true, 'message' => 'تم التحديث بنجاح'];
        }

        // Edit Car Info
        elseif ($_POST['action'] === 'edit_car') {
            $mid = $_POST['member_id'];
            $regId = $_POST['registration_id'] ?? null;

            $carData = [
                'car_type' => $_POST['car_type'] ?? '',
                'car_year' => $_POST['car_year'] ?? '',
                'car_color' => $_POST['car_color'] ?? '',
                'engine_size' => $_POST['engine_size'] ?? '',
                'participation_type' => $_POST['participation_type'] ?? '',
                'plate_governorate' => $_POST['plate_governorate'] ?? '',
                'plate_letter' => $_POST['plate_letter'] ?? '',
                'plate_number' => $_POST['plate_number'] ?? '',
            ];

            // Update registration if exists
            if ($regId && is_numeric($regId)) {
                $stmt = $pdo->prepare("
                    UPDATE registrations SET 
                    car_type = ?, car_year = ?, car_color = ?, engine_size = ?,
                    participation_type = ?, plate_governorate = ?, plate_letter = ?, plate_number = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $carData['car_type'], $carData['car_year'], $carData['car_color'], $carData['engine_size'],
                    $carData['participation_type'], $carData['plate_governorate'], $carData['plate_letter'], $carData['plate_number'],
                    $regId
                ]);
            }

            // Always update member's last_ fields
            $stmt = $pdo->prepare("
                UPDATE members SET 
                last_car_type = ?, last_car_year = ?, last_car_color = ?, last_engine_size = ?,
                last_plate_governorate = ?, last_plate_letter = ?, last_plate_number = ?,
                last_participation_type = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $carData['car_type'], $carData['car_year'], $carData['car_color'], $carData['engine_size'],
                $carData['plate_governorate'], $carData['plate_letter'], $carData['plate_number'],
                $carData['participation_type'],
                $mid
            ]);

            // Sync to JSON
            MemberService::syncToJson($mid);

            auditLog('edit_car', 'member', $mid, null, json_encode($carData), $currentUser->id ?? null);
            $response = ['success' => true, 'message' => 'تم تحديث بيانات السيارة'];
        }

        // Add Warning
        elseif ($_POST['action'] === 'add_warning') {
            $mid = $_POST['member_id'];
            $text = trim($_POST['text']);
            $severity = $_POST['severity'] ?? 'low';
            $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

            $warningId = MemberService::addWarning($mid, $text, $severity, $expiresAt, null, $currentUser->id ?? null);
            $response = ['success' => true, 'message' => 'تمت إضافة المخالفة', 'id' => $warningId];
        }

        // Resolve Warning
        elseif ($_POST['action'] === 'resolve_warning') {
            $warningId = $_POST['warning_id'];
            MemberService::resolveWarning($warningId, $currentUser->id ?? null);
            $response = ['success' => true, 'message' => 'تم حل المخالفة'];
        }

        // Update Championships Count
        elseif ($_POST['action'] === 'update_championships') {
            $mid = $_POST['member_id'];
            $count = $_POST['count'];
            MemberService::updateManualStats($mid, $count);
            $response = ['success' => true, 'message' => 'تم تحديث عدد البطولات'];
        }

        // Update Rounds Count
        elseif ($_POST['action'] === 'update_rounds') {
            $mid = $_POST['member_id'];
            $count = $_POST['count'];
            MemberService::updateManualRounds($mid, $count);
            $response = ['success' => true, 'message' => 'تم تحديث عدد الجولات'];
        }

        // Edit Single Car Field (Inline Edit)
        elseif ($_POST['action'] === 'edit_car_field') {
            $mid = $_POST['member_id'];
            $field = $_POST['field'];
            $value = trim($_POST['value']);
            $regId = $_POST['registration_id'] ?? null;

            $allowedCarFields = ['car_type', 'car_year', 'car_color', 'engine_size', 
                                 'participation_type', 'plate_governorate', 'plate_letter', 'plate_number'];
            if (!in_array($field, $allowedCarFields)) {
                throw new Exception('حقل غير مسموح');
            }

            // Update registration if exists
            if ($regId && is_numeric($regId)) {
                $stmt = $pdo->prepare("UPDATE registrations SET $field = ? WHERE id = ?");
                $stmt->execute([$value, $regId]);
            }

            // Map registration fields to member last_ fields
            $memberField = 'last_' . $field;
            if ($field === 'participation_type') $memberField = 'last_participation_type';
            
            $stmt = $pdo->prepare("UPDATE members SET $memberField = ? WHERE id = ?");
            $stmt->execute([$value, $mid]);

            MemberService::syncToJson($mid);
            auditLog('edit_car_field', 'member', $mid, null, "$field => $value", $currentUser->id ?? null);
            $response = ['success' => true, 'message' => 'تم تحديث ' . $field];
        }

        // Generalized Upload Handler
        elseif ($_POST['action'] === 'upload_any_photo') {
            $mid = $_POST['member_id'];
            $target = $_POST['target_table'];
            $key = $_POST['image_key'];
            $code = $_POST['permanent_code'] ?? 'temp';

            $allowedKeys = [
                'personal_photo', 'national_id_front', 'national_id_back',
                'id_front', 'id_back',
                'front_image', 'side_image', 'back_image', 'edited_image', 'acceptance_image',
                'license_front', 'license_back'
            ];
            if (!in_array($key, $allowedKeys)) throw new Exception('حقل غير صالح');

            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('لم يتم رفع الملف بشكل صحيح');
            }

            $uploadSubDir = date('Y-m');
            $uploadDir = __DIR__ . '/../uploads/' . $uploadSubDir . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $dbKey = $key;
            if ($key === 'id_front') $dbKey = 'national_id_front';
            if ($key === 'id_back') $dbKey = 'national_id_back';
            $filename = $code . '_' . $key . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . $filename;

            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
                throw new Exception('فشل في حفظ الملف');
            }

            $relativePath = 'uploads/' . $uploadSubDir . '/' . $filename;

            // Update SQLite (best effort - column may not exist)
            try {
                if ($target === 'member') {
                    $pdo->prepare("UPDATE members SET $dbKey = ? WHERE id = ?")->execute([$relativePath, $mid]);
                } else {
                    $pdo->prepare("UPDATE registrations SET $dbKey = ? WHERE member_id = ? AND is_active = 1")->execute([$relativePath, $mid]);
                }
            } catch (Exception $e) {
                error_log("[UPLOAD_PHOTO] SQLite update failed (OK): " . $e->getMessage());
            }

            // CRITICAL: Also update members.json directly (this is what the page reads!)
            $permCode = $code;
            if ($permCode === 'temp' || is_numeric($permCode)) {
                $stmtC = $pdo->prepare("SELECT permanent_code FROM members WHERE id = ?");
                $stmtC->execute([$mid]);
                $permCode = $stmtC->fetchColumn() ?: $code;
            }
            $membersFile = __DIR__ . '/data/members.json';
            if (file_exists($membersFile)) {
                $mjData = json_decode(file_get_contents($membersFile), true) ?? [];
                if (isset($mjData[$permCode])) {
                    if (!isset($mjData[$permCode]['images'])) $mjData[$permCode]['images'] = [];
                    $mjData[$permCode]['images'][$key] = $relativePath;
                    file_put_contents($membersFile, json_encode($mjData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }

            // Also update data.json (source of truth for registrations)
            $dataFile = __DIR__ . '/data/data.json';
            if (file_exists($dataFile)) {
                $djData = json_decode(file_get_contents($dataFile), true) ?? [];
                foreach ($djData as &$entry) {
                    if (isset($entry['registration_code']) && $entry['registration_code'] === $permCode) {
                        if (!isset($entry['images'])) $entry['images'] = [];
                        $entry['images'][$key] = $relativePath;
                        break;
                    }
                }
                unset($entry);
                file_put_contents($dataFile, json_encode($djData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            // Sync
            MemberService::syncToJson($mid);
            auditLog('upload_image', $target, $mid, null, "$dbKey => $relativePath", $currentUser->id ?? null);
            $response = ['success' => true, 'message' => 'تم الرفع بنجاح', 'path' => $relativePath];
        }

        // Generalized Delete Handler
        elseif ($_POST['action'] === 'delete_any_photo') {
            $mid = $_POST['member_id'];
            $target = $_POST['target_table']; 
            $key = $_POST['image_key'];
            
            $allowedKeys = [
                'personal_photo', 'national_id_front', 'national_id_back',
                'id_front', 'id_back',
                'front_image', 'side_image', 'back_image', 'edited_image', 'acceptance_image',
                'license_front', 'license_back'
            ];
            if (!in_array($key, $allowedKeys)) throw new Exception('حقل غير صالح');

            $dbKey = $key;
            if ($key === 'id_front') $dbKey = 'national_id_front';
            if ($key === 'id_back') $dbKey = 'national_id_back';

            // Get permanent_code for JSON lookups
            $stmtCode = $pdo->prepare("SELECT permanent_code FROM members WHERE id = ?");
            $stmtCode->execute([$mid]);
            $permCode = $stmtCode->fetchColumn() ?: '';

            // Step 1: Find the image path from ALL sources to ensure we delete physical files
            $pathsToDelete = [];
            
            // Try SQLite first
            try {
                if ($target === 'member') {
                    $stmt = $pdo->prepare("SELECT $dbKey FROM members WHERE id = ?");
                    $stmt->execute([$mid]);
                    $pathsToDelete[] = $stmt->fetchColumn();
                } else {
                    $stmt = $pdo->prepare("SELECT $dbKey FROM registrations WHERE member_id = ? AND is_active = 1");
                    $stmt->execute([$mid]);
                    $pathsToDelete[] = $stmt->fetchColumn();
                }
            } catch (Exception $e) {
                error_log("[DELETE_PHOTO] SQLite SELECT failed (OK): " . $e->getMessage());
            }

            // Fallback: members.json
            if ($permCode) {
                $membersFile = __DIR__ . '/data/members.json';
                if (file_exists($membersFile)) {
                    $mjData = json_decode(file_get_contents($membersFile), true) ?? [];
                    $memberRec = $mjData[$permCode] ?? [];
                    $images = $memberRec['images'] ?? [];
                    $pathsToDelete[] = $images[$key] ?? '';
                    $pathsToDelete[] = $images[$dbKey] ?? '';
                }
            }

            // Fallback: data.json
            if ($permCode) {
                $dataFile = __DIR__ . '/data/data.json';
                if (file_exists($dataFile)) {
                    $djData = json_decode(file_get_contents($dataFile), true) ?? [];
                    foreach ($djData as $entry) {
                        if (isset($entry['registration_code']) && $entry['registration_code'] === $permCode) {
                            $pathsToDelete[] = $entry['images'][$key] ?? '';
                            $pathsToDelete[] = $entry['images'][$dbKey] ?? '';
                            $pathsToDelete[] = $entry[$key] ?? '';
                            break;
                        }
                    }
                }
            }

            // Step 2: Unlink physically ALL found paths
            $pathsToDelete = array_filter(array_unique($pathsToDelete)); // Remove empty & duplicates
            error_log("[DELETE_PHOTO] Paths to delete: " . print_r($pathsToDelete, true));
            
            foreach ($pathsToDelete as $currentPath) {
                $relPath = ltrim(str_replace('../', '', $currentPath), '/');
                $fullPath = __DIR__ . '/../' . $relPath;
                error_log("[DELETE_PHOTO] Checking physical path: " . $fullPath);
                if (file_exists($fullPath) && is_file($fullPath)) {
                    @unlink($fullPath);
                    error_log("[DELETE_PHOTO] Physical file deleted: " . $fullPath);
                }
            }

            // Step 3: Clear from SQLite (best effort)
            try {
                if ($target === 'member') {
                    $pdo->prepare("UPDATE members SET $dbKey = NULL WHERE id = ?")->execute([$mid]);
                } else {
                    $pdo->prepare("UPDATE registrations SET $dbKey = NULL WHERE member_id = ? AND is_active = 1")->execute([$mid]);
                }
                error_log("[DELETE_PHOTO] SQLite cleared properly");
            } catch (Exception $e) {
                error_log("[DELETE_PHOTO] SQLite UPDATE failed (OK): " . $e->getMessage());
            }

            // Step 4: CRITICAL - Clear from members.json directly
            error_log("[DELETE_PHOTO] JSON Clear -> target Code: $permCode | key: $key | dbKey: $dbKey");
            if ($permCode) {
                $membersFile = __DIR__ . '/data/members.json';
                if (file_exists($membersFile)) {
                    $mjData = json_decode(file_get_contents($membersFile), true) ?? [];
                    if (isset($mjData[$permCode])) {
                        if (!isset($mjData[$permCode]['images'])) {
                            $mjData[$permCode]['images'] = [];
                        }
                        
                        // Log before clear
                        $beforeValMj1 = $mjData[$permCode]['images'][$key] ?? 'MISSING';
                        $beforeValMj2 = $mjData[$permCode]['images'][$dbKey] ?? 'MISSING';
                        error_log("[DELETE_PHOTO] MJ Before -> [$key]: $beforeValMj1 | [$dbKey]: $beforeValMj2");

                        $mjData[$permCode]['images'][$key] = '';
                        if ($key !== $dbKey) {
                            $mjData[$permCode]['images'][$dbKey] = '';
                        }
                        file_put_contents($membersFile, json_encode($mjData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        error_log("[DELETE_PHOTO] members.json saved.");
                    } else {
                        error_log("[DELETE_PHOTO] Code $permCode NOT FOUND in members.json");
                    }
                }
            }

            // Step 5: Clear from data.json
            if ($permCode) {
                $dataFile = __DIR__ . '/data/data.json';
                if (file_exists($dataFile)) {
                    $djData = json_decode(file_get_contents($dataFile), true) ?? [];
                    foreach ($djData as &$entry) {
                        if (isset($entry['registration_code']) && $entry['registration_code'] === $permCode) {
                            if (!isset($entry['images'])) {
                                $entry['images'] = [];
                            }
                            
                            $beforeValDj1 = $entry['images'][$key] ?? 'MISSING';
                            $beforeValDj2 = $entry['images'][$dbKey] ?? 'MISSING';
                            $beforeValDj3 = $entry[$key] ?? 'MISSING';
                            error_log("[DELETE_PHOTO] DJ Before -> [$key]: $beforeValDj1 | [$dbKey]: $beforeValDj2 | ROOT: $beforeValDj3");

                            $entry['images'][$key] = '';
                            if ($key !== $dbKey) {
                                $entry['images'][$dbKey] = '';
                            }
                            $entry[$key] = ''; // Also clear root key if it exists
                            break;
                        }
                    }
                    unset($entry);
                    file_put_contents($dataFile, json_encode($djData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    error_log("[DELETE_PHOTO] data.json saved.");
                }
            }

            // Step 6: Sync
            MemberService::syncToJson($mid);
            auditLog('delete_image', $target, $mid, null, $key, $currentUser->id ?? null);
            error_log("[DELETE_PHOTO] Success. Returning response.");
            $response = ['success' => true, 'message' => 'تم الحذف بنجاح'];
        }

        // Add Note
        elseif ($_POST['action'] === 'add_note') {
            $mid = $_POST['member_id'];
            $noteText = trim($_POST['note_text']);
            $noteType = $_POST['note_type'] ?? 'info';
            $priority = $_POST['priority'] ?? 'low';

            $stmt = $pdo->prepare("INSERT INTO notes (member_id, note_text, note_type, priority, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$mid, $noteText, $noteType, $priority, $currentUser->id ?? null]);

            auditLog('add_note', 'member', $mid, null, $noteText, $currentUser->id ?? null);
            $response = ['success' => true, 'message' => 'تمت إضافة الملاحظة'];
        }

        // Delete Warning
        elseif ($_POST['action'] === 'delete_warning') {
            $warnId = intval($_POST['warning_id'] ?? 0);
            if ($warnId > 0) {
                $stmt = $pdo->prepare("DELETE FROM warnings WHERE id = ?");
                $stmt->execute([$warnId]);
                auditLog('delete_warning', 'member', $_POST['member_id'] ?? 0, null, "Warning #$warnId", $currentUser->id ?? null);
                $response = ['success' => true, 'message' => 'تم حذف التحذير'];
            }
        }

        // Delete Note
        elseif ($_POST['action'] === 'delete_note') {
            $noteId = intval($_POST['note_id'] ?? 0);
            if ($noteId > 0) {
                $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ?");
                $stmt->execute([$noteId]);
                auditLog('delete_note', 'member', $_POST['member_id'] ?? 0, null, "Note #$noteId", $currentUser->id ?? null);
                $response = ['success' => true, 'message' => 'تم حذف الملاحظة'];
            }
        }

        // Edit Warning Text
        elseif ($_POST['action'] === 'edit_warning') {
            $warnId = intval($_POST['warning_id'] ?? 0);
            $newText = trim($_POST['text'] ?? '');
            if ($warnId > 0 && !empty($newText)) {
                $stmt = $pdo->prepare("UPDATE warnings SET warning_text = ? WHERE id = ?");
                $stmt->execute([$newText, $warnId]);
                auditLog('edit_warning', 'member', $_POST['member_id'] ?? 0, null, "Warning #$warnId => $newText", $currentUser->id ?? null);
                $response = ['success' => true, 'message' => 'تم تعديل المخالفة'];
            }
        }

        // Edit Note Text
        elseif ($_POST['action'] === 'edit_note') {
            $noteId = intval($_POST['note_id'] ?? 0);
            $newText = trim($_POST['text'] ?? '');
            if ($noteId > 0 && !empty($newText)) {
                $stmt = $pdo->prepare("UPDATE notes SET note_text = ? WHERE id = ?");
                $stmt->execute([$newText, $noteId]);
                auditLog('edit_note', 'member', $_POST['member_id'] ?? 0, null, "Note #$noteId => $newText", $currentUser->id ?? null);
                $response = ['success' => true, 'message' => 'تم تعديل الملاحظة'];
            }
        }

    } catch (Exception $e) {
        $response = ['success' => false, 'error' => $e->getMessage()];
    }

    echo json_encode($response);
    exit;
}

// -- Load Profile Data --
$profile = MemberService::getProfile($memberId);

if (!$profile) {
    echo '<div style="text-align:center;padding:50px;direction:rtl;font-family:Cairo,sans-serif;">';
    echo '<h2>❌ العضو غير موجود</h2>';
    echo '<p>الكود: <code>' . htmlspecialchars($memberId) . '</code></p>';
    echo '<a href="members.php" class="btn btn-primary">العودة لقائمة الأعضاء</a>';
    echo '</div>';
    exit;
}

$member = $profile['member'];
$championshipsCount = $profile['championships_count'];
$roundsEntered = $profile['rounds_entered'];
$warnings = $profile['warnings'];
$warningsCount = $profile['warnings_count'];
$notes = $profile['notes'];
$registrations = $profile['registrations'];
$currentReg = $profile['current_registration'];
$hasBlockers = $profile['has_blockers'];

// Determine display registration for car info
$displayReg = $currentReg;
$regId = $displayReg['id'] ?? '';

// ============ ENRICHMENT: Merge images from members.json ============
// This ensures images appear even when SQLite registrations table is empty
$membersJsonPath = __DIR__ . '/data/members.json';
if (file_exists($membersJsonPath)) {
    $mjData = json_decode(file_get_contents($membersJsonPath), true) ?? [];
    $pCode = $member['permanent_code'] ?? '';
    if (isset($mjData[$pCode]) && !empty($mjData[$pCode]['images'])) {
        $mjImages = $mjData[$pCode]['images'];
        // Merge images: keep existing non-empty, fill gaps from members.json
        if (!isset($displayReg['images']) || !is_array($displayReg['images'])) {
            $displayReg['images'] = [];
        }
        foreach ($mjImages as $imgKey => $imgPath) {
            if (!empty($imgPath) && empty($displayReg['images'][$imgKey])) {
                $displayReg['images'][$imgKey] = $imgPath;
            }
        }
        // Also fill car data gaps
        if (empty($displayReg['car_type']) && !empty($mjData[$pCode]['car_type'])) {
            $displayReg['car_type'] = $mjData[$pCode]['car_type'];
        }
        if (empty($displayReg['car_year']) && !empty($mjData[$pCode]['car_year'])) {
            $displayReg['car_year'] = $mjData[$pCode]['car_year'];
        }
        if (empty($displayReg['car_color']) && !empty($mjData[$pCode]['car_color'])) {
            $displayReg['car_color'] = $mjData[$pCode]['car_color'];
        }
        if (empty($displayReg['engine_size']) && !empty($mjData[$pCode]['engine_size'])) {
            $displayReg['engine_size'] = $mjData[$pCode]['engine_size'];
        }
        if (empty($displayReg['participation_type']) && !empty($mjData[$pCode]['participation_type'])) {
            $displayReg['participation_type'] = $mjData[$pCode]['participation_type'];
        }
        if (empty($displayReg['plate_number']) && !empty($mjData[$pCode]['plate_number'])) {
            $displayReg['plate_number'] = $mjData[$pCode]['plate_number'];
        }
        if (empty($displayReg['plate_letter']) && !empty($mjData[$pCode]['plate_letter'])) {
            $displayReg['plate_letter'] = $mjData[$pCode]['plate_letter'];
        }
        if (empty($displayReg['plate_governorate']) && !empty($mjData[$pCode]['plate_governorate'])) {
            $displayReg['plate_governorate'] = $mjData[$pCode]['plate_governorate'];
        }
    }
}
// =====================================================================

// Photo path
$photoPath = $displayReg['images']['personal_photo'] ?? $member['personal_photo'] ?? $displayReg['personal_photo'] ?? '';
$photoUrl = !empty($photoPath) ? '../' . ltrim($photoPath, '/') : '';

// Load Championship Display Name from Frame Settings
$championshipDisplayName = 'بطولة';
$frameSettingsFile = __DIR__ . '/data/frame_settings.json';
if (file_exists($frameSettingsFile)) {
    $frameSettings = json_decode(file_get_contents($frameSettingsFile), true);
    if (!empty($frameSettings['form_titles']['sub_title'])) {
        $championshipDisplayName = $frameSettings['form_titles']['sub_title'];
    }
}
$currentChampId = getCurrentChampionshipId();

// Label Maps for Display
$engineLabels = [
    '8_cylinder_natural' => '8 سلندر تنفس طبيعي',
    '8_cylinder_boost' => '8 سلندر بوست',
    '6_cylinder_natural' => '6 سلندر تنفس طبيعي',
    '6_cylinder_boost' => '6 سلندر بوست',
    '4_cylinder' => '4 سلندر',
    '4_cylinder_boost' => '4 سلندر بوست',
];
$participationLabels = [
    'free_show' => 'المشاركة بالاستعراض الحر',
    'special_car' => 'المشاركة كسيارة مميزة فقط بدون استعراض',
    'burnout' => 'المشاركة بفعالية Burnout',
];
// Also load dynamic labels from registration_settings.json
$regSettingsFile = __DIR__ . '/data/registration_settings.json';
if (file_exists($regSettingsFile)) {
    $regSettings = json_decode(file_get_contents($regSettingsFile), true);
    if (!empty($regSettings['participation_types'])) {
        foreach ($regSettings['participation_types'] as $pt) {
            if (!empty($pt['id']) && !empty($pt['label'])) {
                $participationLabels[$pt['id']] = $pt['label'];
            }
        }
    }
}

$currentPage = 'member_details';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تفاصيل العضو - <?= htmlspecialchars($member['name'] ?? '') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f8f9fa; padding: 20px; color: #333; }
        .profile-header {
            background: #fff;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 30px;
            position: relative;
            border: 1px solid #edf2f7;
        }
        /* Framed Personal Photo */
        .framed-photo-container {
            position: relative;
            width: 140px;
            height: 140px;
            padding: 8px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border: 2px solid #337ab7;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .profile-photo {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #f1f1f1;
        }
        .profile-photo-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: #ccc;
            background: #fdfdfd;
        }
        .photo-badge {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: #5cb85c;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid #fff;
            font-size: 14px;
        }
        .profile-info h2 { margin: 0 0 10px; font-weight: 700; font-size: 26px; }
        .stat-box {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-bottom: 4px solid #337ab7;
            transition: transform 0.2s;
        }
        .stat-box:hover { transform: translateY(-3px); }
        .gallery-section { margin-bottom: 20px; }
        .gallery-title { 
            font-size: 15px; 
            font-weight: bold; 
            margin-bottom: 12px; 
            padding-bottom: 5px; 
            border-bottom: 2px solid #eee;
            color: #555;
        }
        .img-container {
            position: relative;
            height: 120px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #ddd;
            margin-bottom: 10px;
            background: #fdfdfd;
            transition: 0.2s;
        }
        .img-container:hover { border-color: #337ab7; box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
        .img-container img { width: 100%; height: 100%; object-fit: cover; cursor: pointer; }
        .img-overlay-actions {
            position: absolute;
            top: 5px;
            right: 5px;
            display: flex;
            gap: 4px;
        }
        .img-placeholder {
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #aaa;
        }
        .img-placeholder i { font-size: 24px; margin-bottom: 5px; }
        .btn-img-action {
            width: 28px;
            height: 28px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 12px;
            line-height: 1;
        }
        .photo-upload-label { cursor: pointer; margin: 0; }
        .photo-upload-label input { display: none; }
    </style>
</head>
<body>

<!-- Navbar -->
<?php include '../include/navbar.php'; ?>
<div style="height: 60px;"></div>

<div class="container-fluid">
    <a href="members.php" class="back-btn"><i class="fa-solid fa-arrow-right"></i> العودة لقائمة الأعضاء</a>

    <!-- Profile Header -->
    <div class="profile-header">
        <div>
            <div class="framed-photo-container">
                <?php if (!empty($photoUrl)): ?>
                    <img src="../thumb.php?src=<?= urlencode($photoUrl) ?>&w=300&h=300" class="profile-photo" id="profilePhoto" alt="صورة العضو" onclick="viewImage('<?= htmlspecialchars($photoUrl) ?>')">
                    <div class="photo-badge" title="صورة مؤكدة"><i class="fa-solid fa-check"></i></div>
                <?php else: ?>
                    <div class="profile-photo-placeholder" id="profilePhoto"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
            </div>

            <?php if ($canEdit): ?>
            <div class="photo-actions" style="justify-content: center;">
                <label class="btn btn-xs btn-primary photo-upload-label">
                    <i class="fa-solid fa-camera"></i> تغيير
                    <input type="file" accept="image/*" onchange="uploadPhoto(this)">
                </label>
                <?php if (!empty($photoUrl)): ?>
                <button class="btn btn-xs btn-danger" onclick="deletePhoto()"><i class="fa-solid fa-trash"></i></button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="profile-info" style="flex: 1;">
            <h2>
                <span class="editable" onclick="editField('name', this)" title="اضغط للتعديل"><?= htmlspecialchars($member['name'] ?? 'بدون اسم') ?></span>
                <?php if ($hasBlockers): ?>
                    <span class="blocker-badge"><i class="fa-solid fa-ban"></i> محظور</span>
                <?php endif; ?>
            </h2>
            <div style="margin-bottom: 8px;">
                <span class="code"><?= htmlspecialchars($member['permanent_code'] ?? '') ?></span>
                <?php if (!empty($member['account_activated'])): ?>
                    <span class="label label-success" style="margin-right: 8px;"><i class="fa-solid fa-check"></i> مفعل</span>
                <?php else: ?>
                    <span class="label label-warning" style="margin-right: 8px;"><i class="fa-solid fa-clock"></i> غير مفعل</span>
                <?php endif; ?>
            </div>
            <div style="color: #777; font-size: 14px;">
                <i class="fa-solid fa-phone"></i>
                <span class="editable" onclick="editField('phone', this)" title="اضغط للتعديل" dir="ltr"><?= htmlspecialchars($member['phone'] ?? '-') ?></span>
                &nbsp;&nbsp;
                <i class="fa-solid fa-map-marker-alt"></i>
                <span class="editable" onclick="editField('governorate', this)" title="اضغط للتعديل"><?= htmlspecialchars($member['governorate'] ?? '-') ?></span>
                &nbsp;&nbsp;
                <i class="fa-brands fa-instagram"></i>
                <span class="editable" onclick="editField('instagram', this)" title="اضغط للتعديل"><?= htmlspecialchars($member['instagram'] ?? '') ?: '<span style="color:#ccc;font-size:12px;">أضف انستقرام</span>' ?></span>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row" style="margin-bottom: 20px;">
        <div class="col-md-3 col-xs-6" style="margin-bottom:10px;">
            <div class="stat-box">
                <div class="number editable" onclick="editStat('championships', this)" title="اضغط للتعديل" style="cursor:pointer;"><?= $championshipsCount ?></div>
                <span class="label-text"><i class="fa-solid fa-trophy text-warning"></i> البطولات</span>
            </div>
        </div>
        <div class="col-md-3 col-xs-6" style="margin-bottom:10px;">
            <div class="stat-box">
                <div class="number editable" onclick="editStat('rounds', this)" title="اضغط للتعديل" style="cursor:pointer;"><?= $roundsEntered ?></div>
                <span class="label-text"><i class="fa-solid fa-flag-checkered"></i> الجولات</span>
            </div>
        </div>
        <div class="col-md-3 col-xs-6" style="margin-bottom:10px;">
            <div class="stat-box">
                <div class="number" style="color: <?= $warningsCount > 0 ? '#d9534f' : '#5cb85c' ?>"><?= $warningsCount ?></div>
                <span class="label-text"><i class="fa-solid fa-triangle-exclamation"></i> المخالفات النشطة</span>
            </div>
        </div>
        <div class="col-md-3 col-xs-6" style="margin-bottom:10px;">
            <div class="stat-box">
                <div class="number"><?= count($registrations) ?></div>
                <span class="label-text"><i class="fa-solid fa-list"></i> سجل المشاركات</span>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Right Column: Personal Info + Car -->
        <div class="col-md-6">
            <!-- Car Info Panel -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa-solid fa-car"></i> بيانات السيارة
                        <?php if ($canEdit): ?>
                        <button class="btn btn-xs btn-warning pull-left" onclick="$('#carModal').modal('show')"><i class="fa-solid fa-pen"></i> تعديل</button>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="panel-body">
                    <table class="table table-bordered">
                        <tr><th style="width:35%; background:#f9f9f9;">نوع السيارة</th><td><span class="editable" onclick="editCarField('car_type', this)"><?= htmlspecialchars($displayReg['car_type'] ?? '-') ?></span></td></tr>
                        <tr><th style="background:#f9f9f9;">موديل</th><td><span class="editable" onclick="editCarField('car_year', this)"><?= htmlspecialchars($displayReg['car_year'] ?? '-') ?></span></td></tr>
                        <tr><th style="background:#f9f9f9;">اللون</th><td><span class="editable" onclick="editCarField('car_color', this)"><?= htmlspecialchars($displayReg['car_color'] ?? '-') ?></span></td></tr>
                        <tr><th style="background:#f9f9f9;">المحرك</th><td><span class="editable" onclick="editCarField('engine_size', this)"><?= htmlspecialchars($engineLabels[$displayReg['engine_size'] ?? ''] ?? $displayReg['engine_size_label'] ?? $displayReg['engine_size'] ?? '-') ?></span></td></tr>
                        <tr><th style="background:#f9f9f9;">نوع المشاركة</th><td><span class="editable" onclick="editCarField('participation_type', this)"><?= htmlspecialchars($participationLabels[$displayReg['participation_type'] ?? ''] ?? $displayReg['participation_type_label'] ?? $displayReg['participation_type'] ?? '-') ?></span></td></tr>
                        <tr><th style="background:#f9f9f9;">اللوحة</th><td><?= htmlspecialchars($displayReg['plate_full'] ?? (($displayReg['plate_governorate'] ?? '') . ' ' . ($displayReg['plate_letter'] ?? '') . ' ' . ($displayReg['plate_number'] ?? ''))) ?> <button class="btn btn-xs btn-warning" onclick="$('#carModal').modal('show')"><i class="fa-solid fa-pen"></i></button></td></tr>
                    </table>
                    <div style="text-align: center; margin-top: 10px;">
                        <a href="../badge.php?token=<?= urlencode($member['permanent_code'] ?? '') ?>" target="_blank" class="btn btn-info btn-sm"><i class="fa-solid fa-id-card"></i> عرض البادج</a>
                    </div>
                </div>
            </div>

            <!-- Comprehensive Media Gallery -->
            <div class="panel panel-success">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="fa-solid fa-images"></i> معرض الصور والمستندات</h3>
                </div>
                <div class="panel-body">
                    
                    <!-- Section A: Identity -->
                    <div class="gallery-section">
                        <div class="gallery-title">الهوية الشخصية</div>
                        <div class="row">
                            <?php 
                            $idImages = [
                                'id_front' => ['label' => 'الهوية (وجه)', 'source' => 'member', 'alt_key' => 'national_id_front'],
                                'id_back'  => ['label' => 'الهوية (ظهر)', 'source' => 'member', 'alt_key' => 'national_id_back'],
                                'license_front'     => ['label' => 'إجازة السوق (وجه)', 'source' => 'registration', 'alt_key' => 'license_front'],
                                'license_back'      => ['label' => 'إجازة السوق (ظهر)', 'source' => 'registration', 'alt_key' => 'license_back']
                            ];
                            foreach($idImages as $key => $cfg): 
                                $altKey = $cfg['alt_key'] ?? '';
                                $path = $displayReg['images'][$key] 
                                     ?? $displayReg['images'][$altKey] 
                                     ?? $member[$key] 
                                     ?? $displayReg[$key] 
                                     ?? $displayReg[$altKey] 
                                     ?? '';
                                $url = !empty($path) ? '../' . ltrim($path, '/') : '';
                            ?>
                            <div class="col-xs-6">
                                <div class="img-container">
                                    <?php if ($url): ?>
                                        <img src="../thumb.php?src=<?= urlencode($url) ?>&w=300&h=300" onclick="viewImage('<?= htmlspecialchars($url) ?>')">
                                        <?php if ($canEdit): ?>
                                        <div class="img-overlay-actions">
                                            <button class="btn-img-action btn-danger" onclick="deleteAnyPhoto('<?= $key ?>', '<?= $cfg['source'] ?>')"><i class="fa-solid fa-times"></i></button>
                                        </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="img-placeholder">
                                            <i class="fa-solid fa-id-card"></i>
                                            <span><?= $cfg['label'] ?></span>
                                            <?php if ($canEdit): ?>
                                            <label class="photo-upload-label text-primary" style="margin-top:5px;">
                                                <i class="fa-solid fa-plus-circle"></i> إرفاق
                                                <input type="file" onchange="uploadAnyPhoto(this, '<?= $key ?>', '<?= $cfg['source'] ?>')">
                                            </label>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Section B: Vehicle Images -->
                    <div class="gallery-section">
                        <div class="gallery-title">صور المركبة</div>
                        <div class="row">
                            <?php 
                            $carImages = [
                                'front_image' => 'المقدمة',
                                'side_image'  => 'الجانب',
                                'back_image'  => 'الخلفية',
                                'edited_image' => 'التعديلات'
                            ];
                            foreach($carImages as $key => $label): 
                                $path = $displayReg['images'][$key] ?? $displayReg[$key] ?? '';
                                $url = !empty($path) ? '../' . ltrim($path, '/') : '';
                            ?>
                            <div class="col-xs-6">
                                <div class="img-container">
                                    <?php if ($url): ?>
                                        <img src="../thumb.php?src=<?= urlencode($url) ?>&w=400&h=300" onclick="viewImage('<?= htmlspecialchars($url) ?>')">
                                        <?php if ($canEdit): ?>
                                        <div class="img-overlay-actions">
                                            <button class="btn-img-action btn-danger" onclick="deleteAnyPhoto('<?= $key ?>', 'registration')"><i class="fa-solid fa-times"></i></button>
                                        </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="img-placeholder">
                                            <i class="fa-solid fa-car"></i>
                                            <span><?= $label ?></span>
                                            <?php if ($canEdit): ?>
                                            <label class="photo-upload-label text-primary" style="margin-top:5px;">
                                                <i class="fa-solid fa-plus-circle"></i> رفع
                                                <input type="file" onchange="uploadAnyPhoto(this, '<?= $key ?>', 'registration')">
                                            </label>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Section C: Official Assets -->
                    <div class="gallery-section" style="margin-bottom: 0;">
                        <div class="gallery-title">المرفقات الرسمية</div>
                        <div class="row">
                            <?php 
                            $assetKey = 'acceptance_image';
                            $path = $displayReg['images'][$assetKey] ?? $displayReg[$assetKey] ?? '';
                            $url = !empty($path) ? '../' . ltrim($path, '/') : '';
                            ?>
                            <div class="col-xs-12">
                                <div class="img-container" style="height: 150px;">
                                    <?php if ($url): ?>
                                        <img src="../thumb.php?src=<?= urlencode($url) ?>&w=400&h=300" onclick="viewImage('<?= htmlspecialchars($url) ?>')">
                                        <?php if ($canEdit): ?>
                                        <div class="img-overlay-actions">
                                            <button class="btn-img-action btn-danger" onclick="deleteAnyPhoto('<?= $assetKey ?>', 'registration')"><i class="fa-solid fa-times"></i></button>
                                        </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="img-placeholder">
                                            <i class="fa-solid fa-file-contract"></i>
                                            <span>إطار القبول المعدل</span>
                                            <?php if ($canEdit): ?>
                                            <label class="photo-upload-label text-primary" style="margin-top:5px;">
                                                <i class="fa-solid fa-plus-circle"></i> رفع الملف
                                                <input type="file" onchange="uploadAnyPhoto(this, '<?= $assetKey ?>', 'registration')">
                                            </label>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Warnings Panel -->
            <div class="panel panel-danger">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa-solid fa-triangle-exclamation"></i> المخالفات والتنبيهات (<?= $warningsCount ?>)
                        <?php if ($canEdit): ?>
                        <button class="btn btn-xs btn-danger pull-left" style="border-color:#fff;" onclick="$('#warningModal').modal('show')"><i class="fa-solid fa-plus"></i> إضافة</button>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="panel-body">
                    <?php if (empty($warnings)): ?>
                        <p style="text-align: center; color: #999; padding: 20px;">
                            <i class="fa-solid fa-check-circle" style="font-size: 30px; color: #5cb85c;"></i><br>
                            لا توجد مخالفات نشطة
                        </p>
                    <?php else: ?>
                        <?php foreach ($warnings as $w): ?>
                        <div class="warning-card <?= $w['severity'] ?? 'medium' ?>">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <div>
                                    <span class="severity-<?= $w['severity'] ?? 'medium' ?>">
                                        <?php
                                        $sIcon = ['high' => '🔴', 'medium' => '🟡', 'low' => '🟢'];
                                        echo $sIcon[$w['severity'] ?? 'medium'] ?? '🟡';
                                        ?>
                                    </span>
                                    <?= htmlspecialchars($w['warning_text'] ?? '') ?>
                                </div>
                                <?php if ($canEdit && empty($w['source'])): ?>
                                <div class="btn-group">
                                <button class="btn btn-xs btn-warning" onclick="editWarning('<?= $w['id'] ?>', '<?= $memberId ?>')" title="تعديل"><i class="fa-solid fa-pen"></i></button>
                                <button class="btn btn-xs btn-success" onclick="resolveWarning('<?= $w['id'] ?>')" title="حل"><i class="fa-solid fa-check"></i></button>
                                <button class="btn btn-xs btn-danger" onclick="deleteWarning('<?= $w['id'] ?>', '<?= $memberId ?>')" title="حذف"><i class="fa-solid fa-trash"></i></button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 12px; color: #999; margin-top: 5px;">
                                <?= htmlspecialchars($w['championship_name'] ?? '') ?> |
                                <?= htmlspecialchars($w['created_by_name'] ?? '') ?> |
                                <?= htmlspecialchars($w['created_at'] ?? '') ?>
                                <?php if (!empty($w['expires_at'])): ?>
                                | ينتهي: <?= htmlspecialchars($w['expires_at']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Left Column: Notes + History -->
        <div class="col-md-6">
            <!-- Notes Panel -->
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa-solid fa-clipboard"></i> الملاحظات (<?= count($notes) ?>)
                        <?php if ($canEdit): ?>
                        <button class="btn btn-xs btn-info pull-left" style="border-color:#fff;" onclick="$('#noteModal').modal('show')"><i class="fa-solid fa-plus"></i> إضافة</button>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="panel-body" style="max-height: 300px; overflow-y: auto;">
                    <?php if (empty($notes)): ?>
                        <p style="text-align: center; color: #999; padding: 20px;">لا توجد ملاحظات</p>
                    <?php else: ?>
                        <?php foreach ($notes as $n): ?>
                        <div class="note-card <?= $n['note_type'] ?? 'info' ?>">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <div>
                                    <?php
                                    $typeIcons = ['info' => 'ℹ️', 'warning' => '⚠️', 'blocker' => '🚫', 'positive' => '✅'];
                                    echo $typeIcons[$n['note_type'] ?? 'info'] ?? 'ℹ️';
                                    ?>
                                    <span id="note-text-<?= $n['id'] ?>"><?= htmlspecialchars($n['note_text'] ?? '') ?></span>
                                </div>
                                <?php if ($canEdit): ?>
                                <div class="btn-group" style="flex-shrink:0;">
                                    <button class="btn btn-xs btn-warning" onclick="editNote('<?= $n['id'] ?>', '<?= $memberId ?>')" title="تعديل"><i class="fa-solid fa-pen"></i></button>
                                    <button class="btn btn-xs btn-danger" onclick="deleteNote('<?= $n['id'] ?>', '<?= $memberId ?>')" title="حذف"><i class="fa-solid fa-trash"></i></button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 11px; color: #999; margin-top: 4px;">
                                <?= htmlspecialchars($n['created_by_name'] ?? $n['created_by_username'] ?? 'نظام') ?> |
                                <?= htmlspecialchars($n['created_at'] ?? '') ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Registration History Panel -->
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa-solid fa-history"></i> سجل المشاركات (<?= count($registrations) ?>)
                    </h3>
                </div>
                <div class="panel-body" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($registrations)): ?>
                        <p style="text-align: center; color: #999; padding: 20px;">لا توجد مشاركات مسجلة</p>
                    <?php else: ?>
                        <?php foreach ($registrations as $reg): ?>
                        <div class="reg-history-item <?= ($reg['source'] ?? '') === 'archive' ? 'archived' : '' ?>">
                            <div style="display: flex; justify-content: space-between;">
                                <strong>
                                    <?php 
                                    if (($reg['championship_id'] ?? 0) == $currentChampId) {
                                        echo htmlspecialchars($championshipDisplayName);
                                    } else {
                                        echo htmlspecialchars($reg['championship_name'] ?? 'بطولة');
                                    }
                                    ?>
                                </strong>
                                <span class="label label-<?= ($reg['status'] ?? '') === 'approved' ? 'success' : 'default' ?>">
                                    <?= ($reg['status'] ?? '') === 'approved' ? 'مقبول' : htmlspecialchars($reg['status'] ?? '') ?>
                                </span>
                            </div>
                            <div style="font-size: 13px; color: #777; margin-top: 5px;">
                                <?php if (!empty($reg['car_type'])): ?>
                                🚗 <?= htmlspecialchars($reg['car_type']) ?>
                                <?php endif; ?>
                                <?php if (!empty($reg['car_year'])): ?>
                                 | <?= htmlspecialchars($reg['car_year']) ?>
                                <?php endif; ?>
                                <?php if (!empty($reg['car_color'])): ?>
                                 | <?= htmlspecialchars($reg['car_color']) ?>
                                <?php endif; ?>
                                <?php if (!empty($reg['participation_type'])): ?>
                                <br>📋 <?= htmlspecialchars($participationLabels[$reg['participation_type']] ?? $reg['participation_type_label'] ?? $reg['participation_type']) ?>
                                <?php endif; ?>
                                <?php if (!empty($reg['wasel'])): ?>
                                <br>🔢 وصل: <?= htmlspecialchars($reg['wasel']) ?>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 11px; color: #aaa; margin-top: 3px;">
                                <?= htmlspecialchars($reg['created_at'] ?? '') ?>
                                <?php if (($reg['source'] ?? '') === 'archive'): ?>
                                <span class="label label-default" style="font-size: 10px;">أرشيف</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Registration Action Archive Panel -->
            <?php
            // Load action history for this member
            $memberCode = $member['permanent_code'] ?? '';
            $memberPhone = $member['phone'] ?? '';
            $actionHistory = RegistrationActionLogger::getByCode($memberCode, $memberPhone);
            $actionSummary = RegistrationActionLogger::getSummary($memberCode, $memberPhone);
            ?>
            <div class="panel panel-warning">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa-solid fa-clock-rotate-left"></i> أرشيف الإجراءات (<?= $actionSummary['total'] ?>)
                    </h3>
                </div>
                <div class="panel-body">
                    <?php if ($actionSummary['total'] > 0): ?>
                    <!-- Summary Stats -->
                    <div class="row" style="margin-bottom: 15px;">
                        <div class="col-xs-3 text-center">
                            <div style="background:#f0f9ff;border-radius:8px;padding:10px;">
                                <div style="font-size:22px;font-weight:bold;color:#007bff;"><?= $actionSummary['registered'] ?></div>
                                <div style="font-size:11px;color:#666;">📝 تسجيل</div>
                            </div>
                        </div>
                        <div class="col-xs-3 text-center">
                            <div style="background:#fff8e1;border-radius:8px;padding:10px;">
                                <div style="font-size:22px;font-weight:bold;color:#f57c00;"><?= $actionSummary['re_registered'] ?></div>
                                <div style="font-size:11px;color:#666;">✏️ تعديل</div>
                            </div>
                        </div>
                        <div class="col-xs-3 text-center">
                            <div style="background:#fce4ec;border-radius:8px;padding:10px;">
                                <div style="font-size:22px;font-weight:bold;color:#dc3545;"><?= $actionSummary['rejected'] ?></div>
                                <div style="font-size:11px;color:#666;">❌ رفض</div>
                            </div>
                        </div>
                        <div class="col-xs-3 text-center">
                            <div style="background:#e8f5e9;border-radius:8px;padding:10px;">
                                <div style="font-size:22px;font-weight:bold;color:#28a745;"><?= $actionSummary['approved'] ?></div>
                                <div style="font-size:11px;color:#666;">✅ قبول</div>
                            </div>
                        </div>
                    </div>

                    <!-- Timeline -->
                    <div style="max-height: 300px; overflow-y: auto;">
                    <?php 
                    $actionIcons = [
                        'registered' => ['icon' => '📝', 'color' => '#007bff', 'label' => 'تسجيل جديد'],
                        're_registered' => ['icon' => '✏️', 'color' => '#f57c00', 'label' => 'إعادة تسجيل / تعديل'],
                        'approved' => ['icon' => '✅', 'color' => '#28a745', 'label' => 'تم القبول'],
                        'rejected' => ['icon' => '❌', 'color' => '#dc3545', 'label' => 'تم الرفض']
                    ];
                    // Show newest first
                    $reversedHistory = array_reverse($actionHistory);
                    foreach ($reversedHistory as $act):
                        $aType = $act['action'] ?? 'registered';
                        $aInfo = $actionIcons[$aType] ?? $actionIcons['registered'];
                    ?>
                    <div style="display:flex;gap:12px;align-items:flex-start;padding:10px;background:#f8f9fa;border-radius:8px;margin-bottom:8px;border-right:4px solid <?= $aInfo['color'] ?>;">
                        <div style="font-size:22px;min-width:30px;text-align:center;"><?= $aInfo['icon'] ?></div>
                        <div style="flex:1;">
                            <div style="font-weight:bold;font-size:13px;color:<?= $aInfo['color'] ?>;">
                                <?= htmlspecialchars($aInfo['label']) ?>
                            </div>
                            <div style="font-size:12px;color:#337ab7;margin-top:2px;">
                                <i class="fa-solid fa-trophy" style="margin-left:3px"></i> <?= htmlspecialchars($act['championship_name'] ?? $championshipDisplayName) ?>
                            </div>
                            <?php if (!empty($act['details'])): ?>
                            <div style="font-size:12px;color:#555;margin-top:3px;">
                                <?= htmlspecialchars($act['details']) ?>
                            </div>
                            <?php endif; ?>
                            <div style="font-size:11px;color:#999;margin-top:4px;">
                                <i class="fa-solid fa-user" style="margin-left:3px"></i> <?= htmlspecialchars($act['user'] ?? 'نظام') ?>
                                &nbsp;|&nbsp;
                                <i class="fa-solid fa-clock" style="margin-left:3px"></i> <?= htmlspecialchars($act['timestamp'] ?? '') ?>
                                <?php if (!empty($act['wasel'])): ?>
                                &nbsp;|&nbsp;
                                🔢 وصل: <?= htmlspecialchars($act['wasel']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                        <p style="text-align:center;color:#999;padding:20px;">
                            <i class="fa-solid fa-inbox" style="font-size:30px;color:#ccc;"></i><br>
                            لا يوجد أرشيف إجراءات بعد
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Car Edit Modal -->
<div id="carModal" class="modal fade" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa-solid fa-car"></i> تعديل بيانات السيارة</h4>
      </div>
      <div class="modal-body">
        <form id="carForm">
            <input type="hidden" name="action" value="edit_car">
            <input type="hidden" name="member_id" value="<?= htmlspecialchars($member['id']) ?>">
            <input type="hidden" name="registration_id" value="<?= htmlspecialchars($regId) ?>">

            <div class="form-group">
                <label>نوع السيارة</label>
                <input type="text" name="car_type" class="form-control" value="<?= htmlspecialchars($displayReg['car_type'] ?? '') ?>">
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>موديل (السنة)</label>
                        <input type="text" name="car_year" class="form-control" value="<?= htmlspecialchars($displayReg['car_year'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>اللون</label>
                        <input type="text" name="car_color" class="form-control" value="<?= htmlspecialchars($displayReg['car_color'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>حجم المحرك</label>
                        <input type="text" name="engine_size" class="form-control" value="<?= htmlspecialchars($displayReg['engine_size'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>نوع المشاركة</label>
                        <input type="text" name="participation_type" class="form-control" value="<?= htmlspecialchars($displayReg['participation_type'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>محافظة اللوحة</label>
                        <input type="text" name="plate_governorate" class="form-control" value="<?= htmlspecialchars($displayReg['plate_governorate'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>حرف اللوحة</label>
                        <input type="text" name="plate_letter" class="form-control" value="<?= htmlspecialchars($displayReg['plate_letter'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>رقم اللوحة</label>
                        <input type="text" name="plate_number" class="form-control" value="<?= htmlspecialchars($displayReg['plate_number'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-warning btn-block"><i class="fa-solid fa-save"></i> حفظ التغييرات</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Warning Modal -->
<div id="warningModal" class="modal fade" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">🔴 إضافة مخالفة: <?= htmlspecialchars($member['name'] ?? '') ?></h4>
      </div>
      <div class="modal-body">
        <form id="warningForm">
            <input type="hidden" name="action" value="add_warning">
            <input type="hidden" name="member_id" value="<?= htmlspecialchars($member['id']) ?>">

            <div class="form-group">
                <label>نص المخالفة / التنبيه</label>
                <textarea name="text" class="form-control" rows="3" required></textarea>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>مستوى الخطورة</label>
                        <select name="severity" class="form-control">
                            <option value="low">منخفض (تنبيه)</option>
                            <option value="medium" selected>متوسط (إنذار)</option>
                            <option value="high">شديد (حرمان)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>تاريخ الانتهاء (اختياري)</label>
                        <input type="date" name="expires_at" class="form-control">
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-danger btn-block">تأكيد وإضافة</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Note Modal -->
<div id="noteModal" class="modal fade" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa-solid fa-clipboard"></i> إضافة ملاحظة</h4>
      </div>
      <div class="modal-body">
        <form id="noteForm">
            <input type="hidden" name="action" value="add_note">
            <input type="hidden" name="member_id" value="<?= htmlspecialchars($member['id']) ?>">

            <div class="form-group">
                <label>نص الملاحظة</label>
                <textarea name="note_text" class="form-control" rows="3" required></textarea>
            </div>
            <div class="form-group">
                <label>نوع الملاحظة</label>
                <select name="note_type" class="form-control">
                    <option value="info">ℹ️ معلومة</option>
                    <option value="positive">✅ إيجابية</option>
                    <option value="warning">⚠️ تحذير</option>
                    <option value="blocker">🚫 حظر</option>
                </select>
            </div>
            <button type="submit" class="btn btn-info btn-block">إضافة الملاحظة</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>

<script>
const MEMBER_ID = '<?= htmlspecialchars($member['id']) ?>';
const PERMANENT_CODE = '<?= htmlspecialchars($member['permanent_code'] ?? '') ?>';

// Inline Edit
function editField(field, el) {
    const currentVal = el.innerText.trim();
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentVal === '-' ? '' : currentVal;
    input.className = 'form-control input-sm';
    input.style.display = 'inline-block';
    input.style.width = '200px';

    el.replaceWith(input);
    input.focus();

    function save() {
        const newVal = input.value.trim();
        $.post('member_details.php?id=<?= urlencode($memberId) ?>', {
            action: 'edit_member',
            member_id: MEMBER_ID,
            field: field,
            value: newVal
        }, function(res) {
            if (res.success) {
                const span = document.createElement('span');
                span.className = 'editable';
                span.onclick = function() { editField(field, span); };
                span.title = 'اضغط للتعديل';
                span.innerText = newVal || '-';
                if (field === 'phone') span.dir = 'ltr';
                input.replaceWith(span);
            } else {
                alert('خطأ: ' + (res.error || 'فشل التحديث'));
                location.reload();
            }
        }).fail(function() {
            alert('خطأ في الاتصال');
            location.reload();
        });
    }

    input.onblur = save;
    input.onkeydown = function(e) {
        if (e.key === 'Enter') { e.preventDefault(); save(); }
        if (e.key === 'Escape') { location.reload(); }
    };
}

// Inline Edit Stat (Championships/Rounds)
function editStat(type, el) {
    const currentVal = el.innerText.trim();
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentVal;
    input.className = 'form-control input-sm';
    input.style.display = 'inline-block';
    input.style.width = '80px';
    input.style.textAlign = 'center';

    el.replaceWith(input);
    input.focus();

    function save() {
        const newVal = input.value.trim();
        const action = type === 'championships' ? 'update_championships' : 'update_rounds';
        
        $.post('member_details.php?id=<?= urlencode($memberId) ?>', {
            action: action,
            member_id: MEMBER_ID,
            count: newVal
        }, function(res) {
            if (res.success) {
                const div = document.createElement('div');
                div.className = 'number editable';
                div.onclick = function() { editStat(type, div); };
                div.style.cursor = 'pointer';
                div.innerText = newVal;
                input.replaceWith(div);
            } else {
                alert('خطأ: ' + (res.error || 'فشل التحديث'));
                location.reload();
            }
        }).fail(function() {
            alert('خطأ في الاتصال');
            location.reload();
        });
    }

    input.onblur = save;
    input.onkeydown = function(e) {
        if (e.key === 'Enter') { e.preventDefault(); save(); }
        if (e.key === 'Escape') { location.reload(); }
    };
}

// Inline Edit Car Field
const REG_ID = '<?= htmlspecialchars($regId) ?>';

function editCarField(field, el) {
    const currentVal = el.innerText.trim();
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentVal === '-' ? '' : currentVal;
    input.className = 'form-control input-sm';
    input.style.display = 'inline-block';
    input.style.width = '100%';

    el.replaceWith(input);
    input.focus();

    function save() {
        const newVal = input.value.trim();
        $.post('member_details.php?id=<?= urlencode($memberId) ?>', {
            action: 'edit_car_field',
            member_id: MEMBER_ID,
            registration_id: REG_ID,
            field: field,
            value: newVal
        }, function(res) {
            if (res.success) {
                const span = document.createElement('span');
                span.className = 'editable';
                span.onclick = function() { editCarField(field, span); };
                span.innerText = newVal || '-';
                input.replaceWith(span);
            } else {
                alert('خطأ: ' + (res.error || 'فشل التحديث'));
                location.reload();
            }
        }).fail(function() {
            alert('خطأ في الاتصال');
            location.reload();
        });
    }

    input.onblur = save;
    input.onkeydown = function(e) {
        if (e.key === 'Enter') { e.preventDefault(); save(); }
        if (e.key === 'Escape') { location.reload(); }
    };
}

// Car Form
$('#carForm').on('submit', function(e) {
    e.preventDefault();
    $.post('member_details.php?id=<?= urlencode($memberId) ?>', $(this).serialize(), function(res) {
        if (res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + (res.error || 'فشل التحديث'));
        }
    });
});

// Warning Form
$('#warningForm').on('submit', function(e) {
    e.preventDefault();
    $.post('member_details.php?id=<?= urlencode($memberId) ?>', $(this).serialize(), function(res) {
        if (res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + (res.error || 'فشل'));
        }
    });
});

// Note Form
$('#noteForm').on('submit', function(e) {
    e.preventDefault();
    $.post('member_details.php?id=<?= urlencode($memberId) ?>', $(this).serialize(), function(res) {
        if (res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + (res.error || 'فشل'));
        }
    });
});

// Resolve Warning
function resolveWarning(id) {
    if (!confirm('هل تريد حل هذه المخالفة؟')) return;
    $.post('member_details.php?id=<?= urlencode($memberId) ?>', {
        action: 'resolve_warning',
        warning_id: id
    }, function(res) {
        if (res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + (res.error || 'فشل'));
        }
    });
}

function deleteWarning(id, memberId) {
    if (!confirm('هل تريد حذف هذا التحذير نهائياً؟')) return;
    $.post('member_details.php?id=<?= urlencode($memberId) ?>', {
        action: 'delete_warning',
        warning_id: id,
        member_id: memberId
    }, function(res) {
        if (res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + (res.error || 'فشل'));
        }
    });
}

function deleteNote(id, memberId) {
    if (!confirm('هل تريد حذف هذه الملاحظة نهائياً؟')) return;
    $.post('member_details.php?id=<?= urlencode($memberId) ?>', {
        action: 'delete_note',
        note_id: id,
        member_id: memberId
    }, function(res) {
        if (res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + (res.error || 'فشل'));
        }
    });
}

function editWarning(id, memberId) {
    var currentText = '';
    // Try to get current text from the DOM
    var card = $('button[onclick*="editWarning(\'' + id + '\'"]').closest('.warning-card');
    if (card.length) {
        var clone = card.find('div:first div:first').clone();
        clone.find('span').remove();
        currentText = clone.text().trim();
    }
    var newText = prompt('تعديل نص المخالفة:', currentText);
    if (newText === null || newText.trim() === '') return;
    $.post('member_details.php?id=<?= urlencode($memberId) ?>', {
        action: 'edit_warning',
        warning_id: id,
        member_id: memberId,
        text: newText.trim()
    }, function(res) {
        if (res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + (res.error || 'فشل'));
        }
    });
}

function editNote(id, memberId) {
    var currentText = $('#note-text-' + id).text().trim();
    var newText = prompt('تعديل نص الملاحظة:', currentText);
    if (newText === null || newText.trim() === '') return;
    $.post('member_details.php?id=<?= urlencode($memberId) ?>', {
        action: 'edit_note',
        note_id: id,
        member_id: memberId,
        text: newText.trim()
    }, function(res) {
        if (res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + (res.error || 'فشل'));
        }
    });
}

// Upload Photo (Personal - Legacy Wrapper)
function uploadPhoto(input) {
    uploadAnyPhoto(input, 'personal_photo', 'member');
}

// Delete Photo (Personal - Legacy Wrapper)
function deletePhoto() {
    if (!confirm('هل تريد حذف الصورة الشخصية؟')) return;
    deleteAnyPhoto('personal_photo', 'member');
}

// Generalized Upload
function uploadAnyPhoto(input, key, target) {
    if (!input.files || !input.files[0]) return;
    
    // Show loading behavior
    const origHtml = $(input).parent().html();
    $(input).parent().addClass('disabled').html('<i class="fa fa-spinner fa-spin"></i> جارِ الرفع...');

    const formData = new FormData();
    formData.append('action', 'upload_any_photo');
    formData.append('member_id', MEMBER_ID);
    formData.append('permanent_code', PERMANENT_CODE);
    formData.append('target_table', target);
    formData.append('image_key', key);
    formData.append('photo', input.files[0]);

    $.ajax({
        url: 'member_details.php?id=<?= urlencode($memberId) ?>',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert('❌ خطأ: ' + (res.error || 'فشل الرفع'));
                location.reload();
            }
        },
        error: function() {
            alert('خطأ في الاتصال بالسيرفر');
            location.reload();
        }
    });
}

// Generalized Delete
function deleteAnyPhoto(key, target) {
    if (!confirm('هل أنت متأكد من حذف هذا الملف؟')) return;
    
    $.post('member_details.php?id=<?= urlencode($memberId) ?>', {
        action: 'delete_any_photo',
        member_id: MEMBER_ID,
        target_table: target,
        image_key: key
    }, function(res) {
        if (typeof res === 'string') {
            try { res = JSON.parse(res); } catch(e) {
                alert('❌ خطأ في الرد من السيرفر');
                console.error('Response:', res);
                return;
            }
        }
        if (res.success) {
            location.reload();
        } else {
            alert('❌ خطأ: ' + (res.error || 'فشل الحذف'));
        }
    }, 'json').fail(function(xhr, status, error) {
        alert('❌ فشل الاتصال بالسيرفر: ' + (error || status));
        console.error('Delete failed:', xhr.responseText);
    });
}

// View Image Modal
function viewImage(src) {
    $('#modalViewImage').attr('src', src);
    $('#imageViewerModal').modal('show');
}
</script>

<!-- Image Viewer Modal -->
<div id="imageViewerModal" class="modal fade" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">عرض الصورة</h4>
            </div>
            <div class="modal-body text-center">
                <img id="modalViewImage" src="" style="max-width: 100%; max-height: 80vh; border-radius: 4px; box-shadow: 0 0 20px rgba(0,0,0,0.2);">
            </div>
        </div>
    </div>
</div>


</body>
</html>
