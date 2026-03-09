<?php
/**
 * pull_images.php V3 - DEFINITIVE Image & Data Sync
 * ==================================================
 * Pulls correct images for each member by matching registration timestamps
 * with image upload timestamps. Also syncs all car data to member profiles.
 * 
 * Fixed: Uses DIRECT registration → member link from SQLite
 * Fixed: Syncs car data (type, year, color, engine, plate) to member profiles
 * Fixed: Uses Iraq timezone for all date comparisons
 */

date_default_timezone_set('Asia/Baghdad');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/include/db.php';

echo "🔧 Pull Images V3 - تزامن الصور والبيانات الشامل\n";
echo str_repeat('━', 50) . "\n\n";

$pdo = db();
$uploadsDir = __DIR__ . '/uploads/';

// ========================================
// STEP 1: Get ALL members from SQLite
// ========================================
echo "[1] جلب الأعضاء من قاعدة البيانات...\n";
$members = $pdo->query("SELECT * FROM members WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
echo "    عدد الأعضاء: " . count($members) . "\n\n";

// ========================================
// STEP 2: Get ALL registrations with their member links
// ========================================
echo "[2] جلب التسجيلات مع ربط الأعضاء...\n";
$regs = $pdo->query("
    SELECT r.*, m.permanent_code, m.phone as member_phone, m.name as member_name
    FROM registrations r
    JOIN members m ON r.member_id = m.id
    WHERE r.is_active = 1
    ORDER BY r.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
echo "    عدد التسجيلات: " . count($regs) . "\n";

// Build member_code → wasels map
$codeToWasels = [];
$codeToLatestReg = [];
foreach ($regs as $reg) {
    $code = $reg['permanent_code'];
    $wasel = $reg['wasel'];
    if (!isset($codeToWasels[$code])) $codeToWasels[$code] = [];
    $codeToWasels[$code][] = $wasel;
    
    // Keep track of latest registration per member
    if (!isset($codeToLatestReg[$code])) {
        $codeToLatestReg[$code] = $reg;
    }
}
echo "    أعضاء لهم تسجيلات: " . count($codeToWasels) . "\n\n";

// ========================================
// STEP 3: Also check data.json for additional wasel mappings  
// ========================================
echo "[3] فحص data.json و members.json للمزيد من الربط...\n";
$dataFile = __DIR__ . '/admin/data/data.json';
$membersJsonFile = __DIR__ . '/admin/data/members.json';

$jsonData = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) ?? [] : [];
$membersJson = file_exists($membersJsonFile) ? json_decode(file_get_contents($membersJsonFile), true) ?? [] : [];

// Build phone → code mapping from ALL sources
$phoneToCode = [];
foreach ($members as $m) {
    $phone = substr(preg_replace('/[^0-9]/', '', $m['phone'] ?? ''), -10);
    if (!empty($phone)) {
        $phoneToCode[$phone] = $m['permanent_code'];
    }
}

// Add mappings from data.json
$extraWasels = 0;
foreach ($jsonData as $item) {
    $regCode = $item['registration_code'] ?? '';
    $wasel = $item['wasel'] ?? '';
    $phone = substr(preg_replace('/[^0-9]/', '', $item['phone'] ?? ''), -10);
    
    // Try to find the member code
    $code = $regCode;
    if (!empty($phone) && isset($phoneToCode[$phone])) {
        $code = $phoneToCode[$phone];
    }
    
    if (!empty($code) && !empty($wasel)) {
        if (!isset($codeToWasels[$code])) $codeToWasels[$code] = [];
        if (!in_array($wasel, $codeToWasels[$code])) {
            $codeToWasels[$code][] = $wasel;
            $extraWasels++;
        }
    }
}

// Check backup files for more wasels
$backupFiles = glob(__DIR__ . '/admin/data/data_backup_*.json') ?: [];
$backupFiles = array_merge($backupFiles, glob(__DIR__ . '/admin/data/archives/*.json') ?: []);
foreach ($backupFiles as $bf) {
    $bData = json_decode(file_get_contents($bf), true);
    if (!is_array($bData)) continue;
    if (isset($bData['data'])) $bData = $bData['data']; // archives format
    
    foreach ($bData as $item) {
        if (!is_array($item)) continue;
        $wasel = $item['wasel'] ?? '';
        $phone = substr(preg_replace('/[^0-9]/', '', $item['phone'] ?? ''), -10);
        
        if (!empty($phone) && !empty($wasel) && isset($phoneToCode[$phone])) {
            $code = $phoneToCode[$phone];
            if (!isset($codeToWasels[$code])) $codeToWasels[$code] = [];
            if (!in_array($wasel, $codeToWasels[$code])) {
                $codeToWasels[$code][] = $wasel;
                $extraWasels++;
            }
        }
    }
}
echo "    واصلات إضافية من JSON/backups: $extraWasels\n\n";

// ========================================
// STEP 4: Scan uploads folder and group by wasel
// ========================================
echo "[4] فحص مجلد الصور (uploads/)...\n";
$allFiles = [];
if (is_dir($uploadsDir)) {
    // Scan recursively - images are in monthly subdirs like uploads/2026-02/
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) continue;
        $f = $fileInfo->getFilename();
        // Pattern: {wasel}_{type}_{timestamp}.{ext}
        if (preg_match('/^(\d+)_(.+?)_(\d+)\.(jpg|jpeg|png|gif|webp)$/i', $f, $m)) {
            // Build relative path from site root
            $relPath = str_replace(__DIR__ . '/', '', $fileInfo->getPathname());
            $relPath = str_replace('\\', '/', $relPath);
            $allFiles[] = [
                'filename' => $f,
                'wasel' => $m[1],
                'type' => $m[2],
                'timestamp' => intval($m[3]),
                'path' => $relPath
            ];
        }
    }
}
echo "    إجمالي الملفات المتطابقة: " . count($allFiles) . "\n";

