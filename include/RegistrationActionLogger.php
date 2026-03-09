<?php
/**
 * Registration Action Logger
 * =========================
 * يسجل كل الإجراءات المتخذة على التسجيلات (تسجيل، تعديل، قبول، رفض)
 * يُستخدم في member_details.php لعرض الأرشيف
 */
class RegistrationActionLogger {

    private static $file = null;

    private static function getFilePath() {
        if (self::$file === null) {
            // Try to find the correct path
            $candidates = [
                __DIR__ . '/../admin/data/registration_actions.json',
                dirname(__DIR__) . '/admin/data/registration_actions.json'
            ];
            foreach ($candidates as $c) {
                $dir = dirname($c);
                if (is_dir($dir)) {
                    self::$file = $c;
                    break;
                }
            }
            if (self::$file === null) {
                self::$file = $candidates[0];
            }
        }
        return self::$file;
    }

    /**
     * Log an action on a registration
     * 
     * @param string $action One of: registered, re_registered, approved, rejected
     * @param array $data Registration data (must contain wasel, registration_code, phone)
     * @param string $details Description/reason
     * @param string $user Username who performed the action
     */
    public static function log($action, $data, $details = '', $user = 'system') {
        $filePath = self::getFilePath();
        
        // Load existing
        $actions = [];
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $actions = json_decode($content, true);
            if (!is_array($actions)) $actions = [];
        }
        
        // Build entry
        $champName = '';
        $frameSettingsFile = __DIR__ . '/../admin/data/frame_settings.json';
        if (file_exists($frameSettingsFile)) {
            $fs = json_decode(file_get_contents($frameSettingsFile), true);
            $champName = $fs['form_titles']['sub_title'] ?? '';
        }
        
        $entry = [
            'wasel' => strval($data['wasel'] ?? ''),
            'registration_code' => $data['registration_code'] ?? '',
            'phone' => $data['phone'] ?? '',
            'full_name' => $data['full_name'] ?? $data['name'] ?? '',
            'action' => $action,
            'details' => $details,
            'user' => $user,
            'championship_name' => $data['championship_name'] ?? $champName,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $actions[] = $entry;
        
        // Save
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($filePath, json_encode($actions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Get all actions for a specific registration (by code or phone)
     * 
     * @param string $registrationCode
     * @param string $phone (optional, for fallback matching)
     * @return array Actions sorted by timestamp
     */
    public static function getByCode($registrationCode, $phone = '') {
        $filePath = self::getFilePath();
        if (!file_exists($filePath)) return [];
        
        $actions = json_decode(file_get_contents($filePath), true);
        if (!is_array($actions)) return [];
        
        $results = [];
        $cleanPhone = self::cleanPhone($phone);
        
        foreach ($actions as $a) {
            $match = false;
            
            if (!empty($registrationCode) && ($a['registration_code'] ?? '') === $registrationCode) {
                $match = true;
            }
            
            if (!$match && !empty($cleanPhone) && self::cleanPhone($a['phone'] ?? '') === $cleanPhone) {
                $match = true;
            }
            
            if ($match) {
                $results[] = $a;
            }
        }
        
        // Sort by timestamp ascending
        usort($results, function($a, $b) {
            return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? '');
        });
        
        return $results;
    }

    /**
     * Get summary counts for a registration
     * 
     * @param string $registrationCode
     * @param string $phone
     * @return array ['registered' => N, 're_registered' => N, 'approved' => N, 'rejected' => N, 'total' => N]
     */
    public static function getSummary($registrationCode, $phone = '') {
        $actions = self::getByCode($registrationCode, $phone);
        
        $summary = [
            'registered' => 0,
            're_registered' => 0,
            'approved' => 0,
            'rejected' => 0,
            'total' => count($actions)
        ];
        
        foreach ($actions as $a) {
            $type = $a['action'] ?? '';
            if (isset($summary[$type])) {
                $summary[$type]++;
            }
        }
        
        return $summary;
    }

    private static function cleanPhone($phone) {
        $clean = preg_replace('/\D/', '', $phone);
        if (strlen($clean) > 10 && substr($clean, 0, 3) === '964') {
            $clean = substr($clean, 3);
        }
        if (strlen($clean) === 11 && substr($clean, 0, 2) === '07') {
            $clean = substr($clean, 1);
        }
        return substr($clean, -10);
    }
}
