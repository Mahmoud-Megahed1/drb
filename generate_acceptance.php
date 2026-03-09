<?php
/**
 * Generate Acceptance Image w/ Frame Settings & Polyfills
 */

// ===== UTF-8 ENCODING FIX =====
mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');
header('Content-Type: image/png');

// Prevent errors from breaking the image stream (we handle them)
error_reporting(0);
ini_set('display_errors', 0);

// 1. Polyfills for Missing GD Functions (CRITICAL for this server)
if (!function_exists('imagettfbbox')) {
    function imagettfbbox($size, $angle, $fontfile, $text) {
        if (function_exists('imageftbbox')) {
            return @imageftbbox($size, $angle, $fontfile, $text);
        }
        return false; // Fail gracefully
    }
}
if (!function_exists('imagettftext')) {
    function imagettftext($image, $size, $angle, $x, $y, $color, $fontfile, $text) {
        if (function_exists('imagefttext')) {
            return @imagefttext($image, $size, $angle, $x, $y, $color, $fontfile, $text);
        }
        // Fallback to built-in font
        imagestring($image, 5, $x, $y, $text, $color);
        return true;
    }
}

// 2. Load ArabicShaper (try multiple locations)
$arabicShaperLoaded = false;
$arabicShaperPaths = [
    __DIR__ . '/admin/ArabicShaper.php',
    __DIR__ . '/include/arabic_helper.php',
    __DIR__ . '/ArabicShaper.php'
];
foreach ($arabicShaperPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $arabicShaperLoaded = true;
        break;
    }
}

// 2.1 Load Service Layer
require_once __DIR__ . '/services/MemberService.php';

// Fallback Arabic text processor if no shaper found
if (!class_exists('ArabicShaper')) {
    class ArabicShaper {
        public static function shape($text) {
            // Simple RTL reversal for Arabic
            if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
                preg_match_all('/./u', $text, $matches);
                return implode('', array_reverse($matches[0]));
            }
            return $text;
        }
        public static function isArabic($text) {
            return preg_match('/[\x{0600}-\x{06FF}]/u', $text);
        }
    }
}

$token = $_GET['token'] ?? '';
$wasel = $_GET['wasel'] ?? '';

// 3. Load Data via Service Layer
$profile = MemberService::getProfile($token ?: $wasel);
if (!$profile) {
    outputErrorImage("Registration not found");
    exit;
}
$registration = $profile['current_registration'] ?? null;

// 3.1 Check for Manually Edited Image (Visual Editor)
// If an edited image exists, serve it directly instead of generating a new one
if (!empty($registration['edited_image'])) {
    $editedPath = __DIR__ . '/' . $registration['edited_image'];
    $editedPathAdmin = __DIR__ . '/admin/' . $registration['edited_image']; // In case path is relative
    
    // Check paths
    $finalPath = null;
    if (file_exists($editedPath)) $finalPath = $editedPath;
    elseif (file_exists($editedPathAdmin)) $finalPath = $editedPathAdmin;
    
    if ($finalPath) {
        $info = @getimagesize($finalPath);
        if ($info) {
             header('Content-Type: ' . $info['mime']);
             header('Cache-Control: no-cache, no-store, must-revalidate');
             readfile($finalPath);
             exit;
        }
    }
}

// 4. Load Frame Settings & Path
// PRIORITY: Use saved_frame_settings from registration if available (snapshot from approval time)
// Otherwise fallback to global frame_settings.json
$frameSettingsFile = __DIR__ . '/admin/data/frame_settings.json';
$framePath = __DIR__ . '/images/acceptance_frame.png'; // Default to root images

// Defaults
$frameSettings = [
    'personal_photo' => ['enabled' => true, 'x' => 50, 'y' => 60, 'width' => 35, 'height' => 35, 'shape' => 'circle'],
    'participant_name' => ['enabled' => true, 'x' => 50, 'y' => 70, 'font_size' => 22, 'color' => '#FFD700'],
    'registration_id' => ['enabled' => true, 'x' => 50, 'y' => 88, 'font_size' => 32, 'color' => '#FFD700'],
    'plate_number' => ['enabled' => true, 'x' => 50, 'y' => 90, 'font_size' => 18, 'color' => '#FFFFFF'],
    'car_type' => ['enabled' => false, 'x' => 50, 'y' => 92, 'font_size' => 18, 'color' => '#FFD700'],
    'governorate' => ['enabled' => true, 'x' => 50, 'y' => 95, 'font_size' => 18, 'color' => '#FFD700']
];

