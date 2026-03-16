<?php
/**
 * Approve/Reject Registration
 * معالجة قبول أو رفض التسجيلات
 */

// Enable error reporting but send to JSON
// ONLY throw on actual errors, NOT on warnings/notices
set_error_handler(function($severity, $message, $file, $line) {
    // Only throw for real errors (E_ERROR, E_PARSE, E_CORE_ERROR, E_USER_ERROR)
    if ($severity & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    // Log warnings/notices but don't crash
    error_log("PHP Warning in approve_registration: [$severity] $message in $file:$line");
    return true; // Don't execute PHP's internal error handler
});

try {
    session_start();
} catch (Exception $e) {
    // Session already started
}

header('Content-Type: application/json; charset=utf-8');

// Prevent timeout for long operations (WhatsApp + Image Gen)
set_time_limit(300); 
ini_set('max_execution_time', 300);
ignore_user_abort(true);

require_once __DIR__ . '/include/AdminLogger.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/wasender.php';

require_once __DIR__ . '/admin/ArabicShaper.php';
require_once __DIR__ . '/services/MemberService.php';
require_once __DIR__ . '/include/RegistrationActionLogger.php';

// Check if logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صالحة']);
    exit;
}

$action = $_POST['action'] ?? '';
$wasel = $_POST['wasel'] ?? '';

if (empty($wasel)) {
    echo json_encode(['success' => false, 'message' => 'رقم التسجيل مفقود']);
    exit;
}

// Load data
$dataFile = 'admin/data/data.json';
if (!file_exists($dataFile)) {
    echo json_encode(['success' => false, 'message' => 'ملف البيانات غير موجود']);
    exit;
}

$data = json_decode(file_get_contents($dataFile), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'خطأ في قراءة البيانات']);
    exit;
}

// Find the registration
$registrationIndex = -1;
$registration = null;

foreach ($data as $index => $item) {
    if ($item['wasel'] == $wasel) {  // Use loose comparison
        $registrationIndex = $index;
        $registration = $item;
        break;
    }
}

if ($registrationIndex === -1) {
    echo json_encode(['success' => false, 'message' => 'التسجيل غير موجود']);
    exit;
}

