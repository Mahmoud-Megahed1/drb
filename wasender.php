<?php
/**
 * WaSender API Helper
 * للتكامل مع WhatsApp عبر WaSender API
 */
require_once dirname(__FILE__) . '/include/WhatsAppLogger.php';
date_default_timezone_set('Asia/Baghdad');

class WaSender {
    private $apiKeys = [];
    private $apiUrl = 'https://wasenderapi.com/api/send-message';
    private $logFile;
    private $waLogger;
    private $apiKey; // The currently selected key for this request
    private $rotationFile; // Tracks which key to use next
    private $queueFile; // Message queue for failed sends
    private $healthFile; // Cached health status
    
    public function __construct() {
        $this->logFile = dirname(__FILE__) . '/admin/data/whatsapp_log.txt';
        $this->rotationFile = dirname(__FILE__) . '/admin/data/wasender_rotation.txt';
        $this->queueFile = dirname(__FILE__) . '/admin/data/message_queue.json';
        $this->healthFile = dirname(__FILE__) . '/admin/data/wasender_health.json';
        
        // Load API keys from config file (supports multiple sessions/numbers)
        $keysFile = dirname(__FILE__) . '/admin/data/wasender_keys.json';
        if (file_exists($keysFile)) {
            $config = json_decode(file_get_contents($keysFile), true);
            if (!empty($config['keys']) && is_array($config['keys'])) {
                $this->apiKeys = array_filter($config['keys']); // Remove empty entries
            }
        }
        
        // Fallback to default key if no config
        if (empty($this->apiKeys)) {
            $this->apiKeys = ['d477a788ce0f9de54c5e86f847a95abdb01041f3f81dc576ccc6afdbe92079a8'];
        }
        
        // Select key using round-robin rotation
        $this->apiKey = $this->getNextApiKey();
        
        try {
            $this->waLogger = new WhatsAppLogger();
        } catch (Exception $e) {
            $this->waLogger = null;
        }
    }
    