// *** FIXED: Use registration's saved_frame_settings FIRST (this is the key fix!) ***
$loaded = null;
if (!empty($registration['saved_frame_settings'])) {
    // Use snapshot from registration (set when frame settings were saved)
    $loaded = $registration['saved_frame_settings'];
} elseif (file_exists($frameSettingsFile)) {
    // Fallback to global file for old registrations
    $loaded = json_decode(file_get_contents($frameSettingsFile), true);
}

// Apply loaded settings
if ($loaded) {
    // 4.1 Update Frame Path
    if (!empty($loaded['frame_image'])) {
        // Check relative to admin first, then root, then images/settings
        $candidates = [
            __DIR__ . '/admin/' . $loaded['frame_image'],
            __DIR__ . '/' . $loaded['frame_image'],
            __DIR__ . '/images/settings/' . basename($loaded['frame_image']),
            __DIR__ . '/admin/images/settings/' . basename($loaded['frame_image'])
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) {
                $framePath = $c;
                break;
            }
        }
    }

    // 4.2 Update Element Settings
    if (isset($loaded['elements'])) {
        foreach ($loaded['elements'] as $key => $el) {
            $frameSettings[$key] = array_merge($frameSettings[$key] ?? [], $el);
        }
    }
}

// Fallback search for frame if custom one missing
if (!file_exists($framePath)) {
    $defaults = [
        __DIR__ . '/images/acceptance_frame.png',
        __DIR__ . '/admin/images/acceptance_frame.png',
        __DIR__ . '/admin/uploads/frame.png'
    ];
    foreach ($defaults as $d) {
        if (file_exists($d)) {
            $framePath = $d;
            break;
        }
    }
}

// 5. Find Font
$fontPath = null;
$fonts = [
    __DIR__ . '/fonts/Cairo-Bold.ttf',
    __DIR__ . '/fonts/NotoSansArabic.ttf',
    __DIR__ . '/admin/fonts/Cairo-Bold.ttf'
];
foreach ($fonts as $f) {
    if (file_exists($f)) {
        $fontPath = realpath($f);
        break;
    }
}

// 6. Create Base Image
if (!file_exists($framePath)) {
    outputSimpleImage($registration, $fontPath, "Frame missing");
    exit;
}

$info = @getimagesize($framePath);
if (!$info) {
    outputSimpleImage($registration, $fontPath, "Frame corrupt");
    exit;
}

$width = $info[0];
$height = $info[1];
$type = $info[2];

switch ($type) {
    case IMAGETYPE_PNG: $im = imagecreatefrompng($framePath); break;
    case IMAGETYPE_JPEG: $im = imagecreatefromjpeg($framePath); break;
    default: outputSimpleImage($registration, $fontPath, "Bad format"); exit;
}

imagealphablending($im, true);
imagesavealpha($im, true);

// 7. Render Elements

// 7.1 Search for Photo (Personal or Car)
$photoPath = null;
// Candidates prioritized
$photoCandidates = [];
if (!empty($registration['personal_photo'])) $photoCandidates[] = $registration['personal_photo'];
if (!empty($registration['images']['personal_photo'])) $photoCandidates[] = $registration['images']['personal_photo'];
if (!empty($registration['front_image'])) $photoCandidates[] = $registration['front_image'];
if (!empty($registration['images']['front_image'])) $photoCandidates[] = $registration['images']['front_image'];

foreach ($photoCandidates as $val) {
    if (empty($val)) continue;
    $cleanVal = ltrim(str_replace('\\', '/', $val), '/');
    $searches = [
        __DIR__ . '/' . $cleanVal,
        __DIR__ . '/admin/' . $cleanVal,
        __DIR__ . '/' . basename($cleanVal),
        __DIR__ . '/admin/uploads/' . basename($cleanVal)
    ];
    foreach ($searches as $f) {
        if (file_exists($f) && !is_dir($f)) {
            $photoPath = $f;
            break 2;
        }
    }
}

// Fallback to default user image if profile picture is missing
if (!$photoPath) {
    $defaults = [
        __DIR__ . '/images/default_user.png',
        __DIR__ . '/admin/images/default_user.png'
    ];
    foreach ($defaults as $d) {
        if (file_exists($d)) {
            $photoPath = $d;
            break;
        }
    }
}

