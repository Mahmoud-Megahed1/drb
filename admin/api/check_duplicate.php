<?php
/**
 * Check Duplicate Registration
 * API ?????? ?? ??????? ??? ???????
 * 
 * ????? ??:
 * 1. ??? ??????
 * 2. ??? ??????
 */

header('Content-Type: application/json; charset=utf-8');

$phone = $_GET['phone'] ?? $_POST['phone'] ?? '';
$countryCode = $_GET['country_code'] ?? $_POST['country_code'] ?? '+964';
$plateNumber = $_GET['plate_number'] ?? $_POST['plate_number'] ?? '';
$plateLetter = $_GET['plate_letter'] ?? $_POST['plate_letter'] ?? '';
$plateGovernorate = $_GET['plate_governorate'] ?? $_POST['plate_governorate'] ?? '';

// Load data - Fixed paths
$dataFile = __DIR__ . '/../data/data.json';
$membersFile = __DIR__ . '/../data/members.json';

$registrations = [];
$members = [];

if (file_exists($dataFile)) {
    $registrations = json_decode(file_get_contents($dataFile), true) ?? [];
}

if (file_exists($membersFile)) {
    $members = json_decode(file_get_contents($membersFile), true) ?? [];
}

$duplicates = [
    'phone_duplicate' => false,
    'phone_exists_in' => null,
    'phone_member_name' => null,
    'plate_duplicate' => false,
    'plate_exists_in' => null,
    'plate_member_name' => null,
    'is_returning_member' => false,
    'member_code' => null
];

// Check phone
if (!empty($phone)) {
    // Normalize phone to 10 digits starting with 7
    $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($normalizedPhone, '964')) {
        $normalizedPhone = substr($normalizedPhone, 3);
    }
    if (strlen($normalizedPhone) === 11 && str_starts_with($normalizedPhone, '07')) {
        $normalizedPhone = substr($normalizedPhone, 1);
    }
    
    // Check in current registrations
    foreach ($registrations as $reg) {
        $regPhone = preg_replace('/[^0-9]/', '', $reg['phone'] ?? '');
        // Normalize regPhone for comparison
        if (str_starts_with($regPhone, '964')) {
            $regPhone = substr($regPhone, 3);
        }
        if (strlen($regPhone) === 11 && str_starts_with($regPhone, '07')) {
            $regPhone = substr($regPhone, 1);
        }
        
        if ($regPhone === $normalizedPhone) {
            $duplicates['phone_duplicate'] = true;
            $duplicates['phone_exists_in'] = 'registrations';
            $duplicates['phone_member_name'] = $reg['full_name'] ?? $reg['name'] ?? 'بيانات غير معروفة';
            $duplicates['phone_status'] = $reg['status'] ?? 'pending';
            $duplicates['phone_wasel'] = $reg['wasel'] ?? null;
            break;
        }
    }
    
    // Check in members database
    if (!$duplicates['phone_duplicate']) {
        foreach ($members as $code => $member) {
            $memberPhone = preg_replace('/[^0-9]/', '', $member['phone'] ?? '');
            // Normalize memberPhone for comparison
            if (str_starts_with($memberPhone, '964')) {
                $memberPhone = substr($memberPhone, 3);
            }
            if (strlen($memberPhone) === 11 && str_starts_with($memberPhone, '07')) {
                $memberPhone = substr($memberPhone, 1);
            }
            
            if ($memberPhone === $normalizedPhone) {
                $duplicates['is_returning_member'] = true;
                $duplicates['member_code'] = $code;
                $duplicates['phone_member_name'] = $member['full_name'] ?? $member['name'] ?? 'بيانات غير معروفة';
                break;
            }
        }
    }
}

// Check plate
if (!empty($plateNumber)) {
    foreach ($registrations as $reg) {
        if (
            ($reg['plate_number'] ?? '') == $plateNumber &&
            ($reg['plate_letter'] ?? '') == $plateLetter &&
            ($reg['plate_governorate'] ?? '') == $plateGovernorate
        ) {
            $duplicates['plate_duplicate'] = true;
            $duplicates['plate_exists_in'] = 'registrations';
            $duplicates['plate_member_name'] = $reg['full_name'] ?? $reg['name'] ?? '??? ????';
            $duplicates['plate_status'] = $reg['status'] ?? 'pending';
            $duplicates['plate_wasel'] = $reg['wasel'] ?? null;
            break;
        }
    }
}

// Build response
$response = [
    'success' => true,
    'has_duplicate' => $duplicates['phone_duplicate'] || $duplicates['plate_duplicate'],
    'is_returning_member' => $duplicates['is_returning_member'],
    'duplicates' => $duplicates,
    'message' => ''
];

if ($duplicates['phone_duplicate']) {
    $response['message'] = "??? ?????? ???? ?????? ????: " . $duplicates['phone_member_name'];
} elseif ($duplicates['plate_duplicate']) {
    $response['message'] = "??? ?????? ???? ?????? ????: " . $duplicates['plate_member_name'];
} elseif ($duplicates['is_returning_member']) {
    $response['message'] = "?????? ??????! ?? ?????? ???? ???? ????.";
    $response['member_code'] = $duplicates['member_code'];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>
