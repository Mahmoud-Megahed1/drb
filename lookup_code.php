<?php
// API endpoint to lookup registration by code
header('Content-Type: application/json');

require_once __DIR__ . '/services/MemberService.php';

$code = $_GET['code'] ?? $_POST['code'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'الكود مطلوب']);
    exit;
}

// Clean the code
$code = strtoupper(trim($code));

// Use MemberService to get comprehensive profile (handles DB, JSON, and Virtual/Historical fallback)
$profile = MemberService::getProfile($code);

if (!$profile || empty($profile['member'])) {
    echo json_encode(['success' => false, 'error' => 'الكود غير موجود']);
    exit;
}

$member = $profile['member'];
$reg = $profile['current_registration']; // Might be the real current registration or a virtual/historical one

// Prepare mapping
$result = [
    'full_name' => $reg['full_name'] ?? $member['name'] ?? '',
    'phone' => $member['phone'] ?? $reg['phone'] ?? '',
    'country_code' => $reg['country_code'] ?? '+964',
    'governorate' => $member['governorate'] ?? $reg['governorate'] ?? '',
    'instagram' => $member['instagram'] ?? $reg['instagram'] ?? '',
    'car_type' => $reg['car_type'] ?? '',
    'car_year' => $reg['car_year'] ?? '',
    'car_color' => $reg['car_color'] ?? '',
    'engine_size' => $reg['engine_size'] ?? '',
    'plate_letter' => $reg['plate_letter'] ?? '',
    'plate_number' => $reg['plate_number'] ?? '',
    'plate_governorate' => $reg['plate_governorate'] ?? '',
    'participation_type' => $reg['participation_type'] ?? '',
    'plate_year' => $reg['plate_year'] ?? '', 
];

// --- IMAGES RESOLUTION ---
$images = [];
if (!empty($reg['images'])) {
    foreach ($reg['images'] as $key => $path) {
        if (!empty($path)) {
            $images[$key] = ltrim($path, './');
        }
    }
    
    // Explicit cross-mapping to ensure index.php always gets both formats
    // If we only have national_*, copy it to id_*
    if (empty($images['id_front']) && !empty($images['national_id_front'])) $images['id_front'] = $images['national_id_front'];
    if (empty($images['id_back']) && !empty($images['national_id_back'])) $images['id_back'] = $images['national_id_back'];
    
    // If we only have id_*, copy it to national_* (just to be safe)
    if (empty($images['national_id_front']) && !empty($images['id_front'])) $images['national_id_front'] = $images['id_front'];
    if (empty($images['national_id_back']) && !empty($images['id_back'])) $images['national_id_back'] = $images['id_back'];
    
    if (empty($images['personal_photo'])) {
        if (!empty($reg['personal_photo'])) {
            $images['personal_photo'] = ltrim($reg['personal_photo'], './');
        } elseif (!empty($member['personal_photo'])) {
            $images['personal_photo'] = ltrim($member['personal_photo'], './');
        }
    }
} else {
    // Fallback if images array is empty but we have direct fields
    if (!empty($reg['personal_photo'])) {
        $images['personal_photo'] = ltrim($reg['personal_photo'], './');
    } elseif (!empty($member['personal_photo'])) {
        $images['personal_photo'] = ltrim($member['personal_photo'], './');
    }
}

// Always ensure the root profile personal photo is accessible
if (empty($images['personal_photo']) && !empty($member['personal_photo'])) {
    $images['personal_photo'] = ltrim($member['personal_photo'], './');
}

// --- NORMALIZATION FOR IMPORTED DATA ---
// Fix Engine Size (Map Text to Value)
$engineMap = [
    '8 سلندر تنفس طبيعي' => '8_cylinder_natural',
    '8 سلندر بوست' => '8_cylinder_boost',
    '6 سلندر تنفس طبيعي' => '6_cylinder_natural',
    '6 سلندر بوست' => '6_cylinder_boost',
    '4 سلندر' => '4_cylinder',
    '4 سلندر بوست' => '4_cylinder_boost',
    'أخرى' => 'other'
];
if (isset($engineMap[$result['engine_size']])) {
    $result['engine_size'] = $engineMap[$result['engine_size']];
}

// Fix Participation Type (Map Text to Value)
$partMap = [
    'المشاركة بالاستعراض الحر' => 'free_show',
    'المشاركة كسيارة مميزة فقط بدون استعراض' => 'special_car',
    'المشاركة كسيارة مميزة فقط بدون النزول للاستعارض' => 'special_car',
    'المشاركة بفعالية Burnout (عدد محدود)' => 'burnout',
    'المشاركة بفعالية Burnout' => 'burnout'
];
if (!isset($partMap[$result['participation_type']])) {
    foreach ($partMap as $key => $val) {
        if (strpos($result['participation_type'] ?? '', $key) !== false) {
            $result['participation_type'] = $val;
            break;
        }
    }
} else {
    $result['participation_type'] = $partMap[$result['participation_type']];
}

// Fix Plate Swap (Heuristic: If Letter is numeric and Number is not)
if (is_numeric($result['plate_letter'] ?? '') && !is_numeric($result['plate_number'] ?? '')) {
    $temp = $result['plate_letter'];
    $result['plate_letter'] = $result['plate_number'];
    $result['plate_number'] = $temp;
}

// Fix Plate Governorate (Hamza Mismatch)
$govMap = [
    'اربيل' => 'أربيل',
    'الانبار' => 'الأنبار',
    'انبار' => 'الأنبار'
];
if (isset($result['plate_governorate']) && isset($govMap[$result['plate_governorate']])) {
    $result['plate_governorate'] = $govMap[$result['plate_governorate']];
}

// Return data
echo json_encode([
    'success' => true,
    'data' => array_merge($result, [
        'images' => $images,
        'is_registered_current' => $profile['is_registered_current'] ?? false,
        'current_wasel' => $reg['wasel'] ?? null,
        'registration_code' => $code
    ])
]);
exit;
?>
