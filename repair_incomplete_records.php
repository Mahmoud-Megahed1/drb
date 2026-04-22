<?php
/**
 * Repair Incomplete Records in data.json
 * 
 * This script finds records with missing fields and fills them from:
 * 1. members.json (by registration_code)
 * 2. SQLite database (by phone or registration_code)
 * 
 * Run once on the live server to fix existing corrupt records.
 * Usage: php repair_incomplete_records.php
 */

echo "=== Repair Incomplete Records ===\n\n";

// Load data.json
$dataFile = __DIR__ . '/admin/data/data.json';
if (!file_exists($dataFile)) {
    die("ERROR: data.json not found!\n");
}

$data = json_decode(file_get_contents($dataFile), true);
if (!is_array($data)) {
    die("ERROR: data.json is invalid!\n");
}

// Backup first
$backupFile = $dataFile . '.backup_' . date('Ymd_His');
copy($dataFile, $backupFile);
echo "✅ Backup created: " . basename($backupFile) . "\n\n";

// Load members.json
$membersFile = __DIR__ . '/admin/data/members.json';
$members = [];
if (file_exists($membersFile)) {
    $members = json_decode(file_get_contents($membersFile), true) ?? [];
}
echo "Loaded " . count($members) . " members from members.json\n";

// Load SQLite database
$dbMembers = [];
try {
    require_once __DIR__ . '/include/db.php';
    $pdo = db();
    
    // Get all members with their registrations
    $stmt = $pdo->query("
        SELECT m.*, r.car_type as reg_car_type, r.car_year as reg_car_year, 
               r.car_color as reg_car_color, r.engine_size as reg_engine_size,
               r.plate_governorate as reg_plate_gov, r.plate_letter as reg_plate_let,
               r.plate_number as reg_plate_num, r.participation_type as reg_participation,
               r.personal_photo as reg_photo, r.front_image as reg_front, r.back_image as reg_back
        FROM members m
        LEFT JOIN registrations r ON r.member_id = m.id AND r.is_active = 1
        ORDER BY r.created_at DESC
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code = $row['permanent_code'] ?? '';
        $phone = $row['phone'] ?? '';
        if (!empty($code)) $dbMembers['code_' . $code] = $row;
        if (!empty($phone)) $dbMembers['phone_' . substr(preg_replace('/\D/', '', $phone), -10)] = $row;
    }
    echo "Loaded " . count($dbMembers) . " DB entries\n";
} catch (Exception $e) {
    echo "⚠️ Could not load SQLite DB: " . $e->getMessage() . "\n";
}

echo "\nScanning " . count($data) . " records...\n\n";

// Critical fields that the dashboard needs
$criticalFields = ['car_type', 'car_year', 'car_color', 'governorate', 'registration_date'];
$repaired = 0;
$unrepairable = 0;

