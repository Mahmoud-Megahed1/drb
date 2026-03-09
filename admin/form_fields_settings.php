<?php
/**
 * Form Fields Settings - إعدادات حقول الاستمارة
 * تفعيل/تعطيل الحقول الإضافية
 */
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

$currentUser = $_SESSION['user'];
$isRoot = (isset($currentUser->username) && $currentUser->username === 'root');

if (!$isRoot) {
    header('location:../dashboard.php');
    exit;
}

require_once 'form_fields_config.php';

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'personal_photo_enabled' => isset($_POST['personal_photo_enabled']),
        'personal_photo_required' => isset($_POST['personal_photo_required']),
        'instagram_enabled' => isset($_POST['instagram_enabled']),
        'instagram_required' => isset($_POST['instagram_required']),
        'license_images_enabled' => isset($_POST['license_images_enabled']),
        'license_images_required' => isset($_POST['license_images_required']),
        'id_front_enabled' => isset($_POST['id_front_enabled']),
        'id_front_required' => isset($_POST['id_front_required']),
        'id_back_enabled' => isset($_POST['id_back_enabled']),
        'id_back_required' => isset($_POST['id_back_required'])
    ];
    
    saveFormFieldsSettings($settings);
    $message = 'تم حفظ الإعدادات بنجاح';
    $messageType = 'success';
}

$settings = getFormFieldsSettings();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات حقول الاستمارة</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; padding-top: 70px; }
        .settings-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        .field-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        .field-row:last-child { border-bottom: none; }
        .field-info { flex: 1; }
        .field-info h5 { margin: 0 0 5px 0; font-weight: 600; }
        .field-info p { margin: 0; font-size: 12px; color: #888; }
        .field-toggles { display: flex; gap: 20px; }
        .toggle-group { display: flex; align-items: center; gap: 8px; }
        .toggle-group label { margin: 0; font-size: 13px; cursor: pointer; }
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 26px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #28a745;
        }
        input:focus + .slider {
            box-shadow: 0 0 1px #28a745;
        }
        input:checked + .slider:before {
            transform: translateX(24px);
        }
        
        /* Required toggle custom color */
        .switch.required-switch input:checked + .slider {
            background-color: #dc3545;
        }
        
        .navbar-brand { font-weight: bold; color: #ffc107 !important; }
    </style>
</head>
<body>

<?php include '../include/navbar.php'; ?>
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <h3 class="text-center" style="margin-bottom: 30px; font-weight: bold;">
                <i class="fa-solid fa-list-check"></i> إعدادات حقول الاستمارة
            </h3>
            
            <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <?= $message ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <!-- Personal Photo -->
                <div class="settings-card">
                    <div class="field-row">
                        <div class="field-info">
                            <h5><i class="fa-solid fa-user"></i> الصورة الشخصية</h5>
                            <p>صورة وجه السائق</p>
                        </div>
                        <div class="field-toggles">
                            <div class="toggle-group">
                                <label class="switch">
                                    <input type="checkbox" name="personal_photo_enabled" <?= $settings['personal_photo_enabled'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>تفعيل</label>
                            </div>
                            <div class="toggle-group">
                                <label class="switch required-switch">
                                    <input type="checkbox" name="personal_photo_required" <?= $settings['personal_photo_required'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>مطلوب</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Instagram -->
                <div class="settings-card">
                    <div class="field-row">
                        <div class="field-info">
                            <h5><i class="fa-brands fa-instagram"></i> حساب الانستقرام</h5>
                            <p>اسم المستخدم على انستقرام</p>
                        </div>
                        <div class="field-toggles">
                            <div class="toggle-group">
                                <label class="switch">
                                    <input type="checkbox" name="instagram_enabled" <?= $settings['instagram_enabled'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>تفعيل</label>
                            </div>
                            <div class="toggle-group">
                                <label class="switch required-switch">
                                    <input type="checkbox" name="instagram_required" <?= $settings['instagram_required'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>مطلوب</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- License Images -->
                <div class="settings-card">
                    <div class="field-row">
                        <div class="field-info">
                            <h5><i class="fa-solid fa-id-card"></i> صور إجازة السوق</h5>
                            <p>صورة الوجه والظهر لإجازة السوق</p>
                        </div>
                        <div class="field-toggles">
                            <div class="toggle-group">
                                <label class="switch">
                                    <input type="checkbox" name="license_images_enabled" <?= $settings['license_images_enabled'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>تفعيل</label>
                            </div>
                            <div class="toggle-group">
                                <label class="switch required-switch">
                                    <input type="checkbox" name="license_images_required" <?= $settings['license_images_required'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>مطلوب</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ID Card Images -->
                <div class="settings-card">
                    <div class="field-row">
                        <div class="field-info">
                            <h5><i class="fa-solid fa-address-card"></i> صورة الهوية (الوجه)</h5>
                            <p>الوجه الأمامي للبطاقة الوطنية/الهوية</p>
                        </div>
                        <div class="field-toggles">
                            <div class="toggle-group">
                                <label class="switch">
                                    <input type="checkbox" name="id_front_enabled" <?= $settings['id_front_enabled'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>تفعيل</label>
                            </div>
                            <div class="toggle-group">
                                <label class="switch required-switch">
                                    <input type="checkbox" name="id_front_required" <?= $settings['id_front_required'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>مطلوب</label>
                            </div>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field-info">
                            <h5><i class="fa-solid fa-address-card"></i> صورة الهوية (الظهر)</h5>
                            <p>الوجه الخلفي للبطاقة الوطنية/الهوية</p>
                        </div>
                        <div class="field-toggles">
                            <div class="toggle-group">
                                <label class="switch">
                                    <input type="checkbox" name="id_back_enabled" <?= $settings['id_back_enabled'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>تفعيل</label>
                            </div>
                            <div class="toggle-group">
                                <label class="switch required-switch">
                                    <input type="checkbox" name="id_back_required" <?= $settings['id_back_required'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <label>مطلوب</label>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fa-solid fa-save"></i> حفظ التغييرات
                </button>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>

</body>
</html>
