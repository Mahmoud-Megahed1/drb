<?php
/**
 * Force Update Frame Settings for All Registrations
 * يحدث إعدادات الفريم لكل التسجيلات بالإعدادات الحالية
 */

header('Content-Type: text/html; charset=utf-8');

$dataFile = __DIR__ . '/admin/data/data.json';
$frameSettingsFile = __DIR__ . '/admin/data/frame_settings.json';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if (!file_exists($dataFile) || !file_exists($frameSettingsFile)) {
        echo '<p style="color:red;">❌ ملفات البيانات غير موجودة!</p>';
        exit;
    }
    
    $data = json_decode(file_get_contents($dataFile), true);
    $frameSettings = json_decode(file_get_contents($frameSettingsFile), true);
    
    if ($action === 'update_all') {
        // Update ALL registrations
        $updated = 0;
        foreach ($data as $index => $reg) {
            if (($reg['status'] ?? '') === 'approved') {
                $data[$index]['saved_frame_settings'] = $frameSettings;
                $updated++;
            }
        }
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "<p style='color:green;'>✅ تم تحديث $updated تسجيل بالإعدادات الجديدة!</p>";
    }
    
    if ($action === 'update_one') {
        // Update specific registration
        $wasel = $_POST['wasel'] ?? '';
        foreach ($data as $index => $reg) {
            if ($reg['wasel'] == $wasel) {
                $data[$index]['saved_frame_settings'] = $frameSettings;
                file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo "<p style='color:green;'>✅ تم تحديث التسجيل #$wasel بالإعدادات الجديدة!</p>";
                break;
            }
        }
    }
    
    if ($action === 'clear_all') {
        // Clear saved settings (will use defaults)
        $cleared = 0;
        foreach ($data as $index => $reg) {
            if (isset($data[$index]['saved_frame_settings'])) {
                unset($data[$index]['saved_frame_settings']);
                $cleared++;
            }
        }
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "<p style='color:green;'>✅ تم مسح الإعدادات المحفوظة من $cleared تسجيل!</p>";
    }
}

// Load data for display
$data = [];
if (file_exists($dataFile)) {
    $data = json_decode(file_get_contents($dataFile), true) ?? [];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحديث إعدادات الفريم</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: #1a1a2e;
            min-height: 100vh;
            padding: 20px;
            color: #fff;
        }
        h1 { color: #ffc107; margin-bottom: 20px; }
        .card {
            background: rgba(255,255,255,0.05);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .card h3 { color: #28a745; margin-bottom: 15px; }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            margin: 5px;
        }
        .btn-success { background: #28a745; color: #fff; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-danger { background: #dc3545; color: #fff; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 10px; border: 1px solid #333; text-align: right; }
        th { background: rgba(255,193,7,0.2); }
        .has-settings { color: #28a745; }
        .no-settings { color: #dc3545; }
        input[type="text"] {
            padding: 8px;
            border: 1px solid #333;
            background: #2a2a4a;
            color: #fff;
            border-radius: 5px;
            width: 100px;
        }
    </style>
</head>
<body>
    <h1>🔧 تحديث إعدادات الفريم للتسجيلات</h1>
    
    <div class="card">
        <h3>⚡ تحديث جماعي</h3>
        <p>هذا سيطبق الإعدادات الحالية على كل التسجيلات المقبولة:</p>
        <form method="POST" style="margin-top: 15px;">
            <input type="hidden" name="action" value="update_all">
            <button type="submit" class="btn btn-success" onclick="return confirm('هل أنت متأكد؟ سيتم تحديث كل التسجيلات!')">✅ تحديث الكل بالإعدادات الحالية</button>
        </form>
        <form method="POST" style="margin-top: 10px;">
            <input type="hidden" name="action" value="clear_all">
            <button type="submit" class="btn btn-danger" onclick="return confirm('هل أنت متأكد؟ سيتم مسح كل الإعدادات المحفوظة!')">🗑️ مسح كل الإعدادات المحفوظة</button>
        </form>
    </div>
    
    <div class="card">
        <h3>📋 التسجيلات المقبولة</h3>
        <table>
            <tr>
                <th>#</th>
                <th>الاسم</th>
                <th>الحالة</th>
                <th>إعدادات محفوظة</th>
                <th>تحديث</th>
            </tr>
            <?php foreach ($data as $reg): ?>
            <?php if (($reg['status'] ?? '') === 'approved'): ?>
            <tr>
                <td><?= htmlspecialchars($reg['wasel'] ?? '') ?></td>
                <td><?= htmlspecialchars($reg['full_name'] ?? '') ?></td>
                <td>✅ مقبول</td>
                <td class="<?= !empty($reg['saved_frame_settings']) ? 'has-settings' : 'no-settings' ?>">
                    <?= !empty($reg['saved_frame_settings']) ? '✅ نعم' : '❌ لا' ?>
                </td>
                <td>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="update_one">
                        <input type="hidden" name="wasel" value="<?= htmlspecialchars($reg['wasel'] ?? '') ?>">
                        <button type="submit" class="btn btn-warning" style="padding: 5px 10px;">🔄 تحديث</button>
                    </form>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
        </table>
    </div>
    
    <div class="card">
        <h3>⚠️ تنبيه</h3>
        <ul>
            <li>• <strong>تحديث الكل:</strong> يطبق الإعدادات الحالية على كل التسجيلات</li>
            <li>• <strong>تحديث واحد:</strong> يطبق الإعدادات الحالية على تسجيل محدد</li>
            <li>• <strong>مسح الكل:</strong> يزيل الإعدادات المحفوظة (سيستخدمون الإعدادات الافتراضية)</li>
        </ul>
    </div>
    
    <p style="margin-top: 20px;">
        <a href="admin/frame_settings.php" style="color: #ffc107;">← رجوع لإعدادات الفريم</a>
    </p>
</body>
</html>

