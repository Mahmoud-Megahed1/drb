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
    // 1. Skip compression for small files (< 1.5MB) to save CPU
    // 2. Skip compression for huge files (> 8MB) to prevent "Out of Memory" 503 errors on shared hosting
    $filesize = @filesize($source);
    if ($filesize < 1.5 * 1024 * 1024 || $filesize > 8 * 1024 * 1024) {
        return move_uploaded_file($source, $destination);
    }

    try {
        // Get image info
        $info = @getimagesize($source);
        if ($info === false) {
            return move_uploaded_file($source, $destination);
        }

        $mime = $info['mime'];
        $width = $info[0];
        $height = $info[1];
        
        // Safety check: if image dimensions are absolutely massive (e.g. 6000x4000 = 24MP)
        // it requires ~100MB RAM just to open. Skip compression to avoid 503 error.
        if ($width * $height > 16000000) { // > 16 Megapixels
            return move_uploaded_file($source, $destination);
        }

        // Create image resource from source
        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($source);
                break;
            case 'image/gif':
                return move_uploaded_file($source, $destination); 
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($source);
                } else {
                    return move_uploaded_file($source, $destination);
                }
                break;
            default:
                return move_uploaded_file($source, $destination);
        }

        if (!$image) {
            return move_uploaded_file($source, $destination);
        }

        // Resize if needed
        if ($maxWidth && $width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = floor($height * ($maxWidth / $width));
            
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency
            if ($mime == 'image/png' || $mime == 'image/webp') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image); // Free original image memory immediately
            $image = $newImage;
        }

        // Save compressed image
        $result = false;
        switch ($mime) {
            case 'image/jpeg':
                $result = imagejpeg($image, $destination, $quality);
                break;
            case 'image/png':
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $result = imagepng($image, $destination, 9);
                break;
             case 'image/webp':
                $result = imagewebp($image, $destination, $quality);
                break;
        }

        imagedestroy($image); // Free final image memory
        
        if ($result) {
            return true;
        } else {
            return move_uploaded_file($source, $destination);
        }
    } catch (\Throwable $e) {
        // If anything crashes (memory limit, GD error), fallback to normal upload
        error_log("Image compression failed, falling back to move: " . $e->getMessage());
        return move_uploaded_file($source, $destination);
    }
}
?>
