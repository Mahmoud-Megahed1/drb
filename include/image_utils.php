<?php
/**
 * Image Utilities
 * Helper functions for image manipulation
 */

/**
 * Compress an image and save it to the destination
 * 
 * @param string $source Path to source file
 * @param string $destination Path to destination file
 * @param int $quality Compression quality (0-100)
 * @param int|null $maxWidth Maximum width (optional, maintain aspect ratio)
 * @return bool True on success, False on failure
 */
function compressImage($source, $destination, $quality = 75, $maxWidth = 1600) {
    // Get image info
    $info = getimagesize($source);
    if ($info === false) {
        return false;
    }

    $mime = $info['mime'];
    
    // Create image resource from source
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            break;
        case 'image/gif':
            // GIFs are tricky to compress while keeping animation, just move it or simple copy
            // For this optimized version, we'll just copy GIFs to avoid breaking animations
            return move_uploaded_file($source, $destination); 
        case 'image/webp':
             // Check if WebP is supported
            if (function_exists('imagecreatefromwebp')) {
                $image = imagecreatefromwebp($source);
            } else {
                return move_uploaded_file($source, $destination);
            }
            break;
        default:
            return move_uploaded_file($source, $destination);
    }

    if (!$image) {
        return false;
    }

    // Resize if needed
    $width = imagesx($image);
    $height = imagesy($image);
    
    if ($maxWidth && $width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = floor($height * ($maxWidth / $width));
        
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG/WebP
        if ($mime == 'image/png' || $mime == 'image/webp') {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
        }
        
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $newImage;
    }

    // Save compressed image
    $result = false;
    
    // Always convert to JPEG for maximum compression unless it needs transparency?
    // User asked to reduce size. JPEG is best for photos (which these are).
    // But let's respect format unless it's huge.
    
    // Actually, converting everything to JPEG is often the best space saver for photos.
    // However, if we want to keep transparency (rare for car photos/IDs), we should keep PNG.
    // Let's stick to original format but compress.
    
    switch ($mime) {
        case 'image/jpeg':
            $result = imagejpeg($image, $destination, $quality);
            break;
        case 'image/png':
            // PNG quality is 0-9 (0 = no compression, 9 = max compression)
            // It's lossless, so 'quality' param from function (0-100) maps roughly
            $pngQuality = 9; // Always max compression for PNG as it is lossless
            // To really compress PNG we'd need to convert to JPEG, but let's keep it safe.
            // If we want "lossy" PNG we need external tools or convert to JPEG.
            // Let's convert to JPEG if no transparency? No, safer to keep PNG.
             imagealphablending($image, false);
             imagesavealpha($image, true);
            $result = imagepng($image, $destination, $pngQuality);
            break;
         case 'image/webp':
            $result = imagewebp($image, $destination, $quality);
            break;
    }

    imagedestroy($image);
    
    return $result;
}
?>
