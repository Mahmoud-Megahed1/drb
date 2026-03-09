<?php
/**
 * Entry Badge Generator - باج دخول الحلبة
 * يُنشئ صورة/صفحة باج الدخول للمشترك
 * Uses TrueType fonts for high quality Arabic + English text
 */

session_start();
header('Content-Type: application/json');

require_once 'ArabicShaper.php';

// Check if logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

// Check GD Library
if (!extension_loaded('gd')) {
    echo json_encode(['success' => false, 'error' => 'مكتبة GD غير متوفرة']);
    exit;
}

$wasel = $_GET['wasel'] ?? $_POST['wasel'] ?? '';

if (empty($wasel)) {
    echo json_encode(['success' => false, 'error' => 'رقم التسجيل مطلوب']);
    exit;
}

// Load registration data
$dataFile = 'data/data.json';
if (!file_exists($dataFile)) {
    echo json_encode(['success' => false, 'error' => 'ملف البيانات غير موجود']);
    exit;
}

$data = json_decode(file_get_contents($dataFile), true);
$registration = null;

foreach ($data as $item) {
    if ($item['wasel'] == $wasel) {
        $registration = $item;
        break;
    }
}

if (!$registration) {
    echo json_encode(['success' => false, 'error' => 'التسجيل غير موجود']);
    exit;
}

/**
 * Check if text contains Arabic characters
 */
function containsArabic($text) {
    return preg_match('/[\x{0600}-\x{06FF}]/u', $text);
}

/**
 * Arabic text shaping for PHP GD rendering
 */
// ArabicShaper is now used instead of local function

/**
 * Draw text with TTF font (high quality)
 * Supports both Arabic and English
 */
function drawTextTTF($image, $text, $x, $y, $fontSize, $color, $fontPath, $centered = true) {
    // Check if font exists
    if (!file_exists($fontPath)) {
        // Fallback to built-in font
        $textWidth = imagefontwidth(5) * strlen($text);
        $textX = $centered ? $x - $textWidth / 2 : $x;
        imagestring($image, 5, (int)$textX, (int)$y, $text, $color);
        return;
    }
    
    // Process Arabic text
    $isArabic = containsArabic($text);
    if ($isArabic) {
        $text = ArabicShaper::shape($text);
    }
    
    // Calculate text width for centering
    $bbox = @imagettfbbox($fontSize, 0, $fontPath, $text);
    if ($bbox === false) {
        // Fallback
        imagestring($image, 5, (int)$x, (int)$y, $text, $color);
        return;
    }
    
    $textWidth = abs($bbox[2] - $bbox[0]);
    $textX = $centered ? $x - $textWidth / 2 : $x;
    
    // Draw text
    imagettftext($image, $fontSize, 0, (int)$textX, (int)$y, $color, $fontPath, $text);
}

// Badge dimensions (optimized for mobile)
$badgeWidth = 600;
$badgeHeight = 900;

// Create badge image
$badge = imagecreatetruecolor($badgeWidth, $badgeHeight);
imagesavealpha($badge, true);

// Colors
$bgColor = imagecolorallocate($badge, 26, 26, 46);
$headerColor = imagecolorallocate($badge, 220, 53, 69);
$goldColor = imagecolorallocate($badge, 255, 193, 7);
$whiteColor = imagecolorallocate($badge, 255, 255, 255);
$grayColor = imagecolorallocate($badge, 150, 150, 150);
$cardBg = imagecolorallocate($badge, 45, 45, 65);
$greenColor = imagecolorallocate($badge, 40, 167, 69);

// Fill background
imagefilledrectangle($badge, 0, 0, $badgeWidth, $badgeHeight, $bgColor);

// Header
imagefilledrectangle($badge, 0, 0, $badgeWidth, 120, $headerColor);

// Find available font - Try multiple paths
$fontPath = null;
$englishFontPath = null;
$fontCandidates = [
    __DIR__ . '/../fonts/Cairo-Bold.ttf',
    __DIR__ . '/../fonts/NotoSansArabic.ttf',
    __DIR__ . '/../fonts/DejaVuSans-Bold.ttf',
];

