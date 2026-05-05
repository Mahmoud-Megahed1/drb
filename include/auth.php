<?php
/**
 * Authentication & Authorization Helper
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// Permission matrix
define('PERMISSIONS', [
    'admin'  => ['rounds', 'notes', 'dashboard', 'settings', 'users', 'all'],
    'rounds' => ['rounds', 'view_notes_high', 'view_participant'],
    'notes'  => ['notes', 'view_participant'],
    'gate'   => ['gate', 'notes', 'view_notes_high', 'view_participant'],
    'approver' => ['dashboard', 'registrations', 'approvals']
]);

/**
 * Check if user has specific role(s)
 */
function hasRole($roles) {
    $userRole = $_SESSION['user_role'] ?? null;
    if (!$userRole) return false;
    return in_array($userRole, (array)$roles);
}

/**
 * Check if user has specific permission
 */
function hasPermission($permission) {
    $userRole = $_SESSION['user_role'] ?? null;
    if (!$userRole) return false;
    
    $perms = PERMISSIONS[$userRole] ?? [];
    return in_array('all', $perms) || in_array($permission, $perms);
}

/**
 * Get current user info
 */
function getCurrentUser() {
    // Ensure legacy session is migrated
    isLoggedIn();
    
    // Build username with fallback to legacy session
    $username = $_SESSION['username'] ?? null;
    if (!$username && isset($_SESSION['user'])) {
        $username = is_object($_SESSION['user']) 
            ? ($_SESSION['user']->username ?? null) 
            : ($_SESSION['user']['username'] ?? null);
    }
    
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $username,
        'role' => $_SESSION['user_role'] ?? null,
        'device' => $_SESSION['device_name'] ?? null
    ];
}

/**
 * Login user
 */
function loginUser($username, $password, $deviceName = null) {
    $pdo = db();
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'بيانات الدخول غير صحيحة'];
    }
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['device_name'] = $deviceName ?? $user['device_name'];
    $_SESSION['login_time'] = time();
    
    // New fields for entry logging
    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
    $_SESSION['department'] = $user['department'] ?? 'البوابة';
    
    // Bridge: Populate legacy $_SESSION['user'] for compatibility with older pages
    $_SESSION['user'] = (object)[
        'username' => $user['username'],
        'role' => $user['role'],
        'full_name' => $user['full_name'] ?? $user['username'],
        'id' => $user['id']
    ];
    
    return ['success' => true, 'user' => $user];
}

/**
 * Logout user
 */
function logoutUser() {
    $_SESSION = [];
    session_destroy();
}

/**
 * Check if logged in
 */
function isLoggedIn() {
    // Check new system
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        // Ensure $_SESSION['user'] object exists for legacy pages
        if (!isset($_SESSION['user']) || !is_object($_SESSION['user'])) {
            $_SESSION['user'] = (object)[
                'username' => $_SESSION['username'] ?? 'unknown',
                'role' => $_SESSION['user_role'] ?? 'viewer',
                'id' => $_SESSION['user_id']
            ];
        }
        return true;
    }
    
    // Check legacy system and AUTO-MIGRATE session (Legacy -> New)
    if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
        $u = $_SESSION['user'];
        // Auto-fix session variables for new functions
        $_SESSION['user_id'] = $_SESSION['user_id'] ?? 999;
        $_SESSION['username'] = is_object($u) ? ($u->username ?? 'legacy') : ($u['username'] ?? 'legacy');
        
        // Map roles
        if (!isset($_SESSION['user_role'])) {
            $uName = $_SESSION['username'];
            $uRole = is_object($u) ? ($u->role ?? '') : ($u['role'] ?? '');
            
            if ($uName === 'root' || $uRole === 'root' || $uRole === 'admin') {
                $_SESSION['user_role'] = 'admin';
            } elseif ($uName === 'scanner' || $uRole === 'gate') {
                $_SESSION['user_role'] = 'gate';
            } elseif ($uRole === 'whatsapp') {
                $_SESSION['user_role'] = 'admin';
            } elseif ($uRole === 'approver') {
                $_SESSION['user_role'] = 'approver';
            } else {
                $_SESSION['user_role'] = 'viewer';
            }
        }
        
        $_SESSION['device_name'] = $_SESSION['device_name'] ?? 'Legacy Session';
        return true;
    }
    
    // Check Gate User (Standalone Gate Password)
    if (isset($_SESSION['gate_user']) && $_SESSION['gate_user'] === true) {
        // Auto-populate role for helpers
        if (!isset($_SESSION['user_role'])) {
            $_SESSION['user_role'] = 'gate';
            $_SESSION['username'] = 'gate_operator';
            $_SESSION['user_id'] = 888; // Virtual ID
            
            // Populate legacy object too
            $_SESSION['user'] = (object)[
                'username' => 'gate_operator',
                'role' => 'gate',
                'id' => 888
            ];
        }
        return true;
    }
    
    return false;
}

/**
 * Require authentication (redirect if not logged in)
 */
function requireAuth($redirectTo = '../login.php') {
    if (!isLoggedIn()) {
        header("Location: $redirectTo");
        exit;
    }
}

/**
 * Require specific role
 */
function requireRole($roles, $redirectTo = '../unauthorized.php') {
    requireAuth();
    if (!hasRole($roles)) {
        header("Location: $redirectTo");
        exit;
    }
}

/**
 * Rate limiting (SQLite-based)
 */
function checkRateLimit($key, $maxAttempts = 5, $windowSeconds = 60) {
    $pdo = db();
    $now = time();
    $cutoff = $now - $windowSeconds;
    
    // Clean old entries (inline cleanup)
    $pdo->prepare("DELETE FROM rate_limits WHERE created_at < ?")->execute([$cutoff]);
    
    // Count recent attempts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE limit_key = ? AND created_at > ?");
    $stmt->execute([$key, $cutoff]);
    $count = $stmt->fetchColumn();
    
    if ($count >= $maxAttempts) {
        return false;
    }
    
    // Record this attempt
    $stmt = $pdo->prepare("INSERT INTO rate_limits (limit_key, created_at) VALUES (?, ?)");
    $stmt->execute([$key, $now]);
    
    return true;
}

/**
 * Create default users if none exist
 */
function ensureDefaultUsers() {
    $pdo = db();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() > 0) return;
    
    $users = [
        ['admin', 'admin123', 'admin', 'dashboard'],
        ['rounds_user', 'rounds2025', 'rounds', 'rounds_tablet'],
        ['notes_user', 'notes2025', 'notes', 'notes_tablet'],
        ['gate_user', 'gate2025', 'gate', 'main_gate']
    ];
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, device_name) VALUES (?, ?, ?, ?)");
    foreach ($users as $u) {
        $stmt->execute([$u[0], password_hash($u[1], PASSWORD_DEFAULT), $u[2], $u[3]]);
    }
}
