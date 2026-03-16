<?php
/**
 * REPAIR SCRIPT: Fix corrupted members.json by rebuilding it from data.json
 * 
 * This script:
 * 1. Reads data.json (current registrations with correct images)
 * 2. Rebuilds members.json with correct image-to-participant mapping
 * 3. Updates SQLite members table with correct image paths
 * 4. Reports what was fixed
 * 
 * RUN THIS ONCE on the server, then delete it.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre style='direction:rtl; font-family:monospace; font-size:14px; padding:20px;'>\n";
echo "========================================\n";
echo "  REPAIR: Fixing Image-Participant Mapping\n";
echo "========================================\n\n";

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/helpers.php';

$pdo = db();

// Ensure columns exist
$addCols = [
    "ALTER TABLE members ADD COLUMN national_id_front TEXT",
    "ALTER TABLE members ADD COLUMN national_id_back TEXT",
    "ALTER TABLE members ADD COLUMN personal_photo TEXT",
    "ALTER TABLE members ADD COLUMN last_car_type TEXT",
    "ALTER TABLE members ADD COLUMN last_car_year TEXT",
    "ALTER TABLE members ADD COLUMN last_car_color TEXT",
    "ALTER TABLE members ADD COLUMN last_engine_size TEXT",
    "ALTER TABLE members ADD COLUMN last_plate_letter TEXT",
    "ALTER TABLE members ADD COLUMN last_plate_number TEXT",
    "ALTER TABLE members ADD COLUMN last_plate_governorate TEXT",
    "ALTER TABLE members ADD COLUMN last_participation_type TEXT",
    "ALTER TABLE members ADD COLUMN instagram TEXT",
];
foreach ($addCols as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) { /* already exists */ }
}

// 1. Read data.json
$dataFile = __DIR__ . '/admin/data/data.json';
if (!file_exists($dataFile)) {
    die("ERROR: data.json not found!\n");
}

$data = json_decode(file_get_contents($dataFile), true);
if (!is_array($data)) {
    die("ERROR: data.json is invalid!\n");
}

echo "Found " . count($data) . " registrations in data.json\n\n";

// 2. Read current members.json (backup first)
$membersFile = __DIR__ . '/admin/data/members.json';
$membersBackup = __DIR__ . '/admin/data/members_backup_' . date('Y-m-d_H-i-s') . '.json';

if (file_exists($membersFile)) {
    copy($membersFile, $membersBackup);
    echo "Backed up members.json to: " . basename($membersBackup) . "\n\n";
}

// 3. Build a NEW clean members.json from data.json
// Key = phone number (normalized) -> participant data
// This ensures each participant maps to THEIR OWN images

$phoneToMember = []; // phone -> member data from SQLite
$repaired = 0;
$errors = 0;

// First, fetch ALL members from SQLite indexed by phone
$stmt = $pdo->query("SELECT * FROM members WHERE is_active = 1");
$allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($allMembers as $m) {
    $phoneToMember[$m['phone']] = $m;
}

echo "Found " . count($allMembers) . " members in SQLite database\n\n";

// Now rebuild members.json
$newMembersJson = [];

