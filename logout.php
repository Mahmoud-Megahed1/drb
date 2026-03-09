<?php
/**
 * Logout - تسجيل الخروج
 */
session_start();

// Log the logout BEFORE destroying session
$username = 'unknown';
if (isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
    if (is_object($user)) {
        $username = $user->username ?? 'unknown';
    } elseif (is_array($user)) {
        $username = $user['username'] ?? 'unknown';
    }
}

if ($username !== 'unknown') {
    try {
        require_once 'include/AdminLogger.php';
        $logger = new AdminLogger();
        $logger->log(AdminLogger::ACTION_LOGOUT, $username, 'تسجيل خروج');
    } catch (Exception $e) {
        // Don't block logout if logging fails
    }
}

session_unset();
session_destroy();

header('location:login.php');