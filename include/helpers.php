<?php
/**
 * Helper Functions
 * Phone normalization, QR generation, common utilities
 */

/**
 * Normalize Iraqi phone number to 10 digits starting with 7
 * 
 * @param string $phone Raw phone input
 * @return string Normalized phone (78XXXXXXXX)
 * @throws InvalidArgumentException If phone is invalid
 */
function normalizePhone($phone) {
    // Remove all non-digits
    $phone = preg_replace('/\D/', '', $phone);
    
    // Handle +964 or 964 prefix -> Remove it
    if (str_starts_with($phone, '964')) {
        $phone = substr($phone, 3);
    }
    
    // Handle 07XXXXXXXXX (11 digits starting with 07) -> Remove 0
    if (strlen($phone) === 11 && str_starts_with($phone, '07')) {
        $phone = substr($phone, 1);
    }
    
    // Strict Check for Iraqi formatted numbers (7XXXXXXXXX)
    if (str_starts_with($phone, '7') && strlen($phone) === 10) {
        return $phone; // Valid Iraqi Number (10 digits starting with 7)
    }
    
    // If it didn't match Iraqi format, allow it if it's a valid international length (8-15 digits)
    if (strlen($phone) >= 8 && strlen($phone) <= 15) {
        return $phone; 
    }
    
    // If neither, throw exception
    throw new InvalidArgumentException('رقم الهاتف غير صحيح (يجب أن يكون بين 8 و 15 رقم)');
}

/**
 * Generate permanent QR code for a member
 * Deterministic - same ID always produces same code
 * 
 * @param int $memberId Member ID
 * @return string Permanent code (DRB-XXXXXXXXXX)
 */
function generatePermanentCode($memberId) {
    $salt = getSetting('qr_salt') ?? 'DRB_SECRET_SALT_2025';
    $hash = hash('sha256', $salt . '_MEMBER_' . $memberId);
    return strtoupper(substr($hash, 0, 12));
}

/**
 * Generate session badge token (for current registration)
 * 
 * @param int $registrationId Registration ID
 * @return string Session token
 */
function generateSessionBadgeToken($registrationId) {
    $salt = getSetting('qr_salt') ?? 'DRB_SECRET_SALT_2025';
    $hash = hash('sha256', $salt . '_REG_' . $registrationId . '_' . time());
    return 'REG-' . strtoupper(substr($hash, 0, 12));
}

/**
 * Get setting from database
 * 
 * @param string $key Setting key
 * @param mixed $default Default value if not found
 * @return mixed Setting value
 */
function getSetting($key, $default = null) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        
        if ($result === false) {
            return $default;
        }
        
        // Convert string booleans
        if ($result === 'true') return true;
        if ($result === 'false') return false;
        
        return $result;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Set setting in database with audit
 * 
 * @param string $key Setting key
 * @param mixed $value Setting value
 * @param int|null $userId User making the change
 * @return bool Success
 */
function setSetting($key, $value, $userId = null) {
    $pdo = db();
    
    // Convert booleans to string
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    }
    
    // Get old value for audit
    $oldValue = getSetting($key);
    
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (key, value, updated_at, updated_by) 
        VALUES (?, ?, datetime('now', '+3 hours'), ?)
        ON CONFLICT(key) DO UPDATE SET 
            value = excluded.value,
            updated_at = datetime('now', '+3 hours'),
            updated_by = excluded.updated_by
    ");
    $result = $stmt->execute([$key, $value, $userId]);
    
    // Audit log
    if ($result && $oldValue !== $value) {
        auditLog('update', 'system_settings', null, 
            json_encode(['key' => $key, 'old' => $oldValue]),
            json_encode(['key' => $key, 'new' => $value]),
            $userId
        );
    }
    
    return $result;
}

/**
 * Get multiple settings at once
 * 
 * @param array $keys Array of setting keys
 * @return array Associative array of settings
 */
function getSettings($keys) {
    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT key, value FROM system_settings WHERE key IN ($placeholders)");
    $stmt->execute($keys);
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $val = $row['value'];
        // Convert string booleans
        if ($val === 'true') $val = true;
        elseif ($val === 'false') $val = false;
        $settings[$row['key']] = $val;
    }
    
    return $settings;
}

/**
 * Get current championship ID
 * 
 * @return int Championship ID
 */
function getCurrentChampionshipId() {
    return intval(getSetting('current_championship_id', 1));
}

/**
 * Write to audit log
 * 
 * @param string $action Action performed (create, update, delete, approve, etc.)
 * @param string $entity Entity type (member, registration, warning, etc.)
 * @param int|null $entityId Entity ID
 * @param string|null $oldValue JSON of old values
 * @param string|null $newValue JSON of new values
 * @param int|null $userId User performing the action
 */