foreach ($data as $idx => &$record) {
    // Check if record is missing critical fields
    $missingFields = [];
    foreach ($criticalFields as $field) {
        if (empty($record[$field])) {
            $missingFields[] = $field;
        }
    }
    if (empty($record['images']) || !is_array($record['images']) || count($record['images']) === 0) {
        $missingFields[] = 'images';
    }
    
    if (empty($missingFields)) continue; // Record is complete
    
    $wasel = $record['wasel'] ?? '?';
    $name = $record['full_name'] ?? $record['name'] ?? '?';
    echo "⚠️ Record #$wasel ($name) missing: " . implode(', ', $missingFields) . "\n";
    
    // Try to find member data to fill in
    $memberData = null;
    $dbData = null;
    
    // 1. Try by registration_code in members.json
    $code = $record['registration_code'] ?? '';
    if (!empty($code) && isset($members[$code])) {
        $memberData = $members[$code];
    }
    
    // 2. Try by registration_code in DB
    if (!empty($code) && isset($dbMembers['code_' . $code])) {
        $dbData = $dbMembers['code_' . $code];
    }
    
    // 3. Try by phone in DB
    if (!$dbData) {
        $phone = preg_replace('/\D/', '', $record['phone'] ?? '');
        $phoneLast10 = substr($phone, -10);
        if (!empty($phoneLast10) && isset($dbMembers['phone_' . $phoneLast10])) {
            $dbData = $dbMembers['phone_' . $phoneLast10];
        }
    }
    
    $fixed = [];
    
    // Fill missing fields from member data or DB
    $sources = [$memberData, $dbData];
    
    // car_type
    if (empty($record['car_type'])) {
        foreach ($sources as $src) {
            $val = $src['car_type'] ?? $src['reg_car_type'] ?? '';
            if (!empty($val)) { $record['car_type'] = $val; $fixed[] = 'car_type'; break; }
        }
    }
    
    // car_year
    if (empty($record['car_year'])) {
        foreach ($sources as $src) {
            $val = $src['car_year'] ?? $src['reg_car_year'] ?? '';
            if (!empty($val)) { $record['car_year'] = $val; $fixed[] = 'car_year'; break; }
        }
    }
    
    // car_color
    if (empty($record['car_color'])) {
        foreach ($sources as $src) {
            $val = $src['car_color'] ?? $src['reg_car_color'] ?? '';
            if (!empty($val)) { $record['car_color'] = $val; $fixed[] = 'car_color'; break; }
        }
    }
    
    // engine_size
    if (empty($record['engine_size'])) {
        foreach ($sources as $src) {
            $val = $src['engine_size'] ?? $src['reg_engine_size'] ?? '';
            if (!empty($val)) { $record['engine_size'] = $val; $fixed[] = 'engine_size'; break; }
        }
    }
    
    // governorate
    if (empty($record['governorate'])) {
        foreach ($sources as $src) {
            $val = $src['governorate'] ?? '';
            if (!empty($val)) { $record['governorate'] = $val; $fixed[] = 'governorate'; break; }
        }
    }
    
    // plate fields
    if (empty($record['plate_letter'])) {
        foreach ($sources as $src) {
            $val = $src['plate_letter'] ?? $src['reg_plate_let'] ?? '';
            if (!empty($val)) { $record['plate_letter'] = $val; $fixed[] = 'plate_letter'; break; }
        }
    }
    if (empty($record['plate_number'])) {
        foreach ($sources as $src) {
            $val = $src['plate_number'] ?? $src['reg_plate_num'] ?? '';
            if (!empty($val)) { $record['plate_number'] = $val; $fixed[] = 'plate_number'; break; }
        }
    }
    if (empty($record['plate_governorate'])) {
        foreach ($sources as $src) {
            $val = $src['plate_governorate'] ?? $src['reg_plate_gov'] ?? '';
            if (!empty($val)) { $record['plate_governorate'] = $val; $fixed[] = 'plate_governorate'; break; }
        }
    }
    
    // participation_type
    if (empty($record['participation_type'])) {
        foreach ($sources as $src) {
            $val = $src['participation_type'] ?? $src['reg_participation'] ?? '';
            if (!empty($val)) { $record['participation_type'] = $val; $fixed[] = 'participation_type'; break; }
        }
    }
    
    // country_code
    if (empty($record['country_code'])) {
        $record['country_code'] = '+964';
        $fixed[] = 'country_code';
    }
    
    // registration_date
    if (empty($record['registration_date'])) {
        $record['registration_date'] = $record['entry_time'] ?? date('Y-m-d H:i:s');
        $fixed[] = 'registration_date';
    }
    
    // images
    if (empty($record['images']) || !is_array($record['images']) || count($record['images']) === 0) {
        $images = [];
        // Try from memberData
        if ($memberData && !empty($memberData['images'])) {
            $images = $memberData['images'];
        }
        if (!empty($images)) {
            $record['images'] = array_filter($images); // Remove empty paths
            $fixed[] = 'images';
        }
    }
    
    // register_type
    if (empty($record['register_type'])) {
        $record['register_type'] = 'returning';
        $record['register_type_label'] = 'مسجل قديم';
        $fixed[] = 'register_type';
    }
    
    if (!empty($fixed)) {
        echo "   ✅ Fixed: " . implode(', ', $fixed) . "\n";
        $repaired++;
    } else {
        echo "   ❌ Could not repair (no source data found)\n";
        $unrepairable++;
    }
}

// Save
file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n=== Results ===\n";
echo "Total records: " . count($data) . "\n";
echo "Repaired: $repaired\n";
echo "Unrepairable: $unrepairable\n";
echo "Backup: " . basename($backupFile) . "\n";
echo "\nDone! ✅\n";