    /**
     * Round-robin: get the next API key in rotation
     */
    private function getNextApiKey() {
        $count = count($this->apiKeys);
        if ($count === 1) return $this->apiKeys[0];
        
        // Read current index
        $currentIndex = 0;
        if (file_exists($this->rotationFile)) {
            $currentIndex = intval(file_get_contents($this->rotationFile));
        }
        
        // Get key at current index
        $key = $this->apiKeys[$currentIndex % $count];
        
        // Save next index
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
     * حفظ سجل الرسالة في ملف JSON
     */
    private function saveMessageLog($phone, $type, $success, $error = null, $extra = []) {
        // 1. Legacy: Save to message_logs.json (DISABLED - Modern logger uses SQLite)
        /*
        $logsFile = dirname(__FILE__) . '/admin/data/message_logs.json';
        
        $logs = [];
        if (file_exists($logsFile)) {
            $logs = json_decode(file_get_contents($logsFile), true) ?? [];
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'phone' => $phone,
            'type' => $type,
            'success' => $success,
            'error' => $error,
            'wasel' => $extra['wasel'] ?? '',
            'name' => $extra['name'] ?? ''
        ];
        
        $logs[] = $logEntry;
        
        // Keep only last 5000 logs
        if (count($logs) > 5000) {
            $logs = array_slice($logs, -5000);
        }
        
        file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        */
        
        // 2. WhatsAppLogger: Save to DB + whatsapp_log.json
        if ($this->waLogger) {
            try {
                // Map simple type to WhatsApp message type constant
                $messageType = $type;
                if (isset($extra['type'])) {
                    $messageType = $extra['type'];
                }
                
                return $this->waLogger->log(
                    $phone,
                    $messageType,
                    $success,
                    $error,
                    [
                        'name' => $extra['name'] ?? null,
                        'wasel' => $extra['wasel'] ?? null,
                        'country_code' => $extra['country_code'] ?? '+964',
                        'registration_code' => $extra['registration_code'] ?? null,
                        'db_id' => $extra['db_id'] ?? null
                    ]
                );
            } catch (Exception $e) {
                error_log('WhatsAppLogger Error in WaSender: ' . $e->getMessage());
            }
        }
        return null;
    }
    
    /**
     * كتابة log للملف
     */
    private function logToFile($data) {
        $line = date('Y-m-d H:i:s') . ' - ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
    
    /**
     * تنسيق رقم الهاتف لصيغة دولية
     * يتعامل مع كل الحالات: بصفر، بدون صفر، بكود دولة، بـ + أو 00
     */
    private function formatPhone($phone, $countryCode = '+964') {
        // إزالة المسافات والرموز الخاصة
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        
        // إزالة علامة + من البداية
        $phone = ltrim($phone, '+');
        
        // إذا يبدأ بـ 00 احذفهم
        if (substr($phone, 0, 2) === '00') {
            $phone = substr($phone, 2);
        }
        
        // تحديد الدولة بناءً على طول الرقم والبادئة
        
        // حالة 1: الرقم يبدأ بكود دولة كامل (20, 966, 971, 964, 965, 968, etc.)
        // هذا النمط يغطي أرقام تبدأ بكود الدولة مباشرة وتتراوح بين 7 إلى 12 رقمًا بعد الكود
        if (preg_match('/^(20|966|971|964|965|968)\d{7,12}$/', $phone)) {
            return $phone; // الرقم صحيح بالفعل
        }
        
        // حالة 2: الرقم يبدأ بصفر
        if (substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1); // احذف الصفر
        }
        
        // الآن حدد الدولة من البادئة وطول الرقم
        
        // مصر: 1xxxxxxxxx (10 أرقام بعد 0) -> 201xxxxxxxxx
        if (preg_match('/^1\d{9}$/', $phone)) {
            return '20' . $phone;
        }
        
        // السعودية: 5xxxxxxxx (9 أرقام بعد 0) -> 9665xxxxxxxx
        if (preg_match('/^5\d{8}$/', $phone)) {
            return '966' . $phone;
        }
        
        // الإمارات: 5xxxxxxxx (9 أرقام بعد 0) -> 9715xxxxxxxx
        // يجب أن يكون هذا قبل العراق إذا كان هناك تداخل في البادئات
        if (preg_match('/^5\d{8}$/', $phone) && $countryCode === '+971') { // Added countryCode check for disambiguation
            return '971' . $phone;
        }
        
        // العراق: 7xxxxxxxxx (10 أرقام بعد 0) -> 9647xxxxxxxxx
        if (preg_match('/^7\d{9}$/', $phone)) {
            return '964' . $phone;
        }
        
        // لو مفيش مطابقة، استخدم الكود الافتراضي (العراق)
        $countryCode = str_replace('+', '', $countryCode);
        return $countryCode . $phone;
    }
    
    /**
     * إرسال رسالة نصية (يضعها في الطابور)
     */
    public function sendMessage($phone, $message, $countryCode = '+964', $extra = []) {
        $phone = $this->formatPhone($phone, $countryCode);
        
        $data = [
            'to' => $phone,
            'text' => $message
        ];
        
        // حفظ السجل الأولي كـ pending
        $logEntry = $this->saveMessageLog($phone, $extra['type'] ?? 'text', false, 'Queued for sending', $extra);
        
        // Add to global queue
        $this->queueMessage($phone, 'text', $data, $extra, '', $logEntry['db_id'] ?? null);
        
        // Wake up background worker
        $this->triggerBackgroundWorker();
        
        // Return instant success to the browser
        return ['success' => true, 'queued' => true, 'message' => 'Message queued for background delivery'];
    }
    
    /**
     * إرسال رسالة مع صورة (يضعها في الطابور)
     */
    public function sendImage($phone, $imageUrl, $caption = '', $countryCode = '+964', $extra = []) {
        $phone = $this->formatPhone($phone, $countryCode);
        
        // WaSender API يتطلب 'imageUrl' ويستخدم 'text' كالتسمية
        $data = [
            'to' => $phone,
            'imageUrl' => $imageUrl,
            'text' => $caption
        ];
        
        // حفظ السجل الأولي كـ pending
        $logEntry = $this->saveMessageLog($phone, $extra['type'] ?? 'image', false, 'Queued for sending', $extra);
        
        // Add to global queue
        $this->queueMessage($phone, 'image', $data, $extra, '', $logEntry['db_id'] ?? null);
        
        // Wake up background worker
        $this->triggerBackgroundWorker();
        
        // Return instant success to the browser
        return ['success' => true, 'queued' => true, 'message' => 'Image queued for background delivery'];
    }
    
    /**
     * إرسال رسالة مع ملف PDF (يضعها في الطابور)
     */
    public function sendDocument($phone, $documentUrl, $filename, $caption = '', $countryCode = '+964', $extra = []) {
        $phone = $this->formatPhone($phone, $countryCode);
        
        $data = [
            'to' => $phone,
            'document' => $documentUrl,
            'filename' => $filename,
            'caption' => $caption
        ];
        
        // حفظ السجل الأولي كـ pending
        $logEntry = $this->saveMessageLog($phone, $extra['type'] ?? 'document', false, 'Queued for sending', $extra);
        
        // Add to global queue
        $this->queueMessage($phone, 'document', $data, $extra, '', $logEntry['db_id'] ?? null);
        
        // Wake up background worker
        $this->triggerBackgroundWorker();
        
        // Return instant success to the browser
        return ['success' => true, 'queued' => true, 'message' => 'Document queued for background delivery'];
    }
    
    /**
     * تنفيذ الطلب
     */
    private function makeRequest($data) {
        $postData = json_encode($data);
        
        // Log the request
        $this->logToFile([
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'REQUEST',
            'url' => $this->apiUrl,
            'data' => $data
        ]);
        
        // Try cURL first (more reliable for HTTPS)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            
            // Basic cURL settings
            curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            
            // Headers
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($postData)
            ]);
            
