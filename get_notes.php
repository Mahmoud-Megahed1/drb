<?php
/**
 * Get Notes API
 * Retrieves notes for a participant or all notes
 * Enhanced to show global history per member + orphaned notes
 * With Lazy Migration from JSON
 */

require_once 'include/db.php';
require_once 'include/auth.php';
require_once 'include/errors.php';
require_once 'services/MemberService.php'; // Required for migration

header('Content-Type: application/json; charset=utf-8');

// Auth check
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

$badge_id = $_GET['badge_id'] ?? '';
$plate_number = $_GET['plate_number'] ?? '';
$plate_letter = $_GET['plate_letter'] ?? '';
$plate_governorate = $_GET['plate_governorate'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_priority = $_GET['priority'] ?? '';
$include_resolved = $_GET['include_resolved'] ?? '0';

// البحث برقم اللوحة أولاً في data.json
if (!empty($plate_number)) {
    $jsonFile = __DIR__ . '/admin/data/data.json';
    if (file_exists($jsonFile)) {
        $data = json_decode(file_get_contents($jsonFile), true) ?? [];
        foreach ($data as $reg) {
            if (($reg['status'] ?? '') !== 'approved') continue;
            
            $regPlateNumber = $reg['plate_number'] ?? '';
            if ((string)$regPlateNumber !== (string)$plate_number) continue;
            
            if (!empty($plate_letter) && mb_strtoupper($reg['plate_letter'] ?? '') !== mb_strtoupper($plate_letter)) continue;
            if (!empty($plate_governorate) && ($reg['plate_governorate'] ?? '') !== $plate_governorate) continue;
            
            // تم العثور - استخدم badge_token كـ badge_id
            $badge_id = $reg['badge_token'] ?? $reg['badge_id'] ?? $reg['registration_code'] ?? '';
            break;
        }
        
        if (empty($badge_id)) {
            echo json_encode(['success' => false, 'error' => 'لم يتم العثور على مشارك بهذه اللوحة']);
            exit;
        }
    }
}

// DEBUG LOGGING
function log_debug($msg) {
    $logDir = __DIR__ . '/admin/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    file_put_contents($logDir . '/debug_scanner.log', date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

try {
    $pdo = db();
    
    // 1. Resolve Member
    $member = null;
    $term = trim($badge_id);
    log_debug("Search Request: $term");
    
    if (empty($term)) {
        echo json_encode(['success' => false, 'error' => 'No input provided']);
        exit;
    }
    
    // A. Check Member Code
    $stmt = $pdo->prepare("SELECT * FROM members WHERE permanent_code = ?");
    $stmt->execute([$term]);
    $member = $stmt->fetch();
    
    // B. Check Session Token
    if (!$member) {
        $stmt = $pdo->prepare("SELECT m.* FROM registrations r JOIN members m ON r.member_id = m.id WHERE r.session_badge_token = ?");
        $stmt->execute([$term]);
        $member = $stmt->fetch();
    }
    
    // C. Check Wasel
    if (!$member) {
        // Wasel might match many if championship not specified, but we take latest
        $stmt = $pdo->prepare("SELECT m.* FROM registrations r JOIN members m ON r.member_id = m.id WHERE r.wasel = ? ORDER BY r.created_at DESC LIMIT 1");
        $stmt->execute([$term]);
        $member = $stmt->fetch();
    }

    // D. Fallback: Check participants (Strict Join)
    if (!$member) {
        $stmt = $pdo->prepare("SELECT m.* FROM participants p 
                              JOIN members m ON p.badge_id = m.permanent_code 
                              WHERE p.badge_id = ?");
        $stmt->execute([$term]);
        $member = $stmt->fetch();
        
        if (!$member) {
             $stmt = $pdo->prepare("SELECT m.* FROM participants p 
                              JOIN members m ON p.registration_code = m.permanent_code 
                              WHERE p.badge_id = ?");
             $stmt->execute([$term]);
             $member = $stmt->fetch();
        }
    }
    
    // E. JSON Lazy Migration
    if (!$member) {
        $jsonFile = __DIR__ . '/admin/data/data.json';
        if (file_exists($jsonFile)) {
             $data = json_decode(file_get_contents($jsonFile), true);
             if ($data) {
                 $foundJson = null;
                 foreach ($data as $reg) {
                     $bId = $reg['badge_id'] ?? $reg['badge_token'] ?? $reg['registration_code'] ?? $reg['permanent_code'] ?? '';
                     $bToken = $reg['badge_token'] ?? '';
                     $wasel = $reg['wasel'] ?? '';
                     
                     if ($bId == $term || $bToken == $term || $wasel == $term) {
                         $foundJson = $reg;
                         break;
                     }
                 }
                 
                 if ($foundJson) {
                     try {
                         $pdo->beginTransaction();
                         
                         $name = $foundJson['full_name'] ?? $foundJson['name'] ?? 'Unknown Member';
                         $phone = $foundJson['phone'] ?? '0000000000';
                         $gov = $foundJson['governorate'] ?? '';
                         
                         $migratedMember = MemberService::getOrCreateMember($phone, $name, $gov);
                         $member = $migratedMember;
                         
                         $champId = getCurrentChampionshipId();
                         $wasel = $foundJson['wasel'] ?? null;
                         $token = $foundJson['badge_token'] ?? null;
                         
                         $stmt = $pdo->prepare("SELECT id FROM registrations WHERE member_id = ? AND championship_id = ?");
                         $stmt->execute([$member['id'], $champId]);
                         if (!$stmt->fetch()) {
                             $stmt = $pdo->prepare("
                                INSERT INTO registrations (
                                    member_id, championship_id, wasel, session_badge_token,
                                    status, created_at, is_active
                                ) VALUES (?, ?, ?, ?, 'approved', datetime('now', '+3 hours'), 1)
                             ");
                             $stmt->execute([$member['id'], $champId, $wasel, $token]);
                         }
                         
                         $pBadgeId = $foundJson['badge_id'] ?? $term;
                         $pRegCode = $member['permanent_code'];
                         
                         $stmt = $pdo->prepare("SELECT id FROM participants WHERE badge_id = ?");
                         $stmt->execute([$pBadgeId]);
                         if (!$stmt->fetch()) {
                             $stmt = $pdo->prepare("
                                INSERT INTO participants (badge_id, registration_code, wasel, name, phone) 
                                VALUES (?, ?, ?, ?, ?)
                             ");
                             $stmt->execute([$pBadgeId, $pRegCode, $wasel, $name, $phone]);
                         }
                         
                         $pdo->commit();
                         log_debug("Migrated successfully: " . $member['id']);
                         
                     } catch (Exception $e) {
                         if ($pdo->inTransaction()) $pdo->rollBack();
                         log_debug("Migration Exception: " . $e->getMessage());
                     }
                 }
             }
        }
    }
    
    // F. Loose Participant Lookup (If Member Link Failed)
    $standaloneParticipant = null;
    if (!$member) {
        $stmt = $pdo->prepare("SELECT * FROM participants WHERE badge_id = ?");
        $stmt->execute([$term]);
        $standaloneParticipant = $stmt->fetch();
        
        if ($standaloneParticipant) {
            // Fake member object to allow display
            $member = [
                'id' => 0,
                'name' => $standaloneParticipant['name'],
                'permanent_code' => $standaloneParticipant['registration_code'] ?? '',
                'phone' => $standaloneParticipant['phone'] ?? ''
            ];
            log_debug("Found Standalone Participant: " . $standaloneParticipant['id']);
        }
    }
    
    if (!$member) {
        log_debug("FAILED TO FIND MEMBER: $term");
        echo json_encode(['success' => false, 'error' => 'لم يتم العثور على العضو']);
        exit;
    }

    // 2. Fetch Championships
    $championships = $pdo->query("SELECT * FROM championships ORDER BY created_at DESC")->fetchAll();
    
    // 3. Fetch All Notes
    // Query strategy: 
    // IF member['id'] > 0: Get notes by member_id OR (participant_id matches badge)
    // IF member['id'] == 0: Get notes by participant_id (standalone)
    
    $params = [];
    $conditions = [];
    
    if ($member['id'] > 0) {
        // Main History + Orphaned notes for this badge
        $conditions[] = "(n.member_id = ? OR n.participant_id IN (SELECT id FROM participants WHERE badge_id = ? OR registration_code = ?))";
        $params[] = $member['id'];
        $params[] = $term;
        $params[] = $member['permanent_code'];
        // Note: This covers cases where add_note saved with participant_id only
    } else {
        // Only standalone
        $conditions[] = "n.participant_id = ?";
        $params[] = $standaloneParticipant['id'];
    }
    
    // Filters
    if (!empty($filter_type) && in_array($filter_type, ['info', 'warning', 'blocker'])) {
        $conditions[] = "n.note_type = ?";
        $params[] = $filter_type;
    }
    
    if (!empty($filter_priority) && in_array($filter_priority, ['low', 'medium', 'high'])) {
        $conditions[] = "n.priority = ?";
        $params[] = $filter_priority;
    }
    
    if ($include_resolved !== '1') {
        $conditions[] = "n.is_resolved = 0";
    }

    $userRole = $_SESSION['user_role'] ?? '';
    if ($userRole !== 'admin' && $userRole !== 'root') {
        $conditions[] = "(n.visibility LIKE '%\"all\"%' OR n.visibility LIKE ?)";
        $params[] = '%"' . $userRole . '"%';
    }
    
    $where = implode(" AND ", $conditions);
    
    $sql = "
        SELECT n.*, 
               u.username as created_by_name
        FROM notes n
        LEFT JOIN users u ON n.created_by = u.id
        WHERE $where
        ORDER BY n.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notes = $stmt->fetchAll();
    
    // 4. Enrich Notes
    foreach ($notes as &$note) {
        $note['visibility'] = json_decode($note['visibility'], true) ?? ['all'];
        
        $note['type_label'] = match($note['note_type']) {
            'info' => 'معلومة',
            'warning' => 'تحذير',
            'blocker' => 'مانع',
            default => 'ملاحظة'
        };

        $note['priority_label'] = match($note['priority']) {
            'low' => 'عادي',
            'medium' => 'متوسط',
            'high' => 'مهم',
            default => 'عادي'
        };

        $note['type_icon'] = match($note['note_type']) {
            'info' => 'ℹ️',
            'warning' => '⚠️',
            'blocker' => '🚫',
            default => '📝'
        };
        
        $noteTime = strtotime($note['created_at']);
        $noteChamp = 'عام'; 
        
        foreach ($championships as $champ) {
            $start = $champ['start_date'] ? strtotime($champ['start_date']) : strtotime($champ['created_at']);
            $end = $champ['end_date'] ? strtotime($champ['end_date']) : PHP_INT_MAX;
            
            if ($noteTime >= $start && $noteTime <= $end) {
                $noteChamp = $champ['name'];
                break;
            }
        }
        $note['championship_name'] = $noteChamp;
        $note['participant_name'] = $member['name'];
    }
    
    $lastReg = null;
    if ($member['id'] > 0) {
        $stmt = $pdo->prepare("SELECT * FROM registrations WHERE member_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$member['id']]);
        $lastReg = $stmt->fetch();
    } elseif ($standaloneParticipant) {
        // Use participant data as fallback reg
        $lastReg = [
            'wasel' => $standaloneParticipant['wasel'],
            'car_type' => $standaloneParticipant['car_type'],
            'plate_governorate' => '',
            'plate_number' => $standaloneParticipant['plate']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'notes' => $notes,
        'member' => [
            'name' => $member['name'],
            'wasel' => $lastReg['wasel'] ?? '',
            'car' => $lastReg['car_type'] ?? '',
            'plate' => trim(($lastReg['plate_governorate']??'') . ' ' . ($lastReg['plate_number']??'')),
            'badge_id' => $badge_id
        ]
    ]);

} catch (Exception $e) {
    if (function_exists('log_debug')) log_debug("DB Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()]);
}
