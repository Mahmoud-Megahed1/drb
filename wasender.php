<?php
/**
 * WaSender API Helper v2.0
 * ========================
 * للتكامل مع WhatsApp عبر WaSender API
 * 
 * REDESIGNED: All state now lives in SQLite `messages` table (via WhatsAppLogger).
 * No more JSON queue files. No more dual logging. Single source of truth.
 */
require_once dirname(__FILE__) . '/include/WhatsAppLogger.php';
date_default_timezone_set('Asia/Baghdad');

class WaSender {
    private $apiKeys = [];
    private $apiUrl = 'https://wasenderapi.com/api/send-message';
    private $logFile;
    private $waLogger;
    private $apiKey;
    private $rotationFile;
    private $healthFile;
    
    public function __construct() {
        $this->logFile = dirname(__FILE__) . '/admin/data/whatsapp_log.txt';
        $this->rotationFile = dirname(__FILE__) . '/admin/data/wasender_rotation.txt';
        $this->healthFile = dirname(__FILE__) . '/admin/data/wasender_health.json';
        
        // Load API keys from config file
        $keysFile = dirname(__FILE__) . '/admin/data/wasender_keys.json';
        if (file_exists($keysFile)) {
            $config = json_decode(file_get_contents($keysFile), true);
            if (!empty($config['keys']) && is_array($config['keys'])) {
                $this->apiKeys = array_filter($config['keys']);
            }
        }
        
        if (empty($this->apiKeys)) {
            $this->apiKeys = ['d477a788ce0f9de54c5e86f847a95abdb01041f3f81dc576ccc6afdbe92079a8'];
        }
        
        $this->apiKey = $this->getNextApiKey();
        
        try {
            $this->waLogger = new WhatsAppLogger();
        } catch (Exception $e) {
            $this->waLogger = null;
            error_log('WaSender: WhatsAppLogger init failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Round-robin: get the next API key in rotation
     */
    private function getNextApiKey() {
        $count = count($this->apiKeys);
        if ($count === 1) return $this->apiKeys[0];
        
        $currentIndex = 0;
        if (file_exists($this->rotationFile)) {
            $currentIndex = intval(file_get_contents($this->rotationFile));
        }
        
        $key = $this->apiKeys[$currentIndex % $count];
        $nextIndex = ($currentIndex + 1) % $count;
        @file_put_contents($this->rotationFile, strval($nextIndex));
        
        return $key;
    }
    
    /**
     * Get Support Number
     */
    private function getSupportNumber() {
        $settingsFile = dirname(__FILE__) . '/admin/data/registration_settings.json';
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true);
            if (!empty($settings['support_number'])) {
                return $settings['support_number'];
            }
        }
        return '9647736000096';
    }
    
    /**
     * Log to debug text file
     */
    private function logToFile($data) {
        $line = date('Y-m-d H:i:s') . ' - ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($this->logFile, $line, FILE_APPEND);
    }
    
    /**
     * تنسيق رقم الهاتف لصيغة دولية
     */
    private function formatPhone($phone, $countryCode = '+964') {
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        $phone = ltrim($phone, '+');
        
        if (substr($phone, 0, 2) === '00') {
            $phone = substr($phone, 2);
        }
        
        if (preg_match('/^(20|966|971|964|965|968)\d{7,12}$/', $phone)) {
            return $phone;
        }
        
        if (substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }
        
        // مصر
        if (preg_match('/^1\d{9}$/', $phone)) return '20' . $phone;
        // السعودية
        if (preg_match('/^5\d{8}$/', $phone) && $countryCode !== '+971') return '966' . $phone;
        // الإمارات
        if (preg_match('/^5\d{8}$/', $phone) && $countryCode === '+971') return '971' . $phone;
        // العراق
        if (preg_match('/^7\d{9}$/', $phone)) return '964' . $phone;
        
        $countryCode = str_replace('+', '', $countryCode);
        return $countryCode . $phone;
    }
    
    // ==================== PUBLIC SEND METHODS ====================
    // All methods now: format phone → insert into DB → trigger worker → return
    
    /**
     * إرسال رسالة نصية
     */
    public function sendMessage($phone, $message, $countryCode = '+964', $extra = []) {
        $phone = $this->formatPhone($phone, $countryCode);
        
        if (!$this->waLogger) {
            $this->logToFile(['type' => 'CRITICAL', 'error' => 'WhatsAppLogger unavailable, message NOT queued', 'phone' => $phone]);
            return ['success' => false, 'error' => 'خطأ في قاعدة البيانات - الرسالة لم تُحفظ', 'queued' => false];
        }
        
        $apiPayload = ['to' => $phone, 'text' => $message];
        $messageType = $extra['type'] ?? 'text';
        $extra['country_code'] = $countryCode;
        
        $msgId = $this->waLogger->queueMessage($phone, $messageType, 'text', $apiPayload, $extra);
        
        $this->triggerBackgroundWorker();
        
        return ['success' => true, 'queued' => true, 'message_id' => $msgId, 'message' => 'Message queued for delivery'];
    }
    
    /**
     * إرسال رسالة مع صورة
     */
    public function sendImage($phone, $imageUrl, $caption = '', $countryCode = '+964', $extra = []) {
        $phone = $this->formatPhone($phone, $countryCode);
        
        if (!$this->waLogger) {
            $this->logToFile(['type' => 'CRITICAL', 'error' => 'WhatsAppLogger unavailable, image NOT queued', 'phone' => $phone]);
            return ['success' => false, 'error' => 'خطأ في قاعدة البيانات - الرسالة لم تُحفظ', 'queued' => false];
        }
        
        $apiPayload = ['to' => $phone, 'imageUrl' => $imageUrl, 'text' => $caption];
        $messageType = $extra['type'] ?? 'image';
        $extra['country_code'] = $countryCode;
        
        $msgId = $this->waLogger->queueMessage($phone, $messageType, 'image', $apiPayload, $extra);
        
        $this->triggerBackgroundWorker();
        
        return ['success' => true, 'queued' => true, 'message_id' => $msgId, 'message' => 'Image queued for delivery'];
    }
    
    /**
     * إرسال رسالة مع ملف PDF
     */
    public function sendDocument($phone, $documentUrl, $filename, $caption = '', $countryCode = '+964', $extra = []) {
        $phone = $this->formatPhone($phone, $countryCode);
        
        if (!$this->waLogger) {
            $this->logToFile(['type' => 'CRITICAL', 'error' => 'WhatsAppLogger unavailable, document NOT queued', 'phone' => $phone]);
            return ['success' => false, 'error' => 'خطأ في قاعدة البيانات - الرسالة لم تُحفظ', 'queued' => false];
        }
        
        $apiPayload = ['to' => $phone, 'document' => $documentUrl, 'filename' => $filename, 'caption' => $caption];
        $messageType = $extra['type'] ?? 'document';
        $extra['country_code'] = $countryCode;
        
        $msgId = $this->waLogger->queueMessage($phone, $messageType, 'document', $apiPayload, $extra);
        
        $this->triggerBackgroundWorker();
        
        return ['success' => true, 'queued' => true, 'message_id' => $msgId, 'message' => 'Document queued for delivery'];
    }
    
    // ==================== MESSAGE TEMPLATES ====================
    
    /**
     * إرسال رسالة استلام التسجيل
     */
    public function sendRegistrationReceived($orderData) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? 'مشترك';
        $carType = $orderData['car_type'] ?? '-';
        $registrationCode = $orderData['registration_code'] ?? '';
        
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $defaultMessage = "🏎️ *تسجيل سيارات الاستعراض الحر*\n━━━━━━━━━━━━━━━\n📋 *تم حجز طلبك بنجاح!*\n\n🔢 *رقم الطلب:* {wasel}\n👤 *الاسم:* {name}\n🚗 *السيارة:* {car_type}\n\n⏳ *سيتم التواصل معك قريباً لتأكيد الطلب*\n━━━━━━━━━━━━━━━\n\n🔑 *كود التسجيل السريع:*\n{registration_code}\n📌 _احتفظ بهذا الكود للتسجيل السريع في البطولات القادمة_";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['registration_message'])) {
                $defaultMessage = $messages['registration_message'];
            }
        }
        
        $message = str_replace(
            ['{wasel}', '{name}', '{car_type}', '{registration_code}'],
            [$wasel, $name, $carType, $registrationCode],
            $defaultMessage
        );
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode, [
            'type' => 'registration_received',
            'name' => $name,
            'wasel' => $wasel,
            'registration_code' => $registrationCode
        ]);
    }
    
    /**
     * إرسال رسالة القبول مع صورة الـ Frame
     */
    public function sendAcceptanceWithImage($orderData, $imageUrl) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? 'مشترك';
        $carType = $orderData['car_type'] ?? '-';
        $plate = $orderData['plate_full'] ?? '';
        
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $defaultCaption = "🏎️ *تم تأكيد اشتراكك في البطولة!*\n\n🔢 *رقم التسجيل:* {wasel}\n👤 *الاسم:* {name}\n🚗 *السيارة:* {car_type}\n\n✅ مبروك! تم قبول سيارتك للمشاركة\n📍 يرجى الالتزام بالقوانين والتعليمات";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['acceptance_message'])) {
                $defaultCaption = $messages['acceptance_message'];
            }
        }
        
        $caption = str_replace(
            ['{wasel}', '{name}', '{car_type}', '{plate}'],
            [$wasel, $name, $carType, $plate],
            $defaultCaption
        );
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendImage($phone, $imageUrl, $caption, $countryCode, [
            'type' => 'acceptance',
            'name' => $name,
            'wasel' => $wasel
        ]);
    }
    
    /**
     * إرسال رسالة القبول بدون صورة (نص فقط)
     */
    public function sendAcceptanceText($orderData) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? 'مشترك';
        $carType = $orderData['car_type'] ?? '-';
        $plate = $orderData['plate_full'] ?? '';
        
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $defaultMessage = "🏎️ *تم تأكيد اشتراكك في البطولة!*\n\n🔢 *رقم التسجيل:* {wasel}\n👤 *الاسم:* {name}\n🚗 *السيارة:* {car_type}\n\n✅ مبروك! تم قبول سيارتك للمشاركة\n📍 يرجى الالتزام بالقوانين والتعليمات";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['acceptance_message'])) {
                $defaultMessage = $messages['acceptance_message'];
            }
        }
        
        $message = str_replace(
            ['{wasel}', '{name}', '{car_type}', '{plate}'],
            [$wasel, $name, $carType, $plate],
            $defaultMessage
        );
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode, [
            'type' => 'acceptance',
            'name' => $name,
            'wasel' => $wasel
        ]);
    }
    
    /**
     * إرسال رسالة الرفض
     * FIX: Now passes name/wasel in extras (was missing → caused "غير معروف")
     */
    public function sendRejection($orderData, $reason = '') {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? 'مشترك';
        $carType = $orderData['car_type'] ?? '-';
        
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $supportNumber = $this->getSupportNumber();
        
        $defaultMessage = "🏎️ *تسجيل سيارات الاستعراض الحر*\n━━━━━━━━━━━━━━━\n🔄 *يرجى مراجعة وتعديل طلب التسجيل*\n\n🔢 *رقم التسجيل:* {wasel}\n👤 *الاسم:* {name}\n📝 *الملاحظات:* {reason}\n\n✏️ *يمكنك إعادة التسجيل بعد إجراء التعديلات المطلوبة*\n\n📞 للاستفسار: +{$supportNumber}\n━━━━━━━━━━━━━━━";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['rejection_message'])) {
                $defaultMessage = $messages['rejection_message'];
            }
        }
        
        $message = str_replace(
            ['{wasel}', '{name}', '{car_type}', '{reason}'],
            [$wasel, $name, $carType, $reason],
            $defaultMessage
        );
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode, [
            'type' => 'rejection',
            'name' => $name,
            'wasel' => $wasel
        ]);
    }
    
    /**
     * إرسال رسالة تفعيل الحساب
     * FIX: Now passes name in extras
     */
    public function sendAccountActivation($memberData) {
        $phone = $memberData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $name = $memberData['name'] ?? $memberData['full_name'] ?? 'عضو';
        $permanentCode = $memberData['permanent_code'] ?? '';
        
        if (empty($permanentCode)) {
            return ['success' => false, 'error' => 'كود العضو مفقود'];
        }
        
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $supportNumber = $this->getSupportNumber();
        
        $defaultMessage = "🏎️ *تفعيل حسابك في نادي بلاد الرافدين*\n";
        $defaultMessage .= "━━━━━━━━━━━━━━━\n";
        $defaultMessage .= "✅ *تم تفعيل حسابك بنجاح!*\n\n";
        $defaultMessage .= "👤 *الاسم:* {name}\n";
        $defaultMessage .= "🔑 *كود التسجيل الدائم:*\n";
        $defaultMessage .= "*{permanent_code}*\n\n";
        $defaultMessage .= "📌 *احتفظ بهذا الكود!*\n";
        $defaultMessage .= "يمكنك استخدامه للتسجيل السريع في جميع البطولات القادمة.\n";
        $defaultMessage .= "━━━━━━━━━━━━━━━\n";
        $defaultMessage .= "📞 للاستفسار: +{$supportNumber}";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['activation_message'])) {
                $defaultMessage = $messages['activation_message'];
            }
        }
        
        $message = str_replace(
            ['{name}', '{permanent_code}'],
            [$name, $permanentCode],
            $defaultMessage
        );
        
        $countryCode = $memberData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode, [
            'type' => 'activation',
            'name' => $name,
            'registration_code' => $permanentCode
        ]);
    }
    
    /**
     * إرسال QR فقط للدخول السريع
     * FIX: Now passes name/wasel in extras
     */
    public function sendQrOnly($orderData, $qrImageUrl) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? $orderData['name'] ?? 'مشارك';
        $carType = $orderData['car_type'] ?? '-';
        
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $defaultCaption = "🏎️ *كود الدخول السريع*\n";
        $defaultCaption .= "━━━━━━━━━━━━━━━\n";
        $defaultCaption .= "👤 *الاسم:* {name}\n";
        $defaultCaption .= "🚗 *السيارة:* {car_type}\n";
        $defaultCaption .= "🔢 *رقم الواصل:* {wasel}\n\n";
        $defaultCaption .= "📲 امسح هذا الكود عند بوابة الدخول\n";
        $defaultCaption .= "━━━━━━━━━━━━━━━";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['qr_only_message'])) {
                $defaultCaption = $messages['qr_only_message'];
            }
        }
        
        $caption = str_replace(
            ['{wasel}', '{name}', '{car_type}'],
            [$wasel, $name, $carType],
            $defaultCaption
        );
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendImage($phone, $qrImageUrl, $caption, $countryCode, [
            'type' => 'qr_only',
            'name' => $name,
            'wasel' => $wasel
        ]);
    }
    
    // ==================== API REQUEST ====================
    
    /**
     * Execute API request to WaSender
     * Returns: ['success' => bool, 'error' => string|null, 'response' => array|null, 'rate_limit' => bool, 'retry_after' => int]
     */
    public function makeRequest($data) {
        // $data can be JSON string (from DB) or array
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        
        $postData = json_encode($data);
        
        $this->logToFile(['type' => 'REQUEST', 'url' => $this->apiUrl, 'phone' => $data['to'] ?? 'unknown']);
        
        if (function_exists('curl_init')) {
            $ch = curl_init();
            
            curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($postData)
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            
            // CA bundle paths
            foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/ca-bundle.pem'] as $path) {
                if (file_exists($path)) { curl_setopt($ch, CURLOPT_CAINFO, $path); break; }
            }
            
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            
            // SSL retry
            if ($curlErrno == CURLE_SSL_CACERT || $curlErrno == CURLE_SSL_PEER_CERTIFICATE || 
                $curlErrno == CURLE_SSL_CONNECT_ERROR || strpos($curlError, 'SSL') !== false) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
            }
            
            // Rate limit
            if ($httpCode === 429) {
                $respData = json_decode($response, true);
                $retryAfter = $respData['retry_after'] ?? 10;
                curl_close($ch);
                $this->logToFile(['type' => 'RATE_LIMIT', 'retry_after' => $retryAfter]);
                return ['success' => false, 'error' => 'Rate limit (429)', 'rate_limit' => true, 'retry_after' => $retryAfter];
            }
            
            curl_close($ch);
            
            if ($response === false || !empty($curlError)) {
                $this->logToFile(['type' => 'CURL_ERROR', 'error' => $curlError, 'http_code' => $httpCode]);
                return ['success' => false, 'error' => 'cURL: ' . $curlError, 'retryable' => true];
            }
        } else {
            // Fallback: file_get_contents
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Authorization: Bearer " . $this->apiKey . "\r\n" .
                               "Content-Type: application/json\r\n" .
                               "Accept: application/json\r\n",
                    'content' => $postData,
                    'timeout' => 60,
                    'ignore_errors' => true
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ];
            $context = stream_context_create($options);
            $response = @file_get_contents($this->apiUrl, false, $context);
            
            $httpCode = 0;
            if (isset($http_response_header) && count($http_response_header) > 0) {
                preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
                $httpCode = isset($matches[1]) ? (int)$matches[1] : 0;
            }
            
            if ($response === false) {
                return ['success' => false, 'error' => 'Connection failed', 'retryable' => true];
            }
        }
        
        // Parse API response
        $this->logToFile(['type' => 'API_RESPONSE', 'http_code' => $httpCode, 'response' => substr($response, 0, 500)]);
        
        $result = json_decode($response, true);
        $success = ($httpCode >= 200 && $httpCode < 300);
        
        // API-level failure detection
        if ($success && is_array($result)) {
            if (isset($result['status']) && !in_array($result['status'], ['success', 'ok', true], true)) $success = false;
            if (isset($result['success']) && $result['success'] === false) $success = false;
            if (isset($result['error']) && !empty($result['error'])) $success = false;
        }
        
        // Determine if retryable
        $retryable = !$success && ($httpCode >= 500 || $httpCode === 0);
        $permanent = !$success && ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429);
        
        return [
            'success' => $success,
            'http_code' => $httpCode,
            'response' => $result,
            'error' => !$success ? ($result['message'] ?? $result['error'] ?? "HTTP $httpCode") : null,
            'retryable' => $retryable,
            'permanent' => $permanent,
            'api_key_used' => substr($this->apiKey, -8)
        ];
    }
    
    // ==================== QUEUE PROCESSOR ====================
    
    /**
     * Process the message queue (called by worker/cron)
     * Reads from DB, sends via API, updates DB.
     */
    public function processQueueLoop($maxRuntime = 290) {
        @set_time_limit($maxRuntime + 10);
        $startTime = time();
        $processed = 0;
        $delaySeconds = 7;
        
        if (!$this->waLogger) {
            $this->logToFile(['type' => 'WORKER_ERROR', 'error' => 'WhatsAppLogger not available']);
            return 0;
        }
        
        $this->logToFile(['type' => 'WORKER_START', 'max_runtime' => $maxRuntime]);
        
        while (time() - $startTime < $maxRuntime) {
            // Pick next message (concurrency-safe via status guard)
            $msg = $this->waLogger->pickNextMessage();
            
            if (!$msg) {
                // Check if there are messages waiting for a future retry
                $nextRetry = $this->waLogger->getNextRetryTime();
                if ($nextRetry) {
                    $waitSeconds = max(1, min(60, strtotime($nextRetry) - time()));
                    $this->logToFile(['type' => 'WORKER_WAITING', 'next_retry_in' => $waitSeconds . 's']);
                    sleep($waitSeconds);
                    continue; // Re-check after waiting
                }
                
                $this->logToFile(['type' => 'WORKER_IDLE', 'processed' => $processed]);
                break; // No messages at all
            }
            
            $this->logToFile(['type' => 'WORKER_PROCESSING', 'id' => $msg['id'], 'phone' => $msg['phone'], 'attempt' => $msg['attempts']]);
            
            // Rotate API key before each message
            $this->apiKey = $this->getNextApiKey();
            
            // Send via API
            $result = $this->makeRequest($msg['api_payload']);
            
            if ($result['success']) {
                $this->waLogger->markSent($msg['id'], $result['response'] ?? null, $result['api_key_used'] ?? null);
                $this->updateHealthStatus(true);
                $this->logToFile(['type' => 'WORKER_SENT', 'id' => $msg['id']]);
            } elseif ($result['rate_limit'] ?? false) {
                $retryAfter = $result['retry_after'] ?? 10;
                $this->waLogger->markRateLimited($msg['id'], $retryAfter);
                $this->logToFile(['type' => 'WORKER_RATE_LIMIT', 'id' => $msg['id'], 'retry_after' => $retryAfter, 'note' => 'Attempt NOT counted']);
                sleep($retryAfter);
            } else {
                $this->waLogger->markFailed($msg['id'], $result['error'] ?? 'Unknown error', $msg['attempts'], $msg['max_attempts']);
                $this->updateHealthStatus(false, $result['error'] ?? '');
                $this->logToFile(['type' => 'WORKER_FAILED', 'id' => $msg['id'], 'error' => $result['error']]);
            }
            
            $processed++;
            sleep($delaySeconds);
        }
        
        $this->logToFile(['type' => 'WORKER_END', 'processed' => $processed, 'runtime' => (time() - $startTime)]);
        return $processed;
    }
    
    // ==================== LEGACY COMPATIBILITY ====================
    
    /**
     * Get pending count (reads from DB now)
     */
    public function getPendingCount() {
        return $this->waLogger ? $this->waLogger->getPendingCount() : 0;
    }
    
    /**
     * Get queued messages (reads from DB now)
     */
    public function getQueuedMessages($statusFilter = 'pending') {
        if (!$this->waLogger) return [];
        $messages = $this->waLogger->getMessages($statusFilter);
        
        // Map to legacy format expected by pending_messages.php
        return array_map(function($msg) {
            return [
                'id' => $msg['id'],
                'phone' => $msg['phone'],
                'name' => $msg['recipient_name'],
                'wasel' => $msg['wasel'],
                'type' => $msg['content_type'],
                'message_type' => $msg['message_type'],
                'status' => $msg['status'],
                'error' => $msg['error_message'],
                'message_preview' => $msg['message_preview'],
                'data' => json_decode($msg['api_payload'], true),
                'created_at' => $msg['created_at'],
                'sent_at' => $msg['sent_at'],
                'failed_at' => $msg['failed_at'],
                'attempts' => $msg['attempts'],
                'max_attempts' => $msg['max_attempts'],
                'next_retry_at' => $msg['next_retry_at'],
                'is_manual' => (bool)$msg['is_manual'],
                'last_retry' => $msg['sending_at'],
                'batch_id' => $msg['batch_id']
            ];
        }, $messages);
    }
    
    /**
     * Retry a single message
     */
    public function retryMessage($messageId) {
        if (!$this->waLogger) return ['success' => false, 'error' => 'Logger unavailable'];
        
        if ($this->waLogger->resetToQueued($messageId)) {
            $this->triggerBackgroundWorker();
            return ['success' => true, 'message' => 'تمت إعادة الرسالة للطابور'];
        }
        return ['success' => false, 'error' => 'الرسالة غير موجودة أو لا يمكن إعادتها'];
    }
    
    /**
     * Manual mark as sent
     */
    public function markAsSent($messageId, $adminUsername = 'admin') {
        if (!$this->waLogger) return ['success' => false, 'error' => 'Logger unavailable'];
        
        if ($this->waLogger->markAsSentManual($messageId, $adminUsername)) {
            return ['success' => true, 'message' => 'تم تأكيد الإرسال يدوياً'];
        }
        return ['success' => false, 'error' => 'الرسالة غير موجودة'];
    }
    
    /**
     * Retry all pending messages (triggers worker)
     */
    public function retryAll($limit = 10) {
        if ($this->waLogger) {
            // Reset failed_permanent messages back to queued
            $now = date('Y-m-d H:i:s');
            $this->waLogger->resetAllFailed($limit, $now);
        }
        $this->triggerBackgroundWorker();
        return ['success' => true, 'message' => 'تم إعادة الرسائل الفاشلة للطابور وتشغيل المعالجة'];
    }
    
    /**
     * Remove message from queue
     */
    public function removeFromQueue($messageId) {
        return $this->waLogger ? $this->waLogger->deleteMessage($messageId) : false;
    }
    
    /**
     * Clear sent messages
     */
    public function clearSentMessages() {
        return $this->waLogger ? $this->waLogger->clearCompleted() : false;
    }
    
    // ==================== HEALTH & WORKER ====================
    
    /**
     * Trigger background worker (hybrid: HTTP trigger)
     */
    private function triggerBackgroundWorker() {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'yellowgreen-quail-410393.hostingersite.com';
        $url = "$protocol://$host/api/whatsapp_worker.php";

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            @curl_exec($ch);
            curl_close($ch);
            return;
        }

        $parts = parse_url($url);
        if (!$parts) return;
        $fp = @fsockopen(
            ($parts['scheme'] === 'https' ? 'ssl://' : '') . $parts['host'],
            $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80),
            $errno, $errstr, 1
        );
        if ($fp) {
            fwrite($fp, "GET " . $parts['path'] . " HTTP/1.1\r\nHost: " . $parts['host'] . "\r\nConnection: Close\r\n\r\n");
            fclose($fp);
        }
    }
    
    /**
     * Check API health status
     */
    public function checkHealth() {
        $health = $this->loadHealthStatus();
        
        if (!empty($health['checked_at'])) {
            $age = time() - strtotime($health['checked_at']);
            if ($age < 60) return $health;
        }
        
        $pendingCount = $this->getPendingCount();
        $health['pending_count'] = $pendingCount;
        $health['checked_at'] = date('Y-m-d H:i:s');
        
        if ($pendingCount > 0 && (($health['status'] ?? '') === 'disconnected' || $pendingCount >= 3)) {
            $health['status'] = 'disconnected';
        }
        
        $this->saveHealthStatus($health);
        return $health;
    }
    
    private function updateHealthStatus($success, $error = '') {
        $health = $this->loadHealthStatus();
        if ($success) {
            $health['status'] = 'connected';
            $health['last_success'] = date('Y-m-d H:i:s');
            $health['error'] = '';
        } else {
            $health['status'] = 'disconnected';
            $health['last_error'] = date('Y-m-d H:i:s');
            $health['error'] = $error;
        }
        $health['pending_count'] = $this->getPendingCount();
        $health['checked_at'] = date('Y-m-d H:i:s');
        $this->saveHealthStatus($health);
    }
    
    private function loadHealthStatus() {
        if (!file_exists($this->healthFile)) return ['status' => 'unknown', 'pending_count' => 0];
        return json_decode(file_get_contents($this->healthFile), true) ?? ['status' => 'unknown'];
    }
    
    private function saveHealthStatus($health) {
        $dir = dirname($this->healthFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($this->healthFile, json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    public static function getHealthStatus() {
        $file = dirname(__FILE__) . '/admin/data/wasender_health.json';
        if (!file_exists($file)) return ['status' => 'unknown', 'pending_count' => 0];
        return json_decode(file_get_contents($file), true) ?? ['status' => 'unknown', 'pending_count' => 0];
    }
    
    // ==================== LEGACY HELPERS ====================
    
    public function sendOrderReceipt($orderData, $siteUrl = '') {
        return $this->sendRegistrationReceived($orderData);
    }
    
    public function sendHandedToDelivery($orderData) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? $orderData['name'] ?? 'عميل';
        $message = "🏎️ *تسجيل سيارات الاستعراض الحر*\n━━━━━━━━━━━━━━━\n🚚 *تم تحديث حالة طلبك!*\n\n📋 *رقم الطلب:* {$wasel}\n👤 *الاسم:* {$name}\n━━━━━━━━━━━━━━━";
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode, ['type' => 'update', 'name' => $name, 'wasel' => $wasel]);
    }
    
    public function sendDeliveryConfirmation($orderData) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? $orderData['name'] ?? 'عميل';
        $message = "🏎️ *تسجيل سيارات الاستعراض الحر*\n━━━━━━━━━━━━━━━\n✅ *تم بنجاح!*\n\n📋 *رقم الطلب:* {$wasel}\n👤 *الاسم:* {$name}\n\n🎉 شكراً لك!\n━━━━━━━━━━━━━━━";
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode, ['type' => 'confirmation', 'name' => $name, 'wasel' => $wasel]);
    }
}

// Global helper functions (backward-compatible)
function sendWhatsAppMessage($phone, $message) {
    $wasender = new WaSender();
    return $wasender->sendMessage($phone, $message);
}

function sendRegistrationReceivedWhatsApp($orderData) {
    $wasender = new WaSender();
    return $wasender->sendRegistrationReceived($orderData);
}

function sendAcceptanceImageWhatsApp($orderData, $imageUrl) {
    $wasender = new WaSender();
    return $wasender->sendAcceptanceWithImage($orderData, $imageUrl);
}

function sendRejectionWhatsApp($orderData, $reason = '') {
    $wasender = new WaSender();
    return $wasender->sendRejection($orderData, $reason);
}

function sendHandedToDeliveryWhatsApp($orderData) {
    $wasender = new WaSender();
    return $wasender->sendHandedToDelivery($orderData);
}

function sendOrderReceiptWhatsApp($orderData, $siteUrl = '') {
    $wasender = new WaSender();
    return $wasender->sendOrderReceipt($orderData, $siteUrl);
}

function sendDeliveryConfirmationWhatsApp($orderData) {
    $wasender = new WaSender();
    return $wasender->sendDeliveryConfirmation($orderData);
}
?>
