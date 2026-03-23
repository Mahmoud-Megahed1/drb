<?php
/**
 * Rounds Scanner Page
 * QR Scanner for tracking round entry/exit
 * Version: 2.0 (Modern Dark Mode)
 */

require_once '../include/db.php';
require_once '../include/auth.php';

requireAuth('../rounds_gate.php');

if (!hasPermission('rounds')) {
    header('Location: ../rounds_gate.php');
    exit;
}

// Get rounds
$pdo = db();
$rounds = $pdo->query("SELECT * FROM rounds WHERE is_active = 1 ORDER BY round_number")->fetchAll();

$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>🏁 ماسح الجولات</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
            padding-bottom: 100px;
        }
        .header {
            background: rgba(0,0,0,0.3);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header h1 { font-size: 18px; }
        .header a { color: #ff6b6b; text-decoration: none; }
        
        .container { padding: 20px; max-width: 600px; margin: 0 auto; }
        
        /* Round Selection */
        .round-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .round-btn {
            padding: 15px 10px;
            border: 2px solid #444;
            border-radius: 12px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .round-btn.selected {
            border-color: #28a745;
            background: rgba(40,167,69,0.3);
        }
        .round-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .action-btn {
            padding: 20px;
            border: none;
            border-radius: 15px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            font-family: inherit;
            transition: transform 0.2s;
        }
        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .action-btn.enter {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        .action-btn.exit {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }
        .action-btn.selected {
            transform: scale(1.05);
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        }
        
        /* Scanner */
        .scanner-container {
            background: rgba(0,0,0,0.3);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        #qr-reader {
            width: 100%;
            border-radius: 10px;
            overflow: hidden;
        }
        
        /* Manual Input */
        .manual-input {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .manual-input input {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-family: inherit;
            text-align: center;
        }
        .manual-input button {
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            background: #007bff;
            color: white;
            font-family: inherit;
            cursor: pointer;
        }
        
        /* Result Display */
        .result-container {
            display: none;
            text-align: center;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 20px;
        }
        .result-container.success { background: linear-gradient(135deg, #28a745, #20c997); }
        .result-container.error { background: linear-gradient(135deg, #dc3545, #c82333); }
        .result-container.warning { background: linear-gradient(135deg, #ffc107, #ff9800); color: #000; }
        .result-container h2 { font-size: 48px; margin-bottom: 10px; }
        .result-container .name { font-size: 24px; font-weight: bold; }
        .result-container .details { font-size: 16px; opacity: 0.9; margin-top: 10px; }
        .result-container .error-code { font-size: 12px; opacity: 0.7; margin-top: 10px; }
        .result-container .action-buttons { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 15px; }
        .result-container .action-btn { padding: 12px 20px; border-radius: 10px; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; border: none; font-family: inherit; }
        .btn-profile { background: linear-gradient(135deg,#007bff,#0056b3); color: #fff; }
        .btn-violation { background: linear-gradient(135deg,#dc3545,#c82333); color: #fff; }
        
        /* Violation Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.visible { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 15px;
            padding: 25px;
            width: 90%;
            max-width: 400px;
            color: #333;
        }
        .modal-box h3 { margin-bottom: 15px; color: #c0392b; display: flex; align-items: center; gap: 10px; }
        .modal-box textarea { width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-family: inherit; font-size: 16px; min-height: 100px; }
        .modal-box select { width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-family: inherit; font-size: 16px; margin-top: 10px; }
        .modal-buttons { display: flex; gap: 10px; margin-top: 15px; }
        .modal-buttons button { flex: 1; padding: 12px; border: none; border-radius: 8px; font-family: inherit; font-size: 16px; cursor: pointer; }
        .btn-submit { background: #c0392b; color: #fff; }
        .btn-cancel { background: #bdc3c7; color: #333; }
        
        /* Stats */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .stat-card:active { transform: scale(0.95); }
        .stat-card:hover { background: rgba(255,255,255,0.15); }
        .stat-card .number { font-size: 28px; font-weight: bold; }
        .stat-card .label { font-size: 12px; opacity: 0.7; }
        .stat-card.green .number { color: #28a745; }
        .stat-card.red .number { color: #dc3545; }
        .stat-card.blue .number { color: #007bff; }
        
        /* Offline indicator */
        .offline-indicator {
            display: none;
            background: #ffc107;
            color: #000;
            padding: 10px;
            text-align: center;
            font-weight: bold;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
        }
        body.offline .offline-indicator { display: block; }

        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }

        /* Activity Log */
        .activity-log-box {
            background: rgba(0,0,0,0.4);
            border-radius: 15px;
            padding: 15px;
            margin-top: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .activity-log-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .activity-list {
            max-height: 250px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .activity-item {
            background: rgba(255,255,255,0.05);
            padding: 10px 15px;
            border-radius: 10px;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideIn 0.3s ease-out;
        }
        .activity-item.reset {
            background: rgba(255,193,7,0.1);
            border: 1px dashed #ffc107;
        }
        .activity-item .info {
            display: flex;
            flex-direction: column;
        }
        .activity-item .name { font-weight: bold; }
        .activity-item .time { font-size: 12px; opacity: 0.6; }
        .activity-item .badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 20px;
        }
        .activity-item.reset .name { color: #ffc107; }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fa-solid fa-flag-checkered"></i> ماسح الجولات</h1>
        <div>
            <span style="opacity:0.7; font-size:12px; margin-right: 10px;"><i class="fa-solid fa-mobile-screen"></i> <?= htmlspecialchars($user['device'] ?? '') ?></span>
            <a href="../rounds_gate.php?logout=1"><i class="fa-solid fa-right-from-bracket"></i> خروج</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Round Selection -->
        <div class="round-selector" id="roundSelector">
            <?php foreach ($rounds as $r): ?>
            <button class="round-btn" data-round="<?= $r['id'] ?>">
                <?= htmlspecialchars($r['round_name']) ?>
            </button>
            <?php endforeach; ?>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons" style="grid-template-columns: 1fr;">
            <button class="action-btn enter" data-action="enter" disabled>
                <i class="fa-solid fa-check"></i> تسجيل دخول
            </button>
        </div>
        
        <!-- Stats -->
        <div class="stats" id="stats" style="grid-template-columns: 1fr 1fr;">
            <div class="stat-card green">
                <div class="number" id="statEntered">0</div>
                <div class="label">تم الدخول</div>
            </div>
            <div class="stat-card blue">
                <div class="number" id="statRemaining">0</div>
                <div class="label">المتبقي</div>
            </div>
        </div>
        
        <!-- Result Display -->
        <div class="result-container" id="resultContainer">
            <h2 id="resultIcon"><i class="fa-solid fa-check-circle"></i></h2>
            <div class="name" id="resultName"></div>
            <div class="details" id="resultDetails"></div>
            <div class="error-code" id="resultCode"></div>
            <div class="action-buttons">
                <a href="#" id="profileLink" target="_blank" class="action-btn btn-profile" style="display:none;">
                    <i class="fa-solid fa-user-circle"></i> فتح الملف الشخصي
                </a>
                <button type="button" id="violationBtn" class="action-btn btn-violation" style="display:none;" onclick="openViolationModal()">
                    <i class="fa-solid fa-triangle-exclamation"></i> إضافة مخالفة
                </button>
            </div>
        </div>
        
        <!-- Scanner -->
        <div class="scanner-container">
            <div id="qr-reader"></div>
            <div class="manual-input">
                <input type="text" id="manualCode" placeholder="أو رقم السيارة / الباركود">
                <button onclick="submitManual()"><i class="fa-solid fa-check"></i></button>
            </div>
        </div>

        <!-- Activity Log -->
        <div class="activity-log-box">
            <div class="activity-log-title">
                <span><i class="fa-solid fa-clock-rotate-left"></i> سجل النشاط الأخير</span>
                <span id="logLoading" style="display:none;"><i class="fa-solid fa-spinner fa-spin"></i></span>
            </div>
            <div style="margin-bottom: 10px;">
                <input type="text" id="activitySearch" placeholder="بحث في السجل..." style="width: 100%; padding: 8px 12px; border: none; border-radius: 8px; font-family: inherit; background: rgba(255,255,255,0.1); color: #fff;">
            </div>
            <div class="activity-list" id="activityList">
                <div style="text-align:center; padding:20px; opacity:0.5; font-size:14px;">لا توجد نشاطات مؤخراً</div>
            </div>
        </div>
    </div>
    
    <div class="offline-indicator">
        <i class="fa-solid fa-wifi"></i> لا يوجد اتصال - سيتم حفظ البيانات محلياً
    </div>
    
    <!-- Violation Modal -->
    <div class="modal-overlay" id="violationModal">
        <div class="modal-box">
            <h3><i class="fa-solid fa-triangle-exclamation"></i> إضافة مخالفة</h3>
            <p style="margin-bottom:15px; font-size:14px;">المشارك: <strong id="violationParticipant"></strong></p>
            <textarea id="violationText" placeholder="اكتب تفاصيل المخالفة..."></textarea>
            <select id="violationType">
                <option value="warning">تحذير ⚠️</option>
                <option value="deprivation">حرمان ⛔</option>
                <option value="blocker">منع 🛑</option>
            </select>
            <select id="violationPriority">
                <option value="low">عادي</option>
                <option value="medium">متوسط</option>
                <option value="high">عالي الأهمية</option>
            </select>
            <div class="modal-buttons">
                <button class="btn-submit" onclick="submitViolation()"><i class="fa-solid fa-check"></i> حفظ</button>
                <button class="btn-cancel" onclick="closeViolationModal()"><i class="fa-solid fa-times"></i> إلغاء</button>
            </div>
        </div>
    </div>

    <!-- Participants List Modal -->
    <div class="modal-overlay" id="participantsModal">
        <div class="modal-box" style="max-width: 500px; max-height: 80vh; display: flex; flex-direction: column;">
            <h3 id="modalTitle" style="color: #333; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 0;">
                <i class="fa-solid fa-users"></i> قائمة المشاركين
            </h3>
            <div style="padding: 10px 0;">
                <input type="text" id="modalSearch" placeholder="بحث بالاسم، رقم السيارة، الهاتف، الكود..." style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-family: inherit;">
            </div>
            <div id="modalLoading" style="text-align: center; padding: 30px; display: none;">
                <i class="fa-solid fa-spinner fa-spin fa-2x" style="color: #007bff;"></i>
                <p style="margin-top: 10px;">جاري التحميل...</p>
            </div>
            <div id="participantsList" style="flex: 1; overflow-y: auto; padding: 15px 0;">
                <!-- List items will be injected here -->
            </div>
            <div class="modal-buttons" style="border-top: 1px solid #eee; padding-top: 15px;">
                <button class="btn-cancel" onclick="closeParticipantsModal()"><i class="fa-solid fa-times"></i> إغلاق</button>
            </div>
        </div>
    </div>
    
    <script src="../js/html5-qrcode.min.js"></script>
    <script>
    // State
    let selectedRound = null;
    let selectedAction = null;
    let html5QrCode = null;
    const DEVICE_ID = '<?= htmlspecialchars($user['device'] ?? 'unknown') ?>';
    
    // Offline queue
    const offlineQueue = {
        items: JSON.parse(localStorage.getItem('roundsQueue') || '[]'),
        add(scan) {
            this.items.push({...scan, queued_at: Date.now()});
            this.save();
        },
        save() {
            localStorage.setItem('roundsQueue', JSON.stringify(this.items));
        },
        async flush() {
            const toSync = [...this.items];
            this.items = [];
            this.save();
            
            for (const item of toSync) {
                try {
                    await verifyRound(item.badge_id, item.round_id, item.action, false);
                } catch (e) {
                    this.add(item);
                }
            }
        }
    };
    
    // Round selection
    document.querySelectorAll('.round-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.round-btn').forEach(b => b.classList.remove('selected'));
            this.classList.add('selected');
            selectedRound = parseInt(this.dataset.round);
            updateActionButtons();
            loadStats();
            fetchActivityLog();
        });
    });
    
    // Action selection
    document.querySelectorAll('.action-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (this.disabled) return;
            document.querySelectorAll('.action-btn').forEach(b => b.classList.remove('selected'));
            this.classList.add('selected');
            selectedAction = this.dataset.action;
        });
    });
    
    function updateActionButtons() {
        const disabled = !selectedRound;
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.disabled = disabled;
        });
        if (!disabled && !selectedAction) {
            document.querySelector('.action-btn.enter').classList.add('selected');
            selectedAction = 'enter';
        }
    }
    
    // Load stats
    async function loadStats() {
        if (!selectedRound) return;
        try {
            const resp = await fetch('../api/get_rounds.php');
            const data = await resp.json();
            if (data.success) {
                const round = data.rounds.find(r => r.id == selectedRound);
                if (round) {
                    document.getElementById('statEntered').textContent = round.total_entered || 0;
                    document.getElementById('statInside').textContent = round.currently_in || 0;
                    document.getElementById('statExited').textContent = round.total_exited || 0;
                }
            }
        } catch (e) {
            console.log('Stats not available offline');
        }
    }
    
    // Verify round
    async function verifyRound(badgeId, roundId, action, showResult = true) {
        const formData = new FormData();
        formData.append('badge_id', badgeId);
        formData.append('round_id', roundId || selectedRound);
        formData.append('action', action || selectedAction);
        formData.append('device', DEVICE_ID);
        
        const resp = await fetch('../verify_round.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await resp.json();
        
        if (showResult) {
            showResultUI(result);
            loadStats();
            fetchActivityLog();
        }
        
        return result;
    }
    
    // Show result
    function showResultUI(result) {
        const container = document.getElementById('resultContainer');
        const icon = document.getElementById('resultIcon');
        const name = document.getElementById('resultName');
        const details = document.getElementById('resultDetails');
        const code = document.getElementById('resultCode');
        
        container.style.display = 'block';
        container.className = 'result-container ' + (result.success ? 'success' : 'error');
        
        if (result.success) {
            
            if (result.warning) {
                // Warning State (Already Entered/Exited)
                container.className = 'result-container warning';
                icon.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i>';
                name.textContent = result.message; // "مسجل مسبقاً"
                details.textContent = `${result.participant?.name || ''} | ${result.round?.name || ''}`;
                playSound('error'); // Use distinction sound if possible, or error sound to alert
            } else {
                // Success State
                container.className = 'result-container success';
                
                var actionText = result.status === 'entered' ? '✅ تم الدخول بنجاح' : '✅ تم الخروج بنجاح';
                var iconHtml = result.status === 'entered' ? '<i class="fa-solid fa-arrow-down"></i>' : '<i class="fa-solid fa-arrow-up"></i>';
                
                icon.innerHTML = iconHtml;
                name.textContent = actionText; // Title is the action
                
                // Details: Name | Car | Lifetime Rounds
                const lifetime = result.lifetime_rounds || result.rounds_completed || 0;
                details.innerHTML = `<div style="font-size:1.2em;font-weight:bold;margin-top:5px;">${result.participant?.name || ''}</div>` +
                                   `<div style="color:#eee;font-size:0.9em;">${result.participant?.car || ''}</div>` +
                                   `<div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.2);">` + 
                                   `<strong><i class="fa-solid fa-trophy"></i> إجمالي المشاركات: ${lifetime}</strong>` +
                                   `</div>`;
                
                playSound('success');
            }
            
            // Show profile link and violation button if badge_id available
            const profileLink = document.getElementById('profileLink');
            const violationBtn = document.getElementById('violationBtn');
            if (result.badge_id || result.participant?.badge_id) {
                const badgeId = result.badge_id || result.participant?.badge_id;
                profileLink.href = 'member_details.php?id=' + encodeURIComponent(badgeId);
                profileLink.style.display = 'inline-flex';
                violationBtn.style.display = 'inline-flex';
                
                // Store for violation modal
                currentBadgeId = badgeId;
                currentParticipantName = result.participant?.name || '';
            } else {
                profileLink.style.display = 'none';
                violationBtn.style.display = 'none';
            }
            
            code.textContent = '';
            
        } else {
            icon.innerHTML = '<i class="fa-solid fa-times-circle"></i>';
            name.textContent = result.message || result.error || 'خطأ';
            
            // Show debug info if available
            let debugHtml = result.blocker_text || '';
            if (result.received_id) {
                debugHtml += `<div style="margin-top:10px;padding:5px;background:#333;color:#0f0;font-family:monospace;font-size:10px;text-align:left;direction:ltr;border-radius:4px;">
                    ID: ${result.received_id}<br>
                    File: ${result.file_exists ? 'OK' : 'MISSING'}<br>
                    Count: ${result.records_count}<br>
                    JSON: ${result.json_error || 'OK'}
                </div>`;
            }
            
            if (result.last_action !== undefined) {
                debugHtml += `<div style="margin-top:5px;padding:5px;background:#000;color:#ff0;font-family:monospace;font-size:10px;text-align:left;direction:ltr;border-radius:4px;">
                    Logs Found: ${result.logs_found}<br>
                    Last Action: ${result.last_action || 'NULL'}<br>
                    Attempt: ${result.is_enter ? 'ENTER' : 'EXIT'}<br>
                    PID: ${result.pid_used}
                </div>`;
            }
            
            details.innerHTML = debugHtml;
            
            code.textContent = result.code || '';
            playSound('error');
        }
        
        // Auto-hide after 3 seconds
        setTimeout(() => {
            container.style.display = 'none';
        }, 3000);
    }
    
    // Play sound
    function playSound(type) {
        // Create audio context for sound feedback
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            
            if (type === 'success') {
                osc.frequency.value = 800;
                gain.gain.value = 0.3;
            } else {
                osc.frequency.value = 300;
                gain.gain.value = 0.5;
            }
            
            osc.start();
            osc.stop(ctx.currentTime + 0.2);
        } catch (e) {}
    }
    
    // Manual submit
    function submitManual() {
        const input = document.getElementById('manualCode');
        const code = input.value.trim();
        if (!code) return;
        if (!selectedRound || !selectedAction) {
            alert('اختر الجولة والإجراء أولاً');
            return;
        }
        handleScan(code);
        input.value = '';
    }
    
    // Handle scan
    async function handleScan(code) {
        if (!selectedRound || !selectedAction) {
            alert('اختر الجولة والإجراء أولاً');
            return;
        }
        
        try {
            await verifyRound(code, selectedRound, selectedAction);
        } catch (e) {
            // Offline - queue it
            offlineQueue.add({
                badge_id: code,
                round_id: selectedRound,
                action: selectedAction
            });
            showResultUI({
                success: true,
                status: selectedAction === 'enter' ? 'entered' : 'exited',
                participant: { name: '(محفوظ محلياً)' },
                round: { name: '' }
            });
        }
    }
    
    var lastScannedCode = null;
    var lastScanTime = 0;
    var isProcessing = false;

    // Initialize QR scanner
    async function initScanner() {
        try {
            html5QrCode = new Html5Qrcode("qr-reader");
            await html5QrCode.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                (decodedText) => {
                    // Extract code
                    let code = decodedText;
                    const match = decodedText.match(/[?&]token=([^&]+)/);
                    if (match) code = match[1];
                    
                    // Cooldown / Debounce Logic
                    const now = Date.now();
                    if (code === lastScannedCode && (now - lastScanTime < 4000)) {
                        return; // Ignore same code within 4 seconds
                    }
                    if (isProcessing) return; 

                    lastScannedCode = code;
                    lastScanTime = now;
                    isProcessing = true;
                    
                    handleScan(code).finally(() => {
                         // Allow processing again after a short delay, but keep code cooldown
                         setTimeout(() => { isProcessing = false; }, 500); 
                    });
                },
                () => {}
            );
        } catch (e) {
            console.error('Scanner error:', e);
        }
    }
    
    // Offline detection
    window.addEventListener('online', () => {
        document.body.classList.remove('offline');
        offlineQueue.flush();
    });
    window.addEventListener('offline', () => {
        document.body.classList.add('offline');
    });
    
    // Enter key for manual input
    document.getElementById('manualCode').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') submitManual();
    });

    // --- Stats Logic ---
    async function fetchStats() {
        try {
            const response = await fetch('../api/get_rounds.php');
            const result = await response.json();
            
            if (result.success && result.data && result.data.rounds) {
                const roundId = selectedRound || 1; // Default to 1 if none selected
                const roundData = result.data.rounds.find(r => r.id == roundId);
                
                if (roundData) {
                    document.getElementById('statEntered').textContent = roundData.total_entered;
                    
                    // Use server-side calculated remaining (already filtered by free_show)
                    const remaining = roundData.remaining !== undefined ? roundData.remaining : 0;
                    document.getElementById('statRemaining').textContent = remaining;
                }
            }
        } catch (e) {
            console.error("Stats update failed", e);
        }
    }
    
    // Poll every 10 seconds (reduced frequency)
    setInterval(fetchStats, 10000);
    // Initial call
    fetchStats();

    // Click handlers for stats
    document.getElementById('statEntered').parentElement.addEventListener('click', () => {
        openParticipantsModal('entered');
    });
    document.getElementById('statRemaining').parentElement.addEventListener('click', () => {
        openParticipantsModal('remaining');
    });

    function openParticipantsModal(status) {
        if (!selectedRound) {
            alert('يرجى اختيار الجولة أولاً');
            return;
        }

        const modal = document.getElementById('participantsModal');
        const title = document.getElementById('modalTitle');
        const list = document.getElementById('participantsList');
        const loading = document.getElementById('modalLoading');

        title.innerHTML = status === 'entered' ? 
            '<i class="fa-solid fa-check-circle" style="color: #28a745"></i> المشتركين الذين دخلوا' : 
            '<i class="fa-solid fa-clock" style="color: #007bff"></i> المشتركين المتبقيين';
        
        list.innerHTML = '';
        loading.style.display = 'block';
        modal.classList.add('visible');
        document.getElementById('modalSearch').value = '';

        fetch(`../api/get_round_participants.php?round_id=${selectedRound}&status=${status}`)
            .then(res => res.json())
            .then(data => {
                loading.style.display = 'none';
                if (data.success) {
                    if (data.participants.length === 0) {
                        list.innerHTML = '<div style="text-align:center; padding:20px; color:#666;">لا يوجد مشاركين في هذه القائمة</div>';
                    } else {
                        list.innerHTML = data.participants.map(p => `
                            <div class="modal-list-item" data-search="${(p.name || '')} ${(p.wasel || '')} ${(p.registration_code || '')} ${(p.phone || '')} ${(p.car_number || '')}" style="padding: 12px; border-bottom: 1px solid #f5f5f5; display: flex; justify-content: space-between; align-items: center;">
                                <div style="display: flex; flex-direction: column;">
                                    <a href="member_details.php?id=${encodeURIComponent(p.badge_id || p.registration_code || p.wasel)}" 
                                       target="_blank" 
                                       style="font-weight: bold; color: #333; text-decoration: none; border-bottom: 1px dashed #ccc;">
                                       ${p.name}
                                    </a>
                                    ${p.participation_type_label ? `<span style="font-size: 11px; color: #28a745; margin-top: 2px;">📋 ${p.participation_type_label}</span>` : ''}
                                    <span style="font-size: 12px; color: #666;">الكود: ${p.registration_code || p.wasel}</span>
                                </div>
                                <span style="background: #f0f0f0; padding: 4px 8px; border-radius: 4px; font-size: 12px; color: #555;">#${p.wasel}</span>
                            </div>
                        `).join('');
                    }
                } else {
                    list.innerHTML = `<div style="text-align:center; padding:20px; color:red;">خطأ في تحميل البيانات: ${data.message || 'خطأ مجهول'}</div>`;
                }
            })
            .catch(err => {
                loading.style.display = 'none';
                list.innerHTML = '<div style="text-align:center; padding:20px; color:red;">خطأ في الاتصال بالسيرفر</div>';
            });
    }

    function closeParticipantsModal() {
        document.getElementById('participantsModal').classList.remove('visible');
    }
    
    // Activity Log Logic
    async function fetchActivityLog() {
        if (!selectedRound) return;
        
        const loader = document.getElementById('logLoading');
        loader.style.display = 'inline-block';
        
        try {
            const resp = await fetch(`../api/get_round_activity.php?round_id=${selectedRound}`);
            const data = await resp.json();
            
            if (data.success) {
                const list = document.getElementById('activityList');
                if (data.activity.length === 0) {
                    list.innerHTML = '<div style="text-align:center; padding:20px; opacity:0.5; font-size:14px;">لا توجد نشاطات مؤخراً</div>';
                } else {
                    list.innerHTML = data.activity.map(item => {
                        const isReset = item.action === 'reset_round';
                        const icon = isReset ? '<i class="fa-solid fa-rotate-left"></i>' : '<i class="fa-solid fa-user-check"></i>';
                        const label = isReset ? 'إعادة تعيين' : 'دخول';
                        const colorClass = isReset ? 'reset' : '';
                        
                        let nameHtml = `${icon} ${item.description}`;
                        if (item.badge_id && !isReset) {
                            nameHtml = `${icon} <a href="member_details.php?id=${encodeURIComponent(item.badge_id)}" target="_blank" style="color:white; text-decoration:underline;">${item.description}</a>`;
                        }
                        
                        return `
                        <div class="activity-item ${colorClass} log-list-item" data-search="${(item.description || '')} ${(item.badge_id || '')} ${(item.username || '')}">
                            <div class="info">
                                <span class="name">${nameHtml}</span>
                                <span class="time">${item.date} | ${item.time} | ${item.username}</span>
                            </div>
                        </div>
                        `;
                    }).join('');
                }
            }
        } catch (e) {
            console.error("Activity log update failed", e);
        } finally {
            loader.style.display = 'none';
        }
    }

    // Refresh activity every 30 seconds (it refreshes on action anyway)
    setInterval(fetchActivityLog, 30000);
    </script>
    <script src="../js/html5-qrcode.min.js"></script>
    <script>
    
    // Init
    initScanner();
    
    // Auto-select first round
    const firstRound = document.querySelector('.round-btn');
    if (firstRound) firstRound.click();
    
    // Violation Modal Functions
    let currentBadgeId = '';
    let currentParticipantName = '';
    
    function openViolationModal() {
        document.getElementById('violationParticipant').textContent = currentParticipantName || 'غير معروف';
        document.getElementById('violationText').value = '';
        document.getElementById('violationModal').classList.add('visible');
    }
    
    function closeViolationModal() {
        document.getElementById('violationModal').classList.remove('visible');
    }

    function mapViolationForSubmit(type, selectedPriority) {
        if (type === 'deprivation') {
            return { note_type: 'warning', priority: 'high' };
        }
        if (type === 'blocker') {
            return {
                note_type: 'blocker',
                priority: selectedPriority === 'low' ? 'medium' : selectedPriority
            };
        }
        return {
            note_type: 'warning',
            priority: selectedPriority
        };
    }
    
    async function submitViolation() {
        const text = document.getElementById('violationText').value.trim();
        const type = document.getElementById('violationType').value;
        const selectedPriority = document.getElementById('violationPriority').value;
        const mapped = mapViolationForSubmit(type, selectedPriority);
        
        if (!text) { alert('يرجى كتابة تفاصيل المخالفة'); return; }
        if (!currentBadgeId) { alert('لم يتم تحديد المشارك'); return; }
        
        try {
            const formData = new FormData();
            formData.append('badge_id', currentBadgeId);
            formData.append('note_text', text);
            formData.append('note_type', mapped.note_type);
            formData.append('priority', mapped.priority);
            formData.append('device', 'rounds_scanner');
            
            const resp = await fetch('../add_note.php', { method: 'POST', body: formData });
            const result = await resp.json();
            
            if (result.success) {
                alert('تم حفظ المخالفة بنجاح ✅');
                closeViolationModal();
            } else {
                alert('خطأ: ' + (result.message || result.error_key || 'فشل الحفظ'));
            }
        } catch (e) {
            alert('خطأ في الاتصال بالسيرفر');
        }
    }
    
    // Search Listeners
    document.getElementById('modalSearch').addEventListener('input', function() {
        const val = this.value.toLowerCase();
        document.querySelectorAll('.modal-list-item').forEach(el => {
            const searchData = (el.getAttribute('data-search') || '').toLowerCase();
            el.style.display = searchData.includes(val) ? 'flex' : 'none';
        });
    });
    
    document.getElementById('activitySearch').addEventListener('input', function() {
        const val = this.value.toLowerCase();
        document.querySelectorAll('.log-list-item').forEach(el => {
            const searchData = (el.getAttribute('data-search') || '').toLowerCase();
            el.style.display = searchData.includes(val) ? 'flex' : 'none';
        });
    });
    </script>
</body>
</html>


