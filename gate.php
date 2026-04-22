<?php
/**
 * Gate Entry Page - صفحة الدخول للبوابة
 * صفحة محدودة الصلاحيات لعامل البوابة
 * يتطلب تسجيل الدخول من صفحة المشرفين (login.php)
 */
session_start();

// Handle logout
if (isset($_GET['logout'])) {
    header('Location: logout.php');
    exit;
}

// Check if logged in via the system login (login.php)
// Check legacy session
$isLegacySystemUser = isset($_SESSION['user']) && isset($_SESSION['user']->role) && in_array($_SESSION['user']->role, ['root', 'gate', 'scanner', 'admin']);

// Check new SQLite session
$isNewSystemUser = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'gate', 'scanner', 'root', 'rounds', 'notes']);

$isLoggedIn = $isLegacySystemUser || $isNewSystemUser;

// If not logged in, redirect to login page with redirect back
if (!$isLoggedIn) {
    header('Location: login.php?redirect=gate.php');
    exit;
}

// Load data if logged in
$approvedRegistrations = [];
$enteredCount = 0;
$notEnteredCount = 0;

if ($isLoggedIn) {
    $dataFile = __DIR__ . '/admin/data/data.json';
    $membersFile = __DIR__ . '/admin/data/members.json';
    
    $membersData = file_exists($membersFile) ? json_decode(file_get_contents($membersFile), true) ?? [] : [];
    $allData = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) ?? [] : [];
    
    $typeTranslations = [
        'المشاركة بالاستعراض الحر' => 'استعراض حر',
        'free_show' => 'استعراض حر',
        'show' => 'عرض',
        'organization' => 'تنظيم',
        'sponsor' => 'راعي',
        'special_car' => 'سيارة مميزة',
        'burnout' => 'Burnout',
        'motorbikes' => 'دراجات'
    ];

    // Load approved members from data.json (Current Championship)
    foreach ($allData as $reg) {
        if (($reg['status'] ?? '') !== 'approved') {
            continue;
        }

        $code = $reg['registration_code'] ?? $reg['wasel'] ?? '';
        $m = $membersData[$code] ?? [];
        $hasEntered = $reg['has_entered'] ?? false;
        
        $pType = $reg['participation_type'] ?? '';
        $pTypeLabel = $typeTranslations[$pType] ?? $pType;

        $approvedRegistrations[] = [
            'wasel' => $reg['wasel'] ?? $m['wasel'] ?? '',
            'full_name' => $reg['full_name'] ?? $m['name'] ?? '',
            'phone' => $reg['phone'] ?? $m['phone'] ?? '',
            'participation_type_label' => $pTypeLabel,
            'car_type' => $reg['car_type'] ?? $m['car'] ?? '',
            'car_color' => $reg['car_color'] ?? $m['car_color'] ?? '',
            'plate_full' => $reg['plate_full'] ?? $m['plate'] ?? '',
            'has_entered' => $hasEntered,
            'entry_time' => $reg['entry_time'] ?? null,
            'badge_id' => $reg['badge_token'] ?? $reg['badge_id'] ?? $m['badge_token'] ?? $code
        ];
        
        if ($hasEntered) {
            $enteredCount++;
        } else {
            $notEnteredCount++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة الدخول</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Cairo', sans-serif; 
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
        }
        

        
        /* Dashboard Styles */
        .header {
            background: rgba(0,0,0,0.3);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 18px; }
        .header a { color: #ff6b6b; text-decoration: none; }
        
        .stats {
            display: flex;
            gap: 15px;
            padding: 20px;
            flex-wrap: wrap;
        }
        .stat-card {
            flex: 1;
            min-width: 150px;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
        }
        .stat-card.green { background: linear-gradient(135deg, #28a745, #20c997); }
        .stat-card.orange { background: linear-gradient(135deg, #ffc107, #ff9800); color: #000; }
        .stat-card.blue { background: linear-gradient(135deg, #007bff, #0056b3); }
        .stat-card h2 { font-size: 36px; margin: 10px 0; }
        .stat-card p { font-size: 14px; opacity: 0.9; }
        
        .actions {
            padding: 0 20px 20px;
            display: flex;
            gap: 10px;
        }
        .actions a {
            flex: 1;
            padding: 15px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            text-align: center;
            font-weight: bold;
        }
        
        .filter-tabs {
            display: flex;
            padding: 0 20px;
            gap: 10px;
            margin-bottom: 15px;
        }
        .filter-tabs button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        .filter-tabs button.active {
            background: #007bff;
        }
        
        .list-container {
            padding: 0 20px 20px;
        }
        
        .entry-card {
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .entry-card.entered {
            border-right: 5px solid #28a745;
        }
        .entry-card.not-entered {
            border-right: 5px solid #ffc107;
        }
        .entry-info h3 { font-size: 16px; margin-bottom: 5px; }
        .entry-info h3 a { color: #fff; text-decoration: none; }
        .entry-info h3 a:hover { text-decoration: underline; color: #ffeb3b; }
        .entry-info p { font-size: 13px; opacity: 0.8; }
        .entry-info .p-type { color: #9b59b6; font-size: 12px; font-weight: bold; margin-bottom: 2px; }
        .entry-status {
            font-size: 12px;
            padding: 5px 10px;
            border-radius: 20px;
        }
        .entry-status.entered { background: #28a745; }
        .entry-status.not-entered { background: #ffc107; color: #000; }
        
        .search-box {
            padding: 0 20px 15px;
        }
        .search-box input {
            width: 100%;
            padding: 12px 15px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-family: inherit;
        }
    </style>
</head>
<body>

<!-- Gate Dashboard -->
<div class="header">
    <h1>🚪 بوابة الدخول</h1>
    <a href="?logout=1">خروج</a>
</div>

<div class="stats">
    <div class="stat-card green">
        <p>دخلوا</p>
        <h2><?= $enteredCount ?></h2>
    </div>
    <div class="stat-card orange">
        <p>لم يدخلوا</p>
        <h2><?= $notEnteredCount ?></h2>
    </div>
    <div class="stat-card blue">
        <p>المجموع</p>
        <h2><?= count($approvedRegistrations) ?></h2>
    </div>
</div>

<div class="actions">
    <a href="admin/qr_scanner.php" target="_blank">📷 فتح الماسح</a>
</div>

<div class="search-box">
    <input type="text" id="searchInput" placeholder="🔍 بحث بالاسم أو السيارة أو اللوحة أو الهاتف..." oninput="filterList()">
</div>

<div class="filter-tabs">
    <button class="active" onclick="setFilter('all', this)">الكل</button>
    <button onclick="setFilter('entered', this)">دخلوا ✅</button>
    <button onclick="setFilter('not-entered', this)">لم يدخلوا ⏳</button>
</div>

<div class="list-container" id="entryList">
    <?php foreach ($approvedRegistrations as $reg): 
        $hasEntered = $reg['has_entered'] ?? false;
        $statusClass = $hasEntered ? 'entered' : 'not-entered';
    ?>
    <div class="entry-card <?= $statusClass ?>" 
         data-name="<?= htmlspecialchars(strtolower($reg['full_name'])) ?>"
         data-car="<?= htmlspecialchars(strtolower($reg['car_type'])) ?>"
         data-plate="<?= htmlspecialchars(strtolower($reg['plate_full'])) ?>"
         data-phone="<?= htmlspecialchars(strtolower($reg['phone'])) ?>"
         data-wasel="<?= htmlspecialchars($reg['wasel']) ?>"
         data-badge="<?= htmlspecialchars($reg['badge_id']) ?>"
         data-status="<?= $statusClass ?>">
        <div class="entry-info">
            <h3>
                <a href="admin/member_details.php?id=<?= htmlspecialchars($reg['badge_id']) ?>" target="_blank">
                    #<?= htmlspecialchars($reg['wasel']) ?> - <?= htmlspecialchars($reg['full_name']) ?>
                </a>
            </h3>
            <div class="p-type"><?= htmlspecialchars($reg['participation_type_label']) ?></div>
            <p>🚗 <?= htmlspecialchars($reg['car_type']) ?> - <?= htmlspecialchars($reg['car_color']) ?></p>
            <p>📋 <?= htmlspecialchars($reg['plate_full']) ?></p>
            <?php if ($hasEntered && !empty($reg['entry_time'])): ?>
            <p style="color: #28a745;">⏰ دخل: <?= htmlspecialchars($reg['entry_time']) ?></p>
            <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:5px;">
            <span class="entry-status <?= $statusClass ?>">
                <?= $hasEntered ? '✅ دخل' : '⏳ لم يدخل' ?>
            </span>
            <?php if (!$hasEntered): ?>
            <button onclick="admitFromGate('<?= htmlspecialchars($reg['wasel']) ?>', this)" 
                    style="padding:8px 15px;border:none;border-radius:8px;background:#28a745;color:#fff;font-family:inherit;font-size:13px;cursor:pointer;">
                🚪 تسجيل دخول
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
let currentFilter = 'all';

function setFilter(filter, btn) {
    currentFilter = filter;
    document.querySelectorAll('.filter-tabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterList();
}

function filterList() {
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.entry-card');
    
    cards.forEach(card => {
        const name = card.dataset.name || '';
        const car = card.dataset.car || '';
        const plate = card.dataset.plate || '';
        const phone = card.dataset.phone || '';
        const wasel = card.dataset.wasel || '';
        const status = card.dataset.status;
        
        const matchesSearch = !search || name.includes(search) || car.includes(search) || plate.includes(search) || phone.includes(search) || wasel === search;
        const matchesFilter = currentFilter === 'all' || status === currentFilter;
        
        card.style.display = (matchesSearch && matchesFilter) ? 'flex' : 'none';
    });
}

// Admit participant from gate list
function admitFromGate(wasel, btn) {
    if (!confirm('تسجيل دخول هذا المشارك؟')) return;
    
    btn.disabled = true;
    btn.textContent = 'جاري...';
    
    fetch('verify_entry.php?action=checkin&wasel=' + encodeURIComponent(wasel))
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Update the card UI
                const card = btn.closest('.entry-card');
                card.classList.remove('not-entered');
                card.classList.add('entered');
                card.dataset.status = 'entered';
                
                // Update status badge
                const statusSpan = card.querySelector('.entry-status');
                statusSpan.className = 'entry-status entered';
                statusSpan.textContent = '✅ دخل';
                
                // Remove the button
                btn.remove();
                
                // Update stats
                const enteredEl = document.querySelector('.stat-card.green h2');
                const notEnteredEl = document.querySelector('.stat-card.orange h2');
                if (enteredEl) enteredEl.textContent = parseInt(enteredEl.textContent) + 1;
                if (notEnteredEl) notEnteredEl.textContent = Math.max(0, parseInt(notEnteredEl.textContent) - 1);
                
                // Show entry time
                const infoDiv = card.querySelector('.entry-info');
                const timeP = document.createElement('p');
                timeP.style.color = '#28a745';
                timeP.textContent = '⏰ دخل: ' + (data.entry_time || 'الآن');
                infoDiv.appendChild(timeP);
                
                // Flash 
                card.style.transition = 'background 0.5s';
                card.style.background = 'rgba(40,167,69,0.3)';
                setTimeout(() => card.style.background = '', 2000);
            } else {
                alert('❌ ' + (data.message || 'خطأ'));
                btn.disabled = false;
                btn.textContent = '🚪 تسجيل دخول';
            }
        })
        .catch(() => {
            alert('❌ خطأ في الاتصال');
            btn.disabled = false;
            btn.textContent = '🚪 تسجيل دخول';
        });
}
</script>



</body>
</html>