$waselImages = [];
foreach ($allFiles as $img) {
    $w = $img['wasel'];
    if (!isset($waselImages[$w])) $waselImages[$w] = [];
    $waselImages[$w][] = $img;
}
echo "    واصلات فريدة لها صور: " . count($waselImages) . "\n\n";

// ========================================
// STEP 5: Assign images to members - ONLY their own
// ========================================
echo "[5] بدء الربط الدقيق...\n";

$membersUpdated = 0;
$membersWithImages = 0;
$totalImagesAssigned = 0;

// Image type priority (latest 7)
$imageTypes = ['front_image', 'back_image', 'id_front', 'id_back', 'personal_photo', 'license_front', 'license_back', 'national_id_front', 'national_id_back', 'side_image', 'edited_image'];

foreach ($members as $member) {
    $code = $member['permanent_code'];
    $memberId = $member['id'];
    
    // Get all wasels belonging to this member
    $myWasels = $codeToWasels[$code] ?? [];
    
    if (empty($myWasels)) continue;
    
    // Collect ALL images from all wasels belonging to this member
    $myImages = [];
    foreach ($myWasels as $w) {
        if (isset($waselImages[$w])) {
            foreach ($waselImages[$w] as $img) {
                $myImages[] = $img;
            }
        }
    }
    
    if (empty($myImages)) continue;
    
    // Sort by timestamp DESC (newest first)
    usort($myImages, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    
    // Group by type, keep only latest
    $latestByType = [];
    foreach ($myImages as $img) {
        $type = $img['type'];
        // Normalize type names
        if ($type === 'national_id_front') $type = 'id_front';
        if ($type === 'national_id_back') $type = 'id_back';
        
        if (!isset($latestByType[$type])) {
            $latestByType[$type] = $img;
        }
    }
    
    // Take up to 7 latest unique images
    $finalImages = array_slice($latestByType, 0, 7, true);
    $imageCount = count($finalImages);
    
    if ($imageCount > 0) {
        $membersWithImages++;
        $totalImagesAssigned += $imageCount;
        
        // Update SQLite member record with images
        $personalPhoto = $finalImages['personal_photo']['path'] ?? '';
        $idFront = $finalImages['id_front']['path'] ?? '';
        $idBack = $finalImages['id_back']['path'] ?? '';
        
        $pdo->prepare("UPDATE members SET personal_photo = ?, national_id_front = ?, national_id_back = ? WHERE id = ?")
            ->execute([$personalPhoto, $idFront, $idBack, $memberId]);
        
        $membersUpdated++;
    }
}

echo "    ✓ أعضاء تم تحديث صورهم: $membersUpdated\n";
echo "    ✓ أعضاء لهم صور: $membersWithImages\n";
echo "    ✓ إجمالي صور مُعيَّنة: $totalImagesAssigned\n\n";

// ========================================
// STEP 6: Sync car data from latest registration to member profile
// ========================================
echo "[6] تزامن بيانات السيارة + تحديث registrations...\\n";

// Also update registrations table with images (for members who have registrations)
$regStmt = $pdo->prepare("SELECT r.id, r.member_id, r.wasel, m.permanent_code FROM registrations r JOIN members m ON r.member_id = m.id WHERE r.is_active = 1");
$regStmt->execute();
$activeRegs = $regStmt->fetchAll(PDO::FETCH_ASSOC);
$regsUpdated = 0;

foreach ($activeRegs as $areg) {
    $aCode = $areg['permanent_code'];
    $aWasels = $codeToWasels[$aCode] ?? [];
    
    // Find images for this member's wasels
    $memberImgs = [];
    foreach ($aWasels as $w) {
        if (isset($waselImages[$w])) {
            $memberImgs = array_merge($memberImgs, $waselImages[$w]);
        }
    }
    if (empty($memberImgs)) continue;
    
    usort($memberImgs, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    
    $imgMap = [];
    foreach ($memberImgs as $img) {
        $t = $img['type'];
        if ($t === 'national_id_front') $t = 'id_front';
        if ($t === 'national_id_back') $t = 'id_back';
        if (!isset($imgMap[$t])) $imgMap[$t] = $img['path'];
    }
    
    $pdo->prepare("UPDATE registrations SET 
        personal_photo = COALESCE(NULLIF(?, ''), personal_photo),
        front_image = COALESCE(NULLIF(?, ''), front_image),
        side_image = COALESCE(NULLIF(?, ''), side_image),
        back_image = COALESCE(NULLIF(?, ''), back_image),
        license_front = COALESCE(NULLIF(?, ''), license_front),
        license_back = COALESCE(NULLIF(?, ''), license_back)
        WHERE id = ?")->execute([
        $imgMap['personal_photo'] ?? '',
        $imgMap['front_image'] ?? '',
        $imgMap['side_image'] ?? '',
        $imgMap['back_image'] ?? '',
        $imgMap['license_front'] ?? '',
        $imgMap['license_back'] ?? '',
        $areg['id']
    ]);
    $regsUpdated++;
}
echo "    ✓ تم تحديث $regsUpdated سجل في registrations\\n";
$carDataSynced = 0;

foreach ($codeToLatestReg as $code => $latestReg) {
    $carType = $latestReg['car_type'] ?? '';
    $carYear = $latestReg['car_year'] ?? '';
    $carColor = $latestReg['car_color'] ?? '';
    $engineSize = $latestReg['engine_size'] ?? '';
    $partType = $latestReg['participation_type'] ?? '';
    $plateNum = $latestReg['plate_number'] ?? '';
    $plateLet = $latestReg['plate_letter'] ?? '';
    $plateGov = $latestReg['plate_governorate'] ?? '';
    
    // Only update if we have car data
    if (!empty($carType) || !empty($carYear)) {
        $pdo->prepare("
            UPDATE members SET 
                last_car_type = COALESCE(NULLIF(?, ''), last_car_type),
                last_car_year = COALESCE(NULLIF(?, ''), last_car_year),
                last_car_color = COALESCE(NULLIF(?, ''), last_car_color),
                last_engine_size = COALESCE(NULLIF(?, ''), last_engine_size),
                last_participation_type = COALESCE(NULLIF(?, ''), last_participation_type),
                last_plate_number = COALESCE(NULLIF(?, ''), last_plate_number),
                last_plate_letter = COALESCE(NULLIF(?, ''), last_plate_letter),
                last_plate_governorate = COALESCE(NULLIF(?, ''), last_plate_governorate)
            WHERE permanent_code = ?
        ")->execute([$carType, $carYear, $carColor, $engineSize, $partType, $plateNum, $plateLet, $plateGov, $code]);
        $carDataSynced++;
    }
}

// Also sync from data.json (current championship)
foreach ($jsonData as $item) {
    $regCode = $item['registration_code'] ?? '';
    $phone = substr(preg_replace('/[^0-9]/', '', $item['phone'] ?? ''), -10);
    
    $code = $regCode;
    if (!empty($phone) && isset($phoneToCode[$phone])) {
        $code = $phoneToCode[$phone];
    }
    
    if (empty($code)) continue;
    
    $carType = $item['car_type'] ?? '';
    $carYear = $item['car_year'] ?? '';
    if (!empty($carType) || !empty($carYear)) {
        $pdo->prepare("
            UPDATE members SET 
                last_car_type = COALESCE(NULLIF(?, ''), last_car_type),
                last_car_year = COALESCE(NULLIF(?, ''), last_car_year),
                last_car_color = COALESCE(NULLIF(?, ''), last_car_color),
                last_engine_size = COALESCE(NULLIF(?, ''), last_engine_size),
                last_participation_type = COALESCE(NULLIF(?, ''), last_participation_type),
                last_plate_number = COALESCE(NULLIF(?, ''), last_plate_number),
                last_plate_letter = COALESCE(NULLIF(?, ''), last_plate_letter),
                last_plate_governorate = COALESCE(NULLIF(?, ''), last_plate_governorate)
            WHERE permanent_code = ?
        ")->execute([
            $carType, $carYear ?? '', 
            $item['car_color'] ?? '', $item['engine_size'] ?? '', 
            $item['participation_type'] ?? '',
            $item['plate_number'] ?? '', $item['plate_letter'] ?? '', 
            $item['plate_governorate'] ?? '', $code
        ]);
        $carDataSynced++;
    }
}

echo "    ✓ تم تزامن بيانات السيارة لـ $carDataSynced عضو\n\n";

// ========================================
// STEP 7: Rebuild members.json
// ========================================
echo "[7] إعادة بناء members.json...\n";

$allMembers = $pdo->query("SELECT * FROM members WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
$newMembersJson = [];

foreach ($allMembers as $m) {
    $code = $m['permanent_code'];
    $wasels = $codeToWasels[$code] ?? [];
    
    // Collect images for this member
    $images = [];
    foreach ($wasels as $w) {
        if (isset($waselImages[$w])) {
            foreach ($waselImages[$w] as $img) {
                $type = $img['type'];
                if ($type === 'national_id_front') $type = 'id_front';
                if ($type === 'national_id_back') $type = 'id_back';
                if (!isset($images[$type])) {
                    $images[$type] = $img['path'];
                }
            }
        }
    }
    
    // Sort by timestamp to get latest
    $memberImgs = [];
    foreach ($wasels as $w) {
        if (isset($waselImages[$w])) {
            $memberImgs = array_merge($memberImgs, $waselImages[$w]);
        }
    }
    usort($memberImgs, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    
    $latestImages = [];
    foreach ($memberImgs as $img) {
        $type = $img['type'];
        if ($type === 'national_id_front') $type = 'id_front';
        if ($type === 'national_id_back') $type = 'id_back';
        if (!isset($latestImages[$type])) {
            $latestImages[$type] = $img['path'];
        }
    }
    
    $newMembersJson[$code] = [
        'registration_code' => $code,
        'full_name' => $m['name'] ?? '',
        'phone' => $m['phone'] ?? '',
        'country_code' => '+964',
        'governorate' => $m['governorate'] ?? '',
        'car_type' => $m['last_car_type'] ?? '',
        'car_year' => $m['last_car_year'] ?? '',
        'car_color' => $m['last_car_color'] ?? '',
        'engine_size' => $m['last_engine_size'] ?? '',
        'plate_letter' => $m['last_plate_letter'] ?? '',
        'plate_number' => $m['last_plate_number'] ?? '',
        'plate_governorate' => $m['last_plate_governorate'] ?? '',
        'participation_type' => $m['last_participation_type'] ?? '',
        'wasel' => $wasels[0] ?? '',
        'all_wasels' => $wasels,
        'images' => $latestImages,
        'instagram' => $m['instagram'] ?? '',
        'championships_participated' => $m['total_championships'] ?? 1,
        'last_active' => $m['updated_at'] ?? date('Y-m-d H:i:s')
    ];
}

file_put_contents($membersJsonFile, json_encode($newMembersJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "    ✓ تم إعادة بناء members.json: " . count($newMembersJson) . " عضو\n";

// Count members with images
$withImages = 0;
$withCarData = 0;
foreach ($newMembersJson as $m) {
    if (!empty($m['images'])) $withImages++;
    if (!empty($m['car_type'])) $withCarData++;
}
echo "    ✓ أعضاء لهم صور: $withImages\n";
echo "    ✓ أعضاء لهم بيانات سيارة: $withCarData\n\n";

// ========================================
// STEP 8: Update data.json to match
// ========================================
echo "[8] تحديث data.json (الداشبورد)...\n";
$updatedInDash = 0;

foreach ($jsonData as &$item) {
    $regCode = $item['registration_code'] ?? '';
    if (isset($newMembersJson[$regCode])) {
        $memberData = $newMembersJson[$regCode];
        // Sync images
        if (!empty($memberData['images'])) {
            $item['images'] = array_merge($item['images'] ?? [], $memberData['images']);
            $updatedInDash++;
        }
    }
}
unset($item);

file_put_contents($dataFile, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "    ✓ تم تحديث $updatedInDash سجل في data.json\n\n";

echo str_repeat('━', 50) . "\n";
echo "✅ تم الانتهاء بنجاح!\n";
echo "📊 الملخص:\n";
echo "   - أعضاء في DB: " . count($members) . "\n";
echo "   - أعضاء لهم صور صحيحة: $withImages\n";
echo "   - أعضاء لهم بيانات سيارة: $withCarData\n";
echo "   - تم تزامن الصور لـ $membersUpdated عضو\n";
echo "   - تم تزامن بيانات السيارة لـ $carDataSynced عضو\n";