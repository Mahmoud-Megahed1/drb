<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

// Scanner Role Check
if ($_SESSION['user']->username !== 'scanner' && $_SESSION['user']->username !== 'root') {
   // Optional: Redirect back if not scanner/root
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الماسح الضوئي</title>
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #1a1a2e; color: #fff; text-align: center; padding: 20px; }
        .scanner-box { max-width: 500px; margin: 0 auto; background: #16213e; padding: 30px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .btn-scan { width: 100%; padding: 20px; font-size: 24px; border-radius: 15px; margin-bottom: 20px; }
        .status-box { margin-top: 20px; padding: 20px; border-radius: 10px; display: none; }
        .status-success { background: #28a745; color: white; }
        .status-error { background: #dc3545; color: white; }
        .status-warning { background: #ffc107; color: black; }
        input[type="text"] { text-align: center; font-size: 20px; margin-bottom: 15px; }
        /* Icons */
        .fa-solid, .fa-brands, .fa-regular { margin-left: 8px; }
    </style>
</head>
<body>
    <?php include '../include/navbar.php'; ?>

<div class="scanner-box">
    <h2><i class="fa-solid fa-mobile-screen"></i> الماسح الضوئي</h2>
    <p>ادخل رقم الباج أو امسح الكود</p>
    
    <input type="text" id="manualInput" class="form-control" placeholder="رقم الباج / الكود">
    <button class="btn btn-primary btn-block" onclick="checkEntry()">تحقق <i class="fa-solid fa-search"></i></button>
    <br>
    <a href="../logout.php" class="btn btn-danger btn-sm"><i class="fa-solid fa-sign-out-alt"></i> خروج</a>

    <div id="resultBox" class="status-box"></div>
</div>

<script>
function checkEntry() {
    const val = document.getElementById('manualInput').value;
    if (!val) return;

    // Use absolute path to API
    fetch(`../verify_entry.php?action=checkin&wasel=${val}`)
        .then(r => r.json())
        .then(data => {
            const box = document.getElementById('resultBox');
            box.style.display = 'block';
            box.className = 'status-box';
            
            let html = '';
            if (data.status === 'checked_in') {
                box.classList.add('status-success');
                html = `<h3><i class="fa-solid fa-check-circle"></i> تم الدخول!</h3><p>${data.name}</p><p>${data.car}</p>`;
            } else if (data.status === 'already_entered') {
                box.classList.add('status-warning');
                html = `<h3><i class="fa-solid fa-exclamation-triangle"></i> تم الدخول مسبقاً</h3><p>${data.entry_time}</p><p>${data.name}</p>`;
            } else {
                box.classList.add('status-error');
                html = `<h3><i class="fa-solid fa-times-circle"></i> خطأ</h3><p>${data.message}</p>`;
            }
            box.innerHTML = html;
        })
        .catch(err => alert('Error connecting'));
}
</script>

</body>
</html>


