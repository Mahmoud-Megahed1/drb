<?php
session_start();

header('Content-Type: application/json');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Badge Image Generator Function
function generateBadgeImage($registration, $outputDir) {
    if (!extension_loaded('gd')) {
        return null;
    }
    
    $badgeWidth = 600;
    $badgeHeight = 900;
    
    $badge = imagecreatetruecolor($badgeWidth, $badgeHeight);
    imagesavealpha($badge, true);
    
    // Colors
    $bgColor = imagecolorallocate($badge, 26, 26, 46);
    $headerColor = imagecolorallocate($badge, 220, 53, 69);
    $goldColor = imagecolorallocate($badge, 255, 193, 7);
    $whiteColor = imagecolorallocate($badge, 255, 255, 255);
    $grayColor = imagecolorallocate($badge, 150, 150, 150);
    $cardBg = imagecolorallocate($badge, 45, 45, 65);
    
    // Background
    imagefilledrectangle($badge, 0, 0, $badgeWidth, $badgeHeight, $bgColor);
    
    // Include Arabic Shaper
    require_once __DIR__ . '/ArabicShaper.php';
    
    // Font Settings
    $fontPath = __DIR__ . '/../../Cairo-Bold.ttf';
    if (!file_exists($fontPath)) {
        // Fallback or try different path
        $fontPath = __DIR__ . '/../../fonts/Cairo-Bold.ttf';
    }
    
    // Header
    imagefilledrectangle($badge, 0, 0, $badgeWidth, 120, $headerColor);
    
    // Helper to center text
    $drawCentered = function($img, $size, $y, $color, $font, $text) {
        // Shape Arabic text
        if (preg_match('/[\\x{0600}-\\x{06FF}]/u', $text)) {
             $text = ArabicShaper::shape($text);
        }
        $bbox = imagettfbbox($size, 0, $font, $text);
        $textWidth = abs($bbox[4] - $bbox[0]);
        $x = (imagesx($img) - $textWidth) / 2;
        // Adjust Y for baseline (approx + size)
        imagettftext($img, $size, 0, $x, $y + $size, $color, $font, $text);
    };
    
    // Text drawing helper
    $drawText = function($img, $size, $x, $y, $color, $font, $text) {
         if (preg_match('/[\\x{0600}-\\x{06FF}]/u', $text)) {
             $text = ArabicShaper::shape($text);
        }
        imagettftext($img, $size, 0, $x, $y + $size, $color, $font, $text);
    };
    
    // Header text
    $drawCentered($badge, 24, 30, $whiteColor, $fontPath, "نادي بلاد الرافدين 2025");
    $drawCentered($badge, 18, 75, $goldColor, $fontPath, "باج دخول الحلبة");
    
    // Registration number box
    $boxY = 140;
    imagefilledrectangle($badge, 150, $boxY, 450, $boxY + 60, $cardBg);
    $regNum = "No. " . $registration['wasel'];
    $drawCentered($badge, 40, $boxY + 5, $headerColor, $fontPath, $regNum);
    
    // Personal photo (circular)
    $photoY = 220;
    $photoSize = 150;
    $photoX = ($badgeWidth - $photoSize) / 2;
    
    // Draw gold circle border
    imagefilledellipse($badge, $badgeWidth / 2, $photoY + $photoSize / 2, $photoSize + 10, $photoSize + 10, $goldColor);
    
    // Load personal photo
    // Support MemberService merge if available, else use raw registration
    $mergedReg = $registration;
    if (class_exists('MemberService')) {
        $profileData = MemberService::getProfile($registration['wasel']);
        $mergedReg = $profileData['current_registration'] ?? $registration;
    }
    
    $personalPhotoRaw = $mergedReg['images']['personal_photo'] ?? $mergedReg['personal_photo'] ?? $mergedReg['images']['front_image'] ?? '';
    $personalPhoto = '';
    
    if (!empty($personalPhotoRaw)) {
        $cleanPath = ltrim(str_replace('../', '', $personalPhotoRaw), '/');
        $candidates = [
            $personalPhotoRaw,
            __DIR__ . '/../../' . $cleanPath,
            __DIR__ . '/../' . $cleanPath,
            '../' . $cleanPath
        ];
        foreach ($candidates as $cand) {
            if (file_exists($cand) && !is_dir($cand)) {
                $personalPhoto = $cand;
                break;
            }
        }
    }
    
    if (!empty($personalPhoto)) {
        $photoInfo = @getimagesize($personalPhoto);
        if ($photoInfo) {
            $personImg = null;
            switch ($photoInfo[2]) {
                case IMAGETYPE_PNG:
                    $personImg = @imagecreatefrompng($personalPhoto);
                    break;
                case IMAGETYPE_JPEG:
                    $personImg = @imagecreatefromjpeg($personalPhoto);
                    break;
                case IMAGETYPE_WEBP:
                    $personImg = @imagecreatefromwebp($personalPhoto);
                    break;
            }
            
            if ($personImg) {
                $resized = imagecreatetruecolor($photoSize, $photoSize);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $transparent);
                imagealphablending($resized, true);
                
                imagecopyresampled($resized, $personImg, 0, 0, 0, 0, $photoSize, $photoSize, $photoInfo[0], $photoInfo[1]);
                
                // Circular mask
                for ($y = 0; $y < $photoSize; $y++) {
                    for ($x = 0; $x < $photoSize; $x++) {
                        $cx = $x - $photoSize / 2;
                        $cy = $y - $photoSize / 2;
                        if (sqrt($cx * $cx + $cy * $cy) > $photoSize / 2) {
                            imagesetpixel($resized, $x, $y, $transparent);
                        }
                    }
                }
                
                imagecopy($badge, $resized, $photoX, $photoY, 0, 0, $photoSize, $photoSize);
                imagedestroy($resized);
                imagedestroy($personImg);
            }
        }
    }
    
    // Info section
    $infoY = $photoY + $photoSize + 40;
    $lineHeight = 45;
    $leftMargin = 50;
    $labelWidth = 120;
    $valueX = $leftMargin + $labelWidth;
    
    // Font sizes
    $labelSize = 16;
    $valueSize = 18;
    
    // Name
    $drawText($badge, $labelSize, $leftMargin, $infoY, $grayColor, $fontPath, "الاسم:");
    $drawText($badge, $valueSize, $valueX, $infoY, $whiteColor, $fontPath, $registration['full_name'] ?? '');
    $infoY += $lineHeight;
    
    // Phone
    $drawText($badge, $labelSize, $leftMargin, $infoY, $grayColor, $fontPath, "الهاتف:");
    $drawText($badge, $valueSize, $valueX, $infoY, $whiteColor, $fontPath, $registration['phone'] ?? '');
    $infoY += $lineHeight;
    
    // Governorate
    $drawText($badge, $labelSize, $leftMargin, $infoY, $grayColor, $fontPath, "المحافظة:");
    $drawText($badge, $valueSize, $valueX, $infoY, $whiteColor, $fontPath, $registration['governorate'] ?? '');
    $infoY += $lineHeight + 10;
    
    // Separator
    imageline($badge, 50, $infoY, $badgeWidth - 50, $infoY, $grayColor);
    $infoY += 25;
    
    // Car info header
    $drawCentered($badge, 16, $infoY, $goldColor, $fontPath, "--- معلومات السيارة ---");
    $infoY += $lineHeight;
    
    // Car type
    $drawText($badge, $labelSize, $leftMargin, $infoY, $grayColor, $fontPath, "السيارة:");
    $drawText($badge, $valueSize, $valueX, $infoY, $whiteColor, $fontPath, $registration['car_type'] ?? '');
    $infoY += $lineHeight;
    
    // Car year + color
    $drawText($badge, $labelSize, $leftMargin, $infoY, $grayColor, $fontPath, "الموديل:");
    $carModel = ($registration['car_year'] ?? '') . ' - ' . ($registration['car_color'] ?? '');
    $drawText($badge, $valueSize, $valueX, $infoY, $whiteColor, $fontPath, $carModel);
    $infoY += $lineHeight;
    
    // Plate
    $drawText($badge, $labelSize, $leftMargin, $infoY, $grayColor, $fontPath, "اللوحة:");
    $drawText($badge, $valueSize, $valueX, $infoY, $whiteColor, $fontPath, $registration['plate_full'] ?? '');
    $infoY += $lineHeight + 15;
    
    // Registration code box
    if (!empty($registration['registration_code'])) {
        imagefilledrectangle($badge, 100, $infoY, $badgeWidth - 100, $infoY + 50, $cardBg);
        $codeText = "CODE: " . $registration['registration_code'];
        $drawCentered($badge, 20, $infoY + 10, $goldColor, $fontPath, $codeText);
    }
    
    // Footer
    $footerY = $badgeHeight - 50;
    $footerText = "Show this badge at arena entrance";
    $drawCentered($badge, 14, $footerY, $grayColor, $fontPath, $footerText);
    
    // Save
    $filename = 'badge_' . $registration['wasel'] . '_' . time() . '.png';
    $filepath = $outputDir . $filename;
    
    imagepng($badge, $filepath, 9);
    imagedestroy($badge);
    
    return $filename;
}

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['wasel'])) {
    echo json_encode(['success' => false, 'error' => 'بيانات غير صالحة: ' . ($input ? 'JSON parse error' : 'no input')]);
    exit;
}