            // Timeout settings - important for shared hosting
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            
            // SSL Configuration for Hostinger
            // Option 1: Try with SSL verification first (more secure)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            
            // Use system CA bundle or specify Hostinger's CA path
            $caBundlePaths = [
                '/etc/ssl/certs/ca-certificates.crt',  // Debian/Ubuntu
                '/etc/pki/tls/certs/ca-bundle.crt',    // RHEL/CentOS
                '/etc/ssl/ca-bundle.pem',              // OpenSUSE
                '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem', // CentOS 7
            ];
            
            foreach ($caBundlePaths as $path) {
                if (file_exists($path)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $path);
                    break;
                }
            }
            
            // Additional settings for compatibility
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_ENCODING, ''); // Accept all encodings
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            
            // First attempt with SSL verification
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            
            // If SSL error, retry without verification
            if ($curlErrno == CURLE_SSL_CACERT || $curlErrno == CURLE_SSL_PEER_CERTIFICATE || 
                $curlErrno == CURLE_SSL_CONNECT_ERROR || strpos($curlError, 'SSL') !== false) {
                
                $this->logToFile([
                    'timestamp' => date('Y-m-d H:i:s'),
                    'type' => 'SSL_RETRY',
                    'error' => $curlError,
                    'errno' => $curlErrno
                ]);
                
                // Retry without SSL verification
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
            }
            
            if ($httpCode === 429) {
                $respData = json_decode($response, true);
                $retryAfter = $respData['retry_after'] ?? 6;
                $this->logToFile(['type' => 'RATE_LIMIT', 'retry_after' => $retryAfter, 'phone' => $data['to'] ?? 'unknown']);
                curl_close($ch);
                return ['success' => false, 'error' => 'Rate limit exceeded (429). Waiting ' . $retryAfter . 's', 'rate_limit' => true, 'retry_after' => $retryAfter];
            }
            
            curl_close($ch);
            
            if ($response === false || !empty($curlError)) {
                $this->logToFile([
                    'timestamp' => date('Y-m-d H:i:s'),
                    'type' => 'CURL_ERROR',
                    'error' => $curlError,
                    'errno' => $curlErrno ?? 0,
                    'phone' => $data['to'] ?? 'unknown',
                    'http_code' => $httpCode
                ]);
                return ['success' => false, 'message' => 'فشل في إرسال الرسالة: ' . $curlError];
            }
        } else {
            // Fallback to file_get_contents
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Authorization: Bearer " . $this->apiKey . "\r\n" .
                               "Content-Type: application/json\r\n" .
                               "Accept: application/json\r\n" .
                               "Content-Length: " . strlen($postData) . "\r\n",
                    'content' => $postData,
                    'timeout' => 60,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false
                ]
            ];
            
            $context = stream_context_create($options);
            $response = @file_get_contents($this->apiUrl, false, $context);
            
            // If failed, retry without SSL verification
            if ($response === false) {
                $options['ssl'] = [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ];
                $context = stream_context_create($options);
                $response = @file_get_contents($this->apiUrl, false, $context);
            }
            
            $httpCode = 0;
            if (isset($http_response_header) && count($http_response_header) > 0) {
                preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
                $httpCode = isset($matches[1]) ? (int)$matches[1] : 0;
            }
            
            if ($response === false) {
                $lastError = error_get_last();
                $this->logToFile([
                    'timestamp' => date('Y-m-d H:i:s'),
                    'type' => 'CONNECTION_FAILED',
                    'phone' => $data['to'] ?? 'unknown',
                    'error' => $lastError['message'] ?? 'file_get_contents failed'
                ]);
                return ['success' => false, 'message' => 'فشل في الاتصال بالسيرفر'];
            }
        }
        
        // Log response
        $this->logToFile([
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'API_RESPONSE',
            'phone' => $data['to'] ?? 'unknown',
            'http_code' => $httpCode,
            'response' => $response
        ]);
        
        if ($httpCode === 0) {
            return ['success' => true, 'message' => 'Registration saved'];
        }
        
        $result = json_decode($response, true);
        
        $success = ($httpCode >= 200 && $httpCode < 300);
        
        if ($success && is_array($result)) {
            if (isset($result['status']) && $result['status'] !== 'success' && $result['status'] !== 'ok' && $result['status'] !== true) {
                $success = false;
            }
            if (isset($result['success']) && $result['success'] === false) {
                $success = false;
            }
            if (isset($result['error']) && !empty($result['error'])) {
                $success = false;
            }
        }
        
        return [
            'success' => $success,
            'http_code' => $httpCode,
            'response' => $result,
            'error' => !$success ? ($result['message'] ?? $result['error'] ?? 'API Error') : null
        ];
    }
    
    /**
     * إرسال رسالة استلام التسجيل (عند تقديم الطلب)
     */
    public function sendRegistrationReceived($orderData) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? 'مشترك';
        $carType = $orderData['car_type'] ?? '-';
        $registrationCode = $orderData['registration_code'] ?? '';
        
        // Load custom message template
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $defaultMessage = "🏎️ *تسجيل سيارات الاستعراض الحر*\n━━━━━━━━━━━━━━━\n📋 *تم حجز طلبك بنجاح!*\n\n🔢 *رقم الطلب:* {wasel}\n👤 *الاسم:* {name}\n🚗 *السيارة:* {car_type}\n\n⏳ *سيتم التواصل معك قريباً لتأكيد الطلب*\n━━━━━━━━━━━━━━━\n\n🔑 *كود التسجيل السريع:*\n{registration_code}\n📌 _احتفظ بهذا الكود للتسجيل السريع في البطولات القادمة_";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['registration_message'])) {
                $defaultMessage = $messages['registration_message'];
            }
        }
        
        // Replace ALL placeholders including {registration_code}
        $message = str_replace(
            ['{wasel}', '{name}', '{car_type}', '{registration_code}'],
            [$wasel, $name, $carType, $registrationCode],
            $defaultMessage
        );
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode, [
            'type' => 'registration_received',
            'name' => $name,
            'wasel' => $wasel
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
        
        // Load custom message template
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $defaultCaption = "🏎️ *تم تأكيد اشتراكك في البطولة!*\n\n🔢 *رقم التسجيل:* {wasel}\n👤 *الاسم:* {name}\n🚗 *السيارة:* {car_type}\n\n✅ مبروك! تم قبول سيارتك للمشاركة\n📍 يرجى الالتزام بالقوانين والتعليمات";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['acceptance_message'])) {
                $defaultCaption = $messages['acceptance_message'];
            }
        }
        
        // Replace placeholders including {plate}
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
        
        // Load custom message template
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $defaultMessage = "🏎️ *تم تأكيد اشتراكك في البطولة!*\n\n🔢 *رقم التسجيل:* {wasel}\n👤 *الاسم:* {name}\n🚗 *السيارة:* {car_type}\n\n✅ مبروك! تم قبول سيارتك للمشاركة\n📍 يرجى الالتزام بالقوانين والتعليمات";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['acceptance_message'])) {
                $defaultMessage = $messages['acceptance_message'];
            }
        }
        
        // Replace placeholders including {plate}
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
     */
    public function sendRejection($orderData, $reason = '') {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? 'مشترك';
        $carType = $orderData['car_type'] ?? '-';
        
        // Load custom message template
        $messagesFile = dirname(__FILE__) . '/admin/data/whatsapp_messages.json';
        $supportNumber = $this->getSupportNumber();
        
        $defaultMessage = "🏎️ *تسجيل سيارات الاستعراض الحر*\n━━━━━━━━━━━━━━━\n🔄 *يرجى مراجعة وتعديل طلب التسجيل*\n\n🔢 *رقم التسجيل:* {wasel}\n👤 *الاسم:* {name}\n📝 *الملاحظات:* {reason}\n\n✏️ *يمكنك إعادة التسجيل بعد إجراء التعديلات المطلوبة*\n\n📞 للاستفسار: +{$supportNumber}\n━━━━━━━━━━━━━━━";
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true);
            if (isset($messages['rejection_message'])) {
                $defaultMessage = $messages['rejection_message'];
            }
        }
        
        // Replace placeholders
        $message = str_replace(
            ['{wasel}', '{name}', '{car_type}', '{reason}'],
            [$wasel, $name, $carType, $reason],
            $defaultMessage
        );
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode);
    }
    
    /**
     * إرسال رسالة تفعيل الحساب للأعضاء المستوردين
     */
    public function sendAccountActivation($memberData) {
        $phone = $memberData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $name = $memberData['name'] ?? $memberData['full_name'] ?? 'عضو';
        $permanentCode = $memberData['permanent_code'] ?? '';
        
        if (empty($permanentCode)) {
            return ['success' => false, 'error' => 'كود العضو مفقود'];
        }
        
        // Load custom message template
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
        
        // Replace placeholders
        $message = str_replace(
            ['{name}', '{permanent_code}'],
            [$name, $permanentCode],
            $defaultMessage
        );
        
        $countryCode = $memberData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode);
    }
    
    /**
     * إرسال QR فقط للدخول السريع
     */
    public function sendQrOnly($orderData, $qrImageUrl) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? $orderData['name'] ?? 'مشارك';
        $carType = $orderData['car_type'] ?? '-';
        
        // Load custom message template
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
        
        // Replace placeholders
        $caption = str_replace(
            ['{wasel}', '{name}', '{car_type}'],
            [$wasel, $name, $carType],
            $defaultCaption
        );
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendImage($phone, $qrImageUrl, $caption, $countryCode);
    }
    
    // ========== Message Queue System ==========
    
    /**
     * Save a message to the queue to be processed by background worker
     */
    public function queueMessage($phone, $type, $apiData, $extra = [], $error = '', $dbId = null) {
        // Prevent concurrent write issues with file locks (non-blocking to prevent deadlocks)
        $fp = fopen($this->queueFile, "c+");
        if (!$fp) return;
        
        // Try non-blocking lock with timeout
        $locked = false;
        $maxWait = 2; // seconds
        $start = time();
        while ((time() - $start) < $maxWait) {
            $locked = flock($fp, LOCK_EX | LOCK_NB);
            if ($locked) break;
            usleep(100000); // 100ms
        }
        
        if ($locked) {
            clearstatcache(true, $this->queueFile);
            $size = filesize($this->queueFile);
            $queue = [];
            if ($size > 0) {
                rewind($fp);
                $content = fread($fp, $size);
                $queue = json_decode($content, true) ?: [];
            }
            
            $queue[] = [
                'id' => uniqid('msg_'),
                'phone' => $phone,
                'name' => $extra['name'] ?? $extra['full_name'] ?? '',
                'wasel' => $extra['wasel'] ?? '',
                'type' => $type,
                'db_id' => $dbId, // ID from whatsapp_logs table
                'data' => $apiData,
                'extra' => $extra,
                'message_preview' => mb_substr($apiData['text'] ?? $apiData['caption'] ?? '', 0, 100),
                'error' => $error,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 'pending',  // Ensure status is pending for the worker
                'attempts' => 0,
                'max_attempts' => 3  // NEW: Max retry limit
            ];
            
            // Auto-clean: Remove sent messages older than 100 entries to prevent bloat
            $sentCount = 0;
            foreach ($queue as $qIdx => $qItem) {
                if (($qItem['status'] ?? '') === 'sent') {
                    $sentCount++;
                    if ($sentCount > 100) {
                        unset($queue[$qIdx]);
                    }
                }
            }
            $queue = array_values($queue);
            
            // Keep max 500 queued messages (prevent file bloat)
            if (count($queue) > 5000) {
                $queue = array_slice($queue, -5000);
            }
            
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        } else {
            // Lock timeout - append to a fallback file
            file_put_contents($this->queueFile . '.pending', json_encode([
                'id' => uniqid('msg_'),
                'phone' => $phone,
                'type' => $type,
                'data' => $apiData,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 'pending'
            ]) . "\n", FILE_APPEND);
        }
        fclose($fp);
        
        $this->updateHealthStatus(true);
    }
    
    /**
     * Load queue from file
     */
    private function loadQueue() {
        if (!file_exists($this->queueFile)) return [];
        $data = json_decode(file_get_contents($this->queueFile), true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Save queue to file
     */
    private function saveQueue($queue) {
        $dir = dirname($this->queueFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($this->queueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Get all pending messages
     */
    public function getQueuedMessages($statusFilter = 'pending') {
        $queue = $this->loadQueue();
        if ($statusFilter === 'all') return $queue;
        return array_filter($queue, fn($m) => ($m['status'] ?? '') === $statusFilter);
    }
    
    /**
     * Get count of pending messages
     */
    public function getPendingCount() {
        return count(array_filter($this->loadQueue(), fn($m) => ($m['status'] ?? '') === 'pending'));
    }
    
    /**
     * Retry a single queued message by ID
     */
    public function retryMessage($messageId) {
        $queue = $this->loadQueue();
        
        foreach ($queue as &$msg) {
            if ($msg['id'] === $messageId && $msg['status'] === 'pending') {
                $result = $this->makeRequest($msg['data']);
                $msg['attempts'] = ($msg['attempts'] ?? 1) + 1;
                $msg['last_retry'] = date('Y-m-d H:i:s');
                
                if ($result['success'] ?? false) {
                    $msg['status'] = 'sent';
                    $msg['sent_at'] = date('Y-m-d H:i:s');
                    
                    // Update the Message Log (with DB ID for direct hit)
                    $extraInfo = $msg['extra'] ?? [];
                    $extraInfo['db_id'] = $msg['db_id'] ?? null;

                    $this->saveMessageLog(
                        $msg['phone'], 
                        $msg['type'], 
                        true, 
                        null, 
                        $extraInfo
                    );

                    $this->saveQueue($queue);
                    $this->updateHealthStatus(true);
                    return ['success' => true, 'message' => 'تم الإرسال بنجاح'];
                } else {
                    $msg['error'] = $result['error'] ?? 'فشل مرة أخرى';
                    
                    // Update Message Log with fail (with DB ID for direct hit)
                    $extraInfo = $msg['extra'] ?? [];
                    $extraInfo['db_id'] = $msg['db_id'] ?? null;

                    $this->saveMessageLog(
                        $msg['phone'], 
                        $msg['type'], 
                        false, 
                        $msg['error'], 
                        $extraInfo
                    );

                    $this->saveQueue($queue);
                    return ['success' => false, 'error' => $msg['error']];
                }
            }
        }
        
        return ['success' => false, 'error' => 'الرسالة غير موجودة'];
    }
    
    /**
     * Trigger the background worker script via non-blocking HTTP request
     */
    private function triggerBackgroundWorker() {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        // Default to HOST if available, otherwise fallback (needed for CLI)
        $host = $_SERVER['HTTP_HOST'] ?? 'yellowgreen-quail-410393.hostingersite.com';
        
        // Correct path to the worker
        // E.g. https://domain.com/api/whatsapp_worker.php
        $url = "$protocol://$host/api/whatsapp_worker.php";

        // Try cURL first (Non-blocking with 1 second timeout)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_exec($ch);
            curl_close($ch);
            return;
        }

        // Non-blocking request Fallback (fsockopen)
        $parts = parse_url($url);
        if (!$parts) return;

        $fp = @fsockopen(
            ($parts['scheme'] === 'https' ? 'ssl://' : '') . $parts['host'],
            $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80),
            $errno, 
            $errstr, 
            1 // 1 second connection timeout
        );

        if ($fp) {
            $out = "GET " . $parts['path'] . " HTTP/1.1\r\n";
            $out .= "Host: " . $parts['host'] . "\r\n";
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out);
            fclose($fp);
        }
    }

    /**
     * The actual worker loop that process the queue safely
     * This is called by the background whatsapp_worker.php script
     */
    public function processQueueLoop($maxRuntime = 290) { // Default ~5 mins max safe execution
        @set_time_limit(300);
        $startTime = time();
        $processed = 0;
        $delaySeconds = 7; // Optimized: 7 seconds per message (Safe but faster)
        
        while (time() - $startTime < $maxRuntime) {
            // Pick next pending message
            $msgIndex = -1;
            
            // Lock queue to prevent collisions while finding next msg
            $fp = fopen($this->queueFile, "c+");
            $qLocked = false;
            for ($li = 0; $li < 20; $li++) { $qLocked = flock($fp, LOCK_EX | LOCK_NB); if ($qLocked) break; usleep(100000); }
            if ($qLocked) {
                $size = filesize($this->queueFile);
                if ($size > 0) {
                    $content = fread($fp, $size);
                    $queue = json_decode($content, true) ?: [];
                    
                    foreach ($queue as $index => $msg) {
                        // RECOVERY: If stuck in 'processing' for more than 2 minutes, reset to pending
                        if (isset($msg['status']) && $msg['status'] === 'processing') {
                            $lastActive = isset($msg['last_retry']) ? strtotime($msg['last_retry']) : 0;
                            if (time() - $lastActive > 120) { // 2 minutes stale timeout
                                // Check if max attempts exceeded
                                $maxAttempts = $msg['max_attempts'] ?? 3;
                                if (($msg['attempts'] ?? 0) >= $maxAttempts) {
                                    $queue[$index]['status'] = 'failed_permanent';
                                    $queue[$index]['error'] = 'Max attempts exceeded (' . $maxAttempts . ')';
                                } else {
                                    $queue[$index]['status'] = 'pending';
                                    $queue[$index]['error'] = 'Worker timeout recovery';
                                }
                            }
                        }

                        if (isset($msg['status']) && $msg['status'] === 'pending') {
                            // NEW: Check max attempts before processing
                            $maxAttempts = $msg['max_attempts'] ?? 3;
                            if (($msg['attempts'] ?? 0) >= $maxAttempts) {
                                $queue[$index]['status'] = 'failed_permanent';
                                $queue[$index]['error'] = 'Max attempts exceeded (' . $maxAttempts . ')';
                                continue; // Skip this message, find next pending
                            }
                            
                            $msgIndex = $index;
                            $targetId = $msg['id']; // Store the unique ID for safe update later
                            
                            // Immediately mark as processing to prevent double send
                            $queue[$index]['status'] = 'processing';
                            $queue[$index]['last_retry'] = date('Y-m-d H:i:s');
                            $queue[$index]['attempts'] = ($queue[$index]['attempts'] ?? 0) + 1;
                            
                            ftruncate($fp, 0);
                            rewind($fp);
                            fwrite($fp, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            break; // Stop looking, grab only the first one found
                        }
                    }
                }
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
            
            // If no message found, the queue is empty. Stop loop.
            if ($msgIndex === -1) {
                break;
            }
            
            $msg = $queue[$msgIndex];
            
            // SEND THE MESSAGE
            $result = $this->makeRequest($msg['data']);
            
            // Lock Queue to update final status
            $fp = fopen($this->queueFile, "c+");
            $qLocked2 = false;
            for ($li = 0; $li < 20; $li++) { $qLocked2 = flock($fp, LOCK_EX | LOCK_NB); if ($qLocked2) break; usleep(100000); }
            if ($qLocked2) {
                $size = filesize($this->queueFile);
                if ($size > 0) {
                    $content = fread($fp, $size);
                    $queueUpdated = json_decode($content, true) ?: [];
                    
                    // Find by ID to be index-safe
                    $foundIndex = -1;
                    foreach ($queueUpdated as $idx => $qMsg) {
                        if (($qMsg['id'] ?? '') === $targetId) {
                            $foundIndex = $idx;
                            break;
                        }
                    }

                    if ($foundIndex !== -1) {
                        if ($result['success'] ?? false) {
                            $queueUpdated[$foundIndex]['status'] = 'sent';
                            $queueUpdated[$foundIndex]['sent_at'] = date('Y-m-d H:i:s');
                            
                            // SAVE QUEUE FIRST BEFORE LOGGING
                            ftruncate($fp, 0);
                            rewind($fp);
                            fwrite($fp, json_encode($queueUpdated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            fflush($fp);
                            flock($fp, LOCK_UN);
                            fclose($fp);
                            $fp = null;
                            
                            // Update the Message Log (with DB ID for direct hit)
                            $extraInfo = $msg['extra'] ?? [];
                            $extraInfo['db_id'] = $msg['db_id'] ?? null;

                            // USE THE TRUE TYPE FROM EXTRA IF AVAILABLE (e.g. registration_received)
                            $trueType = $msg['type'];
                            if (isset($extraInfo['type'])) {
                                $trueType = $extraInfo['type'];
                            }

                            $this->saveMessageLog(
                                $msg['phone'], 
                                $trueType, 
                                true, 
                                null, 
                                $extraInfo
                            );
                        } else {
                            $errorText = $result['error'] ?? 'فشل الإرسال';
                            
                            // Check if max attempts exceeded
                            $currentAttempts = $queueUpdated[$foundIndex]['attempts'] ?? 1;
                            $maxAttempts = $queueUpdated[$foundIndex]['max_attempts'] ?? 3;
                            
                            if ($currentAttempts >= $maxAttempts) {
                                $queueUpdated[$foundIndex]['status'] = 'failed_permanent';
                                $queueUpdated[$foundIndex]['error'] = 'Max attempts exceeded: ' . $errorText;
                            } else {
                                $queueUpdated[$foundIndex]['status'] = 'pending'; // Leave pending for retry
                                $queueUpdated[$foundIndex]['error'] = $errorText;
                            }
                            
                            // IF RATE LIMITED: Stop processing more for this run
                            if ($result['rate_limit'] ?? false) {
                                ftruncate($fp, 0);
                                rewind($fp);
                                fwrite($fp, json_encode($queueUpdated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                fflush($fp);
                                flock($fp, LOCK_UN);
                                fclose($fp);
                                sleep($result['retry_after'] ?? 6);
                                return $processed; 
                            }
                            
                            // USE THE TRUE TYPE FROM EXTRA IF AVAILABLE
                            $extraInfo = $msg['extra'] ?? [];
                            $extraInfo['db_id'] = $msg['db_id'] ?? null;
                            $trueType = $msg['type'];
                            if (isset($extraInfo['type'])) {
                                $trueType = $extraInfo['type'];
                            }

                            // SAVE QUEUE FIRST BEFORE LOGGING
                            ftruncate($fp, 0);
                            rewind($fp);
                            fwrite($fp, json_encode($queueUpdated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            fflush($fp);
                            flock($fp, LOCK_UN);
                            fclose($fp);
                            $fp = null;

                            $this->saveMessageLog(
                                $msg['phone'], 
                                $trueType, 
                                false, 
                                $errorText, 
                                $extraInfo
                            );
                        }
                        
                        // Fallback queue save if not already closed
                        if ($fp !== null) {
                            ftruncate($fp, 0);
                            rewind($fp);
                            fwrite($fp, json_encode($queueUpdated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        }
                    }
                }
                if ($fp !== null) {
                    fflush($fp);
                    flock($fp, LOCK_UN);
                }
            }
            if ($fp !== null) fclose($fp);
            
            $processed++;
            
            // SLEEP DELAY (15-20 Seconds)
            // Wait AFTER processing so we fully obey the rate limit
            sleep($delaySeconds);
        }
        
        return $processed;
    }
    
    /**
     * Retry all pending messages in batches
     * Processes a maximum of $limit messages per call to avoid timeout
     */
    public function retryAll($limit = 10) {
        // Since we now use the background worker, retryAll just resets
        // any stuck 'processing' messages back to 'pending' and triggers worker.
        $queue = $this->loadQueue();
        foreach ($queue as &$msg) {
            if ($msg['status'] === 'processing') {
                $msg['status'] = 'pending';
            }
        }
        $this->saveQueue($queue);
        $this->triggerBackgroundWorker();
        
        return ['sent' => 0, 'failed' => 0, 'total' => 0, 'message' => 'Worker re-triggered successfully'];
    }
    
    /**
     * Remove a message from queue
     */
    public function removeFromQueue($messageId) {
        $queue = $this->loadQueue();
        $queue = array_filter($queue, fn($m) => $m['id'] !== $messageId);
        $this->saveQueue(array_values($queue));
        return true;
    }
    
    /**
     * Clear all sent messages from queue
     */
    public function clearSentMessages() {
        $queue = $this->loadQueue();
        $queue = array_filter($queue, fn($m) => ($m['status'] ?? '') !== 'sent');
        $this->saveQueue(array_values($queue));
        return true;
    }
    
    // ========== Health Check ==========
    
    /**
     * Check API health status
     */
    public function checkHealth() {
        $health = $this->loadHealthStatus();
        
        // If cached status is less than 60 seconds old, return it
        if (!empty($health['checked_at'])) {
            $age = time() - strtotime($health['checked_at']);
            if ($age < 60) return $health;
        }
        
        // Ping the API with a minimal request to check connectivity
        // We use a dedicated endpoint check or simply check if our last send succeeded
        $pendingCount = $this->getPendingCount();
        
        $health['pending_count'] = $pendingCount;
        $health['checked_at'] = date('Y-m-d H:i:s');
        
        // If we have many recent pending messages, mark as disconnected
        if ($pendingCount > 0 && (($health['status'] ?? '') === 'disconnected' || $pendingCount >= 3)) {
            $health['status'] = 'disconnected';
        }
        
        $this->saveHealthStatus($health);
        return $health;
    }
    
    /**
     * Update health status after send attempt
     */
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
    
    /**
     * Load health status from file
     */
    private function loadHealthStatus() {
        if (!file_exists($this->healthFile)) {
            return [
                'status' => 'unknown',
                'last_success' => null,
                'last_error' => null,
                'error' => '',
                'pending_count' => 0,
                'checked_at' => null
            ];
        }
        $data = json_decode(file_get_contents($this->healthFile), true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Save health status to file
     */
    private function saveHealthStatus($health) {
        $dir = dirname($this->healthFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($this->healthFile, json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Get health status (static access)
     */
    public static function getHealthStatus() {
        $file = dirname(__FILE__) . '/admin/data/wasender_health.json';
        if (!file_exists($file)) return ['status' => 'unknown', 'pending_count' => 0];
        return json_decode(file_get_contents($file), true) ?? ['status' => 'unknown', 'pending_count' => 0];
    }
    
    // ========== Legacy Functions for backwards compatibility ==========
    
    /**
     * إرسال وصل الطلب الجديد مع رابط PDF (Legacy)
     */
    public function sendOrderReceipt($orderData, $siteUrl = '') {
        return $this->sendRegistrationReceived($orderData);
    }
    
    /**
     * إرسال إشعار تم تسليم الطلب لمندوب التوصيل (Legacy)
     */
    public function sendHandedToDelivery($orderData) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? $orderData['name'] ?? 'عميل';
        
        $message = "🏎️ *تسجيل سيارات الاستعراض الحر*\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "🚚 *تم تحديث حالة طلبك!*\n\n";
        $message .= "📋 *رقم الطلب:* {$wasel}\n";
        $message .= "👤 *الاسم:* {$name}\n\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $supportNumber = $this->getSupportNumber();
        $message .= "📞 *مركز الدعم:* +{$supportNumber}\n";
        $message .= "━━━━━━━━━━━━━━━";
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode);
    }
    
    /**
     * إرسال إشعار تم التسليم للعميل (Legacy)
     */
    public function sendDeliveryConfirmation($orderData) {
        $phone = $orderData['phone'] ?? '';
        if (empty($phone)) return ['success' => false, 'error' => 'رقم الهاتف مفقود'];
        
        $wasel = $orderData['wasel'] ?? '-';
        $name = $orderData['full_name'] ?? $orderData['name'] ?? 'عميل';
        
        $message = "🏎️ *تسجيل سيارات الاستعراض الحر*\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "✅ *تم بنجاح!*\n\n";
        $message .= "📋 *رقم الطلب:* {$wasel}\n";
        $message .= "👤 *الاسم:* {$name}\n\n";
        $message .= "🎉 شكراً لك!\n";
        $message .= "نتمنى لك وقتاً ممتعاً في الحدث! 🏎️\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $supportNumber = $this->getSupportNumber();
        $message .= "📞 *مركز الدعم:* +{$supportNumber}\n";
        $message .= "━━━━━━━━━━━━━━━";
        
        $countryCode = $orderData['country_code'] ?? '+964';
        return $this->sendMessage($phone, $message, $countryCode);
    }
}

// دوال مساعدة للاستخدام السريع
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

// Legacy helper functions
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