// English font candidates (for better English support)
$englishFontCandidates = [
    __DIR__ . '/../fonts/DejaVuSans-Bold.ttf',
    __DIR__ . '/../fonts/Roboto-Bold.ttf',
    __DIR__ . '/../fonts/Arial-Bold.ttf',
    __DIR__ . '/../fonts/Roboto-Bold.ttf',
    // Fallback to system fonts on Windows
    'C:/Windows/Fonts/arial.ttf',
    'C:/Windows/Fonts/arialbd.ttf',
    // Linux system fonts
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
];

foreach ($fontCandidates as $candidate) {
    if (file_exists($candidate)) {
        $fontPath = $candidate;
        break;
    }
}

// Find English font
foreach ($englishFontCandidates as $candidate) {
    if (file_exists($candidate)) {
        $englishFontPath = $candidate;
        break;
    }
}

// If no English font found, use Arabic font for everything
if (!$englishFontPath) {
    $englishFontPath = $fontPath;
}

$useTTF = $fontPath && @imagettfbbox(12, 0, $fontPath, 'Test') !== false;

// Header text
$headerText = "نادي بلاد الرافدين 2025";
$subHeaderText = "باج دخول الحلبة";

if ($useTTF) {
    drawTextTTF($badge, $headerText, $badgeWidth / 2, 45, 20, $whiteColor, $fontPath, true);
    drawTextTTF($badge, $subHeaderText, $badgeWidth / 2, 85, 18, $goldColor, $fontPath, true);
} else {
    $textWidth = imagefontwidth(5) * strlen($headerText);
    imagestring($badge, 5, ($badgeWidth - $textWidth) / 2, 35, $headerText, $whiteColor);
    imagestring($badge, 4, $badgeWidth / 2 - 60, 70, "Entry Badge", $goldColor);
}

// Registration number box
$boxY = 140;
imagefilledrectangle($badge, 150, $boxY, 450, $boxY + 80, $cardBg);

$regNum = "#" . $registration['wasel'];
if ($useTTF) {
    drawTextTTF($badge, $regNum, $badgeWidth / 2, $boxY + 52, 32, $headerColor, $fontPath, true);
} else {
    $regWidth = imagefontwidth(5) * strlen($regNum);
    imagestring($badge, 5, ($badgeWidth - $regWidth) / 2, $boxY + 30, $regNum, $headerColor);
}

// Personal photo (circular)
$photoY = 250;
$photoSize = 150;
$photoX = ($badgeWidth - $photoSize) / 2;

// Draw circle background (gold border)
imagefilledellipse($badge, (int)($badgeWidth / 2), (int)($photoY + $photoSize / 2), $photoSize + 10, $photoSize + 10, $goldColor);

// Load personal photo
$personalPhoto = '../' . ($registration['images']['personal_photo'] ?? $registration['images']['front_image'] ?? '');
if (file_exists($personalPhoto)) {
    $photoInfo = @getimagesize($personalPhoto);
    if ($photoInfo) {
        $photoType = $photoInfo[2];
        $personImg = null;
        
        switch ($photoType) {
            case IMAGETYPE_PNG: $personImg = @imagecreatefrompng($personalPhoto); break;
            case IMAGETYPE_JPEG: $personImg = @imagecreatefromjpeg($personalPhoto); break;
            case IMAGETYPE_WEBP: $personImg = @imagecreatefromwebp($personalPhoto); break;
            case IMAGETYPE_GIF: $personImg = @imagecreatefromgif($personalPhoto); break;
        }
        
        if ($personImg) {
            // Resize and make circular
            $resized = imagecreatetruecolor($photoSize, $photoSize);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $transparent);
            imagealphablending($resized, true);
            
            imagecopyresampled($resized, $personImg, 0, 0, 0, 0, $photoSize, $photoSize, $photoInfo[0], $photoInfo[1]);
            
            // Apply circular mask
            for ($y = 0; $y < $photoSize; $y++) {
                for ($x = 0; $x < $photoSize; $x++) {
                    $cx = $x - $photoSize / 2;
                    $cy = $y - $photoSize / 2;
                    if (sqrt($cx * $cx + $cy * $cy) > $photoSize / 2) {
                        imagesetpixel($resized, $x, $y, $transparent);
                    }
                }
            }
            
            imagecopy($badge, $resized, (int)$photoX, $photoY, 0, 0, $photoSize, $photoSize);
            imagedestroy($resized);
            imagedestroy($personImg);
        }
    }
}