foreach ($data as $idx => $reg) {
    $phone = $reg['phone'] ?? '';
    if (empty($phone)) continue;
    
    // Normalize phone
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($cleanPhone) < 8) continue;
    
    // Try Iraqi phone normalization
    if (str_starts_with($cleanPhone, '964')) {
        $cleanPhone = substr($cleanPhone, 3);
    }
    if (strlen($cleanPhone) === 11 && str_starts_with($cleanPhone, '07')) {
        $cleanPhone = substr($cleanPhone, 1);
    }
    
    $name = $reg['full_name'] ?? $reg['name'] ?? 'Unknown';
    $wasel = $reg['wasel'] ?? ($idx + 1);
    
    echo "[$wasel] $name (phone: $cleanPhone)...\n";
    
    // Find their SQLite member record
    $sqlMember = null;
    if (isset($phoneToMember[$cleanPhone])) {
        $sqlMember = $phoneToMember[$cleanPhone];
    } else {
        // Try to find by similar phone
        foreach ($phoneToMember as $p => $m) {
            $pLast10 = substr($p, -10);
            $cLast10 = substr($cleanPhone, -10);
            if ($pLast10 === $cLast10 && strlen($pLast10) >= 10) {
                $sqlMember = $m;
                break;
            }
        }
    }
    
    // Determine the permanent_code (key for members.json)
    $pCode = '';
    if ($sqlMember) {
        $pCode = $sqlMember['permanent_code'] ?? '';
    }
    if (empty($pCode)) {
        // Use registration_code as fallback
        $pCode = $reg['registration_code'] ?? '';
    }
    if (empty($pCode)) {
        echo "  ⚠ SKIP: No permanent_code found\n";
        $errors++;
        continue;
    }
    
    // Extract images from THIS participant's data.json entry
    $images = [];
    $imageKeys = ['personal_photo', 'front_image', 'side_image', 'back_image', 
                  'edited_image', 'acceptance_image', 'id_front', 'id_back', 
                  'national_id_front', 'national_id_back', 'license_front', 'license_back'];
    
    foreach ($imageKeys as $key) {
        // Check direct field first, then images sub-array
        $val = null;
        if (isset($reg['images']) && is_array($reg['images']) && !empty($reg['images'][$key])) {
            $val = $reg['images'][$key];
        } elseif (!empty($reg[$key])) {
            $val = $reg[$key];
        }
        
        if (!empty($val)) {
            $images[$key] = $val;
            // Also set aliases
            if ($key === 'id_front') $images['national_id_front'] = $val;
            if ($key === 'id_back') $images['national_id_back'] = $val;
            if ($key === 'national_id_front' && empty($images['id_front'])) $images['id_front'] = $val;
            if ($key === 'national_id_back' && empty($images['id_back'])) $images['id_back'] = $val;
        }
    }
    
    // Build the clean member record
    $newMembersJson[$pCode] = [
        'registration_code' => $pCode,
        'full_name' => $name,
        'phone' => $cleanPhone,
        'country_code' => $reg['country_code'] ?? '+964',
        'governorate' => $reg['governorate'] ?? '',
        'instagram' => $reg['instagram'] ?? '',
        'car_type' => $reg['car_type'] ?? '',
        'car_year' => $reg['car_year'] ?? '',
        'car_color' => $reg['car_color'] ?? '',
        'engine_size' => $reg['engine_size'] ?? '',
        'plate_number' => $reg['plate_number'] ?? '',
        'plate_letter' => $reg['plate_letter'] ?? '',
        'plate_governorate' => $reg['plate_governorate'] ?? '',
        'plate_full' => ($reg['plate_letter'] ?? '') . ' ' . ($reg['plate_number'] ?? '') . ' - ' . ($reg['plate_governorate'] ?? ''),
        'participation_type' => $reg['participation_type'] ?? '',
        'personal_photo' => $images['personal_photo'] ?? '',
        'images' => $images,
        'last_active' => date('Y-m-d H:i:s'),
    ];
    
    $imgCount = count(array_filter($images));
    echo "  ✅ Code: $pCode | Images: $imgCount\n";
    
    // Also update SQLite member record with images
    if ($sqlMember) {
        try {
            $pdo->prepare("
                UPDATE members SET 
                    personal_photo = COALESCE(NULLIF(?, ''), personal_photo),
                    national_id_front = COALESCE(NULLIF(?, ''), national_id_front),
                    national_id_back = COALESCE(NULLIF(?, ''), national_id_back),
                    last_car_type = COALESCE(NULLIF(?, ''), last_car_type),
                    last_car_year = COALESCE(NULLIF(?, ''), last_car_year),
                    last_car_color = COALESCE(NULLIF(?, ''), last_car_color),
                    last_engine_size = COALESCE(NULLIF(?, ''), last_engine_size),
                    last_plate_letter = COALESCE(NULLIF(?, ''), last_plate_letter),
                    last_plate_number = COALESCE(NULLIF(?, ''), last_plate_number),
                    last_plate_governorate = COALESCE(NULLIF(?, ''), last_plate_governorate),
                    last_participation_type = COALESCE(NULLIF(?, ''), last_participation_type)
                WHERE id = ?
            ")->execute([
                $images['personal_photo'] ?? '',
                $images['id_front'] ?? $images['national_id_front'] ?? '',
                $images['id_back'] ?? $images['national_id_back'] ?? '',
                $reg['car_type'] ?? '',
                $reg['car_year'] ?? '',
                $reg['car_color'] ?? '',
                $reg['engine_size'] ?? '',
                $reg['plate_letter'] ?? '',
                $reg['plate_number'] ?? '',
                $reg['plate_governorate'] ?? '',
                $reg['participation_type'] ?? '',
                $sqlMember['id']
            ]);
            echo "  ✅ SQLite member #" . $sqlMember['id'] . " updated\n";
        } catch (Exception $e) {
            echo "  ⚠ SQLite update failed: " . $e->getMessage() . "\n";
        }
    }
    
    $repaired++;
}

// 4. Save the new clean members.json
$lockFile = fopen(__DIR__ . '/admin/data/members.lock', 'w');
if ($lockFile) flock($lockFile, LOCK_EX);

file_put_contents($membersFile, json_encode($newMembersJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if ($lockFile) { flock($lockFile, LOCK_UN); fclose($lockFile); }

echo "\n========================================\n";
echo "  RESULTS\n";
echo "========================================\n";
echo "Repaired: $repaired participants\n";
echo "Errors: $errors participants\n";
echo "members.json rebuilt with " . count($newMembersJson) . " entries\n";
echo "Backup saved: " . basename($membersBackup) . "\n";
echo "\n✅ REPAIR COMPLETE!\n";
echo "\n⚠ DELETE THIS FILE (repair_data.php) from the server after running!\n";
echo "</pre>\n";
?>
