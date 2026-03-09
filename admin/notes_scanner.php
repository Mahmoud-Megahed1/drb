<?php
/**
 * Notes Scanner Page
 * QR Scanner for adding participant notes
 * Version: 2.0 (Modern Dark Mode)
 */

require_once '../include/db.php';
require_once '../include/auth.php';
require_once '../include/helpers.php';

requireAuth('../notes_gate.php');

if (!hasPermission('notes')) {
    header('Location: ../notes_gate.php');
    exit;
}

$user = getCurrentUser();

$dataFile = __DIR__ . '/data/data.json';
$membersFile = __DIR__ . '/data/members.json';

$membersData = file_exists($membersFile) ? json_decode(file_get_contents($membersFile), true) ?? [] : [];
$allData = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) ?? [] : [];

$participantsList = [];
$typeTranslations = [
    'المشاركة بالاستعراض الحر' => 'استعراض حر',
    'free_show' => 'استعراض حر',
    'show' => 'عرض',
    'organization' => 'تنظيم',
    'sponsor' => 'راعي'
];

foreach ($allData as $reg) {
    if (($reg['status'] ?? '') !== 'approved') {
        continue;
    }

    $code = $reg['registration_code'] ?? $reg['wasel'] ?? '';
    $m = $membersData[$code] ?? [];
    
    $pType = $reg['participation_type'] ?? '';
    $pTypeLabel = $typeTranslations[$pType] ?? $pType;

    $plateGov = $reg['plate_governorate'] ?? $m['plate_governorate'] ?? '';
    $plateLetter = $reg['plate_letter'] ?? $m['plate_letter'] ?? '';
    $plateNumber = $reg['plate_number'] ?? $m['plate_number'] ?? '';
    
    // Some JSON elements might have full plate string instead of separate components
    if (empty($plateGov) && !empty($reg['plate_full'])) {
        $plateParts = explode('-', $reg['plate_full']);
        if (count($plateParts) >= 2) {
            $plateGov = trim($plateParts[0]);
            $plateNumber = trim($plateParts[1]);
        } else {
            $plateNumber = $reg['plate_full'];
        }
    } else if (empty($plateGov) && !empty($m['plate'])) {
        $plateParts = explode('-', $m['plate']);
        if (count($plateParts) >= 2) {
            $plateGov = trim($plateParts[0]);
            $plateNumber = trim($plateParts[1]);
        } else {
            $plateNumber = $m['plate'];
        }
    }

    $participantsList[] = [
        'wasel' => $reg['wasel'] ?? $m['wasel'] ?? '',
        'badge_id' => $reg['badge_token'] ?? $reg['badge_id'] ?? $m['badge_token'] ?? $code,
        'participant_name' => $reg['full_name'] ?? $m['name'] ?? '',
        'phone' => $reg['phone'] ?? $m['phone'] ?? '',
        'car_type' => $reg['car_type'] ?? $m['car'] ?? '',
        'car_color' => $reg['car_color'] ?? $m['car_color'] ?? '',
        'plate_governorate' => $plateGov,
        'plate_letter' => $plateLetter,
        'plate_number' => $plateNumber,
        'has_entered' => ($reg['has_entered'] ?? false) ? 1 : 0,
        'participation_type' => $pType,
        'participation_type_label' => $pTypeLabel
    ];
}