// Info section
$infoY = $photoY + $photoSize + 40;
$lineHeight = 45;
$leftMargin = 80;
$rightMargin = $badgeWidth - 80;

// Helper function for info rows
function drawInfoRow($badge, $label, $value, &$y, $leftMargin, $rightMargin, $labelColor, $valueColor, $fontPath, $useTTF) {
    global $lineHeight;
    
    if ($useTTF && $fontPath) {
        // Label on right (RTL)
        drawTextTTF($badge, $label, $rightMargin, $y, 14, $labelColor, $fontPath, false);
        // Value on left
        drawTextTTF($badge, $value, $leftMargin, $y, 16, $valueColor, $fontPath, false);
    } else {
        imagestring($badge, 3, (int)$rightMargin - 80, (int)$y - 10, $label, $labelColor);
        imagestring($badge, 4, (int)$leftMargin, (int)$y - 10, $value, $valueColor);
    }
    
    $y += $lineHeight;
}

// Draw info rows
$fullName = $registration['full_name'] ?? '';
$phone = $registration['phone'] ?? '';
$governorate = $registration['governorate'] ?? '';
$carType = $registration['car_type'] ?? '';
$carYear = $registration['car_year'] ?? '';
$carColor = $registration['car_color'] ?? '';
$plateFull = $registration['plate_full'] ?? '';

if ($useTTF) {
    // Name
    drawTextTTF($badge, "الاسم:", $rightMargin, $infoY, 14, $grayColor, $fontPath, false);
    drawTextTTF($badge, $fullName, $leftMargin, $infoY, 18, $whiteColor, $fontPath, false);
    $infoY += $lineHeight;
    
    // Phone
    drawTextTTF($badge, "الهاتف:", $rightMargin, $infoY, 14, $grayColor, $fontPath, false);
    drawTextTTF($badge, $phone, $leftMargin, $infoY, 16, $whiteColor, $fontPath, false);
    $infoY += $lineHeight;
    
    // Governorate
    drawTextTTF($badge, "المحافظة:", $rightMargin, $infoY, 14, $grayColor, $fontPath, false);
    drawTextTTF($badge, $governorate, $leftMargin, $infoY, 16, $whiteColor, $fontPath, false);
    $infoY += $lineHeight;
} else {
    imagestring($badge, 3, $rightMargin - 50, $infoY - 10, "Name:", $grayColor);
    imagestring($badge, 4, $leftMargin, $infoY - 10, $fullName, $whiteColor);
    $infoY += $lineHeight;
    
    imagestring($badge, 3, $rightMargin - 50, $infoY - 10, "Phone:", $grayColor);
    imagestring($badge, 4, $leftMargin, $infoY - 10, $phone, $whiteColor);
    $infoY += $lineHeight;
    
    imagestring($badge, 3, $rightMargin - 70, $infoY - 10, "City:", $grayColor);
    imagestring($badge, 4, $leftMargin, $infoY - 10, $governorate, $whiteColor);
    $infoY += $lineHeight;
}

// Separator line
imageline($badge, 50, (int)$infoY, $badgeWidth - 50, (int)$infoY, $grayColor);
$infoY += 25;

// Car info header
if ($useTTF) {
    drawTextTTF($badge, "معلومات السيارة", $badgeWidth / 2, $infoY, 16, $goldColor, $fontPath, true);
} else {
    $carHeader = "-- Car Info --";
    $carHeaderWidth = imagefontwidth(4) * strlen($carHeader);
    imagestring($badge, 4, ($badgeWidth - $carHeaderWidth) / 2, (int)$infoY - 10, $carHeader, $goldColor);
}
$infoY += $lineHeight;

