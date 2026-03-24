<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}
$currentPage = 'pending_messages';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>الرسائل المعلقة - WhatsApp Queue v2.0</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; }
        .status-banner {
            padding: 15px 20px; border-radius: 10px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status-connected { background: linear-gradient(135deg, #d4edda, #c3e6cb); border: 2px solid #28a745; }
        .status-disconnected { background: linear-gradient(135deg, #f8d7da, #f5c6cb); border: 2px solid #dc3545; }
        .status-unknown { background: linear-gradient(135deg, #fff3cd, #ffeaa7); border: 2px solid #ffc107; }
        .status-dot {
            width: 16px; height: 16px; border-radius: 50%;
            display: inline-block; animation: pulse 2s infinite;
        }
        .status-dot.green { background: #28a745; }
        .status-dot.red { background: #dc3545; }
        .status-dot.yellow { background: #ffc107; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        .msg-card {
            background: #fff; border-radius: 10px; padding: 15px; margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-right: 4px solid #dc3545;
            transition: all 0.3s;
        }
        .msg-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .msg-card.status-sent { border-right-color: #28a745; opacity: 0.7; }
        .msg-card.status-sending { border-right-color: #17a2b8; }
        .msg-card.status-failed { border-right-color: #fd7e14; }
        .msg-card.status-failed_permanent { border-right-color: #333; }
        .msg-card .msg-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;
        }
        .msg-card .msg-name { font-weight: bold; font-size: 15px; }
        .msg-card .msg-phone { direction: ltr; color: #555; font-family: monospace; }
        .msg-card .msg-error { 
            color: #dc3545; font-size: 12px; margin-top: 5px; 
            background: #fff5f5; padding: 6px 10px; border-radius: 6px; border: 1px solid #f5c6cb;
        }
        .msg-card .msg-preview { 
            background: #f8f9fa; padding: 8px 12px; border-radius: 8px;
            font-size: 13px; color: #333; margin: 8px 0;
            border-right: 3px solid #17a2b8; max-height: 80px; overflow-y: auto;
        }
        .msg-card .msg-meta { font-size: 11px; color: #999; }
        .msg-card .msg-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .msg-card .msg-retry-timer { 
            font-size: 11px; color: #fd7e14; font-weight: bold; margin-top: 4px;
        }
        
        /* Status badges */
        .badge-queued { background: #ffc107; color: #333; }
        .badge-sending { background: #17a2b8; }
        .badge-sent { background: #28a745; }
        .badge-failed { background: #fd7e14; }
        .badge-failed_permanent { background: #333; }
        .badge-manual { background: #6f42c1; }
        
        .stat-box {
            text-align: center; padding: 15px; border-radius: 10px;
            color: #fff; margin-bottom: 15px;
        }
        .stat-box h3 { margin: 0; font-size: 30px; font-weight: bold; }
        .stat-box p { margin: 5px 0 0; font-size: 13px; }
        
        .filter-bar {
            background: #fff; padding: 12px 20px; border-radius: 10px;
            margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .empty-state { text-align: center; padding: 50px; color: #999; }
        .empty-state i { font-size: 60px; margin-bottom: 15px; display: block; }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container-fluid">
    <!-- Status Banner -->
    <div id="statusBanner" class="status-banner status-unknown">
        <span class="status-dot yellow" id="statusDot"></span>
        <div>
            <strong id="statusText">جاري الفحص...</strong>
            <div id="statusDetail" style="font-size: 12px; color: #666;"></div>
        </div>
        <div style="margin-right: auto;">
            <button class="btn btn-sm btn-default" onclick="refreshStatus()">
                <i class="fa-solid fa-sync"></i> تحديث
            </button>
        </div>
    </div>
    
    <!-- Stats -->
    <div class="row" id="statsRow">
        <div class="col-md-3">
            <div class="stat-box" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                <h3 id="pendingCount">-</h3>
                <p>📨 رسائل معلقة</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box" style="background: linear-gradient(135deg, #28a745, #20c997);">
                <h3 id="sentCount">-</h3>
                <p>✅ تم إرسالها</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box" style="background: linear-gradient(135deg, #fd7e14, #e8590c);">
                <h3 id="failedCount">-</h3>
                <p>❌ فشلت نهائياً</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box" style="background: linear-gradient(135deg, #6f42c1, #5a32a3);">
                <h3 id="lastSuccess">-</h3>
                <p>🕐 آخر إرسال ناجح</p>
            </div>
        </div>
    </div>

    <!-- Alert Banner (populated from ALERT_STATUS.json) -->
    <div id="alertBanner" style="display:none; margin-bottom:15px;"></div>

    <!-- Monitor Link -->
    <div style="text-align:left; margin-bottom: 10px;">
        <a href="../api/whatsapp_monitor.php" target="_blank" class="btn btn-default btn-sm" style="border-radius:20px;">
            <i class="fa-solid fa-chart-line"></i> لوحة المراقبة
        </a>
    </div>

    <!-- Action Bar -->
    <div class="filter-bar">
        <label style="margin: 0;"><strong>عرض:</strong></label>
        <select id="filterSelect" class="form-control" style="width: 200px;" onchange="loadMessages()">
            <option value="pending">المعلقة فقط</option>
            <option value="failed_permanent">فشلت نهائياً</option>
            <option value="sent">المرسلة</option>
            <option value="all">الكل</option>
        </select>
        <button class="btn btn-success" onclick="retryAll()" id="retryAllBtn">
            <i class="fa-solid fa-rotate"></i> إعادة إرسال الكل
        </button>
        <button class="btn btn-warning" onclick="clearSent()" id="clearSentBtn">
            <i class="fa-solid fa-broom"></i> مسح المرسلة والفاشلة
        </button>
    </div>

    <!-- Messages List -->
    <div id="messagesList">
        <div class="empty-state">
            <i class="fa-solid fa-spinner fa-spin"></i>
            <p>جاري التحميل...</p>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-1.12.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
<script>
let currentMessages = [];

const statusLabels = {
    'queued':           '<span class="label badge-queued">⏳ في الطابور</span>',
    'sending':          '<span class="label badge-sending">📤 جاري الإرسال</span>',
    'sent':             '<span class="label badge-sent">✅ تم الإرسال</span>',
    'failed':           '<span class="label badge-failed">⚠️ فشل (سيعاد)</span>',
    'failed_permanent': '<span class="label badge-failed_permanent">❌ فشل نهائي</span>'
};

const typeLabels = {
    'text': '📝 نص', 'image': '🖼️ صورة', 'document': '📄 مستند',
    'registration_received': '📋 استلام تسجيل', 'acceptance': '✅ قبول',
    'badge': '🎫 باج', 'approval_badge_unified': '✅🎫 موحدة (قبول + باج)', 'rejection': '🔴 رفض', 'qr_only': '📲 QR',
    'activation': '🔑 تفعيل', 'broadcast': '📢 بث عام'
};

async function refreshStatus() {
    try {
        const res = await fetch('../api/whatsapp_health.php?action=status');
        const data = await res.json();
        
        const banner = document.getElementById('statusBanner');
        const dot = document.getElementById('statusDot');
        const text = document.getElementById('statusText');
        const detail = document.getElementById('statusDetail');
        
        banner.className = 'status-banner';
        dot.className = 'status-dot';
        
        if (data.status === 'connected') {
            banner.classList.add('status-connected');
            dot.classList.add('green');
            text.textContent = '🟢 WhatsApp متصل - الرسائل تعمل بشكل طبيعي';
        } else if (data.status === 'disconnected') {
            banner.classList.add('status-disconnected');
            dot.classList.add('red');
            text.textContent = '🔴 WhatsApp غير متصل - الرسائل لا يتم إرسالها';
        } else {
            banner.classList.add('status-unknown');
            dot.classList.add('yellow');
            text.textContent = '🟡 حالة غير معروفة';
        }
        
        let details = [];
        if (data.pending_count > 0) details.push(`${data.pending_count} رسالة معلقة`);
        if (data.last_success) details.push(`آخر نجاح: ${data.last_success}`);
        if (data.error) details.push(`الخطأ: ${data.error}`);
        detail.textContent = details.join(' | ');
        
        document.getElementById('pendingCount').textContent = data.pending_count || 0;
        if (data.last_success) {
            document.getElementById('lastSuccess').textContent = data.last_success.split(' ')[1] || '-';
        }
    } catch (e) {
        console.error('Health check failed:', e);
    }
}

async function loadMessages() {
    const filter = document.getElementById('filterSelect').value;
    try {
        const res = await fetch(`../api/whatsapp_health.php?action=pending&filter=${filter}`);
        const data = await res.json();
        
        currentMessages = data.messages || [];
        
        const pending = currentMessages.filter(m => ['queued','sending','failed'].includes(m.status)).length;
        const sent = currentMessages.filter(m => m.status === 'sent').length;
        const failed = currentMessages.filter(m => m.status === 'failed_permanent').length;
        document.getElementById('pendingCount').textContent = pending;
        document.getElementById('sentCount').textContent = sent;
        document.getElementById('failedCount').textContent = failed;
        
        renderMessages(currentMessages);
    } catch (e) {
        console.error('Load messages failed:', e);
    }
}

function renderMessages(messages) {
    const container = document.getElementById('messagesList');
    
    if (!messages.length) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fa-solid fa-check-circle" style="color: #28a745;"></i>
                <h4>لا توجد رسائل 🎉</h4>
                <p>الطابور فارغ حالياً</p>
            </div>`;
        return;
    }
    
    let html = '';
    messages.forEach(msg => {
        const isSent = msg.status === 'sent';
        const isFailed = msg.status === 'failed_permanent';
        const isQueued = msg.status === 'queued' || msg.status === 'failed';
        const typeLabel = typeLabels[msg.message_type] || typeLabels[msg.type] || msg.message_type || msg.type || 'رسالة';
        const statusLabel = statusLabels[msg.status] || `<span class="label label-default">${msg.status}</span>`;
        const manualBadge = msg.is_manual ? '<span class="label badge-manual">يدوي</span>' : '';
        const waLink = `https://wa.me/${msg.phone}?text=${encodeURIComponent(msg.data?.text || msg.data?.caption || '')}`;
        
        // Retry countdown
        let retryTimer = '';
        if (msg.next_retry_at && isQueued && msg.status === 'queued') {
            const nextRetry = new Date(msg.next_retry_at);
            const now = new Date();
            const diffSec = Math.max(0, Math.round((nextRetry - now) / 1000));
            if (diffSec > 0) {
                const mins = Math.floor(diffSec / 60);
                const secs = diffSec % 60;
                retryTimer = `<div class="msg-retry-timer">⏱️ إعادة المحاولة بعد: ${mins > 0 ? mins + ' دقيقة ' : ''}${secs} ثانية</div>`;
            }
        }
        
        html += `
        <div class="msg-card status-${msg.status}" id="msg_${msg.id}">
            <div class="msg-header">
                <div>
                    <span class="msg-name">👤 ${escapeHtml(msg.name || msg.recipient_name || 'غير محدد')}</span>
                    ${msg.wasel ? `<span class="label label-default" style="margin-right:5px;">وصل: ${msg.wasel}</span>` : ''}
                    <span class="label label-info">${typeLabel}</span>
                    ${statusLabel}
                    ${manualBadge}
                </div>
                <span class="msg-phone">${msg.phone}</span>
            </div>
            ${msg.message_preview ? `<div class="msg-preview">${escapeHtml(msg.message_preview)}...</div>` : ''}
            ${!isSent && msg.error ? `<div class="msg-error"><i class="fa-solid fa-triangle-exclamation"></i> <strong>الخطأ:</strong> ${escapeHtml(msg.error)}</div>` : ''}
            ${retryTimer}
            <div class="msg-meta">
                📅 ${msg.created_at || '-'} | 🔄 محاولات: ${msg.attempts || 0}/${msg.max_attempts || 5}
                ${msg.sent_at ? ` | ✅ أُرسلت: ${msg.sent_at}` : ''}
                ${msg.last_retry ? ` | 🔁 آخر محاولة: ${msg.last_retry}` : ''}
            </div>
            <div class="msg-actions">
                ${isQueued || isFailed ? `
                    <button class="btn btn-success btn-sm" onclick="retryOne('${msg.id}')">
                        <i class="fa-solid fa-paper-plane"></i> إعادة إرسال
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="markSent('${msg.id}')">
                        <i class="fa-solid fa-check-double"></i> تم الإرسال يدوياً
                    </button>
                    <a href="${waLink}" target="_blank" class="btn btn-info btn-sm">
                        <i class="fa-brands fa-whatsapp"></i> فتح واتساب
                    </a>
                    <button class="btn btn-default btn-sm" onclick="copyText('${msg.id}')">
                        <i class="fa-solid fa-copy"></i> نسخ النص
                    </button>
                ` : ''}
                <button class="btn btn-danger btn-xs" onclick="removeMsg('${msg.id}')">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        </div>`;
    });
    
    container.innerHTML = html;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function retryOne(id) {
    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    
    try {
        const res = await fetch('../api/whatsapp_health.php?action=retry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `message_id=${id}`
        });
        const data = await res.json();
        
        if (data.success) {
            btn.innerHTML = '✅ في الطابور';
            btn.className = 'btn btn-success btn-sm';
        } else {
            alert('فشل: ' + (data.error || 'خطأ غير معروف'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إعادة إرسال';
        }
        
        setTimeout(() => { refreshStatus(); loadMessages(); }, 1000);
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إعادة إرسال';
        alert('خطأ في الاتصال');
    }
}

async function markSent(id) {
    if (!confirm('هل تأكدت من إرسال هذه الرسالة يدوياً عبر واتساب؟')) return;
    
    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    
    try {
        const res = await fetch('../api/whatsapp_health.php?action=mark_sent', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `message_id=${id}`
        });
        const data = await res.json();
        
        if (data.success) {
            btn.innerHTML = '✅ تم التأكيد';
            btn.className = 'btn btn-success btn-sm';
            document.getElementById(`msg_${id}`).classList.add('status-sent');
        } else {
            alert('فشل: ' + (data.error || 'خطأ'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check-double"></i> تم الإرسال يدوياً';
        }
        
        setTimeout(() => { refreshStatus(); loadMessages(); }, 1000);
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check-double"></i> تم الإرسال يدوياً';
        alert('خطأ في الاتصال');
    }
}

async function retryAll() {
    if (!confirm('سيتم تشغيل معالجة الرسائل في الخلفية.\nهل تريد المتابعة؟')) return;
    
    let btn = document.getElementById('retryAllBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري...';
    }
    
    try {
        const res = await fetch('../api/whatsapp_health.php?action=retry_all', { method: 'POST' });
        const data = await res.json();
        alert(data.message || 'تم تشغيل المعالجة في الخلفية.');
    } catch (e) {
        alert('حدث خطأ في الاتصال.');
    }
    
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate"></i> إعادة إرسال الكل';
    }
    
    refreshStatus();
    loadMessages();
}

async function removeMsg(id) {
    if (!confirm('حذف هذه الرسالة؟')) return;
    
    try {
        const res = await fetch('../api/whatsapp_health.php?action=remove', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `message_id=${id}`
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById(`msg_${id}`)?.remove();
            refreshStatus();
        } else {
            alert('خطأ: ' + (data.error || 'غير معروف'));
        }
    } catch (e) {
        alert('خطأ في الاتصال');
    }
}

async function clearSent() {
    if (!confirm('مسح جميع الرسائل المرسلة والفاشلة نهائياً؟')) return;
    
    try {
        const res = await fetch('../api/whatsapp_health.php?action=clear_sent', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            alert('تم المسح بنجاح');
            loadMessages();
        }
    } catch (e) {
        alert('خطأ في الاتصال');
    }
}

function copyText(id) {
    const msg = currentMessages.find(m => String(m.id) === String(id));
    if (!msg) return;
    
    const text = msg.data?.text || msg.data?.caption || msg.message_preview || '';
    navigator.clipboard.writeText(text).then(() => {
        const btn = event.target.closest('button');
        btn.innerHTML = '<i class="fa-solid fa-check"></i> تم النسخ';
        setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-copy"></i> نسخ النص'; }, 2000);
    });
}

// Init
refreshStatus();
loadMessages();

// Auto-refresh every 30 seconds
setInterval(() => { refreshStatus(); loadMessages(); }, 30000);
</script>
</body>
</html>
