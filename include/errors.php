<?php
/**
 * Error Codes and API Response Helpers
 */

// Error codes with Arabic messages
define('ERROR_CODES', [
    'UNAUTHORIZED' => 'غير مصرح',
    'INVALID_INPUT' => 'مدخلات غير صحيحة',
    'ROUND_NOT_ACTIVE' => 'الجولة غير مفعلة',
    'ROUND_NOT_FOUND' => 'الجولة غير موجودة',
    'PARTICIPANT_NOT_FOUND' => 'المشارك غير موجود',
    'ALREADY_ENTERED' => 'تم الدخول مسبقاً لهذه الجولة',
    'NOT_ENTERED_YET' => 'لم يدخل بعد، لا يمكن تسجيل الخروج',
    'BLOCKER_NOTE' => 'يوجد مانع للدخول',
    'RATE_LIMITED' => 'محاولات كثيرة، انتظر قليلاً',
    'DB_ERROR' => 'خطأ في قاعدة البيانات',
    'NOTE_TOO_SHORT' => 'الملاحظة قصيرة جداً (3 أحرف على الأقل)',
    'NOTE_TOO_LONG' => 'الملاحظة طويلة جداً (500 حرف كحد أقصى)',
    'INVALID_NOTE_TYPE' => 'نوع الملاحظة غير صحيح',
    'USER_EXISTS' => 'اسم المستخدم موجود مسبقاً',
    'WEAK_PASSWORD' => 'كلمة المرور ضعيفة',
    'ACCESS_DENIED_TYPE' => 'نوع المشاركة لا يسمح بدخول هذا النشاط'
]);

/**
 * Create error response
 */
function apiError($code, $extra = []) {
    return array_merge([
        'success' => false,
        'code' => $code,
        'error' => ERROR_CODES[$code] ?? $code
    ], $extra);
}

/**
 * Create success response
 */
function apiSuccess($message, $data = []) {
    return array_merge([
        'success' => true,
        'message' => $message
    ], $data);
}

/**
 * Send JSON response and exit
 */
function jsonResponse($data, $statusCode = 200) {
    if (ob_get_length()) ob_end_clean(); // Prevent JSON corruption
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error and exit
 */
function jsonError($code, $extra = [], $statusCode = 400) {
    jsonResponse(apiError($code, $extra), $statusCode);
}

/**
 * Custom API Exception
 */
class ApiException extends Exception {
    private $errorCode;
    private $extra;
    
    public function __construct($errorCode, $extra = []) {
        parent::__construct(ERROR_CODES[$errorCode] ?? $errorCode);
        $this->errorCode = $errorCode;
        $this->extra = $extra;
    }
    
    public function getErrorCode() {
        return $this->errorCode;
    }
    
    public function getExtra() {
        return $this->extra;
    }
    
    public function toArray() {
        return apiError($this->errorCode, $this->extra);
    }
}
