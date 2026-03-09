<?php
/**
 * Notes Gate Login Page
 * Entry point for notes staff
 */

require_once 'include/db.php';
require_once 'include/auth.php';

// Ensure default users exist
ensureDefaultUsers();

$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $deviceName = trim($_POST['device_name'] ?? 'notes_tablet');
    
    if (!checkRateLimit('login_' . $_SERVER['REMOTE_ADDR'], 5, 60)) {
        $error = 'محاولات كثيرة، انتظر دقيقة';
    } else {
        $result = loginUser($username, $password, $deviceName);
        if ($result['success']) {
            if (hasPermission('notes')) {
                header('Location: admin/notes_scanner.php');
                exit;
            } else {
                logoutUser();
                $error = 'ليس لديك صلاحية الوصول لكاميرا الملاحظات';
            }
        } else {
            $error = $result['error'];
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    logoutUser();
    header('Location: notes_gate.php');
    exit;
}

// If already logged in with correct role
if (isLoggedIn() && hasPermission('notes')) {
    header('Location: admin/notes_scanner.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📝 كاميرا الملاحظات</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-box {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 40px;
            border-radius: 20px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            color: #fff;
        }
        .login-box h1 {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .login-box h2 {
            font-size: 24px;
            margin-bottom: 30px;
            opacity: 0.9;
        }
        .form-group {
            margin-bottom: 20px;
            text-align: right;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            opacity: 0.8;
        }
        .form-group input {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-family: inherit;
            text-align: center;
        }
        .btn-login {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            color: white;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            font-family: inherit;
            margin-top: 10px;
            transition: transform 0.2s;
        }
        .btn-login:hover {
            transform: scale(1.02);
        }
        .error {
            background: rgba(220,53,69,0.2);
            border: 1px solid #dc3545;
            color: #ff6b6b;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .back-link {
            display: block;
            margin-top: 20px;
            color: #aaa;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>📝</h1>
        <h2>كاميرا الملاحظات</h2>
        
        <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>اسم المستخدم</label>
                <input type="text" name="username" placeholder="notes_user" required autofocus>
            </div>
            
            <div class="form-group">
                <label>كلمة المرور</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            
            <div class="form-group">
                <label>اسم الجهاز (اختياري)</label>
                <input type="text" name="device_name" placeholder="مثال: notes_tablet">
            </div>
            
            <button type="submit" class="btn-login">🔓 دخول</button>
        </form>
        
        <a href="index.php" class="back-link">← العودة للرئيسية</a>
    </div>
</body>
</html>

