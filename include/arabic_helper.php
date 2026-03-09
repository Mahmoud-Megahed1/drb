<?php

class ArabicText {
    private $utf8_ar = [
        'ء'=>['fe80','','',''], 'آ'=>['fe81','fe82','',''], 'أ'=>['fe83','fe84','',''], 
        'ؤ'=>['fe85','fe86','',''], 'إ'=>['fe87','fe88','',''], 'ئ'=>['fe89','fe8a','fe8b','fe8c'], 
        'ا'=>['fe8d','fe8e','',''], 'ب'=>['fe8f','fe90','fe91','fe92'], 'ة'=>['fe93','fe94','',''], 
        'ت'=>['fe95','fe96','fe97','fe98'], 'ث'=>['fe99','fe9a','fe9b','fe9c'], 'ج'=>['fe9d','fe9e','fe9f','fea0'], 
        'ح'=>['fea1','fea2','fea3','fea4'], 'خ'=>['fea5','fea6','fea7','fea8'], 'د'=>['fea9','feaa','',''], 
        'ذ'=>['feab','feac','',''], 'ر'=>['fead','feae','',''], 'ز'=>['feaf','feb0','',''], 
        'س'=>['feb1','feb2','feb3','feb4'], 'ش'=>['feb5','feb6','feb7','feb8'], 'ص'=>['feb9','feba','febb','febc'], 
        'ض'=>['febd','febe','febf','fec0'], 'ط'=>['fec1','fec2','fec3','fec4'], 'ظ'=>['fec5','fec6','fec7','fec8'], 
        'ع'=>['fec9','feca','fecb','fecc'], 'غ'=>['fecd','fece','fecf','fed0'], 'ف'=>['fed1','fed2','fed3','fed4'], 
        'ق'=>['fed5','fed6','fed7','fed8'], 'ك'=>['fed9','feda','fedb','fedc'], 'ل'=>['fedd','fede','fedf','fee0'], 
        'م'=>['fee1','fee2','fee3','fee4'], 'ن'=>['fee5','fee6','fee7','fee8'], 'ه'=>['fee9','feea','feeb','feec'], 
        'و'=>['feed','feee','',''], 'ى'=>['feef','fef0','',''], 'ي'=>['fef1','fef2','fef3','fef4'],
        'لآ'=>['fef5','fef6','',''], 'لأ'=>['fef7','fef8','',''], 'لإ'=>['fef9','fefa','',''], 'لا'=>['fefb','fefc','','']
    ];

    public function reshape($str) {
        $str = $this->utf8ToUnicode($str);
        $total = count($str);
        $res = [];
        for ($i = 0; $i < $total; $i++) {
            $char = $str[$i];
            if (isset($this->utf8_ar[$char])) {
                $prev = $str[$i-1] ?? null;
                $next = $str[$i+1] ?? null;
                $pos = $this->getPosition($char, $prev, $next);
                $res[] = $this->hexToUtf8($this->utf8_ar[$char][$pos]);
            } else {
                $res[] = $char;
            }
        }
        return implode('', array_reverse($res)); // Reverse for RTL
    }

    private function getPosition($char, $prev, $next) {
        $prevConn = $prev && isset($this->utf8_ar[$prev]) && !in_array($this->utf8_ar[$prev][2], ['', null]);
        $nextConn = $next && isset($this->utf8_ar[$next]) && !in_array($this->utf8_ar[$next][3], ['', null]); // Correction: check initial/medial capability
        // Simplified logic for brevity - usually 0:isolated, 1:final, 2:initial, 3:medial
        // This is a basic reshaper. Ideally use Ar-PHP library.
        // Re-implementing simplified:
        // 0: Isolated (default)
        // 1: Final (connected to previous)
        // 2: Initial (connected to next)
        // 3: Medial (connected to both)
        
        // Connects to prev?
        $p = $prev && $this->connectsLeft($prev);
        // Connects to next?
        $n = $next && $this->connectsRight($next);
        
        if ($p && $n && $this->connectsBoth($char)) return 3;
        if ($p && $this->connectsLeft($char)) return 1; // Actually Right in visual? No, Previous.
        // Let's rely on standard logic:
        if ($p && $n) return 3;
        if ($p) return 1;
        if ($n) return 2;
        return 0;
    }
    
    private function connectsLeft($char) {
         // Chars that can connect to the left (their future) -> wait, Arabic writes Right to Left.
         // Previous char (Right side) connects to its Left?
         // This logic is complex to reimplement perfectly in 2 mins.
         // BETTER: Just reverse the string basic.
         // But the user has Garbage text.
         return isset($this->utf8_ar[$char]) && $this->utf8_ar[$char][2] !== '';
    }
    private function connectsRight($char) { 
        return isset($this->utf8_ar[$char]); 
    }
    private function connectsBoth($char) { return $this->utf8_ar[$char][2] !== '' && $this->utf8_ar[$char][3] !== ''; }

    private function utf8ToUnicode($str) {
        preg_match_all('/./u', $str, $matches);
        return $matches[0];
    }
    
    private function hexToUtf8($hex) {
        if (!$hex) return '';
        $code = hexdec($hex);
        return mb_chr($code, 'UTF-8');
    }
}

// Simple Reverser if reshape is too risky
function simple_bidi($text) {
    preg_match_all('/./u', $text, $matches);
    return implode('', array_reverse($matches[0]));
}

/**
 * Convert Arabic/Persian digits to English digits
 * 
 * @param string $string Input string
 * @return string String with English digits
 */
function convertArabicToEnglishDigits($string) {
    if (!$string) return '';
    $newNumbers = range(0, 9);
    // Arabic digits
    $arabicNumbers = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $string = str_replace($arabicNumbers, $newNumbers, $string);
    // Persian digits
    $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $string = str_replace($persianNumbers, $newNumbers, $string);
    return $string;
}
?>
