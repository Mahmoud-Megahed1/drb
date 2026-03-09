<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

// Check if GD library is available
if (!extension_loaded('gd')) {
    echo json_encode(['success' => false, 'error' => 'مكتبة GD غير متوفرة']);
    exit;
}

// Get settings from POST
$input = file_get_contents('php://input');
$settings = json_decode($input, true);

if (!$settings) {
    echo json_encode(['success' => false, 'error' => 'إعدادات غير صالحة']);
    exit;
}

// Get frame image
$siteSettingsFile = 'data/site_settings.json';
$frameImagePath = '../images/acceptance_frame.png';
if (file_exists($siteSettingsFile)) {
    $siteSettings = json_decode(file_get_contents($siteSettingsFile), true);
    if (!empty($siteSettings['frame_url']) && file_exists('../' . $siteSettings['frame_url'])) {
        $frameImagePath = '../' . $siteSettings['frame_url'];
    }
}

// Get a sample car image for preview
$sampleCarImage = '../images/sample_car.png';
// If sample doesn't exist, create a placeholder
if (!file_exists($sampleCarImage)) {
    // Try to get first registration's car image
    $dataFile = 'data/data.json';
    if (file_exists($dataFile)) {
        $data = json_decode(file_get_contents($dataFile), true);
        if (is_array($data) && !empty($data)) {
            foreach ($data as $item) {
                if (!empty($item['images']['front_image']) && file_exists('../' . $item['images']['front_image'])) {
                    $sampleCarImage = '../' . $item['images']['front_image'];
                    break;
                }
            }
        }
    }
}