// Car details
if ($useTTF) {
    // Car Type
    drawTextTTF($badge, "النوع:", $rightMargin, $infoY, 14, $grayColor, $fontPath, false);
    drawTextTTF($badge, $carType, $leftMargin, $infoY, 16, $whiteColor, $fontPath, false);
    $infoY += $lineHeight;
    
    // Year + Color
    drawTextTTF($badge, "السنة واللون:", $rightMargin, $infoY, 14, $grayColor, $fontPath, false);
    drawTextTTF($badge, $carYear . ' - ' . $carColor, $leftMargin, $infoY, 16, $whiteColor, $fontPath, false);
    $infoY += $lineHeight;
    
    // Plate
    drawTextTTF($badge, "اللوحة:", $rightMargin, $infoY, 14, $grayColor, $fontPath, false);
    drawTextTTF($badge, $plateFull, $leftMargin, $infoY, 16, $goldColor, $fontPath, false);
    $infoY += $lineHeight + 10;
} else {
    imagestring($badge, 3, $rightMargin - 50, $infoY - 10, "Type:", $grayColor);
    imagestring($badge, 4, $leftMargin, $infoY - 10, $carType, $whiteColor);
    $infoY += $lineHeight;
    
    imagestring($badge, 3, $rightMargin - 50, $infoY - 10, "Year:", $grayColor);
    imagestring($badge, 4, $leftMargin, $infoY - 10, $carYear . ' - ' . $carColor, $whiteColor);
    $infoY += $lineHeight;
    
    imagestring($badge, 3, $rightMargin - 50, $infoY - 10, "Plate:", $grayColor);
    imagestring($badge, 4, $leftMargin, $infoY - 10, $plateFull, $goldColor);
    $infoY += $lineHeight + 10;
}

// Registration code box
if (!empty($registration['registration_code'])) {
    $codeBoxY = $infoY;
    imagefilledrectangle($badge, 100, (int)$codeBoxY, $badgeWidth - 100, (int)$codeBoxY + 60, $cardBg);
    
    if ($useTTF) {
        drawTextTTF($badge, "كود التسجيل السريع", $badgeWidth / 2, $codeBoxY + 22, 12, $grayColor, $fontPath, true);
        drawTextTTF($badge, $registration['registration_code'], $badgeWidth / 2, $codeBoxY + 48, 22, $goldColor, $fontPath, true);
    } else {
        $codeText = "Code: " . $registration['registration_code'];
        $codeWidth = imagefontwidth(5) * strlen($codeText);
        imagestring($badge, 5, (int)(($badgeWidth - $codeWidth) / 2), (int)$codeBoxY + 20, $codeText, $goldColor);
    }
}

// Footer
$footerY = $badgeHeight - 60;
imagefilledrectangle($badge, 0, $footerY, $badgeWidth, $badgeHeight, $greenColor);

if ($useTTF) {
    drawTextTTF($badge, "✅ أظهر هذا الباج عند الدخول", $badgeWidth / 2, $footerY + 35, 16, $whiteColor, $fontPath, true);
} else {
    $footerText = "Show this badge at entrance";
    $footerWidth = imagefontwidth(4) * strlen($footerText);
    imagestring($badge, 4, (int)(($badgeWidth - $footerWidth) / 2), $footerY + 20, $footerText, $whiteColor);
}

// Save badge
$outputDir = '../uploads/badges/';
if (!file_exists($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$filename = 'badge_' . $wasel . '_' . time() . '.png';
$filepath = $outputDir . $filename;

imagepng($badge, $filepath, 9);
imagedestroy($badge);

echo json_encode([
    'success' => true,
    'badge_path' => 'uploads/badges/' . $filename,
    'badge_url' => str_replace('../', '', $filepath),
    'font_used' => $useTTF ? basename($fontPath) : 'built-in'
]);
?>
