<?php

$txt = '';

require_once 'include/db.php';
require_once 'include/auth.php';
require_once 'include/AdminLogger.php';

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:login.php');
    exit;
}

// التحقق من أن المستخدم root أو مدير عام
$currentUser = $_SESSION['user'];
$isRoot = (isset($currentUser->username) && $currentUser->username === 'root');
$isAdmin = ($isRoot || (isset($currentUser->role) && $currentUser->role === 'admin' && isLoggedIn()));

if (!$isAdmin) {
    header('location:dashboard.php');
    exit;
}

$adminsFile = 'admin/data/admins.json';
// Reload from file to ensure we display current state
$admins = file_exists($adminsFile) ? json_decode(file_get_contents($adminsFile)) : [];

// --- HELPER: Sync to SQLite ---
function syncUserToSqlite($username, $password, $role, $deviceName = 'Legacy Admin Page') {
    $pdo = db();
    
    // Only sync roles that exist in the SQLite schema
    $sqliteRoles = ['admin', 'rounds', 'notes', 'gate', 'approver', 'viewer'];
    
    // Map legacy 'scanner' role to 'gate' if needed, or just skip if not compatible
    // The user has 'gate' role in dropdown now.
    
    if (in_array($role, $sqliteRoles)) {
        // Check if exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        if ($stmt->fetch()) {
            // Update
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, role = ? WHERE username = ?");
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $role, $username]);
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, device_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $deviceName]);
        }
    }
}

function deleteUserFromSqlite($username) {
    if ($username === 'admin' || $username === 'root') return; // Protect root
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM users WHERE username = ?");
    $stmt->execute([$username]);
}

// --- ACTIONS ---

if (isset($_POST['delete'])) {
  $index = $_POST['index'];
  if (isset($admins[$index])) {
      $usernameToDelete = $admins[$index]->username;
      
      // Prevent deleting root
      if ($usernameToDelete === 'root') {
          $txt = '<div class="alert alert-danger">لا يمكن حذف المدير العام</div>';
      } else {
          // Delete from SQLite
          deleteUserFromSqlite($usernameToDelete);
          
          // Delete from JSON
          array_splice($admins, $index, 1); 
          
          // Log deletion to AdminLogger
          $adminLogger = new AdminLogger();
          $adminLogger->log(
              AdminLogger::ACTION_PARTICIPANT_DELETE,
              $currentUser->username ?? 'root',
              'حذف مشرف: ' . $usernameToDelete,
              ['deleted_admin' => $usernameToDelete, 'source' => 'admins_page']
          );
          
          $txt = '<div class="alert alert-success">تم الحذف</div>';
          file_put_contents($adminsFile, json_encode($admins, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      }
  }
}

if (isset($_POST['add'])) {
  $role = $_POST['role'];
  
  $newAdmin = [
    "name"     => $_POST['name'],
    "username" => $_POST['username'],
    "password" => $_POST['password'],
    "role"     => $role
  ];
  
  // Save to SQLite (if role is compatible)
  syncUserToSqlite($_POST['username'], $_POST['password'], $role);
  
  // Save to JSON (always)
  $admins[] = json_decode(json_encode($newAdmin));
  
  $txt = '<div class="alert alert-success">تمت الاضافة</div>';
  file_put_contents($adminsFile, json_encode($admins, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<title>إدارة المشرفين</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="utf-8">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet"> 
<style>
    body { font-family: 'Cairo', sans-serif; background: #f5f5f5; }
    .myform { background: #fff; padding: 25px; border-radius: 10px; margin: 20px auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    #table { margin-top: 20px; }
    .table { background: #fff; border-radius: 10px; overflow: hidden; }
    
    /* Navbar tweaks */
    .navbar { margin-bottom: 20px; }
    .fa-solid, .fa-brands, .fa-regular { margin-left: 5px; }
</style>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>

</head>
<body>
<!-- Navbar -->
<?php include 'include/navbar.php'; ?>
<div class="container"> </div>

<div class="container">
    <?=$txt ?>
    <h3 style="text-align: center; border-bottom: 2px solid #000; padding: 10px">المشرفين</h3>
    <form class="form-inline myform" role="form" method="POST">
        <div class="form-group">
            <input type="text" class="form-control" placeholder="الاسم" name="name" required>
        </div>
        <div class="form-group">
            <input type="text" class="form-control" placeholder="اسم المستخدم" name="username" required>
        </div>
        <div class="form-group">
            <input type="text" class="form-control"  placeholder="كلمة المرور" name="password" required>
        </div>
        <div class="form-group">
            <select name="role" class="form-control">
                <optgroup label="صلاحيات لوحة التحكم (القديمة)">
                    <option value="viewer">مشرف مطالعة (عرض فقط)</option>
                    <option value="approver">مشرف قبول (قبول/رفض)</option>
                    <option value="whatsapp">مشرف واتساب (إرسال رسائل)</option>
                    <option value="admin">مدير عام (كامل الصلاحيات)</option>
                </optgroup>
                <optgroup label="صلاحيات البوابات والكاميرات (الجديدة)">
                    <option value="gate">🛡️ مشرف بوابة (ماسح QR)</option>
                    <option value="rounds">� ماسح الجولات (جديد)</option>
                    <option value="notes">� ماسح الملاحظات (جديد)</option>
                </optgroup>
            </select>
        </div>
        <button type="submit" class="btn btn-default" name="add">اضافة مشرف</button>
    </form>
   
    <div class="" id="table">
        <table class="table" style="text-align: center">
            <thead>
                <tr>
                    <th style="text-align: center">الاسم</th>
                    <th style="text-align: center">اسم المستخدم</th>
                    <th style="text-align: center">كلمة المرور</th>
                    <th style="text-align: center">الدور</th>
                    <th style="text-align: center">حذف</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (count($admins) > 0) {
                    $i = 0 ; 
                    foreach ($admins as $admin) {
                        $roleName = $admin->role ?? 'viewer';
                        // Fix legacy users who might not have role set
                        if ($admin->username == 'root') $roleName = 'admin';
                        if ($admin->username == 'scanner') $roleName = 'gate'; // Legacy scanner is gate
                        
                        // Translate role for display
                        $roleDisplay = $roleName;
                        if ($roleName == 'viewer') $roleDisplay = 'مطالعة';
                        if ($roleName == 'approver') $roleDisplay = 'قبول';
                        if ($roleName == 'whatsapp') $roleDisplay = 'واتساب';
                        if ($roleName == 'gate') $roleDisplay = 'بوابة (QR)';
                        if ($roleName == 'rounds') $roleDisplay = 'جولات';
                        if ($roleName == 'notes') $roleDisplay = 'ملاحظات';
                        if ($roleName == 'admin') $roleDisplay = 'مدير عام';
                        ?>
                            <tr>
                                <td><?=$admin->name ?></td>
                                <td><?=$admin->username ?></td>
                                <td><?=$admin->password ?></td>
                                <td><span class="label label-info"><?=$roleDisplay ?></span></td>
                                <td>
                                    <?php if($admin->username !== 'root'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="index" value="<?=$i ?>">
                                        <button name="delete" class="btn btn-danger" onclick="return confirm('هل انت متأكد؟')">حذف</button>
                                    </form>  
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php
                        $i++;
                    }
                }
                ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>