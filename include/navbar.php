<?php
// include/navbar.php
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
    $isRoot = (isset($currentUser->username) && $currentUser->username === 'root');
}

$userRole = $currentUser->role ?? ($isRoot ? 'root' : 'viewer');
if ($isRoot) $userRole = 'root';

$canApprove = in_array($userRole, ['root', 'approver']);
$canManageSettings = ($userRole === 'root');
$canSendWhatsapp = in_array($userRole, ['root', 'whatsapp']);

// Determine if we're in admin folder
$inAdminFolder = (strpos(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/admin/') !== false);
$rootPrefix = $inAdminFolder ? '../' : '';
$adminPrefix = $inAdminFolder ? '' : 'admin/';
?>
<!-- Navbar -->
<nav class="navbar navbar-default navbar-fixed-top" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border: none; box-shadow: 0 2px 15px rgba(0,0,0,0.3);">
    <div class="container-fluid">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#main-nav" style="border-color: rgba(255,255,255,0.3);">
                <span class="sr-only">Toggle</span>
                <span class="icon-bar" style="background: #fff;"></span>
                <span class="icon-bar" style="background: #fff;"></span>
                <span class="icon-bar" style="background: #fff;"></span>
            </button>
            <a class="navbar-brand" href="<?= $rootPrefix ?>dashboard.php" style="color: #ffc107 !important; font-weight: bold;"><i class="fa-solid fa-flag-checkered"></i> نادي بلاد الرافدين</a>
        </div>
        <div class="collapse navbar-collapse" id="main-nav">
            <ul class="nav navbar-nav">
                <li class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <a href="<?= $rootPrefix ?>dashboard.php" style="<?= $currentPage === 'dashboard' ? 'color: #ffc107 !important; background: rgba(255,193,7,0.2) !important; border-bottom: 2px solid #ffc107;' : 'color: rgba(255,255,255,0.8);' ?>">
                        <i class="fa-solid fa-gauge-high"></i> لوحة التحكم
                    </a>
                </li>
                
                <?php if ($canManageSettings): ?>
                <li class="dropdown <?= in_array($currentPage, ['registration_settings', 'form_fields_settings', 'message_settings', 'frame_settings', 'rules_settings', 'badge_settings', 'import_members', 'export_members', 'admins']) ? 'active' : '' ?>">
                    <a href="#" class="dropdown-toggle" data-toggle="dropdown" style="<?= in_array($currentPage, ['registration_settings', 'form_fields_settings', 'message_settings', 'frame_settings', 'rules_settings', 'badge_settings', 'import_members', 'export_members', 'admins']) ? 'color: #ffc107 !important; background: rgba(255,193,7,0.2) !important; border-bottom: 2px solid #ffc107;' : 'color: rgba(255,255,255,0.8);' ?>">
                        <i class="fa-solid fa-gear"></i> الإعدادات <span class="caret"></span>
                    </a>
                    <ul class="dropdown-menu" style="background: #16213e; border: 1px solid rgba(255,255,255,0.1);">
                        <li><a href="<?= $adminPrefix ?>registration_settings.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-file-pen"></i> إعدادات التسجيل</a></li>
                        <li><a href="<?= $adminPrefix ?>form_fields_settings.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-list-check"></i> إعدادات حقول الاستمارة</a></li>
                        <li><a href="<?= $adminPrefix ?>message_settings.php" style="color: rgba(255,255,255,0.8);"><i class="fa-brands fa-whatsapp"></i> رسائل WhatsApp</a></li>
                        <li><a href="<?= $adminPrefix ?>frame_settings.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-image"></i> إعدادات الفريم</a></li>
                        <li><a href="<?= $adminPrefix ?>rules_settings.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-gavel"></i> القوانين</a></li>
                        <li><a href="<?= $adminPrefix ?>badge_settings.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-id-card"></i> إعدادات البادج</a></li>
                        <li class="divider"></li>
                        <li><a href="<?= $adminPrefix ?>import_members.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-file-import"></i> استيراد أعضاء</a></li>
                        <li><a href="<?= $adminPrefix ?>export_members.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-file-export"></i> تصدير أعضاء</a></li>
                        <li class="divider"></li>
                        <li><a href="<?= $rootPrefix ?>admins.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-user-shield"></i> إدارة المشرفين</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if ($isRoot): ?>
                <li class="dropdown <?= in_array($currentPage, ['admin_log', 'whatsapp_log', 'entry_logs', 'rounds_logs', 'view_notes']) ? 'active' : '' ?>">
                    <a href="#" class="dropdown-toggle" data-toggle="dropdown" style="<?= in_array($currentPage, ['admin_log', 'whatsapp_log', 'entry_logs', 'rounds_logs', 'view_notes']) ? 'color: #ffc107 !important; background: rgba(255,193,7,0.2) !important; border-bottom: 2px solid #ffc107;' : 'color: rgba(255,255,255,0.8);' ?>">
                        <i class="fa-solid fa-chart-pie"></i> سجلات وتقارير <span class="caret"></span>
                    </a>
                    <ul class="dropdown-menu" style="background: #16213e; border: 1px solid rgba(255,255,255,0.1);">
                        <li><a href="<?= $adminPrefix ?>admin_log.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-clipboard-list"></i> سجل العمليات</a></li>
                        <li><a href="<?= $adminPrefix ?>whatsapp_log.php" style="color: rgba(255,255,255,0.8);"><i class="fa-brands fa-whatsapp"></i> سجل الرسائل</a></li>
                        <li><a href="<?= $adminPrefix ?>entry_logs.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-door-open"></i> سجل الدخول</a></li>
                        <li><a href="<?= $adminPrefix ?>rounds_logs.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-flag-checkered"></i> سجل الجولات</a></li>
                        <li><a href="<?= $adminPrefix ?>view_notes.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-triangle-exclamation"></i> سجل الملاحظات</a></li>
                        <li class="divider"></li>
                        <li><a href="<?= $adminPrefix ?>pending_messages.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-envelope-circle-check"></i> الرسائل المعلقة</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                
                <?php if ($canSendWhatsapp || $canApprove || $isRoot): ?>
                <li class="dropdown <?= in_array($currentPage, ['whatsapp_broadcast', 'members', 'qr_scanner', 'rounds_settings', 'reset_championship']) ? 'active' : '' ?>">
                    <a href="#" class="dropdown-toggle" data-toggle="dropdown" style="<?= in_array($currentPage, ['whatsapp_broadcast', 'members', 'qr_scanner', 'rounds_settings', 'reset_championship']) ? 'color: #ffc107 !important; background: rgba(255,193,7,0.2) !important; border-bottom: 2px solid #ffc107;' : 'color: rgba(255,255,255,0.8);' ?>">
                        <i class="fa-solid fa-toolbox"></i> أدوات <span class="caret"></span>
                    </a>
                    <ul class="dropdown-menu" style="background: #16213e; border: 1px solid rgba(255,255,255,0.1);">
                        <?php if ($canSendWhatsapp): ?>
                        <li><a href="<?= $adminPrefix ?>whatsapp_broadcast.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-bullhorn"></i> إشعارات جماعية</a></li>
                        <?php endif; ?>
                        
                        <?php if ($canApprove): ?>
                        <li><a href="<?= $adminPrefix ?>members.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-users"></i> الأعضاء</a></li>
                        <?php endif; ?>
                        
                        <?php if ($isRoot): ?>
                        <li><a href="<?= $adminPrefix ?>qr_scanner.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-camera"></i> ماسح QR (Admin)</a></li>
                        <li><a href="<?= $rootPrefix ?>gate.php" target="_blank" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-door-open"></i> بوابة الدخول (مستقلة)</a></li>
                        <li class="divider"></li>
                        <li><a href="<?= $adminPrefix ?>rounds_settings.php" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-flag-checkered"></i> إعدادات الجولات</a></li>
                        <li><a href="<?= $rootPrefix ?>rounds_gate.php" target="_blank" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-stopwatch"></i> بوابة الجولات</a></li>
                        <li><a href="<?= $rootPrefix ?>notes_gate.php" target="_blank" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-clipboard-check"></i> كاميرا الملاحظات</a></li>
                        <li class="divider"></li>
                        <li><a href="<?= $adminPrefix ?>reset_championship.php" style="color: #dc3545;"><i class="fa-solid fa-rotate"></i> بطولة جديدة</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                
                <?php if ($isRoot): ?>
                <li class="<?= $currentPage === 'admins' ? 'active' : '' ?>">
                    <a href="<?= $rootPrefix ?>admins.php" style="<?= $currentPage === 'admins' ? 'color: #ffc107 !important; background: rgba(255,193,7,0.2) !important; border-bottom: 2px solid #ffc107;' : 'color: rgba(255,255,255,0.8);' ?>">
                        <i class="fa-solid fa-user-shield"></i> المشرفين
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($currentPage === 'dashboard'): ?>
                <li><a href="<?= $adminPrefix ?>export_members.php?download=1&source=dashboard&format=csv" style="color: #28a745 !important;"><i class="fa-solid fa-file-excel"></i> تصدير Excel</a></li>
                <?php endif; ?>
                
                <li><a href="<?= $rootPrefix ?>index.php" target="_blank" style="color: rgba(255,255,255,0.8);"><i class="fa-solid fa-arrow-up-right-from-square"></i> صفحة التسجيل</a></li>
            </ul>
            <ul class="nav navbar-nav navbar-left">
                <li><a style="color: #25D366;"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($currentUser->username ?? 'مستخدم') ?></a></li>
                <li><a href="<?= $rootPrefix ?>logout.php" style="color: #dc3545 !important;"><i class="fa-solid fa-right-from-bracket"></i> خروج</a></li>
            </ul>
        </div>
    </div>
</nav>
<div style="height: 55px;"></div>
<style>
    /* For pages that don't have font awesome correctly or conflict */
    .dropdown-menu > li > a:hover {
        background-color: #f8f9fa !important;
        color: #000 !important;
    }
</style>
