<?php
/**
 * Profile Page - صفحة البروفايل
 * عرض بروفايل العضو الكامل مع الإحصائيات والملاحظات والإنذارات
 */

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/helpers.php';
require_once __DIR__ . '/services/MemberService.php';

// Get code from URL
$code = $_GET['code'] ?? $_GET['token'] ?? '';

if (empty($code)) {
    http_response_code(400);
    showError('رابط غير صحيح', 'يرجى استخدام الرابط الصحيح للوصول للبروفايل');
    exit;
}

// Check if staff is viewing
session_start();
$isStaff = false;
if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
    $isStaff = true;
} elseif (isset($_SESSION['gate_user']) || isset($_SESSION['rounds_user']) || isset($_SESSION['notes_user'])) {
    $isStaff = true;
}

// Get settings
$settings = getSettings(['badge_enabled', 'badge_visible_to_staff', 'require_current_registration']);

// Get profile
$profile = MemberService::getProfile($code);

if (!$profile) {
    http_response_code(404);
    showError('العضو غير موجود', 'الكود المستخدم غير مسجّل في النظام');
    exit;
}

// Check visibility
$badgeEnabled = $settings['badge_enabled'] ?? true;
$visibleToStaff = $settings['badge_visible_to_staff'] ?? true;
$requireCurrentReg = $settings['require_current_registration'] ?? true;

if (!$badgeEnabled && !($isStaff && $visibleToStaff)) {
    http_response_code(403);
    showError('البروفايل غير متاح', 'تم إيقاف عرض البروفايلات حالياً');
    exit;
}

// Extract data FIRST (needed for require_current_registration check)
$member = $profile['member'];
$currentReg = $profile['current_registration'];
$isRegistered = $profile['is_registered_current'];
$isApproved = $profile['is_approved_current'];

// Check if registration is required and member is not registered
if ($requireCurrentReg && !$isRegistered && !$isStaff) {
    http_response_code(403);
    showError('غير مسجّل في البطولة الحالية', 'يجب التسجيل في البطولة الحالية للوصول للبروفايل');
    exit;
}