// Render Photo
if ($photoPath) {
    // Determine which settings to use (personal_photo is primary)
    $pSet = $frameSettings['personal_photo'] ?? $frameSettings['car_image'];
    
    if ($pSet['enabled'] ?? true) {
        $pInfo = @getimagesize($photoPath);
        if ($pInfo) {
            $pSrc = null;
            switch ($pInfo[2]) {
                case IMAGETYPE_JPEG: $pSrc = imagecreatefromjpeg($photoPath); break;
                case IMAGETYPE_PNG: $pSrc = imagecreatefrompng($photoPath); break;
                case IMAGETYPE_WEBP: $pSrc = imagecreatefromwebp($photoPath); break;
            }

            if ($pSrc) {
                // Dimensions
                // settings use PERCENTAGE of frame width/height
                // BUT wait, frame_settings.json usually uses 0-100 coordinates
                // Check if 'width' is pixels or percent? Usually percent in this system.
                
                $targetW = ($pSet['width'] / 100) * $width;
                $targetH = ($pSet['height'] / 100) * $height;
                
                // Force square if circle
                if (($pSet['shape'] ?? '') === 'circle') {
                    $targetH = $targetW; 
                }
                
                $posX = ($pSet['x'] / 100) * $width;
                $posY = ($pSet['y'] / 100) * $height;
                
                // Center alignment
                $dstX = $posX - ($targetW / 2);
                $dstY = $posY - ($targetH / 2);

                // Resize Photo (Crop to Fit)
                $srcW = imagesx($pSrc);
                $srcH = imagesy($pSrc);
                $srcMin = min($srcW, $srcH);
                
                $thumb = imagecreatetruecolor($targetW, $targetH);
                
                // Transparancy
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                $trans = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
                imagefill($thumb, 0, 0, $trans);
                
                // Crop Center
                $srcX = ($srcW - $srcMin) / 2;
                $srcY = ($srcH - $srcMin) / 2;
                
                imagecopyresampled($thumb, $pSrc, 0, 0, $srcX, $srcY, $targetW, $targetH, $srcMin, $srcMin);
                
                // Apply Circle Mask
                if (($pSet['shape'] ?? '') === 'circle') {
                   // Simple pixel scan mask (slow but works without external libs)
                   $r = $targetW / 2;
                   for ($x=0; $x<$targetW; $x++) {
                       for ($y=0; $y<$targetH; $y++) {
                           $d = sqrt(pow($x-$r,2) + pow($y-$r,2));
                           if ($d > $r) {
                               imagesetpixel($thumb, $x, $y, $trans);
                           }
                       }
                   }
                   
                   // Border
                   if (!empty($pSet['border_width'])) {
                       $bw = $pSet['border_width'];
                       $bc = hex2rgb($pSet['border_color'] ?? '#ffffff');
                       $bCol = imagecolorallocate($thumb, $bc[0], $bc[1], $bc[2]);
                       for($i=0; $i<$bw; $i++) {
                           imageellipse($thumb, $r, $r, $targetW-$i, $targetH-$i, $bCol);
                       }
                   }
                }
                
                // Merge
                imagealphablending($im, true);
                imagecopy($im, $thumb, $dstX, $dstY, 0, 0, $targetW, $targetH);
                
                imagedestroy($thumb);
                imagedestroy($pSrc);
            }
        }
    }
}

// 7.2 Render Text
foreach (['participant_name', 'registration_id', 'plate_number', 'car_type', 'governorate'] as $key) {
    if (empty($frameSettings[$key]['enabled'])) continue;
    
    $conf = $frameSettings[$key];
    $txt = '';
    
    // get text content
    switch ($key) {
        case 'participant_name': $txt = $registration['full_name'] ?? $member['name'] ?? ''; break;
        case 'registration_id': $txt = '#' . ($registration['wasel'] ?? ''); break;
        case 'car_type': $txt = $registration['car_type'] ?? ''; break;
        case 'plate_number': 
            $txt = trim(($registration['plate_governorate']??'') . ' ' . ($registration['plate_letter']??'') . ' ' . ($registration['plate_number']??''));
            break;
        case 'governorate': 
            $txt = preg_replace('/\(.*\)/', '', $registration['governorate'] ?? '');
            if (empty(trim($txt)) || trim($txt) === '-') {
                $txt = preg_replace('/\(.*\)/', '', $registration['plate_governorate'] ?? '');
            }
            break;
    }
    
    if (empty($txt)) continue;
    
    // Arabic Shaping
    if (class_exists('ArabicShaper')) {
        $txt = ArabicShaper::shape($txt);
    }
    
    // Draw
    drawCenteredText($im, $txt, $conf['x'], $conf['y'], $conf['font_size'], $conf['color'], $fontPath, $width, $height);
}

