<?php
// include/navbar-custom.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine current page if not set
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
}

// Check if root and roles
$currentUser = $_SESSION['user'] ?? null;
if (!isset($isRoot)) {
    $isRoot = (isset($currentUser->username) && $currentUser->username === 'root') || (isset($currentUser->role) && $currentUser->role === 'root');
}

$userRole = $currentUser->role ?? ($isRoot ? 'root' : 'viewer');
if ($isRoot) $userRole = 'root';

$canApprove = in_array($userRole, ['root', 'admin', 'approver']);
$canManageSettings = in_array($userRole, ['root', 'admin']);
$canSendWhatsapp = in_array($userRole, ['root', 'admin', 'whatsapp']);

// Determine if we're in admin folder
$inAdminFolder = (strpos(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/admin/') !== false);
$rootPrefix = $inAdminFolder ? '../' : '';
$adminPrefix = $inAdminFolder ? '' : 'admin/';
?>
<style>
/* Custom Navbar Styles (No Bootstrap Required) */
.custom-navbar-wrapper {
    height: 60px; /* Spacer */
}
.custom-navbar {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    box-shadow: 0 2px 15px rgba(0,0,0,0.3);
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 9999;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 20px;
    height: 60px;
    font-family: 'Cairo', sans-serif;
    color: white;
}
.custom-navbar * {
    box-sizing: border-box;
}
.custom-navbar a {
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    transition: 0.3s;
}
.custom-navbar .brand {
    font-size: 18px;
    font-weight: bold;
    color: #ffc107;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}
.c-nav-menu {
    display: flex;
    gap: 5px;
    align-items: center;
    list-style: none;
    margin: 0;
    padding: 0;
    height: 100%;
}
.c-nav-item {
    position: relative;
    height: 100%;
    display: flex;
    align-items: center;
}
.c-nav-link {
    padding: 8px 15px;
    border-radius: 5px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    cursor: pointer;
}
.c-nav-link:hover, .c-nav-item:hover > .c-nav-link {
    background: rgba(255,255,255,0.1);
    color: #ffc107;
}
.c-nav-link.active {
    color: #ffc107;
    background: rgba(255,193,7,0.2);
    border-bottom: 2px solid #ffc107;
    border-radius: 5px 5px 0 0;
}
.c-dropdown-menu {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    background: #16213e;
    min-width: 220px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.5);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 5px;
    list-style: none;
    padding: 10px 0;
    margin: 0;
}
.c-nav-item:hover .c-dropdown-menu, .c-nav-item.focus-within .c-dropdown-menu {
    display: block;
}
.c-dropdown-item {
    padding: 8px 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: rgba(255,255,255,0.8);
}
.c-dropdown-item:hover {
    background: rgba(255,255,255,0.1);
    color: #ffc107;
}
.c-divider {
    height: 1px;
    background: rgba(255,255,255,0.1);
    margin: 8px 0;
}
.c-nav-right {
    display: flex;
    align-items: center;
    gap: 15px;
    list-style: none;
    margin: 0;
    padding: 0;
}
.c-menu-toggle {
    display: none;
    background: transparent;
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    font-size: 20px;
    padding: 4px 10px;
    border-radius: 5px;
    cursor: pointer;
}
@media (max-width: 900px) {
    .c-menu-toggle { display: block; }
    .c-nav-menu, .c-nav-right {
        display: none;
        flex-direction: column;
        position: absolute;
        top: 60px;
        left: 0;
        right: 0;
        background: #16213e;
        border-top: 1px solid rgba(255,255,255,0.1);
        box-shadow: 0 10px 20px rgba(0,0,0,0.5);
        padding: 10px 0;
        height: auto;
        align-items: stretch;
    }
    .custom-navbar.open .c-nav-menu, .custom-navbar.open .c-nav-right {
        display: flex;
    }
    .c-nav-right {
        top: auto;
        bottom: auto;
        position: relative;
        box-shadow: none;
        border-top: 1px solid rgba(255,255,255,0.1);
        padding: 10px 20px;
    }
    .c-nav-item {
        flex-direction: column;
        align-items: stretch;
    }
    .c-dropdown-menu {
        position: static;
        box-shadow: none;
        border: none;
        border-left: 2px solid rgba(255,255,255,0.1);
        margin: 5px 20px;
        padding: 5px 0;
        display: none;
    }
    /* Simple click reveal for mobile */
    .c-nav-link.toggled + .c-dropdown-menu {
        display: block;
    }
}
</style>

<div class="custom-navbar-wrapper"></div>
<nav class="custom-navbar" id="customNavbar">
    <a href="<?= $rootPrefix ?>dashboard.php" class="brand">
        <i class="fa-solid fa-flag-checkered"></i> نادي بلاد الرافدين
    </a>
    
    <button class="c-menu-toggle" onclick="document.getElementById('customNavbar').classList.toggle('open')">
        <i class="fa-solid fa-bars"></i>
    </button>
    
    <ul class="c-nav-menu">
        <li class="c-nav-item">
            <a href="<?= $rootPrefix ?>dashboard.php" class="c-nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <i class="fa-solid fa-gauge-high"></i> لوحة التحكم
            </a>
        </li>
        
        <?php if ($canManageSettings): ?>
        <li class="c-nav-item">
            <div class="c-nav-link <?= in_array($currentPage, ['registration_settings', 'form_fields_settings', 'message_settings', 'frame_settings', 'rules_settings', 'badge_settings', 'import_members', 'export_members', 'admins']) ? 'active' : '' ?>" onclick="this.classList.toggle('toggled')">
                <i class="fa-solid fa-gear"></i> الإعدادات <i class="fa-solid fa-caret-down" style="font-size: 10px;"></i>
            </div>
            <ul class="c-dropdown-menu">
                <li><a href="<?= $adminPrefix ?>registration_settings.php" class="c-dropdown-item"><i class="fa-solid fa-file-pen"></i> إعدادات التسجيل</a></li>
                <li><a href="<?= $adminPrefix ?>form_fields_settings.php" class="c-dropdown-item"><i class="fa-solid fa-list-check"></i> إعدادات حقول الاستمارة</a></li>
                <li><a href="<?= $adminPrefix ?>message_settings.php" class="c-dropdown-item"><i class="fa-brands fa-whatsapp"></i> رسائل WhatsApp</a></li>
                <li><a href="<?= $adminPrefix ?>frame_settings.php" class="c-dropdown-item"><i class="fa-solid fa-image"></i> إعدادات الفريم</a></li>
                <li><a href="<?= $adminPrefix ?>rules_settings.php" class="c-dropdown-item"><i class="fa-solid fa-gavel"></i> القوانين</a></li>
                <li><a href="<?= $adminPrefix ?>badge_settings.php" class="c-dropdown-item"><i class="fa-solid fa-id-card"></i> إعدادات البادج</a></li>
                <li class="c-divider"></li>
                <li><a href="<?= $adminPrefix ?>import_members.php" class="c-dropdown-item"><i class="fa-solid fa-file-import"></i> استيراد أعضاء</a></li>
                <li><a href="<?= $adminPrefix ?>export_members.php" class="c-dropdown-item"><i class="fa-solid fa-file-export"></i> تصدير أعضاء</a></li>
                <li class="c-divider"></li>
                <li><a href="<?= $rootPrefix ?>admins.php" class="c-dropdown-item"><i class="fa-solid fa-user-shield"></i> إدارة المشرفين</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($isRoot || $userRole === 'admin'): ?>
        <li class="c-nav-item">
            <div class="c-nav-link <?= in_array($currentPage, ['admin_log', 'whatsapp_log', 'entry_logs', 'rounds_logs', 'view_notes']) ? 'active' : '' ?>" onclick="this.classList.toggle('toggled')">
                <i class="fa-solid fa-chart-pie"></i> سجلات وتقارير <i class="fa-solid fa-caret-down" style="font-size: 10px;"></i>
            </div>
            <ul class="c-dropdown-menu">
                <li><a href="<?= $adminPrefix ?>admin_log.php" class="c-dropdown-item"><i class="fa-solid fa-clipboard-list"></i> سجل العمليات</a></li>
                <li><a href="<?= $adminPrefix ?>whatsapp_log.php" class="c-dropdown-item"><i class="fa-brands fa-whatsapp"></i> سجل الرسائل</a></li>
                <li><a href="<?= $adminPrefix ?>entry_logs.php" class="c-dropdown-item"><i class="fa-solid fa-door-open"></i> سجل الدخول</a></li>
                <li><a href="<?= $adminPrefix ?>rounds_logs.php" class="c-dropdown-item"><i class="fa-solid fa-flag-checkered"></i> سجل الجولات</a></li>
                <li><a href="<?= $adminPrefix ?>view_notes.php" class="c-dropdown-item"><i class="fa-solid fa-triangle-exclamation"></i> سجل الملاحظات</a></li>
            </ul>
        </li>
        <?php endif; ?>
        
        <?php if ($canSendWhatsapp || $canApprove || $isRoot): ?>
        <li class="c-nav-item">
            <div class="c-nav-link <?= in_array($currentPage, ['whatsapp_broadcast', 'members', 'qr_scanner', 'rounds_settings', 'reset_championship']) ? 'active' : '' ?>" onclick="this.classList.toggle('toggled')">
                <i class="fa-solid fa-toolbox"></i> أدوات <i class="fa-solid fa-caret-down" style="font-size: 10px;"></i>
            </div>
            <ul class="c-dropdown-menu">
                <?php if ($canSendWhatsapp): ?>
                <li><a href="<?= $adminPrefix ?>whatsapp_broadcast.php" class="c-dropdown-item"><i class="fa-solid fa-bullhorn"></i> إشعارات جماعية</a></li>
                <?php endif; ?>
                
                <?php if ($canApprove): ?>
                <li><a href="<?= $adminPrefix ?>members.php" class="c-dropdown-item"><i class="fa-solid fa-users"></i> الأعضاء</a></li>
                <?php endif; ?>
                
                <?php if ($isRoot || $userRole === 'admin'): ?>
                <li><a href="<?= $adminPrefix ?>qr_scanner.php" class="c-dropdown-item"><i class="fa-solid fa-camera"></i> ماسح QR (Admin)</a></li>
                <li><a href="<?= $rootPrefix ?>gate.php" target="_blank" class="c-dropdown-item"><i class="fa-solid fa-door-open"></i> بوابة الدخول (مستقلة)</a></li>
                <li class="c-divider"></li>
                <li><a href="<?= $adminPrefix ?>rounds_settings.php" class="c-dropdown-item"><i class="fa-solid fa-flag-checkered"></i> إعدادات الجولات</a></li>
                <li><a href="<?= $rootPrefix ?>rounds_gate.php" target="_blank" class="c-dropdown-item"><i class="fa-solid fa-stopwatch"></i> بوابة الجولات</a></li>
                <li><a href="<?= $rootPrefix ?>notes_gate.php" target="_blank" class="c-dropdown-item"><i class="fa-solid fa-clipboard-check"></i> كاميرا الملاحظات</a></li>
                <li class="c-divider"></li>
                <li><a href="<?= $adminPrefix ?>reset_championship.php" class="c-dropdown-item" style="color: #dc3545;"><i class="fa-solid fa-rotate"></i> بطولة جديدة</a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>
        
        <?php if ($isRoot || $userRole === 'admin'): ?>
        <li class="c-nav-item">
            <a href="<?= $rootPrefix ?>admins.php" class="c-nav-link <?= $currentPage === 'admins' ? 'active' : '' ?>">
                <i class="fa-solid fa-user-shield"></i> المشرفين
            </a>
        </li>
        <?php endif; ?>
        
        <?php if ($currentPage === 'dashboard'): ?>
        <li class="c-nav-item"><a href="#" onclick="exportExcel()" class="c-nav-link" style="color: #28a745 !important;"><i class="fa-solid fa-file-excel"></i> تصدير Excel</a></li>
        <?php endif; ?>
        
        <li class="c-nav-item"><a href="<?= $rootPrefix ?>index.php" target="_blank" class="c-nav-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> صفحة التسجيل</a></li>
    </ul>
    
    <ul class="c-nav-right">
        <li><a style="color: #25D366; text-decoration: none; font-size: 14px;"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($currentUser->username ?? 'مستخدم') ?></a></li>
        <li><a href="<?= $rootPrefix ?>logout.php" style="color: #dc3545 !important; text-decoration: none; font-size: 14px; font-weight: bold;"><i class="fa-solid fa-right-from-bracket"></i> خروج</a></li>
    </ul>
</nav>

<script>
// Click outside to close menus on mobile
document.addEventListener('click', function(e) {
    if (!document.getElementById('customNavbar').contains(e.target)) {
        document.getElementById('customNavbar').classList.remove('open');
        document.querySelectorAll('.c-nav-link.toggled').forEach(el => el.classList.remove('toggled'));
    }
});
</script>
