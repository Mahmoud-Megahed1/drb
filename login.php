<?php

$txt = '';

require_once 'include/db.php';
require_once 'include/auth.php';

// Ensure default users exist in SQLite
ensureDefaultUsers();

// Get redirect URL if provided
$redirectUrl = isset($_GET['redirect']) ? $_GET['redirect'] : null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit'])) {
	
	$adminPage = 'dashboard.php';
	
	// Use redirect URL if provided
	if (isset($_POST['redirect']) && !empty($_POST['redirect'])) {
	    $redirectUrl = $_POST['redirect'];
	}
	
	// Legacy JSON login
	$users = json_decode(file_get_contents('admin/data/admins.json'));
	$username = $_POST['login'];
	$password = $_POST['pwd'];
	
	$checks = [];
	$checks = array_filter($users, fn($x) => ($x->username == $username && $x->password == $password));
	
	// Check in new SQLite system first (for rounds/notes users)
	$sqliteLogin = loginUser($username, $password);
	
	if ($sqliteLogin['success']) {
	    // SQLite Login Success
	    // Session already set by loginUser()
	    
	    $role = $_SESSION['user_role'];

        // LOGGING
        if (file_exists('include/AdminLogger.php')) {
            require_once 'include/AdminLogger.php';
            $logger = new AdminLogger();
            $logger->log(AdminLogger::ACTION_LOGIN, $username, 'تسجيل دخول ناجح', ['role' => $role]);
        }
	    
	    // Use redirect URL if provided and safe
	    if ($redirectUrl && !preg_match('/^(https?:\/\/|\/\/)/', $redirectUrl)) {
	        header("location:" . $redirectUrl);
	        exit;
	    }
	    
	    // Redirect based on role
	    if ($role === 'rounds') {
	        header("location:admin/rounds_scanner.php");
	    } elseif ($role === 'notes') {
	        header("location:admin/notes_scanner.php");
	    } elseif ($role === 'gate' || $role === 'scanner') {
            header("location:gate.php");
        } else {
	        header("location:$adminPage");
	    }
	    exit;
	}
	// Fallback to Legacy Login
	else if (count($checks) > 0) {
		session_start();
		$key = array_keys($checks)[0];
		$_SESSION['user'] = $checks[$key];
        
        $uName = $_SESSION['user']->username;
        $uRole = $_SESSION['user']->role ?? 'unknown';

        // LOGGING
        if (file_exists('include/AdminLogger.php')) {
            require_once 'include/AdminLogger.php';
            $logger = new AdminLogger();
            $logger->log(AdminLogger::ACTION_LOGIN, $uName, 'تسجيل دخول ناجح (Legacy)', ['role' => $uRole]);
        }
		
		// Bridge: Set new system session variables for legacy users
		$_SESSION['user_id'] = 999; // Mock ID for legacy
		$_SESSION['username'] = $_SESSION['user']->username;
		$_SESSION['login_time'] = time();
		
		// Map roles
		if ($_SESSION['user']->username === 'root' || $_SESSION['user']->role === 'root' || $_SESSION['user']->role === 'admin') {
		    $_SESSION['user_role'] = 'admin';
		} elseif ($_SESSION['user']->username === 'scanner' || $_SESSION['user']->role === 'gate' || $_SESSION['user']->role === 'scanner') {
		    $_SESSION['user_role'] = 'gate';
		} elseif ($_SESSION['user']->role === 'rounds' || $_SESSION['user']->username === 'champion') {
		    $_SESSION['user_role'] = 'rounds';
		} elseif ($_SESSION['user']->role === 'notes') {
		    $_SESSION['user_role'] = 'notes';
		} elseif ($_SESSION['user']->role === 'whatsapp') {
		    $_SESSION['user_role'] = 'admin';
		} elseif ($_SESSION['user']->role === 'approver') {
		    $_SESSION['user_role'] = 'approver';
		} else {
		    $_SESSION['user_role'] = 'viewer';
		}
		
		// Use redirect URL if provided and safe
		if ($redirectUrl && !preg_match('/^(https?:\/\/|\/\/)/', $redirectUrl)) {
		    header("location:" . $redirectUrl);
		    exit;
		}
		
		// Redirect Scanner to their special dashboard (Legacy)
		if (isset($_SESSION['user']->username) && $_SESSION['user']->username === 'scanner') {
			header("location:gate.php");
			exit;
		} else {
			header("location:$adminPage");
			exit;
		}
	}
	else {
		$txt = 'بيانات الدخول غير صحيحة';
	}
}


?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <title>تسجيل الدخول</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta charset="utf-8">
	
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
	<link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
	
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>	
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>

<style>
body { font-family: 'Cairo', sans-serif; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; }
.form-control{ margin-bottom:10px}
.form-signin { max-width: 400px; margin: 100px auto; background: #fff; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
.form-signin h2 { text-align: center; margin-bottom: 20px; color: #333; }
.wrong-info {
	text-align: center;
	padding: 10px;
	margin: 5px;
	color: rgb(150, 20, 20);
}
</style>
</head>
<body>
	<br /><br /><br />

    <div class="container">
	
	<form class="form-signin" role="form" method="POST" action="">
		<h2 class="form-signin-heading">الرجاء تسجيل الدخول</h2>
		<h3 class="wrong-info"><?=$txt ?></h3>
		<input type="text" class="form-control" placeholder="اسم المستخدم" required autofocus name="login">
		<input type="password" class="form-control" placeholder="كلمة المرور" required name="pwd">
		<?php if ($redirectUrl): ?>
		<input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectUrl) ?>">
		<?php endif; ?>
		<button class="btn btn-lg btn-primary btn-block" type="submit" name="submit">تسجيل الدخول</button>
	</form>

    </div> <!-- /container -->

    <div class="text-center text-muted" style="margin-top: 20px; font-size: 11px;">
        نادي بلاد الرافدين System v2.1 (Confirmed)
    </div>
</body>
</html>







