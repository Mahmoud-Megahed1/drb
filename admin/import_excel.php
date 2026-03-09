<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user']) || $_SESSION['user']->username !== 'root') {
    header('location:../login.php');
    exit;
}

// Handle Import API
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data && is_array($data)) {
        require_once '../include/db.php';
        require_once '../services/MemberService.php';
        
        $pdo = db();
        
        $membersFile = 'data/members.json';
        $currentMembers = [];
        if (file_exists($membersFile)) {
            $currentMembers = json_decode(file_get_contents($membersFile), true) ?? [];
        }
        
        $count = 0;
        $updated = 0;
        
        foreach ($data as $row) {
            // Validate required fields
            if (empty($row['phone'])) continue;
            
            $code = strtoupper(trim($row['registration_code'] ?? ''));
            $cleanPhone = preg_replace('/[^0-9]/', '', $row['phone']);
            if (strlen($cleanPhone) < 10) continue;

            $name = $row['full_name'] ?? $row['name'] ?? 'Unknown';
            $gov = $row['governorate'] ?? '';

            // --- DATABASE SYNC ---
            try {
                // 1. Get or Create Member in DB
                $member = MemberService::getOrCreateMember($cleanPhone, $name, $gov);
                
                // 2. Update Permanent Code if provided (and different)
                if (!empty($code) && $member['permanent_code'] !== $code) {
                    $pdo->prepare("UPDATE members SET permanent_code = ? WHERE id = ?")
                        ->execute([$code, $member['id']]);
                    $member['permanent_code'] = $code;
                }

                // 3. Create/Update Registration (Default Championship)
                $champId = 1; 
                
                // Check if registration exists
                $stmt = $pdo->prepare("SELECT * FROM registrations WHERE member_id = ? AND championship_id = ?");
                $stmt->execute([$member['id'], $champId]);
                $reg = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$reg) {
                    $pdo->prepare("
                        INSERT INTO registrations (
                            member_id, championship_id, wasel,
                            car_type, car_year, car_color,
                            plate_governorate, plate_letter, plate_number,
                            participation_type, status, created_at, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ")->execute([
                        $member['id'], $champId, $code, // Use code as 'wasel' fallback if needed?? Or 0. Let's use code if numeric or just 0. 
                        $row['car_type'] ?? '',
                        $row['car_year'] ?? '',
                        $row['car_color'] ?? '',
                        $row['governorate'] ?? '', // Plate Gov usually
                        '', // Plate Letter
                        '', // Plate Number
                        $row['participation_type'] ?? 'المشاركة بالاستعراض الحر', // Default
                        'approved', // Auto-approve imported
                        date('Y-m-d H:i:s')
                    ]);
                    $count++;
                } else {
                    $updated++;
                    // Optional: Update missing reg info
                }

            } catch (Exception $e) {
                // Log error but continue
                error_log("Import Error ($cleanPhone): " . $e->getMessage());
            }

            // --- LEGACY JSON UPDATE (Backup) ---
            if (!empty($code)) {
                $memberJson = [
                    'registration_code' => $code,
                    'full_name' => $name,
                    'phone' => strval($row['phone']),
                    'country_code' => $row['country_code'] ?? '+964',
                    'governorate' => $gov,
                    'car_type' => $row['car_type'] ?? '',
                    'car_year' => $row['car_year'] ?? '',
                    'car_color' => $row['car_color'] ?? '',
                    'first_registered' => $row['first_registered'] ?? date('Y-m-d'),
                    'championships_participated' => intval($row['championships_participated'] ?? 1),
                    'last_active' => date('Y-m-d H:i:s')
                ];
                
                if (isset($currentMembers[$code])) {
                    $currentMembers[$code] = array_merge($currentMembers[$code], $memberJson);
                } else {
                    $currentMembers[$code] = $memberJson;
                }
            }
        }
        
        require_once '../include/helpers.php';
        file_put_contents($membersFile, json_encode($currentMembers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        auditLog('import', 'members', null, null, "Excel Import: Added $count, Updated $updated", $_SESSION['user_id'] ?? null);

        // Log to AdminLogger
        try {
            require_once '../include/AdminLogger.php';
            $importLogger = new AdminLogger();
            $importLogger->log(
                AdminLogger::ACTION_IMPORT,
                $_SESSION['user']->username ?? 'root',
                "استيراد Excel: $count جديد, $updated محدّث",
                ['added' => $count, 'updated' => $updated, 'source' => 'excel']
            );
        } catch (Exception $e) {}

        echo json_encode(['success' => true, 'added' => $count, 'updated' => $updated]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>استيراد الأعضاء من Excel</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .step { margin-bottom: 20px; padding: 15px; border: 1px solid #eee; border-radius: 5px; }
        .step h4 { margin-top: 0; color: #17a2b8; }
    </style>
</head>
<body>
    <?php include '../include/navbar.php'; ?>

<div class="container">
    <div class="header">
        <a href="members.php" class="btn btn-default pull-left">← رجوع للأعضاء</a>
        <h2><i class="fa-solid fa-file-excel"></i> استيراد الأعضاء من Excel</h2>
    </div>
    <hr>
    
    <div class="alert alert-info">
        <strong>تعليمات الاستيراد:</strong>
        <p>يرجى رفع ملف Excel يحتوي على الأعمدة التالية (باللغة الإنجليزية في السطر الأول):</p>
        <ul>
            <li><code>registration_code</code> (مطلوب - كود العضو)</li>
            <li><code>phone</code> (مطلوب - رقم الهاتف)</li>
            <li><code>full_name</code> (الاسم الكامل)</li>
            <li><code>car_type</code>, <code>car_year</code>, <code>car_color</code>, <code>governorate</code></li>
            <li><code>championships_participated</code> (عدد المشاركات)</li>
        </ul>
    </div>

    <div class="step">
        <h4>1. اختر ملف Excel</h4>
        <input type="file" id="fileInput" class="form-control" accept=".xlsx, .xls">
    </div>

    <div class="step">
        <h4>2. معاينة البيانات</h4>
        <div id="preview" style="max-height: 200px; overflow: auto; margin-bottom: 10px;"></div>
        <button id="btnImport" class="btn btn-primary btn-block btn-lg" disabled onclick="uploadData()"><i class="fa-solid fa-rocket"></i> استيراد البيانات</button>
    </div>
    
    <div id="status" class="alert" style="display: none;"></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
let parsedData = [];

document.getElementById('fileInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        const data = new Uint8Array(e.target.result);
        const workbook = XLSX.read(data, {type: 'array'});
        
        // Get first sheet
        const firstSheetName = workbook.SheetNames[0];
        const worksheet = workbook.Sheets[firstSheetName];
        
        // Convert to JSON
        parsedData = XLSX.utils.sheet_to_json(worksheet);
        
        if (parsedData.length > 0) {
            renderPreview(parsedData);
            $('#btnImport').prop('disabled', false);
        } else {
            alert('الملف فارغ أو لا يحتوي على بيانات صالحة');
        }
    };
    reader.readAsArrayBuffer(file);
});

function renderPreview(data) {
    let html = '<table class="table table-bordered table-condensed"><thead><tr>';
    const keys = Object.keys(data[0]);
    keys.forEach(k => html += `<th>${k}</th>`);
    html += '</tr></thead><tbody>';
    
    // Show first 5 rows
    data.slice(0, 5).forEach(row => {
        html += '<tr>';
        keys.forEach(k => html += `<td>${row[k] || ''}</td>`);
        html += '</tr>';
    });
    html += '</tbody></table>';
    
    if (data.length > 5) html += `<p class="text-center text-muted">... و ${data.length - 5} صفوف أخرى</p>`;
    
    $('#preview').html(html);
}

function uploadData() {
    if (parsedData.length === 0) return;
    
    $('#btnImport').prop('disabled', true).text('جاري الاستيراد...');
    
    $.ajax({
        url: '',
        type: 'POST',
        data: JSON.stringify(parsedData),
        contentType: 'application/json',
        success: function(response) {
            const res = JSON.parse(response);
            if (res.success) {
                $('#status').removeClass('alert-danger').addClass('alert-success')
                    .html(`<i class="fa-solid fa-check-circle"></i> تم الاستيراد بنجاح!<br>تم إضافة: ${res.added}<br>تم تحديث: ${res.updated}`).show();
               
                setTimeout(() => window.location = 'members.php', 2000);
            } else {
                 $('#status').removeClass('alert-success').addClass('alert-danger').html('<i class="fa-solid fa-exclamation-triangle"></i> حدث خطأ: ' + res.error).show();
                 $('#btnImport').prop('disabled', false).html('<i class="fa-solid fa-rocket"></i> استيراد البيانات');
            }
        },
        error: function() {
             $('#status').removeClass('alert-success').addClass('alert-danger').html('<i class="fa-solid fa-exclamation-triangle"></i> حدث خطأ في الاتصال').show();
             $('#btnImport').prop('disabled', false).html('<i class="fa-solid fa-rocket"></i> استيراد البيانات');
        }
    });
}
</script>
</body>
</html>