function showError($title, $message) {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body{font-family:"Cairo",sans-serif;background:linear-gradient(135deg,#1a1a2e,#16213e);min-height:100vh;display:flex;align-items:center;justify-content:center;color:#fff;text-align:center;padding:20px}
            .c{max-width:400px}.i{font-size:80px;margin-bottom:20px}h1{font-size:24px;margin-bottom:10px}p{opacity:.7}
        </style>
    </head>
    <body>
        <div class="c">
            <div class="i">⚠️</div>
            <h1><?= htmlspecialchars($title) ?></h1>
            <p><?= htmlspecialchars($message) ?></p>
        </div>
    </body>
    </html>
    <?php
}

function normalizeNoteVisualType($type, $priority = '') {
    $rawType = strtolower(trim((string)$type));
    $rawPriority = strtolower(trim((string)$priority));

    if ($rawType === 'blocker') {
        return ['key' => 'blocker', 'label' => 'منع'];
    }
    if ($rawType === 'warning' && $rawPriority === 'high') {
        return ['key' => 'deprivation', 'label' => 'حرمان'];
    }
    if ($rawType === 'warning') {
        return ['key' => 'warning', 'label' => 'تحذير'];
    }
    return ['key' => 'info', 'label' => 'ملاحظة'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بروفايل - <?= htmlspecialchars($member['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
            padding: 20px;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
        }
        
        /* Header Card */
        .profile-header {
            background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0.05));
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }
        .profile-header .qr-code {
            width: 120px;
            height: 120px;
            margin: 0 auto 15px;
            background: white;
            border-radius: 15px;
            padding: 10px;
        }
        .profile-header .qr-code img {
            width: 100%;
            height: 100%;
        }
        .profile-header .name {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 5px;
        }
        .profile-header .code {
            font-size: 14px;
            opacity: 0.7;
            font-family: monospace;
            background: rgba(0,0,0,0.3);
            padding: 5px 15px;
            border-radius: 20px;
            display: inline-block;
        }
        
        /* Status Badge */
        .status-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-badge.registered { background: #28a745; }
        .status-badge.not-registered { background: #dc3545; }
        .status-badge.pending { background: #ffc107; color: #000; }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 15px;
            text-align: center;
        }
        .stat-card .icon { font-size: 28px; margin-bottom: 5px; }
        .stat-card .number { font-size: 28px; font-weight: 800; }
        .stat-card .label { font-size: 12px; opacity: 0.7; }
        .stat-card.green .number { color: #28a745; }
        .stat-card.blue .number { color: #17a2b8; }
        .stat-card.yellow .number { color: #ffc107; }
        .stat-card.red .number { color: #dc3545; }
        
        /* Info Card */
        .info-card {
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .info-card h3 {
            font-size: 16px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .label { opacity: 0.7; }
        .info-row .value { font-weight: 600; }
        
        /* Warnings Section */
        .warnings-section {
            background: linear-gradient(135deg, rgba(220,53,69,0.2), rgba(220,53,69,0.1));
            border: 1px solid #dc3545;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .warnings-section h3 {
            color: #dc3545;
            margin-bottom: 15px;
        }
        .warning-item {
            background: rgba(0,0,0,0.2);
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 10px;
            border-right: 4px solid;
        }
        .warning-item.low { border-color: #ffc107; }
        .warning-item.medium { border-color: #ff9800; }
        .warning-item.high { border-color: #dc3545; }
        .warning-item .text { font-size: 14px; }
        .warning-item .meta { font-size: 11px; opacity: 0.6; margin-top: 5px; }
        
        /* Notes Section */
        .notes-section {
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .notes-section h3 { margin-bottom: 15px; }
        .note-item {
            background: rgba(0,0,0,0.2);
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 10px;
            border-right: 4px solid;
        }
        .note-item.info { border-color: #17a2b8; }
        .note-item.warning { border-color: #ffc107; }
        .note-item.deprivation { border-color: #ff6b35; }
        .note-item.blocker { border-color: #dc3545; }
        .note-type-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            margin-bottom: 6px;
            background: rgba(255,255,255,0.14);
        }
        
        /* Championships List */
        .championships-list {
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
            padding: 20px;
        }
        .championships-list h3 { margin-bottom: 15px; }
        .champ-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .champ-item .name { font-weight: 600; }
        .champ-item .status {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
        }
        .champ-item .status.approved { background: #28a745; }
        .champ-item .status.pending { background: #ffc107; color: #000; }
        .champ-item .status.rejected { background: #dc3545; }
        
        /* Staff Badge */
        .staff-view {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            padding: 10px;
            text-align: center;
            font-size: 12px;
            margin-bottom: 20px;
            border-radius: 10px;
        }
        
        /* Not Registered Banner */
        .not-registered-banner {
            background: linear-gradient(135deg, #dc3545, #c82333);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($isStaff): ?>
        <div class="staff-view">
            👁️ عرض الموظفين - البيانات كاملة
        </div>
        <?php endif; ?>
        
        <!-- Profile Header -->
        <div class="profile-header">
            <span class="status-badge <?= $isApproved ? 'registered' : ($isRegistered ? 'pending' : 'not-registered') ?>">
                <?= $isApproved ? '✅ مسجّل' : ($isRegistered ? '⏳ قيد المراجعة' : '❌ غير مسجّل') ?>
            </span>
            
            <div class="qr-code">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode('https://drb.iq/profile.php?code=' . $member['permanent_code']) ?>" alt="QR">
            </div>
            
            <div class="name"><?= htmlspecialchars($member['name']) ?></div>
            <div class="code"><?= htmlspecialchars($member['permanent_code']) ?></div>
        </div>
        
        <?php if (!$isRegistered): ?>
        <div class="not-registered-banner">
            ⚠️ غير مسجّل في البطولة الحالية
        </div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="icon">🏆</div>
                <div class="number"><?= $profile['championships_count'] ?></div>
                <div class="label">عدد البطولات</div>
            </div>
            <div class="stat-card green">
                <div class="icon">🏁</div>
                <div class="number"><?= $profile['rounds_entered'] ?></div>
                <div class="label">عدد النزلات</div>
            </div>
            <div class="stat-card yellow">
                <div class="icon">📝</div>
                <div class="number"><?= count($profile['notes']) ?></div>
                <div class="label">ملاحظات</div>
            </div>
            <div class="stat-card red">
                <div class="icon">⚠️</div>
                <div class="number"><?= $profile['warnings_count'] ?></div>
                <div class="label">إنذارات</div>
            </div>
        </div>
        
        <!-- Current Registration Info -->
        <?php if ($currentReg): ?>
        <div class="info-card">
            <h3>🚗 بيانات التسجيل الحالي</h3>
            <div class="info-row">
                <span class="label">السيارة</span>
                <span class="value"><?= htmlspecialchars($currentReg['car_type'] ?? '-') ?></span>
            </div>
            <div class="info-row">
                <span class="label">السنة</span>
                <span class="value"><?= htmlspecialchars($currentReg['car_year'] ?? '-') ?></span>
            </div>
            <div class="info-row">
                <span class="label">اللون</span>
                <span class="value"><?= htmlspecialchars($currentReg['car_color'] ?? '-') ?></span>
            </div>
            <div class="info-row">
                <span class="label">اللوحة</span>
                <span class="value" style="direction:ltr;">
                    <?= htmlspecialchars(formatPlate($currentReg['plate_governorate'] ?? '', $currentReg['plate_letter'] ?? '', $currentReg['plate_number'] ?? '')) ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">رقم الطلب</span>
                <span class="value">#<?= htmlspecialchars($currentReg['wasel'] ?? '-') ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Warnings (if any) -->
        <div class="warnings-section" style="position:relative">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px">
                <h3>⚠️ إنذارات وملاحظات (<?= count($profile['warnings']) ?>)</h3>
                <?php if ($isStaff): ?>
                <button onclick="openNoteModal()" style="background:#dc3545;color:white;border:none;padding:5px 15px;border-radius:5px;cursor:pointer;font-family:inherit">
                    + إضافة تحذير
                </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($profile['warnings'])): ?>
            <?php foreach ($profile['warnings'] as $warning): ?>
            <div class="warning-item <?= $warning['severity'] ?>">
                <div class="text"><?= htmlspecialchars($warning['warning_text']) ?></div>
                <div class="meta">
                    <?= $warning['championship_name'] ?? 'عام' ?> • 
                    <?= date('Y/m/d', strtotime($warning['created_at'])) ?>
                    <?php if(!empty($warning['created_by_name'])): ?>
                        • بواسطة: <?= htmlspecialchars($warning['created_by_name']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align:center;color:#28a745;padding:20px;background:rgba(40,167,69,0.1);border-radius:10px">
                    <i class="fa-solid fa-check-circle" style="font-size:30px;margin-bottom:10px;display:block"></i>
                    لا توجد مخالفات مسجلة
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Notes (if staff or high priority) -->
        <?php 
        $visibleNotes = $isStaff ? $profile['notes'] : array_filter($profile['notes'], fn($n) => $n['priority'] === 'high');
        if (!empty($visibleNotes)): 
        ?>
        <div class="notes-section">
            <h3>📝 ملاحظات إضافية</h3>
            <?php foreach ($visibleNotes as $note): ?>
            <?php $noteType = normalizeNoteVisualType($note['note_type'] ?? '', $note['priority'] ?? ''); ?>
            <div class="note-item <?= htmlspecialchars($noteType['key']) ?>">
                <div class="text">
                    <div class="note-type-badge"><?= htmlspecialchars($noteType['label']) ?></div>
                    <?= htmlspecialchars($note['note_text']) ?>
                    <div style="font-size:10px;opacity:0.6;margin-top:4px">
                        <?= date('Y/m/d', strtotime($note['created_at'])) ?>
                        <?php if(!empty($note['created_by_name'])): ?>
                            • بواسطة: <?= htmlspecialchars($note['created_by_name']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Championships History -->
        <?php if (!empty($profile['registrations']) && count($profile['registrations']) > 0): ?>
        <div class="championships-list">
            <h3>📋 سجل البطولات</h3>
            <?php foreach ($profile['registrations'] as $reg): ?>
            <div class="champ-item">
                <div class="name"><?= htmlspecialchars($reg['championship_name'] ?? 'بطولة') ?></div>
                <span class="status <?= $reg['status'] ?>">
                    <?= $reg['status'] === 'approved' ? 'مقبول' : ($reg['status'] === 'pending' ? 'قيد المراجعة' : 'مرفوض') ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Note Modal -->
    <div id="noteModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999;align-items:center;justify-content:center">
        <div style="background:#222;padding:20px;border-radius:10px;width:90%;max-width:400px;text-align:right;color:white">
            <h3 style="margin-bottom:15px">إضافة تحذير / ملاحظة</h3>
            <input type="hidden" id="modalBadgeId" value="<?= htmlspecialchars($member['permanent_code']) ?>">
            
            <div style="margin-bottom:10px">
                <label>النوع</label>
                <select id="modalType" style="width:100%;padding:8px;border-radius:5px;background:#333;color:white;border:1px solid #444">
                    <option value="warning">تحذير ⚠️</option>
                    <option value="deprivation">حرمان ⛔</option>
                    <option value="blocker">منع 🛑</option>
                </select>
            </div>
            
            <div style="margin-bottom:10px">
                <label>النص</label>
                <textarea id="modalText" rows="3" style="width:100%;padding:8px;border-radius:5px;background:#333;color:white;border:1px solid #444" placeholder="اكتب تفاصيل المخالفة..."></textarea>
            </div>
            
            <div style="display:flex;gap:10px;margin-top:15px">
                <button onclick="submitNote()" style="flex:1;background:#dc3545;color:white;border:none;padding:10px;border-radius:5px;cursor:pointer">حفظ</button>
                <button onclick="closeNoteModal()" style="flex:1;background:#555;color:white;border:none;padding:10px;border-radius:5px;cursor:pointer">إلغاء</button>
            </div>
        </div>
    </div>

    <script>
        function openNoteModal() {
            document.getElementById('noteModal').style.display = 'flex';
        }
        function closeNoteModal() {
            document.getElementById('noteModal').style.display = 'none';
        }
        
        function submitNote() {
            const btn = event.target;
            btn.innerHTML = 'جاري الحفظ...';
            btn.disabled = true;
            
            const data = new FormData();
            const selectedType = document.getElementById('modalType').value;
            let mappedType = 'warning';
            let mappedPriority = 'medium';

            if (selectedType === 'deprivation') {
                mappedType = 'warning';
                mappedPriority = 'high';
            } else if (selectedType === 'blocker') {
                mappedType = 'blocker';
                mappedPriority = 'high';
            }

            data.append('badge_id', document.getElementById('modalBadgeId').value);
            data.append('note_type', mappedType);
            data.append('note_text', document.getElementById('modalText').value);
            data.append('priority', mappedPriority);
            
            fetch('add_note.php', {
                method: 'POST',
                body: data
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    alert('تمت الإضافة بنجاح');
                    location.reload();
                } else {
                    alert('خطأ: ' + (res.error || res.msg || 'حدث خطأ غير معروف'));
                    btn.innerHTML = 'حفظ';
                    btn.disabled = false;
                }
            })
            .catch(err => {
                alert('خطأ في الاتصال');
                btn.innerHTML = 'حفظ';
                btn.disabled = false;
            });
        }
    </script>
</body>
</html>