$wasel = $data['wasel'];
$state = $data['state'] ?? [];
$canvasData = $data['canvas_data'] ?? '';

// Validate canvas data
if (empty($canvasData)) {
    echo json_encode(['success' => false, 'error' => 'لا توجد صورة']);
    exit;
}

if (strpos($canvasData, 'data:image/png;base64,') !== 0) {
    echo json_encode(['success' => false, 'error' => 'صيغة الصورة غير صالحة']);
    exit;
}

try {
    // Decode base64 image
    $imageData = base64_decode(str_replace('data:image/png;base64,', '', $canvasData));
    
    if ($imageData === false) {
        echo json_encode(['success' => false, 'error' => 'فشل فك تشفير الصورة']);
        exit;
    }
    
    // Create output directory
    $outputDir = '../uploads/accepted/';
    if (!file_exists($outputDir)) {
        if (!mkdir($outputDir, 0777, true)) {
            echo json_encode(['success' => false, 'error' => 'فشل إنشاء المجلد']);
            exit;
        }
    }
    
    // Save image
    $filename = $wasel . '_accepted_' . time() . '.png';
    $filepath = $outputDir . $filename;
    
    $saved = file_put_contents($filepath, $imageData);
    
    if ($saved === false) {
        echo json_encode(['success' => false, 'error' => 'فشل حفظ الصورة على القرص']);
        exit;
    }
    
    // Update registration data
    $dataFile = 'data/data.json';
    $registrations = [];
    $foundReg = null;
    
    if (file_exists($dataFile)) {
        $registrations = json_decode(file_get_contents($dataFile), true);
        if (!is_array($registrations)) {
            $registrations = [];
        }
        
        foreach ($registrations as &$reg) {
            if ($reg['wasel'] == $wasel) {
                $reg['status'] = 'approved';
                $reg['approved_date'] = date('Y-m-d H:i:s');
                $reg['approved_by'] = $_SESSION['user']->username ?? 'admin';
                $reg['acceptance_image'] = 'uploads/accepted/' . $filename;
                $foundReg = $reg;
                break;
            }
        }
        
        file_put_contents($dataFile, json_encode($registrations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    // Save editor state for future edits
    $stateDir = 'data/editor_states/';
    if (!file_exists($stateDir)) {
        mkdir($stateDir, 0777, true);
    }
    file_put_contents($stateDir . $wasel . '.json', json_encode($state, JSON_PRETTY_PRINT));
    
    // Try to send WhatsApp notification (don't fail if it doesn't work)
    $whatsappResult = ['success' => false, 'error' => 'لم يتم الإرسال'];
    $badgeResult = ['success' => false];
    
    // --- NEW: SYNC WITH DATABASE & AUTO-ACTIVATE ---
    if ($foundReg) {
        try {
            require_once '../include/db.php';
            require_once '../services/MemberService.php'; // For getorCreateMember if needed, or just direct DB
            
            $pdo = db();
            
            // 1. Update Registration Status in DB
            // We use 'wasel' to find the registration. 
            // Note: In some legacy cases, registration might not exist in DB yet if it was only in JSON. 
            // But usually process.php creates it.
            
            $stmt = $pdo->prepare("SELECT id, member_id FROM registrations WHERE wasel = ? LIMIT 1");
            $stmt->execute([$wasel]);
            $dbReg = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dbReg) {
                // Update existing registration
                $pdo->prepare("UPDATE registrations SET status = 'approved', approved_date = datetime('now', '+3 hours') WHERE id = ?")
                    ->execute([$dbReg['id']]);
                
                auditLog('participant_approve', 'registrations', $dbReg['id'], null, 'Accepted & Badge Generated', $_SESSION['user_id'] ?? null);
                    
                $memberId = $dbReg['member_id'];
            } else {
                // Fallback: If not in DB (Legacy JSON only), try to find member by phone/code and create registration?
                // For now, let's assume if it's not in DB, we rely on MemberService to migrate it next time it's viewed.
                // But we CAN try to find the member to activate them.
                $memberId = null;
                $phone = $foundReg['phone'] ?? '';
                if ($phone) {
                    $stmt = $pdo->prepare("SELECT id FROM members WHERE phone LIKE ? LIMIT 1");
                    $stmt->execute(["%" . substr($phone, -10)]); // Simple match
                    $memberId = $stmt->fetchColumn();
                }
            }
            
            // 2. Auto-Activate Member Account
            if ($memberId) {
                $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
                $stmt->execute([$memberId]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($member && empty($member['account_activated'])) {
                    // Activate Account
                    $pdo->prepare("UPDATE members SET account_activated = 1, activation_date = datetime('now', '+3 hours') WHERE id = ?")
                        ->execute([$memberId]);
                        
                    // Send Activation WhatsApp
                    require_once '../wasender.php';
                    $wasender = new WaSender();
                    
                    // We need the permanent code. If it's TEMP, we should probably generate one?
                    // MemberService::getOrCreateMember handles this, but we are here now.
                    // Let's rely on what's in DB.
                    
                    if ($member['permanent_code'] && $member['permanent_code'] !== 'TEMP') {
                         $actResult = $wasender->sendAccountActivation([
                            'name' => $member['name'],
                            'phone' => $member['phone'],
                            'permanent_code' => $member['permanent_code'],
                            'country_code' => '+964'
                        ]);
                        
                        if ($actResult['success'] ?? false) {
                            $pdo->prepare("UPDATE members SET activation_message_sent = 1, activation_message_date = datetime('now', '+3 hours') WHERE id = ?")
                                ->execute([$memberId]);
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            // Log error but don't stop the flow
            error_log("DB Sync Error in generate_acceptance: " . $e->getMessage());
        }

        // --- END NEW DB LOGIC ---

        try {
            require_once '../wasender.php';
            $wasender = new WaSender();
            
            // Generate full URL for the acceptance image
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $imageUrl = $protocol . '://' . $host . '/' . str_replace('../', '', $filepath);
            $imageUrl = str_replace('\\', '/', $imageUrl);
            
            // Generate entry badge IMAGE with circular personal photo
            $badgeOutputDir = '../uploads/badges/';
            if (!file_exists($badgeOutputDir)) {
                mkdir($badgeOutputDir, 0777, true);
            }
            
            // Include badge generator function
            $badgeFilename = generateBadgeImage($foundReg, $badgeOutputDir);
            
            $badgeLink = $protocol . '://' . $host . '/badge.php?token=' . urlencode($foundReg['badge_token'] ?? $foundReg['session_badge_token'] ?? $foundReg['registration_code'] ?? '');
            $verifyUrl = $protocol . '://' . $host . '/verify_entry.php?badge_id=' . urlencode($foundReg['badge_id'] ?? $foundReg['registration_code'] ?? $foundReg['wasel'] ?? '') . '&action=checkin';
            $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($verifyUrl);

            $countryCode = $foundReg['country_code'] ?? '+964';
            $whatsappResult = $wasender->sendUnifiedApprovalMessage([
                'phone' => $foundReg['phone'] ?? '',
                'country_code' => $countryCode,
                'wasel' => $foundReg['wasel'] ?? '',
                'full_name' => $foundReg['full_name'] ?? 'مشترك',
                'car_type' => $foundReg['car_type'] ?? '',
                'plate_full' => $foundReg['plate_full'] ?? '',
                'registration_code' => $foundReg['registration_code'] ?? ''
            ], [
                'qr_url' => $qrCodeUrl,
                'badge_link' => $badgeLink,
                'acceptance_link' => $protocol . '://' . $host . '/acceptance.php?token=' . urlencode($foundReg['badge_token'] ?? $foundReg['session_badge_token'] ?? $foundReg['registration_code'] ?? '')
            ]);
            $badgeResult = $whatsappResult;
            
        } catch (Exception $e) {
            $whatsappResult = ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    echo json_encode([
        'success' => true,
        'image_path' => 'uploads/accepted/' . $filename,
        'whatsapp' => $whatsappResult,
        'badge' => $badgeResult
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'خطأ: ' . $e->getMessage()]);
}
?>
