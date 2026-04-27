<?php
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

$file = $_GET['file'] ?? '';
$data = null;
$archiveDate = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['json_file'])) {
    if ($_FILES['json_file']['error'] === UPLOAD_ERR_OK) {
        $rawData = json_decode(file_get_contents($_FILES['json_file']['tmp_name']), true);
        $data = isset($rawData['data']) && is_array($rawData['data']) ? $rawData['data'] : $rawData;
        $archiveDate = "ملف مرفوع: " . htmlspecialchars($_FILES['json_file']['name']);
    }
} elseif (!empty($file)) {
    $file = basename($file);
    $archiveDir = 'data/archives/';
    $filePath = $archiveDir . $file;

    if (file_exists($filePath)) {
        $rawData = json_decode(file_get_contents($filePath), true);
        $data = isset($rawData['data']) && is_array($rawData['data']) ? $rawData['data'] : $rawData;
        preg_match('/(?:archive|championship)_(.*)\.json/', $file, $matches);
        $archiveDate = $matches[1] ?? $file;
    }
}

if (!$data && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // If no data and not a post, show upload form or error
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>عرض الأرشيف - <?= htmlspecialchars($archiveDate) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: #f4f6f9;
            padding: 20px;
            direction: rtl;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 20px; color: #333; }
        .btn {
            padding: 8px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
            transition: 0.3s;
            cursor: pointer;
            border: none;
        }
        .btn-back { background: #6c757d; color: #fff; }
        .btn-download { background: #ffc107; color: #000; }
        
        .table-container {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            color: #666;
            font-weight: 700;
        }
        tr:hover { background: #fdfdfd; }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-approved { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>

<div class="container">
    <?php if (!$data): ?>
    <div class="header" style="justify-content: center; flex-direction: column; text-align: center;">
        <h1 style="margin-bottom: 15px;"><i class="fa-solid fa-file-import"></i> رفع ملف أرشيف لعرضه</h1>
        <p style="margin-bottom: 20px; color: #666;">اختر ملف JSON الذي قمت بتحميله سابقاً لرؤية محتوياته</p>
        <form method="POST" enctype="multipart/form-data" class="table-container" style="width: 100%; max-width: 500px;">
            <input type="file" name="json_file" accept=".json" required class="btn" style="background: #eee; width: 100%; margin-bottom: 10px;">
            <button type="submit" class="btn btn-download" style="width: 100%;"><i class="fa-solid fa-eye"></i> عرض الملف</button>
        </form>
        <a href="reset_championship.php" style="margin-top: 20px; color: #666; text-decoration: none;"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
    </div>
    <?php else: ?>
    <div class="header">
        <div>
            <h1><i class="fa-solid fa-box-archive"></i> بيانات الأرشيف: <?= htmlspecialchars($archiveDate) ?></h1>
            <p style="color: #888; font-size: 13px;">عدد السجلات: <?= count($data) ?></p>
        </div>
        <div>
            <a href="reset_championship.php" class="btn btn-back"><i class="fa-solid fa-arrow-right"></i> رجوع</a>
            <?php if (!empty($file)): ?>
            <a href="download_archive.php?file=<?= urlencode($file) ?>" class="btn btn-download"><i class="fa-solid fa-download"></i> تحميل JSON</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th># وصل</th>
                    <th>الاسم الكامل</th>
                    <th>الهاتف</th>
                    <th>المحافظة</th>
                    <th>السيارة</th>
                    <th>اللوحة</th>
                    <th>الحالة</th>
                    <th>الملف الشخصي</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $reg): 
                    $name = $reg['full_name'] ?? $reg['member_name'] ?? $reg['name'] ?? '';
                    $plate = $reg['plate_full'] ?? (($reg['plate_governorate'] ?? '') . ' - ' . ($reg['plate_letter'] ?? '') . ' - ' . ($reg['plate_number'] ?? ''));
                    $code = $reg['registration_code'] ?? $reg['badge_token'] ?? $reg['member_id'] ?? '';
                ?>
                <tr>
                    <td><?= htmlspecialchars($reg['wasel'] ?? '') ?></td>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td dir="ltr" style="text-align: right;"><?= htmlspecialchars($reg['phone'] ?? '') ?></td>
                    <td><?= htmlspecialchars($reg['governorate'] ?? '') ?></td>
                    <td><?= htmlspecialchars($reg['car_type'] ?? '') ?></td>
                    <td dir="ltr" style="text-align: right;"><?= htmlspecialchars($plate) ?></td>
                    <td>
                        <span class="badge status-<?= ($reg['status'] ?? '') === 'approved' ? 'approved' : 'pending' ?>">
                            <?= ($reg['status'] ?? '') === 'approved' ? 'مقبول' : 'قيد الانتظار' ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <?php if (!empty($code)): ?>
                        <a href="member_details.php?id=<?= urlencode($code) ?>" target="_blank" class="btn" title="الملف الشخصي" style="background: #007bff; color: #fff; padding: 5px 12px; font-size: 13px; text-decoration: none;">
                            <i class="fa-solid fa-user"></i> عرض الملف
                        </a>
                        <?php else: ?>
                        <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    $('table').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json"
        },
        "pageLength": 50,
        "order": [[ 0, "desc" ]]
    });
});
</script>
</body>
</html>
