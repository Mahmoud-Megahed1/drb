<?php
/**
 * Add Note API
 * Adds structured notes to participants/members
 * Enhanced to link notes to Member ID for global history
 * With Fallback to Participant ID if Member not found
 */

require_once 'include/db.php';
require_once 'include/auth.php';
require_once 'include/errors.php';
require_once 'services/MemberService.php';

header('Content-Type: application/json; charset=utf-8');

// Auth check - allow any logged-in admin/staff OR users with notes permission
if (!isLoggedIn() && !hasPermission('notes')) {
    jsonError('UNAUTHORIZED', [], 401);
}

// Rate limit
$deviceKey = $_POST['device'] ?? $_SERVER['REMOTE_ADDR'];
if (!checkRateLimit("notes_$deviceKey", 10, 1)) {
    jsonError('RATE_LIMITED', ['retry_after' => 1], 429);
}

// Input
$badge_id = trim($_POST['badge_id'] ?? '');
$note_text = trim($_POST['note_text'] ?? '');
$note_type = $_POST['note_type'] ?? 'info';
$priority = $_POST['priority'] ?? 'low';
$visibility = $_POST['visibility'] ?? '["all"]';
$device = $_POST['device'] ?? 'unknown';

// Validate
$validTypes = ['info', 'warning', 'blocker'];
$validPriorities = ['low', 'medium', 'high'];

if (empty($badge_id)) {
    jsonError('INVALID_INPUT', ['details' => 'Badge ID required']);
}

if (strlen($note_text) < 3) {
    jsonError('NOTE_TOO_SHORT');
}

if (strlen($note_text) > 500) {
    jsonError('NOTE_TOO_LONG');
}

if (!in_array($note_type, $validTypes)) {
    jsonError('INVALID_NOTE_TYPE');
}

if (!in_array($priority, $validPriorities)) {
    $priority = 'low';
}

if (is_array($visibility)) {
    $visibility = json_encode($visibility);
} elseif (!json_decode($visibility)) {
    $visibility = '["all"]';
}

