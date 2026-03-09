<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

if ($_SESSION['user']->username === 'scanner') {
    header('location:scanner_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إرسال إشعار جماعي</title>
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; }
        .container { max-width: 800px; background: white; padding: 30px; margin-top: 30px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .status-log { background: #000; color: #0f0; padding: 10px; height: 200px; overflow-y: auto; font-family: monospace; border-radius: 5px; margin-top: 20px; display: none; }
        
        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
    </style>
</head>
<body>
    <?php include '../include/navbar.php'; ?>

<div class="navbar navbar-default">
  <div class="container-fluid">
    <div class="navbar-header">
      <a class="navbar-brand" href="../dashboard.php"><i class="fa-solid fa-home"></i> الرئيسية</a>
    </div>
  </div>
</div>

<div class="container">
    <h2><i class="fa-solid fa-bullhorn"></i> إرسال إشعار WhatsApp جماعي</h2>
    <hr>
    
    <div class="form-group">
        <label><i class="fa-solid fa-pen"></i> نص الرسالة:</label>
        <textarea id="message" class="form-control" rows="5" placeholder="اكتب رسالتك هنا..."></textarea>
    </div>
    
    <div class="form-group">
        <label><i class="fa-solid fa-users"></i> إرسال إلى:</label>
        <select id="targetGroup" class="form-control">
            <option value="all">جميع المسجلين</option>
            <option value="approved">المقبولين فقط (Approved)</option>
            <option value="pending">قيد الانتظار (Pending)</option>
            <option value="custom_file">رفع ملف أرقام (TXT)</option>
        </select>
    </div>

    <div id="fileUploadDiv" class="form-group" style="display: none;">
        <label><i class="fa-solid fa-file-upload"></i> ملف الأرقام (.txt - كل رقم في سطر):</label>
        <input type="file" id="numbersFile" class="form-control" accept=".txt">
    </div>

    <div class="form-group">
        <label><i class="fa-solid fa-image"></i> صورة (اختياري):</label>
        <input type="text" id="imageUrl" class="form-control" placeholder="رابط الصورة (http://...)">
    </div>

    <button class="btn btn-success btn-lg btn-block" onclick="startBroadcast()"><i class="fa-solid fa-paper-plane"></i> بدء الإرسال</button>
    
    <div id="log" class="status-log"></div>
</div>

<script>
document.getElementById('targetGroup').addEventListener('change', function() {
    document.getElementById('fileUploadDiv').style.display = (this.value === 'custom_file') ? 'block' : 'none';
});

function startBroadcast() {
    const msg = document.getElementById('message').value;
    const group = document.getElementById('targetGroup').value;
    const img = document.getElementById('imageUrl').value;
    
    if (!msg) { alert('الرجاء كتابة رسالة'); return; }
    
    // UI Setup
    const log = document.getElementById('log');
    log.style.display = 'block';
    log.innerHTML = '🔄 جاري التحضير...\n';
    
    // Prepare Data
    let formData = new FormData();
    formData.append('message', msg);
    formData.append('group', group);
    formData.append('image', img);
    
    if (group === 'custom_file') {
        const fileInput = document.getElementById('numbersFile');
        if (fileInput.files.length > 0) {
            formData.append('file', fileInput.files[0]);
        }
    }

    // Send Request
    fetch('api/send_broadcast.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        
        function read() {
            reader.read().then(({done, value}) => {
                if (done) return;
                const text = decoder.decode(value);
                log.innerHTML += text;
                log.scrollTop = log.scrollHeight;
                read();
            });
        }
        read();
    })
    .catch(err => {
        log.innerHTML += '❌ Error: ' + err.message;
    });
}
</script>

</body>
</html>

