<?php
/**
 * Main QR Scanner
 * Scans participant badge and redirects to details page
 * Version: 2.0 (Modern Dark Mode)
 */

require_once '../include/auth.php';

// Auth check - allow any admin/staff with basic permissions
if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>📷 الماسح الرئيسي</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            min-height: 100vh;
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .header {
            width: 100%;
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
        
        .container { padding: 20px; width: 100%; max-width: 600px; margin-top: 20px; }
        
        /* Scanner */
        .scanner-box {
            background: rgba(0,0,0,0.3);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            position: relative;
        }
        #qr-reader {
            width: 100%;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid rgba(255,255,255,0.1);
        }
        
        .manual-input {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .manual-input input {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-family: inherit;
            text-align: center;
            background: rgba(255,255,255,0.9);
            color: #000;
        }
        .manual-input button {
            padding: 15px 25px;
            border: none;
            border-radius: 10px;
            background: #27ae60;
            color: white;
            font-family: inherit;
            cursor: pointer;
            font-size: 18px;
        }
        
        /* Result Overlay */
        .result-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.9);
            display: none;
            flex-direction: column;
            align-items: center; 
            justify-content: center;
            z-index: 2000;
            text-align: center;
            padding: 20px;
        }
        .result-overlay.visible { display: flex; }
        
        .result-icon { font-size: 80px; margin-bottom: 20px; }
        .result-title { font-size: 32px; font-weight: bold; margin-bottom: 10px; }
        .result-desc { font-size: 18px; opacity: 0.8; margin-bottom: 30px; line-height: 1.6; }
        
        .action-btn {
            padding: 15px 40px;
            border-radius: 50px;
            border: none;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            margin: 10px;
            font-family: inherit;
            text-decoration: none;
            display: inline-block;
            color: white;
            transition: transform 0.2s;
        }
        .action-btn:active { transform: scale(0.95); }
        .btn-green { background: #27ae60; }
        .btn-blue { background: #2980b9; }
        .btn-red { background: #c0392b; }
        
        .warning-box {
            background: rgba(231, 76, 60, 0.2);
            border: 2px solid #e74c3c;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            width: 100%;
            max-width: 500px;
            text-align: right;
        }
        .warning-title { color: #e74c3c; font-weight: bold; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
        
        .success-bg { background: rgba(39, 174, 96, 0.95); }
        .error-bg { background: rgba(192, 57, 43, 0.95); }
        .warning-bg { background: rgba(243, 156, 18, 0.95); color: #000; }
        
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
        .modal-box h3 {
            margin-bottom: 15px;
            color: #c0392b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-box textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            font-size: 16px;
            min-height: 100px;
            resize: vertical;
        }
        .modal-box select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            font-size: 16px;
            margin-top: 10px;
            background: #fff;
        }
        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .modal-buttons button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 16px;
            cursor: pointer;
        }
        .btn-submit { background: #c0392b; color: #fff; }
        .btn-cancel { background: #bdc3c7; color: #333; }
        
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fa-solid fa-qrcode"></i> الماسح الرئيسي</h1>
        
    </div>
    
    <div class="container">
        <div class="scanner-box">
            <div id="qr-reader"></div>
            
            <div class="manual-input">
                <input type="text" id="manualCode" placeholder="رقم البادج أو السيارة">
                <button onclick="handleManual()"><i class="fa-solid fa-search"></i></button>
            </div>
            
            <div class="instructions">
                <p>وجه الكاميرا نحو رمز QR الخاص بالمشارك</p>
                <p>أو أدخل رقم البادج يدوياً</p>
            </div>
        </div>
    </div>
    
    <div class="result-overlay" id="resultOverlay">
        <i class="fa-solid fa-check-circle result-icon" id="resultIcon"></i>
        <div class="result-title" id="resultTitle">تم الدخول بنجاح</div>
        <div class="result-desc" id="resultDesc">
            محمد أحمد<br>
            تويوتا كامري - أبيض
        </div>
        
        <div class="warning-box" id="warningBox" style="display:none;">
            <div class="warning-title"><i class="fa-solid fa-triangle-exclamation"></i> تحذيرات سابقة</div>
            <div id="warningList"></div>
        </div>
        
        <div style="display:flex; flex-wrap:wrap; justify-content:center;">
            <button class="action-btn btn-green" onclick="continueScanning()">
                <i class="fa-solid fa-camera"></i> متابعة المسح
            </button>
            <button class="action-btn btn-red" onclick="openViolationModal()">
                <i class="fa-solid fa-triangle-exclamation"></i> إضافة مخالفة
            </button>
            <a href="#" id="profileLink" class="action-btn btn-blue">
                <i class="fa-solid fa-user"></i> الملف الشخصي
            </a>
        </div>
    </div>
    
    <div class="loading-overlay" id="loader">
        <div class="spinner"></div>
        <div class="loading-text">جاري التحقق...</div>
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
    
    <script src="../js/html5-qrcode.min.js"></script>
    <script>
    let html5QrCode = null;
    let isProcessing = false;
    let lastCode = '';
    
    // Check Entry Logic
    async function handleScan(decodedText) {
        if (isProcessing) return;
        
        // Prevent rapid duplicate scans
        if (decodedText === lastCode && (Date.now() - lastScanTime < 3000)) return;
        
        isProcessing = true;
        lastCode = decodedText;
        lastScanTime = Date.now();
        
        // Extract ID
        let code = decodedText;
        const match = decodedText.match(/[?&](token|badge_id|id)=([^&]+)/);
        if (match) code = match[2];
        
        // Clean URL if full url
        if (code.includes('http')) {
             try {
                 const url = new URL(code);
                 const params = new URLSearchParams(url.search);
                 if (params.get('id')) code = params.get('id');
                 else if (params.get('token')) code = params.get('token');
                 else if (params.get('badge_id')) code = params.get('badge_id');
             } catch(e) {}
        }
        
        showLoader();
        
        try {
            const resp = await fetch(`../verify_entry.php?action=checkin&badge_id=${encodeURIComponent(code)}`);
            const data = await resp.json();
            
            if (data.success) {
                showResult(data);
            } else {
                showError(data.message);
            }
        } catch (e) {
            showError('خطأ في الاتصال بالسيرفر');
        }
        
        hideLoader();
    }
    
    function showResult(data) {
        const overlay = document.getElementById('resultOverlay');
        const icon = document.getElementById('resultIcon');
        const title = document.getElementById('resultTitle');
        const desc = document.getElementById('resultDesc');
        
        // Store for violation modal
        currentBadgeId = data.permanent_id || data.wasel || lastCode;
        currentParticipantName = data.name || '';
        const warningBox = document.getElementById('warningBox');
        const warningList = document.getElementById('warningList');
        const profileLink = document.getElementById('profileLink');
        
        // Reset classes
        overlay.classList.remove('success-bg', 'error-bg', 'warning-bg');
        
        // Setup Profile Link
        profileLink.href = `member_details.php?id=${data.permanent_id || data.wasel}`;
        profileLink.removeAttribute('target'); // Ensure it opens in same window
        
        // Warnings - Restored as per user request
        if (data.warnings && data.warnings.length > 0) {
            warningBox.style.display = 'block'; 
            warningBox.className = 'warning-box';
            warningBox.style.borderColor = '#e74c3c';
            warningBox.style.background = 'rgba(231, 76, 60, 0.2)';
            warningList.innerHTML = '<div class="warning-title" style="color:#e74c3c"><i class="fa-solid fa-triangle-exclamation"></i> تحذيرات سابقة</div>' + 
                                  data.warnings.map(w => `<div>• ${w.text}</div>`).join('');
            playSound('warning');
        } else {
            // No warnings
            warningBox.style.display = 'none'; 
            warningList.innerHTML = '';
        }

        if (data.status === 'checked_in') {
            overlay.classList.add('success-bg');
            icon.className = 'fa-solid fa-check-circle result-icon';
            title.textContent = 'تم الدخول بنجاح';
            desc.innerHTML = `
                <div style="font-size:24px;font-weight:bold">${data.name}</div>
                <div>${data.car} | ${data.plate}</div>
                <div style="margin-top:10px;font-size:14px">#${data.wasel}</div>
                <div style="margin-top:5px;padding-top:5px;border-top:1px solid rgba(255,255,255,0.2);">
                    ${data.assigned_time ? '<div style="margin-bottom:8px;padding:8px;background:rgba(255,193,7,0.2);border-radius:8px;"><i class="fa-solid fa-clock"></i> <strong>موعد الدخول: </strong><span style="font-size:20px;color:#ffc107;font-weight:bold;">' + data.assigned_time + '</span></div>' : ''}
                </div>
            `;
            playSound('success');
        } else if (data.status === 'already_entered') {
            overlay.classList.add('error-bg'); // Red for duplicate
            icon.className = 'fa-solid fa-hand-paper result-icon';
            title.textContent = 'تم الدخول مسبقاً!';
            desc.innerHTML = `
                <div style="font-size:24px;font-weight:bold">${data.name}</div>
                <div style="margin-top:10px">وقت الدخول: ${data.entry_time}</div>
                <div style="font-size:14px">من قبل: ${data.entered_by}</div>
                <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.2);">
                    ${data.assigned_time ? '<div style="margin-bottom:8px;padding:8px;background:rgba(255,193,7,0.2);border-radius:8px;"><i class="fa-solid fa-clock"></i> <strong>موعد الدخول الأصلي: </strong><span style="font-size:20px;color:#ffc107;font-weight:bold;">' + data.assigned_time + '</span></div>' : ''}
                </div>
            `;
            playSound('error');
        } else if (data.status === 'not_found') {
            overlay.classList.add('error-bg');
            icon.className = 'fa-solid fa-times-circle result-icon';
            title.textContent = 'غير مسجل';
            desc.textContent = 'هذا البادج غير مسجل في النظام.';
            playSound('error');
        } else if (data.status === 'blocked') {
            overlay.classList.add('error-bg');
            icon.className = 'fa-solid fa-ban result-icon';
            title.textContent = 'ممنوع الدخول';
            desc.innerHTML = `
                <div style="font-size:24px;font-weight:bold">${data.name}</div>
                <div style="margin-top:10px;font-size:14px">هذا العضو ممنوع من الدخول.</div>
                <div style="margin-top:5px;padding-top:5px;border-top:1px solid rgba(255,255,255,0.2);">
                    ${data.assigned_time ? '<div style="margin-bottom:8px;padding:8px;background:rgba(255,193,7,0.2);border-radius:8px;"><i class="fa-solid fa-clock"></i> <strong>موعد الدخول: </strong><span style="font-size:20px;color:#ffc107;font-weight:bold;">' + data.assigned_time + '</span></div>' : ''}
                </div>
            `;
            playSound('error');
        } else if (data.status === 'warning') {
            overlay.classList.add('warning-bg');
            icon.className = 'fa-solid fa-exclamation-triangle result-icon';
            title.textContent = 'تحذير';
            desc.innerHTML = `
                <div style="font-size:24px;font-weight:bold">${data.name}</div>
                <div style="margin-top:10px;font-size:14px">${data.message || 'يرجى مراجعة المشرف.'}</div>
                <div style="margin-top:5px;padding-top:5px;border-top:1px solid rgba(255,255,255,0.2);">
                    ${data.assigned_time ? '<div style="margin-bottom:8px;padding:8px;background:rgba(255,193,7,0.2);border-radius:8px;"><i class="fa-solid fa-clock"></i> <strong>موعد الدخول: </strong><span style="font-size:20px;color:#ffc107;font-weight:bold;">' + data.assigned_time + '</span></div>' : ''}
                </div>
            `;
            playSound('warning');
        } else {
            // Generic error or unknown status
            overlay.classList.add('error-bg');
            icon.className = 'fa-solid fa-times-circle result-icon';
            title.textContent = 'خطأ';
            desc.textContent = data.message || 'حدث خطأ غير معروف.';
            playSound('error');
        }
        
        overlay.classList.add('visible');
        isProcessing = false;
    }
    
    function showError(msg) {
        const overlay = document.getElementById('resultOverlay');
        overlay.classList.remove('success-bg', 'warning-bg');
        overlay.classList.add('error-bg');
        
        document.getElementById('resultIcon').className = 'fa-solid fa-times-circle result-icon';
        document.getElementById('resultTitle').textContent = 'خطأ';
        document.getElementById('resultDesc').textContent = msg;
        document.getElementById('warningBox').style.display = 'none';
        
        overlay.classList.add('visible');
        playSound('error');
        
        isProcessing = false; // Allow retry immediately
    }
    
    function continueScanning() {
        document.getElementById('resultOverlay').classList.remove('visible');
        isProcessing = false; 
    }
    
    function handleManual() {
        const input = document.getElementById('manualCode');
        const code = input.value.trim();
        if (code) handleScan(code);
    }
    
    // UI Helpers
    function showLoader() { document.getElementById('loader').classList.add('visible'); }
    function hideLoader() { document.getElementById('loader').classList.remove('visible'); }
    let lastScanTime = 0;
    
    function playSound(type) {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            
            if (type === 'success') {
                osc.frequency.value = 800;
                gain.gain.value = 0.2;
                osc.start();
                osc.stop(ctx.currentTime + 0.1);
            } else if (type === 'error') {
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(150, ctx.currentTime);
                osc.frequency.linearRampToValueAtTime(100, ctx.currentTime + 0.3);
                gain.gain.value = 0.3;
                osc.start();
                osc.stop(ctx.currentTime + 0.3);
            } else if (type === 'warning') {
                osc.type = 'square';
                osc.frequency.value = 400;
                // Beep Beep
                gain.gain.value = 0.1;
                osc.start();
                osc.stop(ctx.currentTime + 0.1);
                
                setTimeout(() => {
                    const osc2 = ctx.createOscillator();
                    const gain2 = ctx.createGain();
                    osc2.connect(gain2);
                    gain2.connect(ctx.destination);
                    osc2.type = 'square';
                    osc2.frequency.value = 400;
                    gain2.gain.value = 0.1;
                    osc2.start();
                    osc2.stop(ctx.currentTime + 0.1);
                }, 150);
            }
        } catch (e) {}
    }
    
    // Init Scanner
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
            document.querySelector('.instructions').innerHTML += '<br><span style="color:red">تعذر تشغيل الكاميرا</span>';
        }
    }
    
    document.getElementById('manualCode').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') handleManual();
    });
    
    initScanner();
    
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
        
        if (!text) {
            alert('يرجى كتابة تفاصيل المخالفة');
            return;
        }
        
        if (!currentBadgeId) {
            alert('لم يتم تحديد المشارك');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('badge_id', currentBadgeId);
            formData.append('note_text', text);
            formData.append('note_type', mapped.note_type);
            formData.append('priority', mapped.priority);
            formData.append('device', 'qr_scanner');
            
            const resp = await fetch('../add_note.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            const responseText = await resp.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch(e) {
                alert('خطأ في الاستجابة: ' + responseText.substring(0, 100));
                return;
            }
            
            if (result.success) {
                alert('تم حفظ المخالفة بنجاح ✅');
                closeViolationModal();
            } else {
                alert('خطأ: ' + (result.message || result.error || result.error_key || 'فشل الحفظ'));
            }
        } catch (e) {
            alert('خطأ في الاتصال بالسيرفر: ' + e.message);
        }
    }
    </script>
</body>
</html>


