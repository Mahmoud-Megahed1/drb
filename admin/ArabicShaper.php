<?php
/**
 * Arabic Shaper Class
 * Handles correct shaping of Arabic letters for PHP GD (Right-to-Left, Connecting letters)
 */

class ArabicShaper {
    
    // Complete Arabic character forms: [isolated, initial, medial, final]
    private static $charForms = [
        0x0621 => [0xFE80, 0xFE80, 0xFE80, 0xFE80], // Hamza (no joining)
        0x0622 => [0xFE81, 0xFE81, 0xFE82, 0xFE82], // Alef Madda
        0x0623 => [0xFE83, 0xFE83, 0xFE84, 0xFE84], // Alef Hamza Above
        0x0624 => [0xFE85, 0xFE85, 0xFE86, 0xFE86], // Waw Hamza
        0x0625 => [0xFE87, 0xFE87, 0xFE88, 0xFE88], // Alef Hamza Below
        0x0626 => [0xFE89, 0xFE8B, 0xFE8C, 0xFE8A], // Yeh Hamza
        0x0627 => [0xFE8D, 0xFE8D, 0xFE8E, 0xFE8E], // Alef
        0x0628 => [0xFE8F, 0xFE91, 0xFE92, 0xFE90], // Beh
        0x0629 => [0xFE93, 0xFE93, 0xFE94, 0xFE94], // Teh Marbuta
        0x062A => [0xFE95, 0xFE97, 0xFE98, 0xFE96], // Teh
        0x062B => [0xFE99, 0xFE9B, 0xFE9C, 0xFE9A], // Theh
        0x062C => [0xFE9D, 0xFE9F, 0xFEA0, 0xFE9E], // Jeem
        0x062D => [0xFEA1, 0xFEA3, 0xFEA4, 0xFEA2], // Hah
        0x062E => [0xFEA5, 0xFEA7, 0xFEA8, 0xFEA6], // Khah
        0x062F => [0xFEA9, 0xFEA9, 0xFEAA, 0xFEAA], // Dal
        0x0630 => [0xFEAB, 0xFEAB, 0xFEAC, 0xFEAC], // Thal
        0x0631 => [0xFEAD, 0xFEAD, 0xFEAE, 0xFEAE], // Reh
        0x0632 => [0xFEAF, 0xFEAF, 0xFEB0, 0xFEB0], // Zain
        0x0633 => [0xFEB1, 0xFEB3, 0xFEB4, 0xFEB2], // Seen
        0x0634 => [0xFEB5, 0xFEB7, 0xFEB8, 0xFEB6], // Sheen
        0x0635 => [0xFEB9, 0xFEBB, 0xFEBC, 0xFEBA], // Sad
        0x0636 => [0xFEBD, 0xFEBF, 0xFEC0, 0xFEBE], // Dad
        0x0637 => [0xFEC1, 0xFEC3, 0xFEC4, 0xFEC2], // Tah
        0x0638 => [0xFEC5, 0xFEC7, 0xFEC8, 0xFEC6], // Zah
        0x0639 => [0xFEC9, 0xFECB, 0xFECC, 0xFECA], // Ain
        0x063A => [0xFECD, 0xFECF, 0xFED0, 0xFECE], // Ghain
        0x0641 => [0xFED1, 0xFED3, 0xFED4, 0xFED2], // Feh
        0x0642 => [0xFED5, 0xFED7, 0xFED8, 0xFED6], // Qaf
        0x0643 => [0xFED9, 0xFEDB, 0xFEDC, 0xFEDA], // Kaf
        0x0644 => [0xFEDD, 0xFEDF, 0xFEE0, 0xFEDE], // Lam
        0x0645 => [0xFEE1, 0xFEE3, 0xFEE4, 0xFEE2], // Meem
        0x0646 => [0xFEE5, 0xFEE7, 0xFEE8, 0xFEE6], // Noon
        0x0647 => [0xFEE9, 0xFEEB, 0xFEEC, 0xFEEA], // Heh
        0x0648 => [0xFEED, 0xFEED, 0xFEEE, 0xFEEE], // Waw
        0x0649 => [0xFEEF, 0xFEEF, 0xFEF0, 0xFEF0], // Alef Maksura
        0x064A => [0xFEF1, 0xFEF3, 0xFEF4, 0xFEF2], // Yeh
        
        // Persian/Urdu Extras (Commonly used)
        0x067E => [0xFB56, 0xFB58, 0xFB59, 0xFB57], // Peh
        0x0686 => [0xFB7A, 0xFB7C, 0xFB7D, 0xFB7B], // Tcheh
        0x0698 => [0xFB8A, 0xFB8A, 0xFB8B, 0xFB8B], // Jeh
        0x06AF => [0xFB92, 0xFB94, 0xFB95, 0xFB93], // Gaf
    ];
    
