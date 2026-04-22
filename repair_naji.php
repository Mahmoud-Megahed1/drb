<?php
/**
 * Repair script to merge duplicate member registrations.
 * Merges a newly created code (newCode) back into the original member code (oldCode).
 */

require_once 'include/db.php';
$pdo = db();

$oldCode = 'DD22FE'; // الكود القديم في members.json
$newCode = '6ZR7H2'; // الكود الجديد اللي اتعمل بالغلط

echo "<pre>";
echo "Starting repair process to merge new code ($newCode) into old code ($oldCode)...\n\n";

// 1. Update data.json - the active championship registrations
$dataFile = 'admin/data/data.json';
if (file_exists($dataFile)) {
    $data = json_decode(file_get_contents($dataFile), true);
    $updated = false;
    foreach ($data as &$reg) {
        if (($reg['registration_code'] ?? '') === $newCode) {
            $reg['registration_code'] = $oldCode;
            $reg['register_type'] = 'returning';
            $reg['register_type_label'] = 'مسجل قديم';
            $updated = true;
            echo "- Updated registration in data.json\n";
        }
    }
    if ($updated) {
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } else {
        echo "- New code not found in data.json (maybe already updated?)\n";
    }
}

// 2. Update SQLite Database
try {
    // Find member IDs
    $stmt = $pdo->prepare("SELECT id FROM members WHERE permanent_code = ?");
    $stmt->execute([$oldCode]);
    $oldMemberId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id FROM members WHERE permanent_code = ?");
    $stmt->execute([$newCode]);
    $newMemberId = $stmt->fetchColumn();

    if ($oldMemberId && $newMemberId) {
        // Both exist in SQLite: Transfer everything to the old member and delete the new one
        
        // Fix registrations
        $pdo->prepare("UPDATE registrations SET member_id = ?, session_badge_token = ? WHERE member_id = ?")->execute([$oldMemberId, $oldCode, $newMemberId]);
        
        // Fix warnings, notes, participant logs if any exist
        $pdo->prepare("UPDATE warnings SET member_id = ? WHERE member_id = ?")->execute([$oldMemberId, $newMemberId]);
        $pdo->prepare("UPDATE notes SET member_id = ? WHERE member_id = ?")->execute([$oldMemberId, $newMemberId]);
        $pdo->prepare("UPDATE participants SET registration_code = ? WHERE registration_code = ?")->execute([$oldCode, $newCode]);
        
        // Migrate any profile data if it didn't exist in old one? 
        // We'll let the user update it normally, just point the new registration to the old member.
        
        // Delete the duplicate new member
        $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$newMemberId]);
        
        echo "- Merged SQLite records from member ID $newMemberId to $oldMemberId and deleted duplicate member.\n";
        
    } elseif ($newMemberId && !$oldMemberId) {
        // Only the new one exists in SQLite (old one was probably only in members.json)
        // Just change the code of the new SQLite member to the old code
        $pdo->prepare("UPDATE members SET permanent_code = ? WHERE id = ?")->execute([$oldCode, $newMemberId]);
        $pdo->prepare("UPDATE registrations SET session_badge_token = ? WHERE member_id = ?")->execute([$oldCode, $newMemberId]);
        $pdo->prepare("UPDATE participants SET registration_code = ? WHERE registration_code = ?")->execute([$oldCode, $newCode]);
        echo "- Updated SQLite member ID $newMemberId's permanent code to $oldCode.\n";
    } else {
        echo "- No SQLite changes needed or neither member found in SQLite.\n";
    }
} catch (Exception $e) {
    echo "- SQLite Error: " . $e->getMessage() . "\n";
}

// 3. Clean up members.json just in case the new code was placed there
$membersFile = 'admin/data/members.json';
if (file_exists($membersFile)) {
    $members = json_decode(file_get_contents($membersFile), true);
    if (isset($members[$newCode])) {
        unset($members[$newCode]);
        file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "- Removed duplicated new code from members.json.\n";
    }
}

// Also trigger a member.json sync to ensure the old code profile has the updated car/registration info
require_once 'services/MemberService.php';
if (isset($oldMemberId) && $oldMemberId) {
    MemberService::syncToJson($oldMemberId);
    echo "- Synced MemberService data to members.json for member $oldMemberId.\n";
} elseif (isset($newMemberId) && $newMemberId && !$oldMemberId) {
    MemberService::syncToJson($newMemberId);
    echo "- Synced MemberService data to members.json for member $newMemberId.\n";
}

echo "\nRepair completed successfully.\n";
echo "You can now delete this script.\n";
echo "</pre>";
?>
