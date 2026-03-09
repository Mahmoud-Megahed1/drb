<?php
/**
 * Badge Cache Service
 * Handles JSON caching for high-availability badge access
 * 
 * - Writes cache on profile update
 * - Reads cache when DB is down
 * - Includes metadata (generated_at, source)
 */

class BadgeCacheService {
    
    private static $cacheDir = __DIR__ . '/../cache/badges';
    
    /**
     * Initialize cache directory
     */
    private static function init() {
        if (!file_exists(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
            // Secure cache directory - prevent direct browser access if needed, 
            // though these are public profiles anyway. a simple index.php silence is good.
            file_put_contents(self::$cacheDir . '/index.php', ''); 
            file_put_contents(self::$cacheDir . '/.htaccess', 'Options -Indexes');
        }
    }
    
    /**
     * Get cache file path for a token
     */
    private static function getFilePath($token) {
        // Sanitize token to be filesystem safe
        $safeToken = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        return self::$cacheDir . '/' . $safeToken . '.json';
    }
    
    /**
     * Cache member profile data
     * 
     * @param string $token Permanent Badge/Registration Token
     * @param array $data Profile data (from MemberService::getProfile)
     * @return bool Success
     */
    public static function cacheProfile($token, $data) {
        self::init();
        
        $payload = [
            'meta' => [
                'generated_at' => date('c'),
                'source' => 'cache',
                'version' => '1.0'
            ],
            'data' => $data
        ];
        
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        return file_put_contents(self::getFilePath($token), $json) !== false;
    }
    
    /**
     * Retrieve profile from cache
     * 
     * @param string $token Permanent Badge/Registration Token
     * @return array|null Returns array with ['data' => ..., 'meta' => ...] or null
     */
    public static function getProfile($token) {
        $path = self::getFilePath($token);
        
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $json = json_decode($content, true);
            
            if ($json && isset($json['data'])) {
                // Return structure compatible with fresh service call, 
                // but wrapped to indicate it's cached or just extract data?
                // For simplicity, let's keep the wrapper so the consumer knows.
                return $json; 
            }
        }
        
        return null;
    }
    
    /**
     * Refresh cache for a member
     * Call this whenever member data changes (Entry, Warning, Update)
     */
    public static function refresh($permanentCode) {
        // We need MemberService here. 
        // To avoid circular dependency issues, we require it inside the method if not already loaded
        require_once __DIR__ . '/MemberService.php';
        
        try {
            $data = MemberService::getProfile($permanentCode);
            if ($data) {
                return self::cacheProfile($permanentCode, $data);
            }
        } catch (Exception $e) {
            error_log("Failed to refresh badge cache for $permanentCode: " . $e->getMessage());
        }
        return false;
    }
}
?>
