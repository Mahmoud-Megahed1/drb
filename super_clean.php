<?php
/**
 * FULL SYSTEM RESET - FACTORY CLEANUP
 * WARNING: This completely wipes all registrations, members, and uploaded files.
 * It is used to prepare the system for the final client after testing.
 */
require_once __DIR__ . '/include/db.php';
$pdo = db();

echo "<pre><h3>🚀 بدء تصفير النظام (Factory Reset)...</h3>\n";

// 1. Clear Uploads Folder
function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object))
                    rrmdir($dir . "/" . $object);
                else
                    unlink($dir . "/" . $object);
            }
        }
        if ($dir !== 'uploads' && $dir !== 'admin/data/archives') {
            @rmdir($dir);
        }
    }
}

$uploadsDir = __DIR__ . '/uploads';
echo "🗑️ جاري حذف جميع الصور ومجلدات الرفع...\n";
if (file_exists($uploadsDir)) {
    $subdirs = glob($uploadsDir . '/*' , GLOB_ONLYDIR);
    foreach($subdirs as $dir) {
        rrmdir($dir);
    }
    echo "✅ تم مسح جميع الصور المرفوعة.\n";
}

// 2. Truncate SQLite Tables (Keep Users, Settings, and Championships)
echo "\n🗑️ جاري تفريغ قاعدة البيانات (سجلات المشتركين، التحذيرات، الملاحظات)...\n";
$tablesToEmpty = [
    'registrations', 
    'participants', 
    'notes', 
    'warnings', 
    'round_logs', 
    'audit_logs', 
    'rate_limits',
    'members' // Delete members last because of Foreign Keys
];

try {
    // Disable FK checks temporarily to avoid constraint errors during wipe
    $pdo->exec("PRAGMA foreign_keys = OFF");
    foreach ($tablesToEmpty as $table) {
        $pdo->exec("DELETE FROM $table");
        // Reset Auto-increment
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name='$table'");
        echo " - مسح بيانات الجدول: $table\n";
    }
    $pdo->exec("PRAGMA foreign_keys = ON");
    echo "✅ تم تصفير قاعدة البيانات بنجاح.\n";
} catch (Exception $e) {
    echo "❌ خطأ في قاعدة البيانات: " . $e->getMessage() . "\n";
}

// 3. Clear all JSON Data and Logs
echo "\n🗑️ جاري تصفير ملفات البيانات (JSON)...\n";
$jsonFilesToEmptyArray = [
    'data.json',
    'data_bck.json',
    'members.json',
    'members_bck.json',
    'round_logs.json',
    'entry_logs.json',
    'entry_exit_logs.json',
    'admin_actions.json',
    'registration_actions.json',
    'message_logs.json',
    'whatsapp_log.json',
    'whatsapp_messages.json',
    'whatsapp_failed_queue.json'
];

$dataDir = __DIR__ . '/admin/data/';
foreach ($jsonFilesToEmptyArray as $filename) {
    if (file_exists($dataDir . $filename)) {
        file_put_contents($dataDir . $filename, ($filename === 'members.json') ? '{}' : '[]');
        echo " - تم تصفير: $filename\n";
    }
}

// Reset Counters
if (file_exists($dataDir . 'wasel_counter.json')) {
    file_put_contents($dataDir . 'wasel_counter.json', '{"next_wasel": 1}');
    echo " - تم تصفير العداد.\n";
}

// 4. Clear Archives
$archivesDir = $dataDir . 'archives';
if (is_dir($archivesDir)) {
    echo "\n🗑️ جاري مسح الأرشيف القديم...\n";
    $archives = glob($archivesDir . '/*.json');
    foreach ($archives as $arch) {
        @unlink($arch);
    }
    echo "✅ تم مسح الأرشيف.\n";
}

echo "\n🎉 <b>تم تنظيف النظام بالكامل وهو الآن جاهز للعميل (صفر بيانات).</b>\n";
echo "سيتم إعادة توجيهك وحذف سكريبت التنظيف هذا للأمان خلال 5 ثواني...\n";

// Self Destruct
@unlink(__FILE__);
?>
<script>setTimeout(function(){ window.location.href='admin/dashboard.php'; }, 5000);</script>