try {
    $pdo = db();
    
    // 1. Resolve Member ID
    $member = null;
    $term = $badge_id;
    
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
        $stmt = $pdo->prepare("SELECT m.* FROM registrations r JOIN members m ON r.member_id = m.id WHERE r.wasel = ? ORDER BY r.created_at DESC LIMIT 1");
        $stmt->execute([$term]);
        $member = $stmt->fetch();
    }
    
    // D. Check Participants (Strict Link)
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
    
    // 2. Resolve Participant ID
    $participantId = null;
    $stmt = $pdo->prepare("SELECT id FROM participants WHERE badge_id = ?");
    $stmt->execute([$term]);
    $participantId = $stmt->fetchColumn();
    
    if (!$participantId && $member) {
        // If we found a member but no participant record for this badge, 
        // try to find any participant linked to this member (Best Effort)
        // Or if the term was 'wasel', finding participant by 'wasel' might be better.
        $stmt = $pdo->prepare("SELECT id FROM participants WHERE wasel = ? OR registration_code = ?");
        $stmt->execute([$term, $member['permanent_code']]);
        $participantId = $stmt->fetchColumn();
    }
    
    // 3. Fallback Check & Lazy Migration
    if (!$member && !$participantId) {
        // Try to find in data.json and migrate
        $jsonFile = __DIR__ . '/admin/data/data.json';
        $foundJson = null;
        
        if (file_exists($jsonFile)) {
             $data = json_decode(file_get_contents($jsonFile), true);
             if ($data) {
                 foreach ($data as $reg) {
                     $bId = $reg['badge_id'] ?? $reg['badge_token'] ?? $reg['registration_code'] ?? $reg['permanent_code'] ?? '';
                     $bToken = $reg['badge_token'] ?? '';
                     $wasel = $reg['wasel'] ?? '';
                     $regCode = $reg['registration_code'] ?? '';
                     
                     // Check against term
                     if ($bId == $term || $bToken == $term || $wasel == $term || $regCode == $term) {
                         $foundJson = $reg;
                         break;
                     }
                 }
             }
        }
        
        if ($foundJson) {
            try {
                // Determine Name and attributes
                $name = $foundJson['full_name'] ?? $foundJson['name'] ?? 'Unknown Member';
                $phone = $foundJson['phone'] ?? '0000000000';
                $gov = $foundJson['governorate'] ?? '';
                
                // Get/Create Member
                $member = MemberService::getOrCreateMember($phone, $name, $gov);
                
                // Ensure Registration Exists
                $champId = getCurrentChampionshipId();
                $wasel = $foundJson['wasel'] ?? null;
                $token = $foundJson['badge_token'] ?? null;
                
                // Check if registration exists
                $stmt = $pdo->prepare("SELECT id FROM registrations WHERE member_id = ? AND championship_id = ?");
                $stmt->execute([$member['id'], $champId]);
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare("
                       INSERT INTO registrations (
                           member_id, championship_id, wasel, session_badge_token,
                           status, created_at, is_active,
                           car_type, plate_number, plate_letter, plate_governorate
                       ) VALUES (?, ?, ?, ?, 'approved', datetime('now', '+3 hours'), 1, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $member['id'], 
                        $champId, 
                        $wasel, 
                        $token,
                        $foundJson['car_type'] ?? '',
                        $foundJson['plate_number'] ?? '',
                        $foundJson['plate_letter'] ?? '',
                        $foundJson['plate_governorate'] ?? ''
                    ]);
                }
                
                // Update Permanent Code if needed
                if (!empty($foundJson['registration_code']) && $member['permanent_code'] === 'TEMP') {
                     $pdo->prepare("UPDATE members SET permanent_code = ? WHERE id = ?")
                         ->execute([$foundJson['registration_code'], $member['id']]);
                     $member['permanent_code'] = $foundJson['registration_code'];
                }

            } catch (Exception $e) {
                // Migration failed, proceed to error
            }
        }
        
        if (!$member) {
            jsonError('MEMBER_NOT_FOUND', ['term' => $term]);
        }
    }
    
    // 4. Insert Note
    $stmt = $pdo->prepare("
        INSERT INTO notes (member_id, participant_id, note_text, note_type, priority, visibility, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now', '+3 hours'))
    ");
    
    // Fix User ID retrieval
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId && isset($_SESSION['user'])) {
        if (is_object($_SESSION['user'])) $userId = $_SESSION['user']->id ?? null;
        elseif (is_array($_SESSION['user'])) $userId = $_SESSION['user']['id'] ?? null;
    }
    
    // Check if user ID exists (especially for legacy 999 ID)
    if ($userId) {
        $stmtChk = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmtChk->execute([$userId]);
        if (!$stmtChk->fetchColumn()) {
            $userId = null; // Reset to trigger fallback
        }
    }
    
    // For gate users or system actions without a session user ID, 
    // fetch ANY valid user ID to satisfy FOREIGN KEY constraint
    if (!$userId) {
        // Try getting 'admin'
        $stmtUsers = $pdo->prepare("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
        $stmtUsers->execute();
        $userId = $stmtUsers->fetchColumn();
        
        // If not found, try getting ANY user
        if (!$userId) {
            $stmtUsers = $pdo->prepare("SELECT id FROM users LIMIT 1");
            $stmtUsers->execute();
            $userId = $stmtUsers->fetchColumn();
        }
        
        // If STILL not found (users table empty?!), create a system user
        if (!$userId) {
            $pdo->exec("INSERT INTO users (username, password_hash, role, device_name, full_name) VALUES ('system_auto', 'system_pwd_123', 'admin', 'server', 'System Auto')");
            $userId = $pdo->lastInsertId();
        }
    }
    
    // Final check for strict constraint (should not happen now)
    if (!$userId) throw new Exception("CRITICAL: No valid user found for note creation.");
    
    $memberId = $member ? $member['id'] : null;
    
    // Ensure member_id exists
    if (!$memberId) {
        throw new Exception("Member ID missing after lookup/migration.");
    }
    
    // Ensure participant_id is valid or NULL
    if ($participantId) {
        // Double check existence just in case
        $chk = $pdo->prepare("SELECT id FROM participants WHERE id = ?");
        $chk->execute([$participantId]);
        if (!$chk->fetchColumn()) $participantId = null;
    }
    
    $payload = [
        $memberId,
        $participantId ?: null,
        $note_text,
        $note_type,
        $priority,
        $visibility,
        $userId
    ];
    
    $stmt->execute($payload);
    
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    // Return ACTUAL error message for debugging
    // This overrides the generic 'DB_ERROR' message in the frontend alert
    jsonResponse([
        'success' => false,
        'code' => 'DB_ERROR',
        'error' => 'DB Error: ' . $e->getMessage(), // Show actual error
        'msg' => $e->getMessage()
    ], 500);
}
