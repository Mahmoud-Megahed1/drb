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
    <title>الرسائل المعلقة - WhatsApp Queue</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; }
        .status-banner {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
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
            background: #fff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-right: 4px solid #dc3545;
            transition: all 0.3s;
        }
        .msg-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .msg-card.sent { border-right-color: #28a745; opacity: 0.7; }
        .msg-card .msg-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 10px;
        }
        .msg-card .msg-name { font-weight: bold; font-size: 15px; }
        .msg-card .msg-phone { direction: ltr; color: #555; font-family: monospace; }
        .msg-card .msg-error { color: #dc3545; font-size: 12px; margin-top: 5px; }
        .msg-card .msg-preview { 
            background: #f8f9fa; padding: 8px 12px; border-radius: 8px;
            font-size: 13px; color: #333; margin: 8px 0;
            border-right: 3px solid #17a2b8; max-height: 80px; overflow-y: auto;
        }
        .msg-card .msg-meta { font-size: 11px; color: #999; }
        .msg-card .msg-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        
        .stat-box {
            text-align: center; padding: 15px; border-radius: 10px;
            color: #fff; margin-bottom: 15px;
        }
        .stat-box h3 { margin: 0; font-size: 30px; font-weight: bold; }
        .stat-box p { margin: 5px 0 0; font-size: 13px; }
        
        .filter-bar {
            background: #fff; padding: 12px 20px; border-radius: 10px;
            margin-bottom: 20px; display: flex; gap: 15px; align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .empty-state {
            text-align: center; padding: 50px; color: #999;
        }
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
            <div class="stat-box" style="background: linear-gradient(135deg, #17a2b8, #138496);">
                <h3 id="totalCount">-</h3>
                <p>📊 إجمالي</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box" style="background: linear-gradient(135deg, #6f42c1, #5a32a3);">
                <h3 id="lastSuccess">-</h3>
                <p>🕐 آخر إرسال ناجح</p>
            </div>
        </div>
    </div>

    <!-- Action Bar -->
    <div class="filter-bar">
        <label style="margin: 0;"><strong>عرض:</strong></label>
        <select id="filterSelect" class="form-control" style="width: 200px;" onchange="loadMessages()">
            <option value="pending">المعلقة فقط</option>
            <option value="sent">المرسلة</option>
            <option value="all">الكل</option>
        </select>
        <button class="btn btn-success" onclick="retryAll()" id="retryAllBtn">
            <i class="fa-solid fa-rotate"></i> إعادة إرسال الكل
        </button>
        <button class="btn btn-warning" onclick="clearSent()" id="clearSentBtn">
            <i class="fa-solid fa-broom"></i> مسح المرسلة
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
            text.textContent = '🟡 حالة غير معروفة - لم يتم إرسال رسائل بعد';
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
        
        // Update stats
        document.getElementById('totalCount').textContent = currentMessages.length;
        const pending = currentMessages.filter(m => m.status === 'pending').length;
        const sent = currentMessages.filter(m => m.status === 'sent').length;
        document.getElementById('pendingCount').textContent = pending;
        document.getElementById('sentCount').textContent = sent;
        
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
                <h4>لا توجد رسائل معلقة 🎉</h4>
                <p>جميع الرسائل تم إرسالها بنجاح</p>
            </div>`;
        return;
    }
    
    let html = '';
    messages.forEach(msg => {
        const isSent = msg.status === 'sent';
        const typeLabels = { text: '📝 نص', image: '🖼️ صورة', document: '📄 مستند' };
        const typeLabel = typeLabels[msg.type] || msg.type;
        const waLink = `https://wa.me/${msg.phone}?text=${encodeURIComponent(msg.data?.text || msg.data?.caption || '')}`;
        
        html += `
        <div class="msg-card ${isSent ? 'sent' : ''}" id="msg_${msg.id}">
            <div class="msg-header">
                <div>
                    <span class="msg-name">👤 ${msg.name || 'غير معروف'}</span>
                    ${msg.wasel ? `<span class="label label-default" style="margin-right:5px;">وصل: ${msg.wasel}</span>` : ''}
                    <span class="label label-info">${typeLabel}</span>
                    ${isSent ? '<span class="label label-success">✅ تم الإرسال</span>' : '<span class="label label-danger">⏳ معلقة</span>'}
                </div>
                <span class="msg-phone">${msg.phone}</span>
            </div>
            ${msg.message_preview ? `<div class="msg-preview">${escapeHtml(msg.message_preview)}...</div>` : ''}
            ${!isSent && msg.error ? `<div class="msg-error"><i class="fa-solid fa-triangle-exclamation"></i> ${escapeHtml(msg.error)}</div>` : ''}
            <div class="msg-meta">
                📅 ${msg.created_at || '-'} | 🔄 محاولات: ${msg.attempts || 1}
                ${msg.sent_at ? ` | ✅ أُرسلت: ${msg.sent_at}` : ''}
                ${msg.last_retry ? ` | 🔁 آخر محاولة: ${msg.last_retry}` : ''}
            </div>
            <div class="msg-actions">
                ${!isSent ? `
                    <button class="btn btn-success btn-sm" onclick="retryOne('${msg.id}')">
                        <i class="fa-solid fa-paper-plane"></i> إعادة إرسال
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
            document.getElementById(`msg_${id}`).classList.add('sent');
            btn.innerHTML = '✅ تم';
            btn.className = 'btn btn-success btn-sm';
        } else {
            alert('فشل: ' + (data.error || 'خطأ غير معروف'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إعادة إرسال';
        }
        
        refreshStatus();
        setTimeout(loadMessages, 1000);
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إعادة إرسال';
        alert('خطأ في الاتصال');
    }
}

async function retryAll() {
    if (!confirm('سيتم حث جميع الرسائل المعلقة وإرسالها تباعاً في الخلفية لتجنب الحظر.\nهل تريد المتابعة؟')) return;
    
    // Fallback if ID doesn't exist, use event target
    let btn = document.getElementById('retryAllBtn');
    if (!btn && event && event.target) {
        btn = event.target.closest('button') || event.target;
    }
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> جاري إطلاق المعالجة...`;
    }
    
    try {
        const res = await fetch('../api/whatsapp_health.php?action=retry_all', {
            method: 'POST'
        });
        const data = await res.json();
        
        alert(data.message || 'تم إطلاق مهمة الإرسال في الخلفية. يمكنك ترك الصفحة الآن وسيتم الإرسال تباعاً.');
    } catch (e) {
        alert('حدث خطأ في الاتصال، توقف الإرسال التلقائي.');
    }
    
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate"></i> إعادة إرسال الكل';
    }
    
    refreshStatus();
    loadMessages();
}

async function removeMsg(id) {
    if (!confirm('حذف هذه الرسالة من الطابور؟')) return;
    
    try {
        await fetch('../api/whatsapp_health.php?action=remove', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `message_id=${id}`
        });
        document.getElementById(`msg_${id}`)?.remove();
        refreshStatus();
    } catch (e) {
        alert('خطأ');
    }
}

async function clearSent() {
    if (!confirm('مسح جميع الرسائل التي تم إرسالها بنجاح؟')) return;
    
    try {
        await fetch('../api/whatsapp_health.php?action=clear_sent', { method: 'POST' });
        loadMessages();
    } catch (e) {
        alert('خطأ');
    }
}

function copyText(id) {
    const msg = currentMessages.find(m => m.id === id);
    if (!msg) return;
    
    const text = msg.data?.text || msg.data?.caption || '';
    navigator.clipboard.writeText(text).then(() => {
        const btn = event.target.closest('button');
        btn.innerHTML = '<i class="fa-solid fa-check"></i> تم النسخ';
        setTimeout(() => {
            btn.innerHTML = '<i class="fa-solid fa-copy"></i> نسخ النص';
        }, 2000);
    });
}

// Initial load
refreshStatus();
loadMessages();

// Auto-refresh every 30 seconds
setInterval(() => {
    refreshStatus();
    loadMessages();
}, 30000);
</script>
</body>
</html>
