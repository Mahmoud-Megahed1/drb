<?php
/**
 * Members List - قائمة الأعضاء (Database Version)
 * Features: List, Edit, Delete, Warning Management, Activation
 */
session_start();
require_once '../include/db.php';
require_once '../include/helpers.php';
require_once '../include/AdminLogger.php';
require_once '../include/auth.php';
require_once '../services/MemberService.php';
require_once '../services/BadgeCacheService.php';

// Auth Check
requireAuth('../login.php');

$currentUser = $_SESSION['user'];
$isRoot = (isset($currentUser->username) && $currentUser->username === 'root') || ($currentUser->role ?? '') === 'admin';
$userRole = $currentUser->role ?? ($isRoot ? 'admin' : 'viewer');
if ($isRoot) $userRole = 'admin';

// Access Check
if (!in_array($userRole, ['admin', 'root', 'approver', 'notes'])) {
    header('location:../dashboard.php');
    exit;
}

$canEdit = in_array($userRole, ['admin', 'root', 'notes']);

// -- AJAX HANDLERS --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];
    
    try {
        $pdo = db();

        if ($_POST['action'] === 'activate') {
            $memberId = $_POST['id'];
            $stmt = $pdo->prepare("UPDATE members SET account_activated = 1, activation_date = datetime('now', '+3 hours') WHERE id = ?");
            $stmt->execute([$memberId]);
            
            auditLog('activate', 'members', $memberId, null, 'Manual Activation', $currentUser->id ?? null);
            
            $response = ['success' => true, 'message' => 'تم تفعيل الحساب'];
        }
        // NEW: Activate AND Send WhatsApp
        elseif ($_POST['action'] === 'send_activation') {
            $memberId = $_POST['id'];
            
            // 1. Activate account
            $stmt = $pdo->prepare("UPDATE members SET account_activated = 1, activation_date = datetime('now', '+3 hours') WHERE id = ?");
            $stmt->execute([$memberId]);
            
            auditLog('activate', 'members', $memberId, null, 'Manual Activation + WhatsApp', $currentUser->id ?? null);
            
            // Fetch member for WhatsApp
            $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($member) {
                // ADDED: Register in Current Championship and Dashboard
                try {
                    require_once __DIR__ . '/../include/helpers.php';
                    $champId = getCurrentChampionshipId();
                    if ($champId) {
                        // Check if registration already exists
                        $stmtChk = $pdo->prepare("SELECT id FROM registrations WHERE member_id = ? AND championship_id = ?");
                        $stmtChk->execute([$memberId, $champId]);
                        $existingRegId = $stmtChk->fetchColumn();
                        
                        // Fetch car details from member profile
                        $carType = !empty($member['last_car_type']) ? $member['last_car_type'] : null;
                        $carYear = !empty($member['last_car_year']) ? $member['last_car_year'] : null;
                        $carColor = !empty($member['last_car_color']) ? $member['last_car_color'] : null;
                        $plateNum = !empty($member['last_plate_number']) ? $member['last_plate_number'] : null;
                        $plateLtr = !empty($member['last_plate_letter']) ? $member['last_plate_letter'] : null;
                        $plateGov = !empty($member['last_plate_governorate']) ? $member['last_plate_governorate'] : null;
                        $engineSize = !empty($member['last_engine_size']) ? $member['last_engine_size'] : null;
                        $partType = !empty($member['last_participation_type']) ? $member['last_participation_type'] : null;
                        $personalPhoto = !empty($member['personal_photo']) ? $member['personal_photo'] : null;
                        
                        // ALWAYS load members.json for images (not just when car data missing)
                        $jm = null;
                        $membersJsonFile = __DIR__ . '/data/members.json';
                        if (file_exists($membersJsonFile)) {
                            $mJson = json_decode(file_get_contents($membersJsonFile), true) ?? [];
                            $code = $member['permanent_code'] ?? '';
                            
                            // 1. Try by code
                            if (isset($mJson[$code])) {
                                $jm = $mJson[$code];
                            } 
                            // 2. Try by phone as fallback
                            if (!$jm && !empty($member['phone'])) {
                                foreach ($mJson as $k => $v) {
                                    if (($v['phone'] ?? '') === $member['phone']) {
                                        $jm = $v;
                                        break;
                                    }
                                }
                            }
                            
                            // Backfill missing car data from JSON
                            if ($jm) {
                                if (!$carType) $carType = $jm['car_type'] ?? null;
                                if (!$carYear) $carYear = $jm['car_year'] ?? null;
                                if (!$carColor) $carColor = $jm['car_color'] ?? null;
                                if (!$plateNum) $plateNum = $jm['plate_number'] ?? null;
                                if (!$plateLtr) $plateLtr = $jm['plate_letter'] ?? null;
                                if (!$plateGov) $plateGov = $jm['plate_governorate'] ?? null;
                                if (!$engineSize) $engineSize = $jm['engine_size'] ?? null;
                                if (!$partType) $partType = $jm['participation_type'] ?? null;
                                
                                // Persist to db for the future
                                $pdo->prepare("UPDATE members SET last_car_type=COALESCE(NULLIF(last_car_type,''),?), last_car_year=COALESCE(NULLIF(last_car_year,''),?), last_car_color=COALESCE(NULLIF(last_car_color,''),?), last_plate_number=COALESCE(NULLIF(last_plate_number,''),?), last_plate_letter=COALESCE(NULLIF(last_plate_letter,''),?), last_plate_governorate=COALESCE(NULLIF(last_plate_governorate,''),?), last_engine_size=COALESCE(NULLIF(last_engine_size,''),?), last_participation_type=COALESCE(NULLIF(last_participation_type,''),?) WHERE id=?")
                                    ->execute([$carType, $carYear, $carColor, $plateNum, $plateLtr, $plateGov, $engineSize, $partType, $memberId]);
                            }
                        }
                        
                        $regId = $existingRegId;
                        $waselNum = '';
                        
                        if (!$existingRegId) {
                            // 1a. Create NEW SQLite Registration - Set as 'pending'
                            // Auto-add missing columns if needed
                            try { $pdo->exec("ALTER TABLE registrations ADD COLUMN personal_photo TEXT"); } catch(Exception $e) {}
                            
                            
                            $frontImg = $foundDj['images']['front_image'] ?? $foundDj['front_image'] ?? $jm['images']['front_image'] ?? $jm['front_image'] ?? null;
                            $sideImg = $foundDj['images']['side_image'] ?? $foundDj['side_image'] ?? $jm['images']['side_image'] ?? $jm['side_image'] ?? null;
                            $backImg = $foundDj['images']['back_image'] ?? $foundDj['back_image'] ?? $jm['images']['back_image'] ?? $jm['back_image'] ?? null;
                            $editedImg = $foundDj['images']['edited_image'] ?? $foundDj['edited_image'] ?? $jm['images']['edited_image'] ?? $jm['edited_image'] ?? null;
                            $acceptImg = $foundDj['acceptance_image'] ?? $foundDj['images']['acceptance_image'] ?? $jm['acceptance_image'] ?? $jm['images']['acceptance_image'] ?? null;
                            
                            if (!$personalPhoto) {
                                $personalPhoto = $foundDj['images']['personal_photo'] ?? $foundDj['personal_photo'] ?? $jm['images']['personal_photo'] ?? $jm['personal_photo'] ?? null;
                            }
                            
                            // Get Championship Name
                            $champName = 'البطولة الحالية';
                            $frameSettingsFile = __DIR__ . '/data/frame_settings.json';
                            if (file_exists($frameSettingsFile)) {
                                $fs = json_decode(file_get_contents($frameSettingsFile), true);
                                if (!empty($fs['form_titles']['sub_title'])) {
                                    $champName = $fs['form_titles']['sub_title'];
                                }
                            }
                            
                            // Get next wasel number
                            $nextWasel = $pdo->query("SELECT COALESCE(MAX(wasel),0)+1 FROM registrations WHERE championship_id = $champId")->fetchColumn();
                            
                            try {
                                $pdo->prepare("
                                    INSERT INTO registrations (
                                        member_id, championship_id, wasel, status, 
                                        car_type, car_year, car_color, engine_size, participation_type,
                                        plate_number, plate_letter, plate_governorate, 
                                        personal_photo, front_image, side_image, back_image, edited_image, acceptance_image,
                                        session_badge_token, championship_name, created_at, is_active
                                    ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+3 hours'), 1)
                                ")->execute([
                                    $memberId, $champId, $nextWasel, 
                                    $carType, $carYear, $carColor, $engineSize, $partType,
                                    $plateNum, $plateLtr, $plateGov,
                                    $personalPhoto, $frontImg, $sideImg, $backImg, $editedImg, $acceptImg,
                                    $member['permanent_code'] ?? '',
                                    $champName
                                ]);
                                $regId = $pdo->lastInsertId();
                                $waselNum = $nextWasel;
                            } catch(Exception $sqlErr) {
                                // Fallback: INSERT without images if columns don't exist
                                try {
                                    $pdo->prepare("
                                        INSERT INTO registrations (
                                            member_id, championship_id, wasel, status, 
                                            car_type, car_year, car_color, engine_size, participation_type,
                                            plate_number, plate_letter, plate_governorate, 
                                            session_badge_token, championship_name, created_at, is_active
                                        ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+3 hours'), 1)
                                    ")->execute([
                                        $memberId, $champId, $nextWasel, 
                                        $carType, $carYear, $carColor, $engineSize, $partType,
                                        $plateNum, $plateLtr, $plateGov,
                                        $member['permanent_code'] ?? '',
                                        $champName
                                    ]);
                                    $regId = $pdo->lastInsertId();
                                    $waselNum = $nextWasel;
                                } catch(Exception $sqlErr2) {
                                    error_log('Activation SQL Error: ' . $sqlErr2->getMessage());
                                    $waselNum = $nextWasel;
                                }
                            }
                        } else {
                            // 1b. Registration EXISTS - fetch car data from it if member profile is empty
                            $regStmt = $pdo->prepare("SELECT * FROM registrations WHERE id = ?");
                            $regStmt->execute([$existingRegId]);
                            $existingReg = $regStmt->fetch(PDO::FETCH_ASSOC);
                            if ($existingReg) {
                                if (!$carType) $carType = $existingReg['car_type'] ?? null;
                                if (!$carYear) $carYear = $existingReg['car_year'] ?? null;
                                if (!$carColor) $carColor = $existingReg['car_color'] ?? null;
                                if (!$plateNum) $plateNum = $existingReg['plate_number'] ?? null;
                                if (!$plateLtr) $plateLtr = $existingReg['plate_letter'] ?? null;
                                if (!$plateGov) $plateGov = $existingReg['plate_governorate'] ?? null;
                                if (!$engineSize) $engineSize = $existingReg['engine_size'] ?? null;
                                if (!$partType) $partType = $existingReg['participation_type'] ?? null;
                                if (!$personalPhoto) $personalPhoto = $existingReg['personal_photo'] ?? null;
                                $waselNum = $existingReg['wasel'] ?? '';
                                $regId = $existingRegId;
                                
                                // Update status to pending
                                $pdo->prepare("UPDATE registrations SET status = 'pending' WHERE id = ?")->execute([$existingRegId]);
                            }
                        }
                        
                        // 2. ALWAYS sync to Dashboard data.json (regardless of SQL registration)
                        $dataFile = __DIR__ . '/data/data.json';
                        if (file_exists($dataFile)) {
                            $dataJson = json_decode(file_get_contents($dataFile), true) ?? [];
                            $found = false;
                            foreach ($dataJson as &$d) {
                                if (($d['registration_code'] ?? '') === $member['permanent_code'] ||
                                    ($d['phone'] ?? '') === $member['phone']) {
                                    $d['status'] = 'pending';
                                    $found = true;
                                    break;
                                }
                            }
                            unset($d);
                            if (!$found) {
                                // Build Images Array from ALL sources
                                $finalImages = [];
                                if ($personalPhoto) $finalImages['personal_photo'] = $personalPhoto;
                                
                                // Source 1: members.json images
                                if (!empty($jm) && isset($jm['images']) && is_array($jm['images'])) {
                                    foreach ($jm['images'] as $k => $v) {
                                        if (!empty($v) && !isset($finalImages[$k])) $finalImages[$k] = $v;
                                    }
                                }
                                
                                // Source 2: Direct fields from members.json
                                $imgKeys = ['front_image', 'side_image', 'back_image', 'edited_image', 'acceptance_image', 'id_front', 'id_back', 'personal_photo', 'license_front', 'license_back'];
                                if (!empty($jm)) {
                                    foreach ($imgKeys as $ik) {
                                        if (!empty($jm[$ik]) && empty($finalImages[$ik])) $finalImages[$ik] = $jm[$ik];
                                    }
                                    
                                    // Handle legacy national_id keys
                                    if (!empty($jm['national_id_front']) && empty($finalImages['id_front'])) $finalImages['id_front'] = $jm['national_id_front'];
                                    if (!empty($jm['national_id_back']) && empty($finalImages['id_back'])) $finalImages['id_back'] = $jm['national_id_back'];
                                }
                                
                                // Source 3: SQLite registration (if exists)
                                if (!empty($existingReg)) {
                                    foreach ($imgKeys as $ik) {
                                        if (!empty($existingReg[$ik]) && empty($finalImages[$ik])) $finalImages[$ik] = $existingReg[$ik];
                                    }
                                    
                                    // Handle legacy national_id keys
                                    if (!empty($existingReg['national_id_front']) && empty($finalImages['id_front'])) $finalImages['id_front'] = $existingReg['national_id_front'];
                                    if (!empty($existingReg['national_id_back']) && empty($finalImages['id_back'])) $finalImages['id_back'] = $existingReg['national_id_back'];
                                }
                                
                                // Ensure no duplicate keys in final array
                                unset($finalImages['national_id_front'], $finalImages['national_id_back']);
                                
                                $dataJson[] = [
                                    'id' => $regId,
                                    'wasel' => $waselNum,
                                    'registration_code' => $member['permanent_code'] ?? '',
                                    'full_name' => $member['name'] ?? '',
                                    'phone' => $member['phone'] ?? '',
                                    'country_code' => '+964',
                                    'governorate' => $member['governorate'] ?? '',
                                    
                                    // Include Full Car Data
                                    'car_type' => $carType ?? '',
                                    'car_year' => $carYear ?? '',
                                    'car_color' => $carColor ?? '',
                                    'engine_size' => $engineSize ?? '',
                                    'participation_type' => $partType ?? '',
                                    
                                    // Include Full Plate Data
                                    'plate_number' => $plateNum ?? '',
                                    'plate_letter' => $plateLtr ?? '',
                                    'plate_governorate' => $plateGov ?? '',
                                    'plate_full' => implode(' - ', array_filter([$plateGov, $plateLtr, $plateNum])),
                                    
                                    // Include Images - both top-level AND nested
                                    'personal_photo' => $personalPhoto,
                                    'front_image' => $finalImages['front_image'] ?? '',
                                    'side_image' => $finalImages['side_image'] ?? '',
                                    'back_image' => $finalImages['back_image'] ?? '',
                                    'edited_image' => $finalImages['edited_image'] ?? '',
                                    'acceptance_image' => $finalImages['acceptance_image'] ?? '',
                                    'images' => $finalImages,
                                    
                                    'status' => 'pending',
                                    'badge_token' => $member['permanent_code'] ?? '',
                                    'register_type' => 'activation',
                                    'registration_date' => date('Y-m-d H:i:s'),
                                    'created_at' => date('Y-m-d H:i:s')
                                ];
                            }
                            file_put_contents($dataFile, json_encode($dataJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        }
                    }

                    // Force Sync to JSON to be absolutely sure
                    MemberService::syncToJson($memberId);

                } catch (Exception $e) {
                    error_log('Activation Registration Error: ' . $e->getMessage());
                }
                // 2. Send WhatsApp
                require_once __DIR__ . '/../wasender.php';
                $wasender = new WaSender();
                
                $waResult = $wasender->sendAccountActivation([
                    'name' => $member['name'],
                    'phone' => $member['phone'],
                    'permanent_code' => $member['permanent_code'],
                    'country_code' => '+964' // Default or from DB
                ]);
                
                if ($waResult['success'] ?? false) {
                    $pdo->prepare("UPDATE members SET activation_message_sent = 1, activation_message_date = datetime('now', '+3 hours') WHERE id = ?")
                        ->execute([$memberId]);
                    
                    $response = ['success' => true, 'message' => 'تم تفعيل الحساب وإرسال الرسالة بنجاح!'];
                } else {
                    $response = [
                        'success' => true, 
                        'message' => 'تم تفعيل الحساب، لكن فشل إرسال الرسالة',
                        'wa_error' => $waResult['error'] ?? 'Unknown'
                    ];
                }
            } else {
                throw new Exception('العضو غير موجود');
            }
        } 
        elseif ($_POST['action'] === 'add_warning') {
            if (!$canEdit) throw new Exception('ليس لديك صلاحية');
            $memberId = $_POST['member_id'];
            
            $stmt = $pdo->prepare("INSERT INTO warnings (member_id, warning_text, severity, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, datetime('now', '+3 hours'))");
            $stmt->execute([
                $memberId,
                $_POST['text'],
                $_POST['severity'],
                !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
                $currentUser->id ?? 0
            ]);
            $response = ['success' => true, 'message' => 'تم إضافة المخالفة'];
        }
        elseif ($_POST['action'] === 'delete') {
            if (!$isRoot) throw new Exception('فقط root يمكنه الحذف');
            
            $idToDelete = $_POST['id'];
            
            // Log before delete as we need member data
            $m = $pdo->prepare("SELECT name, phone, permanent_code FROM members WHERE id = ?");
            $m->execute([$idToDelete]);
            $memberData = $m->fetch(PDO::FETCH_ASSOC);
            
            // Delete child records first (FK constraints)
            $pdo->prepare("DELETE FROM warnings WHERE member_id = ?")->execute([$idToDelete]);
            $pdo->prepare("DELETE FROM notes WHERE member_id = ?")->execute([$idToDelete]);
            $pdo->prepare("DELETE FROM registrations WHERE member_id = ?")->execute([$idToDelete]);
            
            // Clean up participants cache and round logs
            if (!empty($memberData['permanent_code'])) {
                $pdo->prepare("DELETE FROM round_logs WHERE participant_id IN (SELECT id FROM participants WHERE registration_code = ?)")->execute([$memberData['permanent_code']]);
                $pdo->prepare("DELETE FROM participants WHERE registration_code = ?")->execute([$memberData['permanent_code']]);
            }

            // Delete the member completely
            $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$idToDelete]);
            
            // Also remove from members.json
            $membersJsonFile = __DIR__ . '/data/members.json';
            if (file_exists($membersJsonFile) && !empty($memberData['permanent_code'])) {
                $mJson = json_decode(file_get_contents($membersJsonFile), true) ?? [];
                if (isset($mJson[$memberData['permanent_code']])) {
                    unset($mJson[$memberData['permanent_code']]);
                    file_put_contents($membersJsonFile, json_encode($mJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
            
            auditLog('participant_delete', 'members', $idToDelete, json_encode($memberData), 'Hard Deleted', $currentUser->id ?? null);
            
            // Log to AdminLogger
            $adminLogger = new AdminLogger();
            $adminLogger->log(
                AdminLogger::ACTION_PARTICIPANT_DELETE,
                $currentUser->username ?? 'unknown',
                'حذف عضو: ' . ($memberData['name'] ?? 'غير معروف') . ' - ' . ($memberData['phone'] ?? ''),
                ['member_id' => $idToDelete, 'member_data' => $memberData]
            );
            
            $response = ['success' => true, 'message' => 'تم حذف العضو نهائياً'];
        }
        elseif ($_POST['action'] === 'delete_all') {
            if (!$isRoot) throw new Exception('فقط root يمكنه الحذف');
            
            // 1. Clear DB tables (SQLite syntax) - DO NOT touch registrations (Dashboard data)
            $pdo->exec("PRAGMA foreign_keys = OFF");
            $pdo->exec("DELETE FROM warnings");
            $pdo->exec("DELETE FROM notes");
            $pdo->exec("DELETE FROM participants");
            $pdo->exec("DELETE FROM round_logs");
            $pdo->exec("DELETE FROM audit_logs");
            $pdo->exec("DELETE FROM members");
            $pdo->exec("PRAGMA foreign_keys = ON");
            
            // 2. Clear JSON files (dashboard data sources)
            $jsonFiles = [
                __DIR__ . '/data/members.json',      // قاعدة بيانات الأعضاء
                // __DIR__ . '/data/data.json',      // DO NOT CLEAR بيانات التسجيلات
            ];
            foreach ($jsonFiles as $jf) {
                if (file_exists($jf)) {
                    file_put_contents($jf, '[]');
                }
            }
            
            auditLog('delete', 'system', null, 'ALL_MEMBERS', 'Database + JSON Cleared', $currentUser->id ?? null);
            
            // Log to AdminLogger
            $adminLogger = new AdminLogger();
            $adminLogger->log(
                AdminLogger::ACTION_DELETE_ALL_MEMBERS,
                $currentUser->username ?? 'unknown',
                'حذف جميع الأعضاء من قاعدة البيانات وملفات JSON',
                ['action' => 'truncate_all_tables_and_json']
            );
            
            $response = ['success' => true, 'message' => 'تم حذف جميع الأعضاء والتسجيلات نهائياً'];
        }
        elseif ($_POST['action'] === 'sync_database') {
            if (!$isRoot) throw new Exception('فقط root يمكنه مزامنة البيانات');
            
            $membersFile = __DIR__ . '/data/members.json';
            if (!file_exists($membersFile)) throw new Exception('ملف البيانات غير موجود');
            
            $membersData = json_decode(file_get_contents($membersFile), true) ?? [];
            $count = 0;
            
            foreach ($membersData as $id => $m) {
                $phone = $m['phone'] ?? '';
                if (empty($phone)) continue;
                
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                $name = $m['full_name'] ?? $m['name'] ?? 'Unknown';
                $gov = $m['governorate'] ?? '';
                
                $member = MemberService::getOrCreateMember($cleanPhone, $name, $gov);
                
                // ALWAYS sync registration_code as permanent_code (dashboard & members page must match)
                $code = $m['registration_code'] ?? $id;
                if (!empty($code)) {
                    $pdo->prepare("UPDATE members SET permanent_code = ? WHERE id = ?")->execute([$code, $member['id']]);
                }
                
                // Sync to scanners
                MemberService::syncToJson($member['id']);
                
                $count++;
            }
            
            auditLog('sync', 'system', null, null, "Synced $count members from JSON", $currentUser->id ?? null);
            $response = ['success' => true, 'message' => "تمت مزامنة $count عضو بنجاح!"];
        }
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// No offset/limit. DataTables handles it.
$search = $_GET['search'] ?? '';

$pdo = db();
$whereInfo = "1=1";
$params = [];

if (!empty($search)) {
    $whereInfo .= " AND (name LIKE ? OR phone LIKE ? OR permanent_code LIKE ? OR last_car_type LIKE ? OR last_plate_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Count Total
$stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE $whereInfo");
$stmt->execute($params);
$totalMembers = $stmt->fetchColumn();

// Sync Check (JSON vs DB)
$jsonCount = 0;
$membersFile = __DIR__ . '/data/members.json';
if (file_exists($membersFile)) {
    $mJson = json_decode(file_get_contents($membersFile), true) ?? [];
    $jsonCount = count($mJson);
}
$needsSync = ($jsonCount > $totalMembers && empty($search));

// Fetch Members - with safe fallback for missing columns
try {
    $stmt = $pdo->prepare("
        SELECT m.*, 
        (COALESCE(m.championships_participated, 0) + COALESCE(m.manual_rounds_count, 0) + (SELECT COUNT(*) FROM registrations r WHERE r.member_id = m.id AND r.status='approved')) as total_championships,
        (COALESCE(m.manual_rounds_count, 0) + (SELECT COUNT(*) FROM round_logs rl JOIN participants p ON rl.participant_id = p.id WHERE p.registration_code = m.permanent_code)) as tours_count,
        COALESCE((SELECT car_type FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1), m.last_car_type) as display_car_type,
        COALESCE((SELECT plate_number FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1), m.last_plate_number) as display_plate_number,
        COALESCE((SELECT plate_governorate FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1), m.last_plate_governorate) as display_plate_gov
        FROM members m
        WHERE $whereInfo
        ORDER BY m.created_at DESC
    ");
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: simpler query without new columns (for servers that haven't run fix_schema.php yet)
    $stmt = $pdo->prepare("
        SELECT m.*, 
        (SELECT COUNT(*) FROM registrations r WHERE r.member_id = m.id AND r.status='approved') as total_championships,
        (COALESCE(m.manual_rounds_count, 0)) as tours_count,
        (SELECT car_type FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_car_type,
        (SELECT plate_number FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_plate_number,
        (SELECT plate_governorate FROM registrations r WHERE r.member_id = m.id ORDER BY created_at DESC LIMIT 1) as display_plate_gov
        FROM members m
        WHERE $whereInfo
        ORDER BY m.created_at DESC
    ");
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Helper to get stats (can be optimized or batched)
// For now, we rely on the subqueries above for speed in list view.
// Round counts might need separate query if DB structure for rounds is complex.
// We can skip detailed round counts in the list for performance or query `round_logs`.
// Let's rely on basic DB data.

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>إدارة الأعضاء</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap.min.css">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; padding: 20px; }
        .action-btn { margin-left: 5px; }
        .badge-active { background-color: #28a745; }
        .badge-inactive { background-color: #6c757d; }
        .stats-mini { font-size: 11px; color: #666; }
        .stats-mini span { margin-left: 10px; }
        .warning-count { background: #dc3545; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
    </style>
</head>
<body>

<!-- Navbar -->
<?php include '../include/navbar.php'; ?>
<div style="height: 60px;"></div>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <!-- Sync Database button removed per new requirements -->


<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="fa-solid fa-users"></i> قاعدة بيانات الأعضاء (<?= $totalMembers ?> عضو)</h3>
                </div>
                <div class="panel-body">
                    
                    <!-- Search moved to DataTables -->
                    <form method="GET" class="form-inline" style="margin-bottom: 20px;">
                        <a href="export_members.php?download=1&source=db&format=csv&search=<?= urlencode($search) ?>" class="btn btn-info"><i class="fa-solid fa-file-csv"></i> تصدير CSV</a>
                        <a href="import_members.php" class="btn btn-success pull-left"><i class="fa-solid fa-file-import"></i> استيراد</a>
                        <?php if ($isRoot): ?>
                        <button type="button" class="btn btn-danger pull-left" style="margin-left: 10px;" onclick="deleteAllMembers()">
                            <i class="fa-solid fa-trash"></i> حذف الكل
                        </button>
                        <?php endif; ?>
                    </form>

                    <table id="membersTable" class="table table-bordered table-striped table-hover">
                        <thead>
                            <tr>
                                <th style="width:100px">الكود</th>
                                <th>الاسم</th>
                                <th>الهاتف</th>
                                <th>السيارة</th>
                                <th>الجولات</th>
                                <th>المخالفات</th>
                                <th>الإحصائيات</th>
                                <th style="width:120px">الحالة</th>
                                <th style="width:200px">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($members as $m): 
                                $code = $m['permanent_code'];
                                // Fallback ID if code is temp
                                $linkId = $code ?: $m['id'];
                                
                                $championshipsCount = $m['total_championships'] ?? 0;
                                // Warnings count (DB)
                                $stmtW = $pdo->prepare("SELECT COUNT(*) FROM warnings WHERE member_id = ? AND is_resolved = 0");
                                $stmtW->execute([$m['id']]);
                                $warningsCount = $stmtW->fetchColumn();
                            ?>
                            <tr>
                                <td><code style="font-size:13px"><?= htmlspecialchars($code) ?></code></td>
                                <td>
                                    <strong><?= htmlspecialchars($m['name'] ?? '-') ?></strong>
                                    <?php if (!empty($m['instagram'])): ?>
                                    <br><small class="text-muted"><i class="fa-brands fa-instagram"></i> <?= htmlspecialchars($m['instagram']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td dir="ltr" style="text-align:right"><?= htmlspecialchars($m['phone']) ?></td>
                                <td>
                                    <?= htmlspecialchars($m['display_car_type'] ?? '-') ?>
                                    <br><small class="text-muted"><?= htmlspecialchars(($m['display_plate_gov'] ?? '') . ' ' . ($m['display_plate_number'] ?? '')) ?></small>
                                </td>
                                <td style="text-align:center; font-weight:bold; font-size:16px;">
                                    <?= (int)$m['tours_count'] ?>
                                </td>
                                <td style="text-align:center; font-weight:bold; font-size:16px; color: <?= $warningsCount > 0 ? '#d9534f' : '#333' ?>;">
                                    <?= $warningsCount ?>
                                </td>
                                <td>
                                    <div class="stats-mini">
                                        <span title="البطولات"><i class="fa-solid fa-trophy text-warning"></i> <?= $championshipsCount ?></span>
                                        <?php if ($warningsCount > 0): ?>
                                        <span class="warning-count" title="المخالفات"><i class="fa-solid fa-exclamation"></i> <?= $warningsCount ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align:center">
                                    <?php 
                                        $isActive = $m['account_activated'] ?? 0;
                                        if($isActive): 
                                    ?>
                                        <span class="label label-success" style="display:block;margin-bottom:4px;"><i class="fa-solid fa-check"></i> مفعل</span>
                                        <button class="btn btn-xs btn-default" onclick="activateMember('<?= $m['id'] ?>')" title="إعادة الإرسال للداشبورد في حال الحذف">
                                            <i class="fa-solid fa-rotate-right"></i> للداشبورد
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-xs btn-warning" onclick="activateMember('<?= $m['id'] ?>')">
                                            <i class="fa-solid fa-paper-plane"></i> تفعيل
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="member_details.php?id=<?= urlencode($linkId) ?>" class="btn btn-xs btn-primary action-btn" title="التفاصيل">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                    <button class="btn btn-xs btn-warning action-btn" onclick="openWarningModal('<?= $m['id'] ?>', '<?= htmlspecialchars($m['name'] ?? '') ?>')" title="مخالفة">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </button>
                                    <a href="../badge.php?token=<?= $code ?>" target="_blank" class="btn btn-xs btn-info action-btn" title="الباج">
                                        <i class="fa-solid fa-id-card"></i>
                                    </a>
                                    <?php if ($isRoot): ?>
                                    <button class="btn btn-xs btn-danger action-btn" onclick="deleteMember('<?= $m['id'] ?>', '<?= htmlspecialchars($m['name'] ?? '') ?>')" title="حذف">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
        <h4 class="modal-title">🔴 إضافة مخالفة للعضو: <span id="warn_member_name"></span></h4>
      </div>
      <div class="modal-body">
        <form id="warningForm">
            <input type="hidden" name="action" value="add_warning">
            <input type="hidden" name="member_id" id="warn_member_id">
            
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
                            <option value="medium">متوسط (إنذار)</option>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap.min.js"></script>

<script>
    $(document).ready(function() {
        $('#membersTable').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/ar.json"
            },
            "pageLength": 50,
            "order": [[ 0, "desc" ]], // Default sort by Code/ID
            "stateSave": true,
            "columnDefs": [
                { "orderable": false, "targets": 8 } // Disable sorting on actions column
            ]
        });
    });

    function syncDatabase() {
        if (!confirm('هل تريد مزامنة الأعضاء من ملفات الجيسون إلى قاعدة البيانات؟ سيتم إضافة الأعضاء المفقودين فقط.')) return;
        
        const btn = event.target.closest('button');
        const oldContent = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري المزامنة...';

        $.ajax({
            url: 'members.php',
            method: 'POST',
            data: { action: 'sync_database' },
            success: function(res) {
                if (res.success) {
                    alert(res.message);
                    location.reload();
                } else {
                    alert('خطأ: ' + (res.error || 'فشلت المزامنة'));
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = oldContent;
                    }
                }
            },
            error: function() {
                alert('حدث خطأ في الاتصال بالخادم');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldContent;
                }
            }
        });
    }

    function activateMember(id) {
    if(!confirm('هل أنت متأكد من تفعيل/إعادة إرسال العضو للداشبورد؟ ستظهر بياناته في صفحة التسجيلات.')) return;
    
    $.post('members.php', { action: 'send_activation', id: id }, function(res) {
        if(res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + res.error);
        }
    });
}

function openWarningModal(id, name) {
    $('#warn_member_id').val(id);
    $('#warn_member_name').text(name);
    $('#warningModal').modal('show');
}

$('#warningForm').on('submit', function(e) {
    e.preventDefault();
    $.post('members.php', $(this).serialize(), function(res) {
        if(res.success) {
            alert(res.message);
            location.reload();
        } else {
            alert('خطأ: ' + res.error);
        }
    });
});

function deleteMember(id, name) {
    if(!confirm('⚠️ هل أنت متأكد من حذف العضو: ' + name + '؟')) return;
    
    $.post('members.php', { action: 'delete', id: id }, function(res) {
        if(res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + res.error);
        }
    });
}

function deleteAllMembers() {
    if(!confirm('⚠️ هل أنت متأكد من حذف جميع الأعضاء؟')) return;
    if(!confirm('🔴 تأكيد نهائي: سيتم حذف كل الأعضاء!')) return;
    
    $.post('members.php', { action: 'delete_all' }, function(res) {
        if(res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ خطأ: ' + res.error);
        }
    });
}
</script>

</body>
</html>