function auditLog($action, $entity, $entityId = null, $oldValue = null, $newValue = null, $userId = null) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (action, entity, entity_id, old_value, new_value, user_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $action,
            $entity,
            $entityId,
            $oldValue,
            $newValue,
            $userId ?? ($_SESSION['user_id'] ?? null),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        // --- BRIDGE TO AdminLogger (Legacy UI support) ---
        require_once __DIR__ . '/AdminLogger.php';
        $logger = new AdminLogger();
        $username = 'System';
        if (isset($_SESSION['user'])) {
            if (is_object($_SESSION['user'])) {
                $username = $_SESSION['user']->username ?? 'System';
            } elseif (is_array($_SESSION['user'])) {
                $username = $_SESSION['user']['username'] ?? 'System';
            }
        }
        
        // Detailed description based on action
        if ($action === 'create') $description = "إضافة $entity جديد (ID: $entityId)";
        elseif ($action === 'update') $description = "تعديل في $entity (ID: $entityId)";
        elseif ($action === 'delete') $description = "حذف $entity (ID: $entityId)";
        elseif ($action === 'import') $description = "عملية استيراد: $newValue";
        
        $logger->log($action, $username, $description, [
            'entity' => $entity,
            'id' => $entityId,
            'old' => $oldValue,
            'new' => $newValue
        ]);

    } catch (Exception $e) {
        // Silent fail - don't break main operation if audit fails
        error_log("Audit log error: " . $e->getMessage());
    }
}

/**
 * Format plate for display
 * 
 * @param string $gov Governorate
 * @param string $letter Letter
 * @param string $number Number
 * @return string Formatted plate
 */
function formatPlate($gov, $letter, $number) {
    return trim("$gov - $letter $number");
}

/**
 * Parse plate from full string
 * 
 * @param string $plateString Full plate string
 * @return array ['gov' => '', 'letter' => '', 'number' => '']
 */
function parsePlate($plateString) {
    // Try different formats
    // Format 1: "بغداد - أ 12345"
    if (preg_match('/^(.+?)\s*-\s*(.+?)\s+(\d+)$/', $plateString, $matches)) {
        return [
            'gov' => trim($matches[1]),
            'letter' => trim($matches[2]),
            'number' => trim($matches[3])
        ];
    }
    
    return ['gov' => '', 'letter' => '', 'number' => $plateString];
}

/**
 * Validate registration uniqueness per championship
 * 
 * @param string $phone Phone number (will be normalized)
 * @param string $plateGov Plate governorate
 * @param string $plateLetter Plate letter
 * @param string $plateNumber Plate number
 * @param int $championshipId Championship ID
 * @param int|null $excludeMemberId Exclude this member (for updates)
 * @return array Errors (empty if valid)
 */
function validateRegistrationUniqueness($phone, $plateGov, $plateLetter, $plateNumber, $championshipId, $excludeMemberId = null) {
    $pdo = db();
    $errors = [];
    
    // Normalize phone
    try {
        $phone = normalizePhone($phone);
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
        return $errors; // Can't continue without valid phone
    }
    
    // Check phone in this championship
    $sql = "
        SELECT m.name FROM registrations r
        JOIN members m ON r.member_id = m.id
        WHERE m.phone = ? AND r.championship_id = ? AND r.is_active = 1
    ";
    $params = [$phone, $championshipId];
    
    if ($excludeMemberId) {
        $sql .= " AND m.id != ?";
        $params[] = $excludeMemberId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $errors[] = "رقم الهاتف مسجّل مسبقاً باسم: {$existing['name']}";
    }
    
    // Check plate in this championship
    if ($plateGov && $plateLetter && $plateNumber) {
        $sql = "
            SELECT m.name FROM registrations r
            JOIN members m ON r.member_id = m.id
            WHERE r.plate_governorate = ? 
            AND r.plate_letter = ? 
            AND r.plate_number = ?
            AND r.championship_id = ?
            AND r.is_active = 1
        ";
        $params = [$plateGov, $plateLetter, $plateNumber, $championshipId];
        
        if ($excludeMemberId) {
            $sql .= " AND m.id != ?";
            $params[] = $excludeMemberId;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = "رقم اللوحة مسجّل مسبقاً باسم: {$existing['name']}";
        }
    }
    
    return $errors;
}

/**
 * Check if member is registered in current championship
 * 
 * @param int $memberId Member ID
 * @return bool|array False if not registered, registration data if registered
 */
function getMemberCurrentRegistration($memberId) {
    $pdo = db();
    $championshipId = getCurrentChampionshipId();
    
    $stmt = $pdo->prepare("
        SELECT * FROM registrations 
        WHERE member_id = ? AND championship_id = ? AND is_active = 1
    ");
    $stmt->execute([$memberId, $championshipId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
}

/**
 * Get member by permanent code
 * 
 * @param string $code Permanent code (DRB-XXXXXXXXXX)
 * @return array|false Member data or false
 */
function getMemberByCode($code) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM members WHERE permanent_code = ? AND is_active = 1");
    $stmt->execute([$code]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get member by phone
 * 
 * @param string $phone Phone number (will be normalized)
 * @return array|false Member data or false
 */
function getMemberByPhone($phone) {
    try {
        $phone = normalizePhone($phone);
    } catch (Exception $e) {
        return false;
    }
    
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM members WHERE phone = ? AND is_active = 1");
    $stmt->execute([$phone]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