    // Characters that don't connect to the NEXT character (right side in RTL)
    private static $noConnectRight = [
        0x0621, 0x0622, 0x0623, 0x0624, 0x0625, 0x0627, 
        0x0629, 0x062F, 0x0630, 0x0631, 0x0632, 0x0648, 0x0649,
        0x0698 // Jeh
    ];

    /**
     * Check if text contains Arabic characters
     */
    public static function isArabic($text) {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }

    /**
     * Shape Arabic text for rendering
     * @param string $text The original Arabic text
     * @return string The shaped and reversed text ready for LTR rendering engines (like GD)
     */
    public static function shape($text) {
        if (!self::isArabic($text)) {
            return $text;
        }

        // Split into words by space to handle multi-word strings correctly
        $words = explode(' ', $text);
        $shapedWords = [];
        
        foreach ($words as $word) {
            if (empty($word)) {
                $shapedWords[] = '';
                continue;
            }
            
            // Get characters array
            preg_match_all('/./u', $word, $matches);
            $chars = $matches[0];
            $len = count($chars);
            $shaped = [];
            
            // Process each character (in original RTL order)
            for ($i = 0; $i < $len; $i++) {
                $char = $chars[$i];
                $code = mb_ord($char, 'UTF-8');
                
                // Check if it's an Arabic letter
                if (isset(self::$charForms[$code])) {
                    // Array index 0 = rightmost in RTL, higher index = more left
                    // So: index-1 = RIGHT neighbor (preceding char in string), index+1 = LEFT neighbor (next char)
                    
                    $rightCharCode = ($i > 0) ? mb_ord($chars[$i - 1], 'UTF-8') : 0;
                    $leftCharCode = ($i < $len - 1) ? mb_ord($chars[$i + 1], 'UTF-8') : 0;
                    
                    // Connect RIGHT if: right neighbor exists AND right neighbor can connect left
                    // "Connect Right" means connecting to the previous character in the string
                    $connectsRight = isset(self::$charForms[$rightCharCode]) && !in_array($rightCharCode, self::$noConnectRight);
                    
                    // Connect LEFT if: left neighbor exists AND current char can connect left
                    // "Connect Left" means connecting to the next character in the string
                    $connectsLeft = isset(self::$charForms[$leftCharCode]) && !in_array($code, self::$noConnectRight);
                    
                    // Determine form
                    $form = 0; // isolated
                    if ($connectsRight && $connectsLeft) {
                        $form = 2; // medial - connects both sides
                    } elseif ($connectsRight) {
                        $form = 3; // final - connects only to right (previous char)
                    } elseif ($connectsLeft) {
                        $form = 1; // initial - connects only to left (next char)
                    }
                    
                    $shaped[] = mb_chr(self::$charForms[$code][$form], 'UTF-8');
                } else {
                    $shaped[] = $char;
                }
            }
            
            // Reverse characters in the word for LTR rendering engines
            $shapedWords[] = implode('', array_reverse($shaped));
        }
        
        // Reverse word order for correct sentence structure in LTR context
        return implode(' ', array_reverse($shapedWords));
    }
}