// 8. Output
// Content-Type already set at top
header('Cache-Control: no-cache, no-store, must-revalidate');
imagepng($im);
imagedestroy($im);


// ============ HELPERS ============

function drawCenteredText($im, $text, $perX, $perY, $size, $colorHex, $font, $w, $h) {
    $rgb = hex2rgb($colorHex);
    $col = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    
    // Coords from percent
    $cx = ($perX / 100) * $w;
    $cy = ($perY / 100) * $h;
    
    // Try TTF font first
    if ($font && function_exists('imagettftext')) {
        $box = @imagettfbbox($size, 0, $font, $text);
        if ($box) {
            $tw = abs($box[4] - $box[0]);
            $th = abs($box[5] - $box[1]);
            
            // Center it
            $x = $cx - ($tw / 2);
            $y = $cy + ($th / 2); // GD Y is bottom-left of text
            
            // Add subtle shadow/outline for readability
            $shadowColor = imagecolorallocate($im, 0, 0, 0);
            @imagettftext($im, $size, 0, $x + 2, $y + 2, $shadowColor, $font, $text);
            
            @imagettftext($im, $size, 0, $x, $y, $col, $font, $text);
            return;
        }
    }
    
    // Fallback: Use built-in GD font (no Arabic support but at least something shows)
    // Scale font size: GD built-in fonts are 1-5
    $gdFont = min(5, max(1, intval($size / 6)));
    
    // Calculate text width for centering (rough estimate)
    $charWidth = imagefontwidth($gdFont);
    $charHeight = imagefontheight($gdFont);
    $textWidth = strlen($text) * $charWidth;
    
    $x = $cx - ($textWidth / 2);
    $y = $cy - ($charHeight / 2);
    
    imagestring($im, $gdFont, $x, $y, $text, $col);
}

function hex2rgb($hex) {
    $hex = str_replace("#", "", $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return [$r, $g, $b];
}

function outputSimpleImage($registration, $font, $errorMsg = "") {
    $w = 800;
    $h = 1000;
    $im = imagecreatetruecolor($w, $h);
    
    $bg = imagecolorallocate($im, 26, 26, 46); // Dark blue
    $gold = imagecolorallocate($im, 255, 215, 0);
    $white = imagecolorallocate($im, 255, 255, 255);
    
    imagefill($im, 0, 0, $bg);
    
    $name = $registration['full_name'] ?? $registration['name'] ?? 'Participant';
    $wasel = '#' . ($registration['wasel'] ?? '000');
    $plate = trim(($registration['plate_governorate'] ?? '') . ' ' . ($registration['plate_letter'] ?? '') . ' ' . ($registration['plate_number'] ?? ''));
    
    // Arabic Shaping
    if (class_exists('ArabicShaper')) {
        $name = ArabicShaper::shape($name);
        $plate = ArabicShaper::shape($plate);
    }
    
    drawCenteredText($im, $name, 50, 40, 30, "#FFD700", $font, $w, $h);
    drawCenteredText($im, $wasel, 50, 55, 60, "#FFD700", $font, $w, $h);
    drawCenteredText($im, $plate, 50, 70, 25, "#FFFFFF", $font, $w, $h);
    
    if ($errorMsg) {
        $red = imagecolorallocate($im, 255, 0, 0);
        imagestring($im, 5, 10, 10, "Debug: $errorMsg", $white);
    }
    
    header('Content-Type: image/png');
    imagepng($im);
    imagedestroy($im);
}

function outputErrorImage($msg) {
    $im = imagecreatetruecolor(400,100);
    $red = imagecolorallocate($im, 200, 50, 50);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $red);
    imagestring($im, 5, 10, 40, "Error: $msg", $white);
    header('Content-Type: image/png');
    imagepng($im);
    imagedestroy($im);
}
?>