// Sort by Wasel
usort($participantsList, function($a, $b) {
    return intval($a['wasel']) - intval($b['wasel']);
});
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>📝 كاميرا الملاحظات</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            min-height: 100vh;
            color: #fff;
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
            background: #9b59b6;
            color: white;
            font-family: inherit;
            cursor: pointer;
        }
        
        /* Participant Info */
        .participant-card {
            display: none;
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .participant-card.visible { display: block; }
        .participant-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .participant-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .participant-info h3 { font-size: 18px; margin-bottom: 5px; }
        .participant-info p { font-size: 14px; opacity: 0.8; }
        
        /* Notes List */
        .notes-list {
            max-height: 200px;
            overflow-y: auto;
            margin-bottom: 15px;
        }
        .note-item {
            background: rgba(0,0,0,0.2);
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 8px;
            border-right: 4px solid;
        }
        .note-item.info { border-color: #17a2b8; }
        .note-item.warning { border-color: #ffc107; }
        .note-item.blocker { border-color: #dc3545; }
        .note-item .note-header {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            opacity: 0.7;
            margin-bottom: 5px;
        }
        .note-item .note-text { font-size: 14px; }
        
        /* Note Form */
        .note-form {
            background: rgba(0,0,0,0.2);
            padding: 15px;
            border-radius: 12px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            opacity: 0.8;
        }
        
        /* Note Type Selector */
        .type-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        .type-btn {
            padding: 12px;
            border: 2px solid #444;
            border-radius: 10px;
            background: transparent;
            color: #fff;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
        }
        .type-btn.selected { border-color: #9b59b6; background: rgba(155,89,182,0.2); }
        .type-btn.info.selected { border-color: #17a2b8; background: rgba(23,162,184,0.2); }
        .type-btn.warning.selected { border-color: #ffc107; background: rgba(255,193,7,0.2); }
        .type-btn.blocker.selected { border-color: #dc3545; background: rgba(220,53,69,0.2); }
        
        /* Priority */
        .priority-selector {
            display: flex;
            gap: 15px;
        }
        .priority-selector label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }
        .priority-selector input { width: 18px; height: 18px; }
        
        /* Visibility */
        .visibility-checks {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        .visibility-checks label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .visibility-checks input { width: 18px; height: 18px; }
        
        /* Text Area */
        .note-textarea {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
        }
        
        /* Submit Button */
        .btn-submit {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
            color: white;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            font-family: inherit;
            margin-top: 15px;
        }
        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Toast */
        .toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 15px 25px;
            border-radius: 10px;
            font-weight: bold;
            display: none;
            z-index: 1000;
        }
        .toast.success { background: #28a745; }
        .toast.error { background: #dc3545; }
        .toast.visible { display: block; }
        
        /* Participant List UI */
        .list-container {
            background: rgba(0,0,0,0.3);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }
        .search-bar {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-family: inherit;
            margin-bottom: 15px;
            text-align: right;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .tab-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-family: inherit;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .tab-btn.active {
            background: #007bff;
            font-weight: bold;
        }
        
        .plist {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 500px;
            overflow-y: auto;
        }
        .pitem {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .pitem-info {
            flex: 1;
        }
        .pitem-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
            color: #fff;
            text-decoration: none;
        }
        .pitem-details {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 2px;
        }
        .pitem-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }
        .status-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-in { background: rgba(40, 167, 69, 0.2); color: #28a745; border: 1px solid #28a745; }
        .status-out { background: rgba(255, 193, 7, 0.2); color: #ffc107; border: 1px solid #ffc107; }
        
        .action-link {
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 14px;
            text-decoration: none;
            color: white;
            font-weight: bold;
        }
        .btn-profile-mini { background: #007bff; }
        .btn-violate-mini { background: #dc3545; border: none; cursor: pointer; font-family: inherit; }

        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fa-solid fa-pen-to-square"></i> كاميرا الملاحظات</h1>
        <div>
            <span style="opacity:0.7; font-size:12px; margin-right: 10px;"><i class="fa-solid fa-mobile-screen"></i> <?= htmlspecialchars($user['device'] ?? '') ?></span>
            <a href="../notes_gate.php?logout=1"><i class="fa-solid fa-right-from-bracket"></i> خروج</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Scanner -->
        <div class="scanner-container" id="scannerSection">
            <div id="qr-reader"></div>
            <div class="manual-input">
                <input type="text" id="manualCode" placeholder="أو أدخل الكود يدوياً">
                <button onclick="lookupParticipant()"><i class="fa-solid fa-search"></i></button>
            </div>
        </div>
        
        <!-- Participant Card -->
        <div class="participant-card" id="participantCard">
            <div class="participant-header">
                <div class="participant-avatar" id="participantAvatar"><i class="fa-solid fa-user"></i></div>
                <div class="participant-info" style="flex:1">
                    <h3 id="participantName">اسم المشارك</h3>
                    <p id="participantDetails">السيارة | اللوحة</p>
                </div>
                <a href="#" id="profileLink" target="_blank" class="profile-btn" style="display:none; background:linear-gradient(135deg,#007bff,#0056b3); color:#fff; padding:10px 15px; border-radius:10px; text-decoration:none; font-size:14px;">
                    <i class="fa-solid fa-user-circle"></i> الملف الشخصي
                </a>
            </div>
            
            <!-- Existing Notes -->
            <div class="notes-list" id="notesList"></div>
            
            <!-- Add Note Form -->
            <div class="note-form" id="noteFormContainer">
                <div class="form-group">
                    <label><i class="fa-solid fa-tag"></i> نوع الملاحظة</label>
                    <div class="type-selector">
                        <button type="button" class="type-btn info selected" data-type="info"><i class="fa-solid fa-info-circle"></i> معلومة</button>
                        <button type="button" class="type-btn warning" data-type="warning"><i class="fa-solid fa-exclamation-triangle"></i> تحذير</button>
                        <button type="button" class="type-btn blocker" data-type="blocker"><i class="fa-solid fa-ban"></i> مانع</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fa-solid fa-sort-amount-up"></i> الأولوية</label>
                    <div class="priority-selector">
                        <label><input type="radio" name="priority" value="low" checked> عادي</label>
                        <label><input type="radio" name="priority" value="medium"> متوسط</label>
                        <label><input type="radio" name="priority" value="high"> مهم</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fa-solid fa-eye"></i> الظهور في</label>
                    <div class="visibility-checks">
                        <label><input type="checkbox" value="all" checked> الكل</label>
                        <label><input type="checkbox" value="main_gate"> البوابة الرئيسية</label>
                        <label><input type="checkbox" value="rounds_gate"> بوابة الجولات</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fa-solid fa-pen"></i> نص الملاحظة</label>
                    <textarea class="note-textarea" id="noteText" placeholder="اكتب الملاحظة هنا..." maxlength="500"></textarea>
                </div>
                
                <button class="btn-submit" id="btnSubmit" onclick="submitNote()"><i class="fa-solid fa-save"></i> حفظ الملاحظة</button>
            </div>
        </div>
        
        <!-- Participants List Module -->
        <div class="list-container">
            <h3 style="margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px;">
                <i class="fa-solid fa-users"></i> قائمة المشاركين
            </h3>
            
            <input type="text" id="pSearch" class="search-bar" placeholder="بحث بالاسم أو السيارة أو اللوحة أو الهاتف...">
            
            <div class="tabs">
                <button class="tab-btn active" data-filter="all">الكل</button>
                <button class="tab-btn" data-filter="in">دخلوا</button>
                <button class="tab-btn" data-filter="out">لم يدخلوا</button>
            </div>
            
            <div class="plist" id="pList">
                <!-- JS will inject here -->
            </div>
        </div>
    </div>
    
    <div class="toast" id="toast"></div>
    
    <script src="../js/html5-qrcode.min.js"></script>
    <script>
    // State
    let currentParticipant = null;
    let selectedType = 'info';
    let html5QrCode = null;
    const DEVICE_ID = '<?= htmlspecialchars($user['device'] ?? 'unknown') ?>';
    
    // Type selection
    document.querySelectorAll('.type-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('selected'));
            this.classList.add('selected');
            selectedType = this.dataset.type;
        });
    });
    
    // Lookup participant
    async function lookupParticipant(badgeId) {
        const code = badgeId || document.getElementById('manualCode').value.trim();
        if (!code) return;
        
        try {
            const resp = await fetch(`../get_notes.php?badge_id=${encodeURIComponent(code)}`);
            const data = await resp.json();
            
            if (data.success) {
                // Use member object from response if available
                const participant = data.member ? {
                    name: data.member.name,
                    wasel: data.member.wasel,
                    car: data.member.car,
                    plate: data.member.plate,
                    badge_id: data.member.badge_id || code
                } : {
                    name: 'مشارك',
                    badge_id: code
                };
                
                showParticipant(participant, data.notes || []);
            } else {
                showToast('المشارك غير موجود', 'error');
            }
        } catch (e) {
            showToast('خطأ في الاتصال', 'error');
        }
    }
    
    // Show participant
    function showParticipant(participant, notes) {
        currentParticipant = participant;
        
        document.getElementById('participantCard').classList.add('visible');
        document.getElementById('participantName').textContent = participant.name + (participant.wasel ? ` #${participant.wasel}` : '');
        document.getElementById('participantDetails').textContent = `${participant.car || ''} | ${participant.plate || ''}`;
        
        // Show profile link
        const profileLink = document.getElementById('profileLink');
        profileLink.href = 'member_details.php?id=' + encodeURIComponent(participant.badge_id);
        profileLink.style.display = 'inline-block';
        
        // Show existing notes
        const notesList = document.getElementById('notesList');
        if (notes.length > 0) {
            notesList.innerHTML = notes.map(n => `
                <div class="note-item ${n.note_type}">
                    <div class="note-header">
                        <span>${n.type_icon} ${n.type_label || n.note_type}</span>
                        <span>${new Date(n.created_at).toLocaleString('ar')}</span>
                    </div>
                    <div class="note-text">${n.note_text}</div>
                </div>
            `).join('');
        } else {
            notesList.innerHTML = '<p style="text-align:center; opacity:0.5;">لا توجد ملاحظات سابقة</p>';
        }
        
        // Scroll to form
        document.getElementById('participantCard').scrollIntoView({ behavior: 'smooth' });
    }
    
    // Submit note
    async function submitNote() {
        if (!currentParticipant) {
            showToast('اختر مشارك أولاً', 'error');
            return;
        }
        
        const noteText = document.getElementById('noteText').value.trim();
        if (noteText.length < 3) {
            showToast('الملاحظة قصيرة جداً', 'error');
            return;
        }
        
        const priority = document.querySelector('input[name="priority"]:checked').value;
        const visibilityChecks = document.querySelectorAll('.visibility-checks input:checked');
        const visibility = Array.from(visibilityChecks).map(c => c.value);
        
        document.getElementById('btnSubmit').disabled = true;
        
        try {
            const formData = new FormData();
            formData.append('badge_id', currentParticipant.badge_id);
            formData.append('note_text', noteText);
            formData.append('note_type', selectedType);
            formData.append('priority', priority);
            formData.append('visibility', JSON.stringify(visibility));
            formData.append('device', DEVICE_ID);
            
            const resp = await fetch('../add_note.php', {
                method: 'POST',
                body: formData
            });
            
            const responseText = await resp.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch(e) {
                showToast('خطأ في الاستجابة: ' + responseText.substring(0, 50), 'error');
                document.getElementById('btnSubmit').disabled = false;
                return;
            }
            
            if (result.success) {
                showToast('تم حفظ الملاحظة <i class="fa-solid fa-check"></i>', 'success');
                document.getElementById('noteText').value = '';
                // Refresh notes
                lookupParticipant(currentParticipant.badge_id);
            } else {
                showToast(result.error || result.msg || result.message || 'خطأ غير معروف', 'error');
            }
        } catch (e) {
            showToast('خطأ في الاتصال: ' + e.message, 'error');
        }
        
        document.getElementById('btnSubmit').disabled = false;
    }
    
    // Toast
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = 'toast ' + type + ' visible';
        setTimeout(() => toast.classList.remove('visible'), 3000);
    }
    
    // Handle scan
    function handleScan(code) {
        let badgeId = code;
        // Match token=... or badge_id=...
        const match = code.match(/[?&](token|badge_id)=([^&]+)/);
        if (match) badgeId = match[2];
        
        document.getElementById('manualCode').value = badgeId;
        lookupParticipant(badgeId);
    }
    
    // Initialize scanner
    async function initScanner() {
        try {
            html5QrCode = new Html5Qrcode("qr-reader");
            await html5QrCode.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                handleScan,
                () => {}
            );
        } catch (e) {
            console.error('Scanner error:', e);
        }
    }
    
    // Enter key
    document.getElementById('manualCode').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') lookupParticipant();
    });
    
    // --- Participants List Logic ---
    const pData = <?= json_encode($participantsList) ?>;
    let currentFilter = 'all';

    function renderParticipants() {
        const query = (document.getElementById('pSearch').value || '').toLowerCase();
        const listDiv = document.getElementById('pList');
        
        const filtered = pData.filter(p => {
            // Filter Tabs
            if (currentFilter === 'in' && p.has_entered == 0) return false;
            if (currentFilter === 'out' && p.has_entered > 0) return false;
            
            // Filter Search Text
            if (!query) return true;
            return (p.participant_name && p.participant_name.toLowerCase().includes(query)) ||
                   (p.car_type && p.car_type.toLowerCase().includes(query)) ||
                   (p.plate_number && p.plate_number.toLowerCase().includes(query)) ||
                   (p.phone && p.phone.toLowerCase().includes(query)) ||
                   (p.badge_id && p.badge_id.toLowerCase().includes(query));
        });
        
        if (filtered.length === 0) {
            listDiv.innerHTML = '<div style="text-align:center; padding:20px; opacity:0.6;">لا توجد نتائج مطابقة</div>';
            return;
        }
        
        listDiv.innerHTML = filtered.map(p => {
            const statusHtml = p.has_entered > 0 
                ? '<span class="status-badge status-in"><i class="fa-solid fa-check"></i> دخلوا</span>' 
                : '<span class="status-badge status-out"><i class="fa-solid fa-hourglass-half"></i> لم يدخل</span>';
            
            const plateStr = [p.plate_governorate, p.plate_letter, p.plate_number].filter(Boolean).join(' - ');
                
            return `
                <div class="pitem">
                    <div class="pitem-info">
                        <div class="pitem-name">#${p.wasel} - ${p.participant_name}</div>
                        <div class="pitem-details">${p.car_type || '-'} | ${p.car_color || ''}</div>
                        <div class="pitem-details" dir="ltr" style="text-align:right;">${plateStr}</div>
                        <div class="pitem-details" style="color:#9b59b6; font-size:12px; font-weight:bold; margin-top:4px;">${p.participation_type_label || ''}</div>
                    </div>
                    <div class="pitem-actions">
                        ${statusHtml}
                        <div style="display:flex; gap:5px; margin-top:5px;">
                            <button class="action-link btn-violate-mini" onclick="openFastViolation('${p.badge_id}')">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </button>
                            <a href="member_details.php?id=${p.badge_id}" target="_blank" class="action-link btn-profile-mini">
                                <i class="fa-solid fa-user"></i> الملف
                            </a>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    document.getElementById('pSearch').addEventListener('input', renderParticipants);

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            renderParticipants();
        });
    });

    function openFastViolation(badgeId) {
        document.getElementById('manualCode').value = badgeId;
        lookupParticipant(badgeId);
        
        // Wait 500ms for participant to load, then scroll to form
        setTimeout(() => {
            const formObj = document.getElementById('noteFormContainer');
            if (formObj) {
                formObj.scrollIntoView({ behavior: 'smooth', block: 'center' });
                formObj.style.boxShadow = '0 0 20px rgba(155, 89, 182, 0.8)';
                formObj.style.transition = 'box-shadow 0.5s';
                setTimeout(() => { formObj.style.boxShadow = 'none'; }, 2000);
            }
        }, 500);
    }
    
    // Init Scanner and List
    initScanner();
    renderParticipants();
    </script>
</body>
</html>

