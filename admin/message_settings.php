<?php
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

$messagesFile = 'data/whatsapp_messages.json';

// Default messages - Updated with latest features
$defaultMessages = [
    'registration_message' => "(معطلة) تم إيقاف رسالة التسجيل الترحيبية لمنع تكرار الرسائل",
    'acceptance_message' => "🎉 *مبروك! تم قبول طلبك!*\n━━━━━━━━━━━━━━━\n\n👤 *الاسم:* {name}\n🔢 *رقم التسجيل:* #{wasel}\n🚗 *السيارة:* {car_type}\n\n✅ تم اعتماد مشاركتك بنجاح\n━━━━━━━━━━━━━━━",
    'rejection_message' => "😔 *نأسف، تم رفض طلبك*\n━━━━━━━━━━━━━━━\n\n👤 *الاسم:* {name}\n🚗 *السيارة:* {car_type}\n\n❌ *السبب:* {reason}\n\n📞 للاستفسار تواصل معنا\n━━━━━━━━━━━━━━━",
    'badge_caption' => "🎫 باج دخول الحلبة\n\n📱 امسح QR عند الدخول\n\n🔑 كود التسجيل: {registration_code}",
    'activation_message' => "🏎️ *تفعيل حسابك في نادي بلاد الرافدين*\n━━━━━━━━━━━━━━━\n\n✅ *تم تفعيل حسابك بنجاح!*\n\n👤 *الاسم:* {name}\n🔢 *الكود الدائم:* {permanent_code}\n\n📌 _يمكنك استخدام هذا الكود للتسجيل السريع في جميع البطولات القادمة_\n\n🏆 نراك في الحلبة!\n━━━━━━━━━━━━━━━"
];

// Load existing messages
$messages = $defaultMessages;
if (file_exists($messagesFile)) {
    $savedMessages = json_decode(file_get_contents($messagesFile), true);
    if ($savedMessages) {
        $messages = array_merge($defaultMessages, $savedMessages);
    }
}