try {
    // Load frame image
    if (!file_exists($frameImagePath)) {
        echo json_encode(['success' => false, 'error' => 'صورة الإطار غير موجودة']);
        exit;
    }
    
    $frameInfo = getimagesize($frameImagePath);
    $frameWidth = $frameInfo[0];
    $frameHeight = $frameInfo[1];
    $frameType = $frameInfo[2];
    
    switch ($frameType) {
        case IMAGETYPE_PNG:
            $frameImage = imagecreatefrompng($frameImagePath);
            break;
        case IMAGETYPE_JPEG:
            $frameImage = imagecreatefromjpeg($frameImagePath);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'نوع الإطار غير مدعوم']);
            exit;
    }
    
    imagealphablending($frameImage, true);
    imagesavealpha($frameImage, true);
    
    // Get car image settings
    $carSettings = $settings['car_image'] ?? [];
    $shape = $carSettings['shape'] ?? 'circle';
    $carX = ($carSettings['x_percent'] ?? 50) / 100 * $frameWidth;
    $carY = ($carSettings['y_percent'] ?? 60) / 100 * $frameHeight;
    $carSizePercent = $carSettings['size_percent'] ?? 35;
    $targetSize = min($frameWidth, $frameHeight) * ($carSizePercent / 100);
    
    // Load and process car image if available
    if (file_exists($sampleCarImage)) {
        $carInfo = @getimagesize($sampleCarImage);
        if ($carInfo) {
            $carType = $carInfo[2];
            switch ($carType) {
                case IMAGETYPE_PNG:
                    $carImage = imagecreatefrompng($sampleCarImage);
                    break;
                case IMAGETYPE_JPEG:
                    $carImage = imagecreatefromjpeg($sampleCarImage);
                    break;
                default:
                    $carImage = null;
            }
            
            if ($carImage) {
                $carWidth = $carInfo[0];
                $carHeight = $carInfo[1];
                
                // Resize car
                $ratio = min($targetSize / $carWidth, $targetSize / $carHeight);
                $newCarWidth = (int)($carWidth * $ratio);
                $newCarHeight = (int)($carHeight * $ratio);
                
                // Create resized car
                $resizedCar = imagecreatetruecolor($newCarWidth, $newCarHeight);
                imagealphablending($resizedCar, false);
                imagesavealpha($resizedCar, true);
                $transparent = imagecolorallocatealpha($resizedCar, 0, 0, 0, 127);
                imagefilledrectangle($resizedCar, 0, 0, $newCarWidth, $newCarHeight, $transparent);
                imagealphablending($resizedCar, true);
                imagecopyresampled($resizedCar, $carImage, 0, 0, 0, 0, $newCarWidth, $newCarHeight, $carWidth, $carHeight);
                
                // Apply mask if circle
                if ($shape === 'circle') {
                    $maskSize = max($newCarWidth, $newCarHeight);
                    $maskedCar = imagecreatetruecolor($maskSize, $maskSize);
                    imagealphablending($maskedCar, false);
                    imagesavealpha($maskedCar, true);
                    $transparentMask = imagecolorallocatealpha($maskedCar, 0, 0, 0, 127);
                    imagefill($maskedCar, 0, 0, $transparentMask);
                    
                    // Draw circle mask
                    $white = imagecolorallocate($maskedCar, 255, 255, 255);
                    imagefilledellipse($maskedCar, $maskSize/2, $maskSize/2, $maskSize, $maskSize, $white);
                    
                    // Create final masked image
                    $finalCar = imagecreatetruecolor($maskSize, $maskSize);
                    imagealphablending($finalCar, false);
                    imagesavealpha($finalCar, true);
                    imagefill($finalCar, 0, 0, $transparentMask);
                    imagealphablending($finalCar, true);
                    
                    // Copy car to center of mask area
                    $offsetX = ($maskSize - $newCarWidth) / 2;
                    $offsetY = ($maskSize - $newCarHeight) / 2;
                    imagecopy($finalCar, $resizedCar, $offsetX, $offsetY, 0, 0, $newCarWidth, $newCarHeight);
                    
                    // Apply circular mask
                    for ($y = 0; $y < $maskSize; $y++) {
                        for ($x = 0; $x < $maskSize; $x++) {
                            $dist = sqrt(pow($x - $maskSize/2, 2) + pow($y - $maskSize/2, 2));
                            if ($dist > $maskSize/2) {
                                imagesetpixel($finalCar, $x, $y, $transparentMask);
                            }
                        }
                    }
                    
                    $carPosX = (int)($carX - $maskSize / 2);
                    $carPosY = (int)($carY - $maskSize / 2);
                    imagecopy($frameImage, $finalCar, $carPosX, $carPosY, 0, 0, $maskSize, $maskSize);
                    imagedestroy($finalCar);
                    imagedestroy($maskedCar);
                } else {
                    // Square - just paste
                    $carPosX = (int)($carX - $newCarWidth / 2);
                    $carPosY = (int)($carY - $newCarHeight / 2);
                    imagecopy($frameImage, $resizedCar, $carPosX, $carPosY, 0, 0, $newCarWidth, $newCarHeight);
                }
                
                imagedestroy($resizedCar);
                imagedestroy($carImage);
            }
        }
    }
    
    // Include Arabic Shaper
    require_once __DIR__ . '/ArabicShaper.php';
    // Font Settings
    $fontPath = __DIR__ . '/../../Cairo-Bold.ttf';
    if (!file_exists($fontPath)) {
        $fontPath = __DIR__ . '/../../fonts/Cairo-Bold.ttf';
    }
    
    // Text drawing helper
    $drawCentered = function($img, $size, $x, $y, $color, $font, $text) {
        $textToDraw = $text;
        if (preg_match('/[\\x{0600}-\\x{06FF}]/u', $text)) {
             $textToDraw = ArabicShaper::shape($text);
        }
        $bbox = imagettfbbox($size, 0, $font, $textToDraw);
        $textWidth = abs($bbox[4] - $bbox[0]);
        $textX = $x - $textWidth / 2;
        imagettftext($img, $size, 0, $textX, $y + $size/2, $color, $font, $textToDraw);
    };
    
    // Add registration text
    $regSettings = $settings['registration_text'] ?? [];
    if ($regSettings['enabled'] ?? true) {
        $regText = ($regSettings['prefix'] ?? '#') . '123';
        $regX = ($regSettings['x_percent'] ?? 50) / 100 * $frameWidth;
        $regY = ($regSettings['y_percent'] ?? 88) / 100 * $frameHeight;
        $regFontSize = ($regSettings['font_size'] ?? 32) * 0.8; 
        $regColor = $regSettings['color'] ?? '#FFD700';
        
        list($r, $g, $b) = sscanf($regColor, "#%02x%02x%02x");
        $textColor = imagecolorallocate($frameImage, $r, $g, $b);
        
        $drawCentered($frameImage, $regFontSize, $regX, $regY, $textColor, $fontPath, $regText);
    }

    // Add Participant Name
    $nameSettings = $settings['elements']['participant_name'] ?? [];
    if ($nameSettings['enabled'] ?? true) {
        $nameText = "اسم المشترك";
        $nameX = ($nameSettings['x'] ?? 50) / 100 * $frameWidth;
        $nameY = ($nameSettings['y'] ?? 80) / 100 * $frameHeight; // Default to 80% if missing
        $nameFontSize = ($nameSettings['font_size'] ?? 24) * 0.8;
        $nameColor = $nameSettings['color'] ?? '#FFFFFF';
        
        list($r, $g, $b) = sscanf($nameColor, "#%02x%02x%02x");
        $textColor = imagecolorallocate($frameImage, $r, $g, $b);
        
        $drawCentered($frameImage, $nameFontSize, $nameX, $nameY, $textColor, $fontPath, $nameText);
    }

    // Add Car Type
    $carSettings = $settings['elements']['car_type'] ?? [];
    if ($carSettings['enabled'] ?? true) {
        $carText = "نوع السيارة 2024";
        $carX = ($carSettings['x'] ?? 50) / 100 * $frameWidth;
        $carY = ($carSettings['y'] ?? 85) / 100 * $frameHeight;
        $carFontSize = ($carSettings['font_size'] ?? 20) * 0.8;
        $carColor = $carSettings['color'] ?? '#FFFFFF';
        
        list($r, $g, $b) = sscanf($carColor, "#%02x%02x%02x");
        $textColor = imagecolorallocate($frameImage, $r, $g, $b);
        
        $drawCentered($frameImage, $carFontSize, $carX, $carY, $textColor, $fontPath, $carText);
    }

    // Add Plate Number
    $plateSettings = $settings['elements']['plate_number'] ?? [];
    if ($plateSettings['enabled'] ?? true) {
        $plateText = "بغداد - 12345";
        $plateX = ($plateSettings['x'] ?? 50) / 100 * $frameWidth;
        $plateY = ($plateSettings['y'] ?? 90) / 100 * $frameHeight;
        $plateFontSize = ($plateSettings['font_size'] ?? 20) * 0.8;
        $plateColor = $plateSettings['color'] ?? '#FFFFFF';
        
        list($r, $g, $b) = sscanf($plateColor, "#%02x%02x%02x");
        $textColor = imagecolorallocate($frameImage, $r, $g, $b);
        
        $drawCentered($frameImage, $plateFontSize, $plateX, $plateY, $textColor, $fontPath, $plateText);
    }

    // Add Governorate
    $govSettings = $settings['elements']['governorate'] ?? [];
    if ($govSettings['enabled'] ?? true) {
        $govText = "بغداد";
        $govX = ($govSettings['x'] ?? 50) / 100 * $frameWidth;
        $govY = ($govSettings['y'] ?? 95) / 100 * $frameHeight;
        $govFontSize = ($govSettings['font_size'] ?? 20) * 0.8;
        $govColor = $govSettings['color'] ?? '#FFD700';
        
        list($r, $g, $b) = sscanf($govColor, "#%02x%02x%02x");
        $textColor = imagecolorallocate($frameImage, $r, $g, $b);
        
        $drawCentered($frameImage, $govFontSize, $govX, $govY, $textColor, $fontPath, $govText);
    }
    
    // Add custom text
    $customSettings = $settings['custom_text'] ?? [];
    if ($customSettings['enabled'] ?? false) {
        $customText = $customSettings['text'] ?? '';
        if (!empty($customText)) {
            $customX = ($customSettings['x_percent'] ?? 50) / 100 * $frameWidth;
            $customY = ($customSettings['y_percent'] ?? 95) / 100 * $frameHeight;
            $customColor = $customSettings['color'] ?? '#FFFFFF';
            $customFontSize = 20; // Default size
            
            list($r, $g, $b) = sscanf($customColor, "#%02x%02x%02x");
            $textColor = imagecolorallocate($frameImage, $r, $g, $b);
            
            $drawCentered($frameImage, $customFontSize, $customX, $customY, $textColor, $fontPath, $customText);
        }
    }
    
    // Save preview
    $previewDir = '../uploads/previews/';
    if (!file_exists($previewDir)) {
        mkdir($previewDir, 0777, true);
    }
    
    $previewPath = $previewDir . 'preview_' . time() . '.png';
    imagepng($frameImage, $previewPath);
    imagedestroy($frameImage);
    
    // Return relative URL
    $previewUrl = str_replace('../', '', $previewPath);
    echo json_encode(['success' => true, 'preview_url' => '../' . $previewUrl]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