// Handle actions
$approvalContext = null; // Will hold context for background processing
try {
    switch ($action) {
        case 'approve':
            // Get message selection options (default: all enabled)
            $messageOptions = [
                'send_registration' => isset($_POST['send_registration']) ? (int)$_POST['send_registration'] : 1,
                'send_acceptance' => isset($_POST['send_acceptance']) ? (int)$_POST['send_acceptance'] : 1,
                'send_badge' => isset($_POST['send_badge']) ? (int)$_POST['send_badge'] : 1,
                'send_qr_only' => isset($_POST['send_qr_only']) ? (int)$_POST['send_qr_only'] : 0
            ];
            $result = handleApproval($data, $registrationIndex, $registration, $messageOptions);
            // If approval succeeded, prepare context for background tasks
            if ($result['success'] ?? false) {
                $approvalContext = [
                    'data' => $data,
                    'index' => $registrationIndex,
                    'registration' => $registration,
                    'messageOptions' => $messageOptions
                ];
            }
            break;
            
        case 'reject':
            $reason = $_POST['reason'] ?? '';
            $result = handleRejection($data, $registrationIndex, $registration, $reason);
            break;
            
        case 'undo_reject':
            $result = handleUndoRejection($data, $registrationIndex, $registration);
            break;
            
        case 'edit_reject':
            $reason = $_POST['reason'] ?? '';
            $result = handleEditRejection($data, $registrationIndex, $registration, $reason);
            break;
            
        default:
            $result = ['success' => false, 'message' => 'إجراء غير صالح'];
    }
} catch (Exception $e) {
    $result = ['success' => false, 'message' => 'خطأ: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
} catch (Error $e) {
    $result = ['success' => false, 'message' => 'خطأ فادح: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
}

// *** SEND RESPONSE AND EXIT IMMEDIATELY ***
echo json_encode($result);
exit;

/**
 * Handle approval
 */
function handleApproval(&$data, $index, $registration, $messageOptions = []) {
    // Default message options (all enabled)
    $sendRegistration = $messageOptions['send_registration'] ?? 1;
    $sendAcceptance = $messageOptions['send_acceptance'] ?? 1;
    $sendBadge = $messageOptions['send_badge'] ?? 1;
    
    // Get username safely
    $username = 'admin';
    if (isset($_SESSION['user'])) {
        if (is_object($_SESSION['user']) && isset($_SESSION['user']->username)) {
            $username = $_SESSION['user']->username;
        } elseif (is_array($_SESSION['user']) && isset($_SESSION['user']['username'])) {
            $username = $_SESSION['user']['username'];
        }
    }
    
    // Update status
    $data[$index]['status'] = 'approved';
    $data[$index]['approved_date'] = date('Y-m-d H:i:s');
    $data[$index]['approved_by'] = $username;
    
    $debugLog = __DIR__ . '/admin/data/approval_trace.log';
    file_put_contents($debugLog, date('[H:i:s] ') . "Started approval for {$registration['wasel']}\n", FILE_APPEND);
    
    // ============ ASSIGN TIME SLOT (Iraq Timezone) ============
    date_default_timezone_set('Asia/Baghdad');
    $regSettingsFile = __DIR__ . '/admin/data/registration_settings.json';
    if (file_exists($regSettingsFile)) {
        $regSettings = json_decode(file_get_contents($regSettingsFile), true);
        $champDate = $regSettings['championship_date'] ?? '';
        $startTime = $regSettings['championship_start_time'] ?? '18:00';
        $interval = intval($regSettings['time_slot_interval'] ?? 10);
        $schedulingEnabled = !empty($regSettings['scheduling_enabled']);
        
        if ($schedulingEnabled && !empty($champDate) && !empty($startTime)) {
            // Count how many are already approved BEFORE this one
            $approvedBefore = 0;
            foreach ($data as $i => $item) {
                if ($i !== $index && ($item['status'] ?? '') === 'approved' && !empty($item['assigned_time'])) {
                    $approvedBefore++;
                }
            }
            
            // Calculate assigned time: start_time + (order × interval minutes)
            $baseDateTime = new DateTime($champDate . ' ' . $startTime, new DateTimeZone('Asia/Baghdad'));
            $baseDateTime->modify('+' . ($approvedBefore * $interval) . ' minutes');
            
            $data[$index]['assigned_time'] = $baseDateTime->format('H:i');
            $data[$index]['assigned_date'] = $champDate;
            $data[$index]['assigned_order'] = $approvedBefore + 1;
            $data[$index]['assigned_datetime'] = $baseDateTime->format('Y-m-d H:i');
        }
    }
    // ===========================================================
    
    // *** CRITICAL FIX: Capture current frame settings at approval time ***
    $frameSettingsFile = __DIR__ . '/admin/data/frame_settings.json';
    if (file_exists($frameSettingsFile)) {
        $currentFrameSettings = json_decode(file_get_contents($frameSettingsFile), true);
        if ($currentFrameSettings) {
            $data[$index]['saved_frame_settings'] = $currentFrameSettings;
        }
    }
    
    // Save current championship name and ID
    $siteSettingsFile = __DIR__ . '/admin/data/site_settings.json';
    $siteSettings = file_exists($siteSettingsFile) ? json_decode(file_get_contents($siteSettingsFile), true) : [];
    $data[$index]['championship_id'] = $siteSettings['current_championship_id'] ?? date('Y') . '_default';
    
    // Snapshot Championship Name from Frame Settings
    $data[$index]['championship_name'] = 'بطولة';
    $frameSettingsFile = __DIR__ . '/admin/data/frame_settings.json';
    if (file_exists($frameSettingsFile)) {
        $frameSettings = json_decode(file_get_contents($frameSettingsFile), true);
        if (!empty($frameSettings['form_titles']['sub_title'])) {
            $data[$index]['championship_name'] = $frameSettings['form_titles']['sub_title'];
        }
    }
    
    // Generate secure badge token if not exists (32-char hex)
    // IMPORTANT: Use existing token if found - DON'T regenerate
    if (empty($data[$index]['badge_token'])) {
        $data[$index]['badge_token'] = bin2hex(random_bytes(16));
    }
    
    // Also check session_badge_token for backward compatibility
    if (empty($data[$index]['session_badge_token'])) {
        $data[$index]['session_badge_token'] = $data[$index]['badge_token'];
    }
    
    // Use same token for badge_id if not set separately
    if (empty($data[$index]['badge_id'])) {
        $data[$index]['badge_id'] = $data[$index]['badge_token'];
    }

    // --- CRITICAL FIX: SAVE HERE IMMEDIATELY ---
    file_put_contents($debugLog, date('[H:i:s] ') . "Saving data Phase 1...\n", FILE_APPEND);
    if (!saveData($data)) {
        return ['success' => false, 'message' => 'فشل في حفظ بيانات القبول'];
    }
    file_put_contents($debugLog, date('[H:i:s] ') . "Saved OK. Returning immediately.\n", FILE_APPEND);

    // Return success IMMEDIATELY - heavy tasks run in background after response
    return [
        'success' => true,
        'message' => 'تم قبول التسجيل بنجاح'
    ];
}

/**
 * Process heavy approval tasks in background (AFTER response is sent to browser)
 * This runs after fastcgi_finish_request() so the browser already got the response
 */
function processApprovalBackground(&$data, $index, $registration, $messageOptions = []) {
    $debugLog = __DIR__ . '/admin/data/approval_trace.log';
    file_put_contents($debugLog, date('[H:i:s] ') . "BG: Start {$registration['wasel']}\n", FILE_APPEND);
    
    $sendAcceptance = $messageOptions['send_acceptance'] ?? 1;
    $sendBadge = $messageOptions['send_badge'] ?? 1;
    
    $username = 'admin';
    if (isset($_SESSION['user'])) {
        if (is_object($_SESSION['user']) && isset($_SESSION['user']->username)) {
            $username = $_SESSION['user']->username;
        } elseif (is_array($_SESSION['user']) && isset($_SESSION['user']['username'])) {
            $username = $_SESSION['user']['username'];
        }
    }
    
    $processLog = [];
    
    // ============ WHATSAPP (just queues messages - fast) ============
    try {
        $waSender = new WaSender();
        $host = $_SERVER['HTTP_HOST'] ?? 'yellowgreen-quail-410393.hostingersite.com';
        $baseUrl = "https://$host";
        
        $badgeToken = $data[$index]['badge_token'] ?? $registration['wasel'];
        $acceptanceLink = $baseUrl . '/acceptance.php?token=' . urlencode($badgeToken);
        
        // Load templates
        $messagesFile = __DIR__ . '/admin/data/whatsapp_messages.json';
        $messageTemplates = [];
        if (file_exists($messagesFile)) {
            $messageTemplates = json_decode(file_get_contents($messagesFile), true) ?? [];
        }
        
        if ($sendAcceptance) {
            $acceptCaption = $messageTemplates['acceptance_message'] ?? "🎉 *مبروك! تم قبول طلبك!*\n\n👤 {name}\n🔢 #{wasel}\n🚗 {car_type}";
            $acceptCaption = str_replace(['{name}', '{wasel}', '{car_type}', '{plate}', '{registration_code}'],
                [$registration['full_name'] ?? '', $registration['wasel'] ?? '', $registration['car_type'] ?? '', $registration['plate_full'] ?? '', $registration['registration_code'] ?? ''],
                $acceptCaption);
            $acceptCaption .= "\n\n🌐 *رابط بطاقة القبول:* \n" . $acceptanceLink;
            $waSender->sendMessage($registration['phone'], $acceptCaption, $registration['country_code'] ?? '+964');
            $processLog[] = 'Accept: Queued';
        }
        
        if ($sendBadge) {
            $badgeId = $data[$index]['badge_id'] ?? $registration['wasel'];
            $verifyUrl = $baseUrl . '/verify_entry.php?badge_id=' . urlencode($badgeId) . '&action=checkin';
            $badgeLink = $baseUrl . '/badge.php?token=' . urlencode($badgeToken);
            $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($verifyUrl);
            
            $badgeCaption = $messageTemplates['badge_caption'] ?? "🎫 باج دخول الحلبة\n\n✅ قم بإظهار هذا الباج عند الدخول للحلبة";
            $badgeCaption = str_replace(['{name}', '{wasel}', '{registration_code}'],
                [$registration['full_name'] ?? '', $registration['wasel'] ?? '', $registration['registration_code'] ?? ''],
                $badgeCaption);
            $badgeCaption .= "\n\n📥 *افتح الباج الكامل:*\n" . $badgeLink;
            
            $waSender->sendImage($registration['phone'], $qrCodeUrl, $badgeCaption, $registration['country_code'] ?? '+964');
            $processLog[] = 'Badge: Queued';
        }
    } catch (\Throwable $e) {
        $processLog[] = 'WA: ' . $e->getMessage();
    }
    
    // ============ SYNC (fast operations) ============
    try { MemberService::ensureSQLiteRecord($data[$index]); } catch (\Throwable $e) {}
    try { MemberService::syncToJsonByWasel($registration['wasel']); } catch (\Throwable $e) {}
    try { RegistrationActionLogger::log('approved', $data[$index], 'تم القبول', $username); } catch (\Throwable $e) {}
    
    file_put_contents($debugLog, date('[H:i:s] ') . "BG: Done {$registration['wasel']} | " . implode(' | ', $processLog) . "\n", FILE_APPEND);
}


/**
 * Handle rejection
 */
function handleRejection(&$data, $index, $registration, $reason) {
    // Update status
    $data[$index]['status'] = 'rejected';
    $data[$index]['rejected_date'] = date('Y-m-d H:i:s');
    $data[$index]['rejected_by'] = $_SESSION['user']->username ?? 'admin';
    $data[$index]['rejection_reason'] = $reason;
    
    // Save data
    if (!saveData($data)) {
        return ['success' => false, 'message' => 'فشل في حفظ البيانات'];
    }
    
    // Send WhatsApp rejection message
    $whatsappResult = ['success' => false];
    try {
        $wasender = new WaSender();
        $whatsappResult = $wasender->sendRejection($registration, $reason);
    } catch (Exception $e) {
        $whatsappResult = ['success' => false, 'error' => $e->getMessage()];
    }
    
    // Log rejection to AdminLogger
    try {
        $rejUsername = 'admin';
        if (isset($_SESSION['user'])) {
            $u = $_SESSION['user'];
            $rejUsername = is_object($u) ? ($u->username ?? 'admin') : ($u['username'] ?? 'admin');
        }
        $adminLogger = new AdminLogger();
        $adminLogger->log(
            AdminLogger::ACTION_PARTICIPANT_REJECT,
            $rejUsername,
            'رفض تسجيل: ' . ($registration['full_name'] ?? 'غير معروف') . ' (#' . ($registration['wasel'] ?? '') . ')',
            [
                'wasel' => $registration['wasel'] ?? '',
                'name' => $registration['full_name'] ?? '',
                'reason' => $reason
            ]
        );
    } catch (Exception $e) {}
    
    // Log to registration actions archive
    try {
        RegistrationActionLogger::log('rejected', $data[$index], $reason, $rejUsername ?? 'admin');
    } catch (Exception $e) {}

    return [
        'success' => true,
        'message' => 'تم رفض التسجيل',
        'whatsapp' => $whatsappResult
    ];
}

/**
 * Handle Undo Rejection
 */
function handleUndoRejection(&$data, $index, $registration) {
    // Update status back to pending
    $data[$index]['status'] = 'pending';
    unset($data[$index]['rejected_date']);
    unset($data[$index]['rejected_by']);
    unset($data[$index]['rejection_reason']);
    
    // Save to data.json
    if (!saveData($data)) {
        return ['success' => false, 'message' => 'فشل في حفظ ملف JSON'];
    }
    
    // SYNC TO SQLITE (Crucial for dashboard visibility)
    try {
        MemberService::ensureSQLiteRecord($data[$index]);
    } catch (\Throwable $e) {
        error_log("UndoReject Sync Error: " . $e->getMessage());
    }
    
    // Log action
    try {
        $rejUsername = 'admin';
        if (isset($_SESSION['user'])) {
            $u = $_SESSION['user'];
            $rejUsername = is_object($u) ? ($u->username ?? 'admin') : ($u['username'] ?? 'admin');
        }
        $adminLogger = new AdminLogger();
        $adminLogger->log('undo_reject', $rejUsername, 'تراجع عن رفض: ' . ($registration['full_name'] ?? 'غير معروف'), ['wasel' => $registration['wasel']]);
        RegistrationActionLogger::log('pending', $data[$index], 'تم التراجع عن الرفض', $rejUsername);
    } catch (Exception $e) {}
    
    return [
        'success' => true,
        'message' => 'تم إرجاع حالة التسجيل إلى (قيد المراجعة) بنجاح'
    ];
}

/**
 * Handle Edit Rejection Reason
 */
function handleEditRejection(&$data, $index, $registration, $newReason) {
    // Update reason
    $data[$index]['rejection_reason'] = $newReason;
    $data[$index]['rejected_date'] = date('Y-m-d H:i:s');
    
    // Save to data.json
    if (!saveData($data)) {
        return ['success' => false, 'message' => 'فشل في حفظ البيانات'];
    }

    // SYNC TO SQLITE
    try {
        MemberService::ensureSQLiteRecord($data[$index]);
    } catch (\Throwable $e) {}
    
    // Resend WhatsApp rejection message with new reason
    $whatsappResult = ['success' => false];
    try {
        $wasender = new WaSender();
        $whatsappResult = $wasender->sendRejection($registration, $newReason);
    } catch (Exception $e) {
        $whatsappResult = ['success' => false, 'error' => $e->getMessage()];
    }
    
    // Log action
    try {
        $rejUsername = 'admin';
        if (isset($_SESSION['user'])) {
            $u = $_SESSION['user'];
            $rejUsername = is_object($u) ? ($u->username ?? 'admin') : ($u['username'] ?? 'admin');
        }
        $adminLogger = new AdminLogger();
        $adminLogger->log('edit_reject', $rejUsername, 'تعديل سبب الرفض: ' . ($registration['full_name'] ?? 'غير معروف'), ['wasel' => $registration['wasel'], 'reason' => $newReason]);
        RegistrationActionLogger::log('rejected', $data[$index], 'تعديل السبب: ' . $newReason, $rejUsername);
    } catch (Exception $e) {}
    
    return [
        'success' => true,
        'message' => 'تم تعديل سبب الرفض وإعادة إرسال الرسالة',
        'whatsapp' => $whatsappResult
    ];
}

/**
 * Reverse Arabic text for proper RTL display in GD
 * GD renders text LTR, so we need to reverse Arabic strings
 */
function reverseArabicText($text) {
    // Split into UTF-8 characters
    preg_match_all('/./u', $text, $chars);
    $chars = $chars[0];
    
    $result = [];
    $currentWord = [];
    $isArabic = false;
    
    foreach ($chars as $char) {
        // Check if character is Arabic (Unicode range 0600-06FF)
        $ord = mb_ord($char, 'UTF-8');
        $charIsArabic = ($ord >= 0x0600 && $ord <= 0x06FF) || 
                        ($ord >= 0xFB50 && $ord <= 0xFDFF) || 
                        ($ord >= 0xFE70 && $ord <= 0xFEFF);
        
        if ($charIsArabic || $char === ' ') {
            $result[] = $char;
        } else {
            // Keep numbers and English as-is
            $result[] = $char;
        }
    }
    
    // Reverse the entire array for RTL
    return implode('', array_reverse($result));
}

/**
 * Generate acceptance image with car photo overlaid on frame
 */
function generateAcceptanceImage($registration) {
    // Get frame image path from site settings - ROBUST PATH
    $siteSettingsFile = __DIR__ . '/admin/data/site_settings.json';
    $framePath = __DIR__ . '/images/acceptance_frame.png';
    
    if (file_exists($siteSettingsFile)) {
        $settings = json_decode(file_get_contents($siteSettingsFile), true);
        if (!empty($settings['frame_url'])) {
            $checkFrame = $settings['frame_url'];
            if (file_exists($checkFrame)) $framePath = $checkFrame;
            elseif (file_exists(__DIR__ . '/' . $checkFrame)) $framePath = __DIR__ . '/' . $checkFrame;
             elseif (file_exists(__DIR__ . '/admin/' . $checkFrame)) $framePath = __DIR__ . '/admin/' . $checkFrame;
        }
    }
    
    if (!file_exists($framePath)) {
        // Log error but try one last fallback
         $framePath = __DIR__ . '/images/acceptance_frame.png';
         if (!file_exists($framePath)) {
             return ['success' => false, 'error' => 'Frame image not found: ' . $framePath];
         }
    }
    
    // Load frame settings - ROBUST PATH
    $frameSettingsFile = __DIR__ . '/admin/data/frame_settings.json';
    
    // Fix for GD Font Path (Hostinger/Linux specific)
    putenv('GDFONTPATH=' . realpath(__DIR__ . '/fonts'));
    
    // Check multiple locations for font
    $fontPath = __DIR__ . '/fonts/Cairo-Bold.ttf';
    if (!file_exists($fontPath)) {
        $fontPath = __DIR__ . '/admin/fonts/Cairo-Bold.ttf';
    }
    if (!file_exists($fontPath)) { 
        $fontPath = 'Cairo-Bold.ttf'; // Fallback to GDFONTPATH
    }

    $frameSettings = [
        'car_image' => ['shape' => 'circle', 'x_percent' => 50, 'y_percent' => 60, 'size_percent' => 35],
        'registration_text' => ['enabled' => true, 'x_percent' => 50, 'y_percent' => 88, 'font_size' => 32, 'color' => '#FFD700', 'prefix' => 'الرقم التعريفي: '],
        'custom_text' => ['enabled' => false, 'text' => '', 'x_percent' => 50, 'y_percent' => 95, 'font_size' => 24, 'color' => '#FFFFFF'],
        'overlays' => []
    ];
    
    // *** FIXED: Use registration's saved_frame_settings FIRST if available ***
    $loaded = null;
    if (!empty($registration['saved_frame_settings'])) {
        $loaded = $registration['saved_frame_settings'];
    } elseif (file_exists($frameSettingsFile)) {
        $loaded = json_decode(file_get_contents($frameSettingsFile), true);
    }
    
    if (is_array($loaded)) {
            // Map new format to expected structure
            if (isset($loaded['elements']['personal_photo'])) {
                $pp = $loaded['elements']['personal_photo'];
                $frameSettings['car_image'] = [
                    'shape' => $pp['shape'] ?? 'circle',
                    'x_percent' => $pp['x'] ?? 50,
                    'y_percent' => $pp['y'] ?? 60,
                    'size_percent' => $pp['width'] ?? 35,
                    'height_percent' => $pp['height'] ?? 35,
                    'border_color' => $pp['border_color'] ?? '#FFD700',
                    'border_width' => $pp['border_width'] ?? 4
                ];
            }
            if (isset($loaded['elements']['registration_id'])) {
                $ri = $loaded['elements']['registration_id'];
                $frameSettings['registration_text'] = [
                    'enabled' => $ri['enabled'] ?? true,
                    'x_percent' => $ri['x'] ?? 50,
                    'y_percent' => $ri['y'] ?? 88,
                    'font_size' => $ri['font_size'] ?? 32,
                    'color' => $ri['color'] ?? '#FFD700',
                    'prefix' => '#'
                ];
            }
            if (isset($loaded['elements']['participant_name'])) {
                $pn = $loaded['elements']['participant_name'];
                $frameSettings['participant_name'] = [
                    'enabled' => $pn['enabled'] ?? true,
                    'x_percent' => $pn['x'] ?? 50,
                    'y_percent' => $pn['y'] ?? 70,
                    'font_size' => $pn['font_size'] ?? 28,
                    'color' => $pn['color'] ?? '#FFD700'
                ];
            }
            if (isset($loaded['elements']['plate_number'])) {
                $plate = $loaded['elements']['plate_number'];
                $frameSettings['plate_number'] = [
                    'enabled' => $plate['enabled'] ?? true,
                    'x_percent' => $plate['x'] ?? 50,
                    'y_percent' => $plate['y'] ?? 90,
                    'font_size' => $plate['font_size'] ?? 18,
                    'color' => $plate['color'] ?? '#FFFFFF'
                ];
            }
            if (isset($loaded['elements']['car_type'])) {
                $ct = $loaded['elements']['car_type'];
                $frameSettings['car_type'] = [
                    'enabled' => $ct['enabled'] ?? true,
                    'x_percent' => $ct['x'] ?? 50,
                    'y_percent' => $ct['y'] ?? 92,
                    'font_size' => $ct['font_size'] ?? 18,
                    'color' => $ct['color'] ?? '#FFD700'
                ];
            }
            if (isset($loaded['elements']['governorate'])) {
                $gov = $loaded['elements']['governorate'];
                $frameSettings['governorate'] = [
                    'enabled' => $gov['enabled'] ?? true,
                    'x_percent' => $gov['x'] ?? 50,
                    'y_percent' => $gov['y'] ?? 95,
                    'font_size' => $gov['font_size'] ?? 18,
                    'color' => $gov['color'] ?? '#FFD700'
                ];
            }
            // Also keep any direct settings
            if (isset($loaded['car_image'])) {
                $frameSettings['car_image'] = array_merge($frameSettings['car_image'], $loaded['car_image']);
            }
            if (isset($loaded['overlays'])) {
                $frameSettings['overlays'] = $loaded['overlays'];
            }
        }
    
    // Get personal photo or fallback to car front image - SOFT FAIL
    $carImagePath = null;
    $candidates = [
        $registration['images']['personal_photo'] ?? null,
        'admin/' . ($registration['images']['personal_photo'] ?? ''), // try with admin prefix
        $registration['images']['front_image'] ?? null,
        'admin/' . ($registration['images']['front_image'] ?? '')
    ];
    
    foreach ($candidates as $cand) {
        if (!empty($cand)) {
             if (file_exists($cand)) { $carImagePath = $cand; break; }
             if (file_exists(__DIR__ . '/' . $cand)) { $carImagePath = __DIR__ . '/' . $cand; break; }
        }
    }
    
    // If still no image, do NOT fail. Just proceed with null.
    // This allows the frame and text to be generated even without the photo.
    
    // Create output directory
    $outputDir = 'uploads/accepted/';
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0777, true);
    }
    
    try {
        // Load frame image
        $frameInfo = getimagesize($framePath);
        $frameWidth = $frameInfo[0];
        $frameHeight = $frameInfo[1];
        $frameType = $frameInfo[2];
        
        switch ($frameType) {
            case IMAGETYPE_PNG:
                $frameImage = imagecreatefrompng($framePath);
                break;
            case IMAGETYPE_JPEG:
                $frameImage = imagecreatefromjpeg($framePath);
                break;
            default:
                return ['success' => false, 'error' => 'Unsupported frame image type'];
        }
        
        // Enable alpha blending
        imagealphablending($frameImage, true);
        imagesavealpha($frameImage, true);
        
        // Load car image (Only if path is valid)
        $carImage = null;
        if ($carImagePath && file_exists($carImagePath)) {
            $carInfo = @getimagesize($carImagePath);
            if ($carInfo) {
                $carWidth = $carInfo[0];
                $carHeight = $carInfo[1];
                $carType = $carInfo[2];
                
                switch ($carType) {
                    case IMAGETYPE_PNG: $carImage = imagecreatefrompng($carImagePath); break;
                    case IMAGETYPE_JPEG: $carImage = imagecreatefromjpeg($carImagePath); break;
                    case IMAGETYPE_GIF: $carImage = imagecreatefromgif($carImagePath); break;
                    case IMAGETYPE_WEBP: $carImage = imagecreatefromwebp($carImagePath); break;
                }
            }
        }
        
        // Get car image settings from frame settings
        $carSettings = $frameSettings['car_image'] ?? [];
        $shape = $carSettings['shape'] ?? 'circle';
        $carX = ($carSettings['x_percent'] ?? 50) / 100 * $frameWidth;
        $carY = ($carSettings['y_percent'] ?? 60) / 100 * $frameHeight;
        $carSizePercent = $carSettings['size_percent'] ?? 35;
        $targetSize = min($frameWidth, $frameHeight) * ($carSizePercent / 100);
        
        // Debug log
        file_put_contents(__DIR__ . '/admin/data/approval_debug.log', 
            date('[Y-m-d H:i:s] ') . "SHAPE DEBUG - shape: $shape | carImage: " . ($carImage ? 'YES' : 'NO') . " | carSettings: " . json_encode($carSettings) . "\n", 
            FILE_APPEND);
        
        // Resize car image (Only if loaded)
        if ($carImage) {
            $ratio = min($targetSize / $carWidth, $targetSize / $carHeight);
            $newCarWidth = (int)($carWidth * $ratio);
            $newCarHeight = (int)($carHeight * $ratio);
            
            // Create resized car image
            $resizedCar = imagecreatetruecolor($newCarWidth, $newCarHeight);
            imagealphablending($resizedCar, false);
            imagesavealpha($resizedCar, true);
            $transparent = imagecolorallocatealpha($resizedCar, 0, 0, 0, 127);
            imagefill($resizedCar, 0, 0, $transparent);
            imagealphablending($resizedCar, true);
            
            imagecopyresampled($resizedCar, $carImage, 0, 0, 0, 0, $newCarWidth, $newCarHeight, $carWidth, $carHeight);
            
            // Apply mask based on shape
            if ($shape === 'circle') {
                // Create circular photo with proper alpha handling
                $maskSize = min($newCarWidth, $newCarHeight); // Use min for true circle
                $centerX = (int)$carX;
                $centerY = (int)$carY;
                $radius = (int)($maskSize / 2);
                
                // Draw circular image directly on frame, pixel by pixel
                for ($y = -$radius; $y <= $radius; $y++) {
                    for ($x = -$radius; $x <= $radius; $x++) {
                        // Check if within circle
                        if (sqrt($x*$x + $y*$y) <= $radius) {
                            // Calculate source position in resized car
                            $srcX = (int)(($x + $radius) * $newCarWidth / $maskSize);
                            $srcY = (int)(($y + $radius) * $newCarHeight / $maskSize);
                            
                            if ($srcX >= 0 && $srcX < $newCarWidth && $srcY >= 0 && $srcY < $newCarHeight) {
                                $color = imagecolorat($resizedCar, $srcX, $srcY);
                                $destX = $centerX + $x;
                                $destY = $centerY + $y;
                                
                                if ($destX >= 0 && $destX < $frameWidth && $destY >= 0 && $destY < $frameHeight) {
                                    imagesetpixel($frameImage, $destX, $destY, $color);
                                }
                            }
                        }
                    }
                }
                
                // Draw circular border
                if (!empty($carSettings['border_width']) && $carSettings['border_width'] > 0) {
                    $borderColor = $carSettings['border_color'] ?? '#FFD700';
                    list($br, $bg, $bb) = sscanf($borderColor, "#%02x%02x%02x");
                    $border = imagecolorallocate($frameImage, $br ?? 255, $bg ?? 215, $bb ?? 0);
                    imagesetthickness($frameImage, $carSettings['border_width']);
                    imagearc($frameImage, $centerX, $centerY, $maskSize, $maskSize, 0, 360, $border);
                }
                
            } else {
                // Square - just paste
                $carPosX = (int)($carX - $newCarWidth / 2);
                $carPosY = (int)($carY - $newCarHeight / 2);
                imagecopy($frameImage, $resizedCar, $carPosX, $carPosY, 0, 0, $newCarWidth, $newCarHeight);
            }
            
            imagedestroy($resizedCar);
        }
        
        // Add overlays
        if (!empty($frameSettings['overlays'])) {
            foreach ($frameSettings['overlays'] as $overlay) {
                $overlayPath = $overlay['image'] ?? '';
                if (file_exists($overlayPath)) {
                    $overlayInfo = getimagesize($overlayPath);
                    if ($overlayInfo) {
                        $overlayImg = imagecreatefrompng($overlayPath);
                        if ($overlayImg) {
                            $overlayX = ($overlay['x_percent'] ?? 50) / 100 * $frameWidth;
                            $overlayY = ($overlay['y_percent'] ?? 50) / 100 * $frameHeight;
                            $overlaySize = min($frameWidth, $frameHeight) * (($overlay['size_percent'] ?? 20) / 100);
                            
                            $oRatio = $overlaySize / max($overlayInfo[0], $overlayInfo[1]);
                            $oWidth = (int)($overlayInfo[0] * $oRatio);
                            $oHeight = (int)($overlayInfo[1] * $oRatio);
                            
                            $resizedOverlay = imagecreatetruecolor($oWidth, $oHeight);
                            imagealphablending($resizedOverlay, false);
                            imagesavealpha($resizedOverlay, true);
                            $trans = imagecolorallocatealpha($resizedOverlay, 0, 0, 0, 127);
                            imagefill($resizedOverlay, 0, 0, $trans);
                            imagealphablending($resizedOverlay, true);
                            imagecopyresampled($resizedOverlay, $overlayImg, 0, 0, 0, 0, $oWidth, $oHeight, $overlayInfo[0], $overlayInfo[1]);
                            
                            $oPosX = (int)($overlayX - $oWidth / 2);
                            $oPosY = (int)($overlayY - $oHeight / 2);
                            imagecopy($frameImage, $resizedOverlay, $oPosX, $oPosY, 0, 0, $oWidth, $oHeight);
                            
                            imagedestroy($resizedOverlay);
                            imagedestroy($overlayImg);
                        }
                    }
                }
            }
        }
        
        // Font path - Use Cairo-Bold for Arabic and English text
        // Cairo-Bold supports both Arabic and Latin characters
        // Try multiple paths to find the font (Hostinger compatible)
        $fontPath = null;
        $baseDir = __DIR__; // This is the root of the project
        
        $fontCandidates = [
            // Try NotoSansArabic first (works on this server!)
            $baseDir . '/fonts/NotoSansArabic.ttf',
            $baseDir . '/fonts/Cairo-Bold.ttf',
            $baseDir . '/Cairo-Bold.ttf',
            // If running from approve_registration.php (root level)
            dirname($baseDir) . '/fonts/Cairo-Bold.ttf',
            // Absolute paths using realpath
            realpath($baseDir . '/fonts/Cairo-Bold.ttf'),
            realpath($baseDir . '/Cairo-Bold.ttf'),
            // Try with public_html prefix (common on Hostinger)
            '/home/' . get_current_user() . '/public_html/fonts/Cairo-Bold.ttf',
            '/home/' . get_current_user() . '/public_html/Cairo-Bold.ttf',
        ];
        
        // Remove false/null entries
        $fontCandidates = array_filter($fontCandidates);
        
        foreach ($fontCandidates as $candidate) {
            if (file_exists($candidate)) {
                $fontPath = $candidate;
                break;
            }
        }
        
        // Test if font works with GD
        $fontWorks = false;
        if ($fontPath) {
            // Suppress errors and test carefully
            $testBbox = @imagettfbbox(12, 0, $fontPath, 'Test');
            $fontWorks = ($testBbox !== false && is_array($testBbox));
            
            // Log font status
            file_put_contents(__DIR__ . '/admin/data/approval_debug.log', 
                date('[Y-m-d H:i:s] ') . "FONT TEST - Path: $fontPath | Works: " . ($fontWorks ? 'YES' : 'NO') . "\n", 
                FILE_APPEND);
        }
        
        // If font doesn't work, set to null to use fallback
        if (!$fontWorks) {
            $fontPath = null;
        }
        
        // Debug font path - Log all tried paths
        $debugMsg = date('[Y-m-d H:i:s] ') . "FONT DEBUG\n";
        $debugMsg .= "  Base Dir: $baseDir\n";
        $debugMsg .= "  Font Path Found: " . ($fontPath ?? 'NONE') . "\n";
        $debugMsg .= "  Font Exists: " . ($fontPath && file_exists($fontPath) ? 'YES' : 'NO') . "\n";
        $debugMsg .= "  Font Works: " . ($fontWorks ? 'YES' : 'NO') . "\n";
        $debugMsg .= "  Candidates Tried: " . count($fontCandidates) . "\n";
        foreach ($fontCandidates as $c) {
            $debugMsg .= "    - $c: " . (file_exists($c) ? 'EXISTS' : 'NOT FOUND') . "\n";
        }
        file_put_contents(__DIR__ . '/admin/data/approval_debug.log', $debugMsg, FILE_APPEND);
        
        // Add registration number text
        $wasel = $registration['wasel'] ?? '';
        $regSettings = $frameSettings['registration_text'] ?? [];
        
        if (($regSettings['enabled'] ?? true) && !empty($wasel)) {
            $regText = $wasel;
            
            $regX = ($regSettings['x_percent'] ?? 50) / 100 * $frameWidth;
            $regY = ($regSettings['y_percent'] ?? 88) / 100 * $frameHeight;
            $regColor = $regSettings['color'] ?? '#FFD700';
            
            list($r, $g, $b) = sscanf($regColor, "#%02x%02x%02x");
            $textColor = imagecolorallocate($frameImage, $r ?? 255, $g ?? 215, $b ?? 0);
            
            // Try TTF font first for larger text
            if ($fontWorks) {
                $fontSize = $regSettings['font_size'] ?? 32;
                $bbox = @imagettfbbox($fontSize, 0, $fontPath, $regText);
                if ($bbox !== false) {
                    $textWidth = abs($bbox[2] - $bbox[0]);
                    $textX = $regX - $textWidth / 2;
                    imagettftext($frameImage, $fontSize, 0, (int)$textX, (int)$regY, $textColor, $fontPath, $regText);
                } else {
                    // Fallback to built-in font
                    $fontSize = 5;
                    $textWidth = imagefontwidth($fontSize) * strlen($regText);
                    $textX = $regX - $textWidth / 2;
                    imagestring($frameImage, $fontSize, (int)$textX, (int)$regY, $regText, $textColor);
                }
            } else {
                // Fallback to built-in font
                $fontSize = 5;
                $textWidth = imagefontwidth($fontSize) * strlen($regText);
                $textX = $regX - $textWidth / 2;
                imagestring($frameImage, $fontSize, (int)$textX, (int)$regY, $regText, $textColor);
            }
        }
        
        // Add Plate Number
        $plateSettings = $frameSettings['plate'] ?? [];
        
        if (($plateSettings['enabled'] ?? true) && !empty($registration['plate_full'])) {
            $plateX = ($plateSettings['x_percent'] ?? 50) / 100 * $frameWidth;
            $plateY = ($plateSettings['y_percent'] ?? 78) / 100 * $frameHeight;
            $plateColor = $plateSettings['color'] ?? '#FFFFFF';
            
            list($r, $g, $b) = sscanf($plateColor, "#%02x%02x%02x");
            $textColor = imagecolorallocate($frameImage, $r ?? 255, $g ?? 255, $b ?? 255);
            
            if ($fontWorks) {
                $plateText = ArabicShaper::shape($registration['plate_full']);
                $fontSize = $plateSettings['font_size'] ?? 18;
                $bbox = @imagettfbbox($fontSize, 0, $fontPath, $plateText);
                if ($bbox !== false) {
                    $textWidth = abs($bbox[2] - $bbox[0]);
                    $textX = $plateX - $textWidth / 2;
                    imagettftext($frameImage, $fontSize, 0, (int)$textX, (int)$plateY, $textColor, $fontPath, $plateText);
                }
            } else {
                // Fallback
                $plateRaw = $registration['plate_full'];
                $textWidth = imagefontwidth(4) * strlen($plateRaw);
                $textX = $plateX - $textWidth / 2;
                imagestring($frameImage, 4, (int)$textX, (int)$plateY - 8, $plateRaw, $textColor);
            }
        }

        
        // Add Governorate
        $govSettings = $frameSettings['governorate'] ?? [];
        if (($govSettings['enabled'] ?? true) && !empty($registration['governorate'])) {
            $govX = ($govSettings['x_percent'] ?? 50) / 100 * $frameWidth;
            $govY = ($govSettings['y_percent'] ?? 95) / 100 * $frameHeight;
            $govColor = $govSettings['color'] ?? '#FFD700';
            
            list($r, $g, $b) = sscanf($govColor, "#%02x%02x%02x");
            $textColor = imagecolorallocate($frameImage, $r ?? 255, $g ?? 215, $b ?? 0);
            
            // Clean governorate name (remove number)
            $govFull = $registration['governorate'];
            $govName = trim(preg_replace('/\(.*\)/', '', $govFull));
            
            if ($fontWorks && $fontPath) {
                $govText = ArabicShaper::shape($govName);
                $fontSize = $govSettings['font_size'] ?? 18;
                $bbox = @imagettfbbox($fontSize, 0, $fontPath, $govText);
                if ($bbox !== false) {
                    $textWidth = abs($bbox[2] - $bbox[0]);
                    $textX = $govX - $textWidth / 2;
                    imagettftext($frameImage, $fontSize, 0, (int)$textX, (int)$govY, $textColor, $fontPath, $govText);
                }
            } else {
                // Fallback
                $textWidth = imagefontwidth(4) * strlen($govName);
                $textX = $govX - $textWidth / 2;
                imagestring($frameImage, 4, (int)$textX, (int)$govY - 8, $govName, $textColor);
            }
        }
        // Save the output image
        $outputPath = $outputDir . $registration['wasel'] . '_accepted_' . time() . '.png';
        imagepng($frameImage, $outputPath, 9);
        
        // Clean up
        imagedestroy($frameImage);
        if ($carImage) imagedestroy($carImage);
        
        return ['success' => true, 'image_path' => $outputPath];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Load data from JSON file with shared lock (prevents reading corrupted data)
 */
function loadDataSafe($dataFile) {
    if (!file_exists($dataFile)) return null;
    $fp = fopen($dataFile, 'r');
    if (!$fp) return null;
    // Non-blocking shared lock with retry
    $maxRetries = 10;
    $locked = false;
    for ($i = 0; $i < $maxRetries; $i++) {
        if (flock($fp, LOCK_SH | LOCK_NB)) {
            $locked = true;
            break;
        }
        usleep(100000); // 100ms
    }
    if (!$locked) {
        // If we can't get shared lock after 1s, read anyway
        flock($fp, LOCK_SH);
    }
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($content, true);
}

/**
 * Save data to file with exclusive lock (prevents concurrent writes)
 */
function saveData($data) {
    $dataFile = 'admin/data/data.json';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    
    // Try to get lock with timeout (non-blocking)
    $fp = @fopen($dataFile, 'c');
    if (!$fp) {
        // Fallback: write without lock
        return file_put_contents($dataFile, $json) !== false;
    }
    
    $maxWait = 3; // seconds
    $start = time();
    $locked = false;
    
    while ((time() - $start) < $maxWait) {
        $locked = flock($fp, LOCK_EX | LOCK_NB);
        if ($locked) break;
        usleep(100000); // 100ms
    }
    
    if ($locked) {
        ftruncate($fp, 0);
        rewind($fp);
        $written = fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $written !== false;
    } else {
        // Lock timeout - write anyway (better than hanging forever)
        fclose($fp);
        file_put_contents($dataFile . '.bak', $json); // backup first
        return file_put_contents($dataFile, $json) !== false;
    }
}

/**
 * Generate and send badge image with Arabic support
 */
function generateAndSendBadge($registration, $wasender, $protocol, $host, $basePath) {
    if (!extension_loaded('gd')) {
        return ['success' => false, 'error' => 'GD not available'];
    }
    
    $badgeWidth = 600;
    $badgeHeight = 950;
    
    $badge = imagecreatetruecolor($badgeWidth, $badgeHeight);
    imagesavealpha($badge, true);
    
    // Define ALL colors FIRST before using them
    $whiteBg = imagecolorallocate($badge, 255, 255, 255);
    $headerColor = imagecolorallocate($badge, 220, 53, 69);
    $cardBg = imagecolorallocate($badge, 248, 249, 250);
    $textGray = imagecolorallocate($badge, 108, 117, 125);
    $textDark = imagecolorallocate($badge, 33, 37, 41);
    $goldBorder = imagecolorallocate($badge, 255, 193, 7);
    $greenFooter = imagecolorallocate($badge, 40, 167, 69);
    
    // Font path for Arabic support - Try NotoSansArabic first
    $fontPath = __DIR__ . '/fonts/NotoSansArabic.ttf';
    
    // Test if NotoSansArabic works
    if (!file_exists($fontPath) || @imagettfbbox(12, 0, $fontPath, 'Test') === false) {
        // Path to font file - Standardized to fonts directory
        $fontPath = __DIR__ . '/fonts/Cairo-Bold.ttf';
        if (!file_exists($fontPath)) {
            // Fallback to root
            $fontPath = __DIR__ . '/Cairo-Bold.ttf';
        }
        // Final fallback
        if (!file_exists($fontPath)) {
            $fontPath = 'arial.ttf'; 
        }
    }
    
    if (!file_exists($fontPath) || @imagettfbbox(12, 0, $fontPath, 'Test') === false) {
        $fontPath = __DIR__ . '/admin/fonts/Cairo-Bold.ttf';
    }
    
    // Final check - set to null if nothing works
    if (!file_exists($fontPath) || @imagettfbbox(12, 0, $fontPath, 'Test') === false) {
        $fontPath = null;
        error_log("No working font found!");
    }
    
    // White background
    imagefilledrectangle($badge, 0, 0, $badgeWidth, $badgeHeight, $whiteBg);
    
    // Red header
    imagefilledrectangle($badge, 0, 0, $badgeWidth, 100, $headerColor);
    
    // Header text
    if ($fontPath) {
        imagettftext($badge, 20, 0, 130, 40, $whiteBg, $fontPath, "نادي بلاد الرافدين 2025");
        imagettftext($badge, 14, 0, 200, 75, $whiteBg, $fontPath, "وصل تأكيد الاشتراك");
    } else {
        $headerText = "نادي بلاد الرافدين 2025";
        $textWidth = imagefontwidth(5) * strlen($headerText);
        imagestring($badge, 5, (int)(($badgeWidth - $textWidth) / 2), 35, $headerText, $whiteBg);
    }
    
    // Registration number box
    $boxY = 120;
    imagefilledrectangle($badge, 100, $boxY, $badgeWidth - 100, $boxY + 70, $cardBg);
    imagerectangle($badge, 100, $boxY, $badgeWidth - 100, $boxY + 70, $textGray);
    
    if ($fontPath) {
        imagettftext($badge, 12, 0, 260, $boxY + 25, $textGray, $fontPath, "رقم الطلب");
    }
    $regNum = "#" . $registration['wasel'];
    if ($fontPath) {
        imagettftext($badge, 28, 0, (int)(($badgeWidth - strlen($regNum) * 15) / 2), $boxY + 55, $headerColor, $fontPath, $regNum);
    } else {
        $regWidth = imagefontwidth(5) * strlen($regNum);
        imagestring($badge, 5, (int)(($badgeWidth - $regWidth) / 2), $boxY + 30, $regNum, $headerColor);
    }
    
    // Personal photo (circular) with gold border
    $photoY = 210;
    $photoSize = 140;
    $photoX = (int)(($badgeWidth - $photoSize) / 2);
    
    imagefilledellipse($badge, (int)($badgeWidth / 2), $photoY + (int)($photoSize / 2), $photoSize + 12, $photoSize + 12, $goldBorder);
    imagefilledellipse($badge, (int)($badgeWidth / 2), $photoY + (int)($photoSize / 2), $photoSize, $photoSize, $whiteBg);
    
    // Load and apply circular photo
    $profileData = MemberService::getProfile($registration['wasel']);
    $mergedReg = $profileData['current_registration'] ?? $registration;
    $personalPhotoRaw = $mergedReg['images']['personal_photo'] ?? $mergedReg['personal_photo'] ?? $mergedReg['images']['front_image'] ?? '';
    
    $personalPhoto = '';
    if (!empty($personalPhotoRaw)) {
        $cleanPath = ltrim(str_replace('../', '', $personalPhotoRaw), '/');
        $candidates = [
            $personalPhotoRaw,
            __DIR__ . '/' . $cleanPath,
            __DIR__ . '/admin/' . $cleanPath,
            '../' . $cleanPath
        ];
        foreach ($candidates as $cand) {
            if (file_exists($cand) && !is_dir($cand)) {
                $personalPhoto = $cand;
                break;
            }
        }
    }
    if ($personalPhoto) {
        $photoInfo = @getimagesize($personalPhoto);
        if ($photoInfo) {
            $personImg = null;
            switch ($photoInfo[2]) {
                case IMAGETYPE_PNG: $personImg = @imagecreatefrompng($personalPhoto); break;
                case IMAGETYPE_JPEG: $personImg = @imagecreatefromjpeg($personalPhoto); break;
                case IMAGETYPE_WEBP: $personImg = @imagecreatefromwebp($personalPhoto); break;
            }
            
            if ($personImg) {
                $resized = imagecreatetruecolor($photoSize, $photoSize);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $transparent);
                imagealphablending($resized, true);
                
                imagecopyresampled($resized, $personImg, 0, 0, 0, 0, $photoSize, $photoSize, $photoInfo[0], $photoInfo[1]);
                
                // Circular mask
                for ($y = 0; $y < $photoSize; $y++) {
                    for ($x = 0; $x < $photoSize; $x++) {
                        $cx = $x - $photoSize / 2;
                        $cy = $y - $photoSize / 2;
                        if (sqrt($cx * $cx + $cy * $cy) > $photoSize / 2) {
                            imagesetpixel($resized, $x, $y, $transparent);
                        }
                    }
                }
                
                imagecopy($badge, $resized, $photoX, $photoY, 0, 0, $photoSize, $photoSize);
                imagedestroy($resized);
                imagedestroy($personImg);
            }
        }
    }
    
    // Personal info
    $infoY = $photoY + $photoSize + 40;
    $leftMargin = 50;
    $rightAlignX = $badgeWidth - $leftMargin;
    
    // Name
    if ($fontPath) {
        $label = "الاسم:";
        $labelBox = imagettfbbox(14, 0, $fontPath, $label);
        $labelWidth = abs($labelBox[2] - $labelBox[0]);
        imagettftext($badge, 14, 0, $rightAlignX - $labelWidth, $infoY, $textGray, $fontPath, $label);
        
        $name = $registration['full_name'] ?? '';
        imagettftext($badge, 18, 0, $leftMargin, $infoY, $textDark, $fontPath, $name);
    } else {
        imagestring($badge, 3, $rightAlignX - 50, $infoY - 10, "Name:", $textGray);
        imagestring($badge, 4, $leftMargin, $infoY - 10, $registration['full_name'] ?? '', $textDark);
    }
    $infoY += 45;
    
    // Phone
    if ($fontPath) {
        $label = "الهاتف:";
        $labelBox = imagettfbbox(14, 0, $fontPath, $label);
        $labelWidth = abs($labelBox[2] - $labelBox[0]);
        imagettftext($badge, 14, 0, $rightAlignX - $labelWidth, $infoY, $textGray, $fontPath, $label);
        
        imagettftext($badge, 18, 0, $leftMargin, $infoY, $textDark, $fontPath, $registration['phone'] ?? '');
    } else {
        imagestring($badge, 3, $rightAlignX - 50, $infoY - 10, "Phone:", $textGray);
        imagestring($badge, 4, $leftMargin, $infoY - 10, $registration['phone'] ?? '', $textDark);
    }
    $infoY += 45;
    
    // Governorate
    if ($fontPath) {
        $label = "المحافظة:";
        $labelBox = imagettfbbox(14, 0, $fontPath, $label);
        $labelWidth = abs($labelBox[2] - $labelBox[0]);
        imagettftext($badge, 14, 0, $rightAlignX - $labelWidth, $infoY, $textGray, $fontPath, $label);
        
        $gov = $registration['governorate'] ?? '';
        imagettftext($badge, 18, 0, $leftMargin, $infoY, $textDark, $fontPath, $gov);
    } else {
        imagestring($badge, 3, $rightAlignX - 70, $infoY - 10, "Governorate:", $textGray);
        imagestring($badge, 4, $leftMargin, $infoY - 10, $registration['governorate'] ?? '', $textDark);
    }
    $infoY += 60;
    
    // Separator line
    imageline($badge, $leftMargin, $infoY, $badgeWidth - $leftMargin, $infoY, $textGray);
    $infoY += 40;
    
    // Car Info Header
    if ($fontPath) {
        $carHeader = "معلومات السيارة";
        $headerBox = imagettfbbox(16, 0, $fontPath, $carHeader);
        $headerWidth = abs($headerBox[2] - $headerBox[0]);
        imagettftext($badge, 16, 0, (int)(($badgeWidth - $headerWidth) / 2), $infoY, $headerColor, $fontPath, $carHeader);
    } else {
        $carHeader = "Car Information";
        $headerWidth = imagefontwidth(5) * strlen($carHeader);
        imagestring($badge, 5, (int)(($badgeWidth - $headerWidth) / 2), $infoY - 10, $carHeader, $headerColor);
    }
    $infoY += 50;
    
    // Car Type
    if ($fontPath) {
        $label = "النوع:";
        $labelBox = imagettfbbox(14, 0, $fontPath, $label);
        $labelWidth = abs($labelBox[2] - $labelBox[0]);
        imagettftext($badge, 14, 0, $rightAlignX - $labelWidth, $infoY, $textGray, $fontPath, $label);
        
        $car = ($registration['car_type'] ?? '') . ' ' . ($registration['car_year'] ?? '');
        imagettftext($badge, 18, 0, $leftMargin, $infoY, $textDark, $fontPath, $car);
    } else {
        imagestring($badge, 3, $rightAlignX - 50, $infoY - 10, "Type:", $textGray);
        imagestring($badge, 4, $leftMargin, $infoY - 10, ($registration['car_type'] ?? '') . ' ' . ($registration['car_year'] ?? ''), $textDark);
    }
    $infoY += 45;
    
    // Plate
    if ($fontPath) {
        $label = "اللوحة:";
        $labelBox = imagettfbbox(14, 0, $fontPath, $label);
        $labelWidth = abs($labelBox[2] - $labelBox[0]);
        imagettftext($badge, 14, 0, $rightAlignX - $labelWidth, $infoY, $textGray, $fontPath, $label);
        
        $plate = $registration['plate_full'] ?? '';
        imagettftext($badge, 18, 0, $leftMargin, $infoY, $textDark, $fontPath, $plate);
    } else {
        imagestring($badge, 3, $rightAlignX - 50, $infoY - 10, "Plate:", $textGray);
        imagestring($badge, 4, $leftMargin, $infoY - 10, $registration['plate_full'] ?? '', $textDark);
    }
    $infoY += 80;

    // Generate & Overlay QR Code
    // Use the same URL format as badge.php for consistency
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    
    // Use registration_code if available (Most Stable), otherwise fallback to badge_id/wasel
    if (!empty($registration['registration_code'])) {
        $verifyUrl = $protocol . '://' . $host . $basePath . '/verify_entry.php?token=' . urlencode($registration['registration_code']) . '&action=checkin';
    } else {
        $badgeIdentifier = $registration['badge_id'] ?? $registration['badge_token'] ?? $registration['wasel'];
        $verifyUrl = $protocol . '://' . $host . $basePath . '/verify_entry.php?badge_id=' . urlencode($badgeIdentifier) . '&action=checkin';
    }
    
    // Generate QR with the verify URL (same as badge.php)
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($verifyUrl);
    
    // Fetch QR image with strict 2-second timeout to prevent server hangs
    $qrContent = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($qrUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2 seconds strict timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $qrContent = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
            $qrContent = false;
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $qrContent = @file_get_contents($qrUrl, false, $ctx);
    }
    if ($qrContent) {
        $qrImg = @imagecreatefromstring($qrContent);
        if ($qrImg) {
            $qrWidth = imagesx($qrImg);
            $qrHeight = imagesy($qrImg);
            
            // Position: Bottom Right (above footer)
            // Frame is 600x950. Footer is at 890.
            // Let's place it at x=430, y=720 (size 140x140)
            $targetQrSize = 140;
            $qrX = $badgeWidth - $targetQrSize - 30; // 30px from right
            $qrY = $badgeHeight - $targetQrSize - 80; // 80px from bottom (above footer)
            
            // Add white background for QR
            imagefilledrectangle($badge, $qrX - 10, $qrY - 10, $qrX + $targetQrSize + 10, $qrY + $targetQrSize + 10, $whiteBg);
            
            // Copy QR
            imagecopyresampled($badge, $qrImg, $qrX, $qrY, 0, 0, $targetQrSize, $targetQrSize, $qrWidth, $qrHeight);
            imagedestroy($qrImg);
        }
    }

    // Code Box
    if (!empty($registration['registration_code'])) {
        imagefilledrectangle($badge, 150, $infoY, $badgeWidth - 150, $infoY + 60, $cardBg);
        imagerectangle($badge, 150, $infoY, $badgeWidth - 150, $infoY + 60, $goldBorder);
        
        if ($fontPath) {
            $codeLabel = "كود التسجيل السريع";
            $codeLabelBox = imagettfbbox(11, 0, $fontPath, $codeLabel);
            $codeLabelWidth = abs($codeLabelBox[2] - $codeLabelBox[0]);
            imagettftext($badge, 11, 0, (int)(($badgeWidth - $codeLabelWidth) / 2), $infoY + 20, $textGray, $fontPath, $codeLabel);
            
            $code = $registration['registration_code'];
            $codeBox = imagettfbbox(18, 0, $fontPath, $code);
            $codeWidth = abs($codeBox[2] - $codeBox[0]);
            imagettftext($badge, 18, 0, (int)(($badgeWidth - $codeWidth) / 2), $infoY + 48, $headerColor, $fontPath, $code);
        } else {
            $codeText = "CODE: " . $registration['registration_code'];
            $codeWidth = imagefontwidth(5) * strlen($codeText);
            imagestring($badge, 5, (int)(($badgeWidth - $codeWidth) / 2), $infoY + 20, $codeText, $headerColor);
        }
    }
    
    // Footer
    $footerY = $badgeHeight - 60;
    imagefilledrectangle($badge, 0, $footerY, $badgeWidth, $badgeHeight, $greenFooter);
    if ($fontPath) {
        $footerText = "يرجى إبراز هذا الباج عند الدخول";
        $footerBbox = imagettfbbox(14, 0, $fontPath, $footerText);
        $footerWidth = abs($footerBbox[2] - $footerBbox[0]);
        imagettftext($badge, 14, 0, (int)(($badgeWidth - $footerWidth) / 2), $footerY + 38, $whiteBg, $fontPath, $footerText);
    } else {
        $footerText = "Show this badge at arena entrance";
        $footerWidth = imagefontwidth(4) * strlen($footerText);
        imagestring($badge, 4, (int)(($badgeWidth - $footerWidth) / 2), $footerY + 20, $footerText, $whiteBg);
    }
    
    // Save Badge
    $badgeDir = 'uploads/badges/';
    if (!file_exists($badgeDir)) {
        mkdir($badgeDir, 0777, true);
    }
    
    $badgeFilename = 'badge_' . $registration['wasel'] . '_' . time() . '.png';
    $badgePath = $badgeDir . $badgeFilename;
    
    imagepng($badge, $badgePath, 9);
    imagedestroy($badge);
    
    // Send Badge
    $badgeUrl = $protocol . '://' . $host . '/' . $basePath . '/' . $badgePath;
    $badgeUrl = preg_replace('#(?<!:)//+#', '/', $badgeUrl);
    
    $badgeCaption = "🎫 وصل تأكيد الاشتراك\n\n✅ قم بإظهار هذا الباج عند الدخول للحلبة";
    if (!empty($registration['registration_code'])) {
        $badgeCaption .= "\n\n🔑 كود التسجيل السريع: " . $registration['registration_code'];
    }
    
    $countryCode = $registration['country_code'] ?? '+964';
    return $wasender->sendImage($registration['phone'], $badgeUrl, $badgeCaption, $countryCode);
}
?>