// Handle form submission
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $messages = [
        'registration_message' => $_POST['registration_message'] ?? '',
        'acceptance_message' => $_POST['acceptance_message'] ?? '',
        'rejection_message' => $_POST['rejection_message'] ?? '',
        'badge_caption' => $_POST['badge_caption'] ?? '',
        'activation_message' => $_POST['activation_message'] ?? ''
    ];
    
    if (!file_exists('data')) {
        mkdir('data', 0777, true);
    }
    
    if (file_put_contents($messagesFile, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        $saved = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات الرسائل - WhatsApp</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Cairo', sans-serif; 
            background: #1a1a2e; 
            color: #fff;
            min-height: 100vh;
            padding-top: 70px;
        }
        
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h1 { color: #25D366; font-size: 24px; margin: 0; }
        
        .btn-custom {
            padding: 10px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-save { background: #25D366; color: #fff; }
        .btn-reset { background: #dc3545; color: #fff; }
        .btn-custom:hover { opacity: 0.9; transform: translateY(-2px); }
        
        .alert-box {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-box.success { background: rgba(37, 211, 102, 0.2); border: 1px solid #25D366; color: #25D366; }
        
        .card {
            background: rgba(255,255,255,0.05);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .card h3 {
            color: #ffc107;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .card p {
            color: #999;
            font-size: 12px;
            margin-bottom: 10px;
        }
        
        textarea {
            width: 100%;
            min-height: 150px;
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            padding: 15px;
            color: #fff;
            font-family: inherit;
            font-size: 13px;
            resize: vertical;
            direction: ltr;
            text-align: left;
        }
        
        textarea:focus {
            outline: none;
            border-color: #25D366;
        }
        
        .variables {
            background: rgba(255,193,7,0.1);
            border: 1px solid rgba(255,193,7,0.3);
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            font-size: 11px;
        }
        
        .variables code {
            background: #ffc107;
            color: #000;
            padding: 2px 6px;
            border-radius: 4px;
            margin: 2px;
            display: inline-block;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
    </style>
</head>
<body>

<!-- Navbar -->
<?php include '../include/navbar.php'; ?>
<div class="container">
    <div class="page-header">
        <h1><i class="fa-brands fa-whatsapp"></i> إعدادات رسائل WhatsApp</h1>
    </div>
    
    <?php if ($saved): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-check-circle"></i> تم حفظ الرسائل بنجاح!
    </div>
    <?php endif; ?>
    
    <form method="post">
        <!-- Info Banner -->
        <div class="card" style="background: rgba(37,211,102,0.1); border: 1px solid #25D366;">
            <h3 style="color: #25D366;"><i class="fa-solid fa-info-circle"></i> ملاحظة مهمة</h3>
            <p style="color: #ccc; font-size: 13px;">
                • الباج يُرسل كـ <strong>صورة</strong> مع الصورة الشخصية وكل المعلومات<br>
                • كود التسجيل يُضاف تلقائياً في رسالة الترحيب<br>
                • استخدم المتغيرات بين {} لإدراج البيانات الديناميكية
            </p>
        </div>
        
        <!-- Registration Message -->
        <div class="card">
            <h3><i class="fa-solid fa-file-contract"></i> رسالة التسجيل (معطلة)</h3>
            <p>تم إيقافها لتفادي تكرار الرسائل. لن يتم إرسالها من التدفق الحالي.</p>
            <textarea name="registration_message" rows="8"><?= htmlspecialchars($messages['registration_message']) ?></textarea>
            <div class="variables">
                <strong>المتغيرات المتاحة:</strong>
                <code>{wasel}</code> رقم التسجيل
                <code>{name}</code> الاسم
                <code>{car_type}</code> نوع السيارة
                <code>{registration_code}</code> كود التسجيل السريع
            </div>
        </div>
        
        <!-- Acceptance Message -->
        <div class="card">
            <h3><i class="fa-solid fa-check-double"></i> رسالة القبول (مع صورة القبول)</h3>
            <p>تُرسل كوصف للصورة عند الموافقة على الطلب</p>
            <textarea name="acceptance_message" rows="6"><?= htmlspecialchars($messages['acceptance_message']) ?></textarea>
            <div class="variables">
                <strong>المتغيرات المتاحة:</strong>
                <code>{wasel}</code> <code>{name}</code> <code>{car_type}</code> <code>{plate}</code>
            </div>
        </div>
        
        <!-- Badge Caption -->
        <div class="card">
            <h3><i class="fa-solid fa-id-card"></i> وصف صورة الباج</h3>
            <p>تُرسل كوصف لصورة الباج (الباج صورة تحتوي كل المعلومات)</p>
            <textarea name="badge_caption" rows="4"><?= htmlspecialchars($messages['badge_caption'] ?? '') ?></textarea>
            <div class="variables">
                <strong>المتغيرات المتاحة:</strong>
                <code>{wasel}</code> <code>{name}</code> <code>{registration_code}</code>
            </div>
        </div>
        
        <!-- Rejection Message -->
        <div class="card">
            <h3><i class="fa-solid fa-times-circle"></i> رسالة الرفض</h3>
            <p>تُرسل عند رفض الطلب</p>
            <textarea name="rejection_message" rows="6"><?= htmlspecialchars($messages['rejection_message']) ?></textarea>
            <div class="variables">
                <strong>المتغيرات المتاحة:</strong>
                <code>{wasel}</code> <code>{name}</code> <code>{car_type}</code> <code>{reason}</code>
            </div>
        </div>
        
        <!-- Activation Message -->
        <div class="card" style="border: 1px solid #17a2b8;">
            <h3 style="color: #17a2b8;"><i class="fa-solid fa-user-check"></i> رسالة التفعيل (للأعضاء المستوردين)</h3>
            <p>تُرسل عند تفعيل حساب عضو تم استيراده من ملف CSV</p>
            <textarea name="activation_message" rows="6"><?= htmlspecialchars($messages['activation_message'] ?? '') ?></textarea>
            <div class="variables">
                <strong>المتغيرات المتاحة:</strong>
                <code>{name}</code> <code>{permanent_code}</code> <code>{phone}</code>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-save"><i class="fa-solid fa-save"></i> حفظ التغييرات</button>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
</body>
</html>
