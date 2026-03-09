<?php
session_start();

if (!isset($_GET['wasel']) || empty($_SESSION['user'])) {
    header('location:dashboard.php');
    exit;
}

$wasel = $_GET['wasel'];
$inputs = json_decode(file_get_contents('admin/data/data.json'), true);

// Find order by wasel ID
$data = null;
foreach ($inputs as $item) {
    if ($item['wasel'] == $wasel) {
        $data = $item;
        break;
    }
}

if (!$data) {
    header('location:dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <title>نادي بلاد الرافدين</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f5f5f5; }
        .order-details { background: #fff; padding: 20px; border-radius: 10px; margin-top: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .order-details h3 { color: #d40000; margin-bottom: 20px; }
        .table td { padding: 12px; }
        .price-row { background: #fff3cd; font-weight: bold; }
        .discount-row { background: #d4edda; }
        .total-row { background: #d40000; color: white; font-weight: bold; }
        .status-delivered { color: #28a745; font-weight: bold; }
        .status-pending { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>
<div class="navbar navbar-default" role="navigation">
    <div class="container">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target=".navbar-collapse">
                <span class="sr-only">Nav</span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
            </button>
            <a class="navbar-brand" href="dashboard.php">الرئيسية</a>
        </div>
        <div class="collapse navbar-collapse">
            <ul class="nav navbar-nav">
                <li><a href="index.php" target="_blank">صفحة الفورم</a></li>
                <li><a href="logout.php">خروج</a></li>
            </ul>
        </div>
    </div>
</div>
<div class="container order-details" id="pdf">
    <h3>🎫 تفاصيل طلب التذاكر - نادي بلاد الرافدين 2025</h3>
    <table class="table table-bordered">
        <tbody>
            <tr>
                <td style="width: 35%;"><strong>رقم الطلب</strong></td>
                <td><?= htmlspecialchars($data['wasel'] ?? '-') ?></td>
            </tr>
            <tr>
                <td><strong>الاسم</strong></td>
                <td><?= htmlspecialchars($data['name'] ?? '-') ?></td>
            </tr>
            <tr>
                <td><strong>رقم الهاتف</strong></td>
                <td><?= htmlspecialchars($data['phone'] ?? '-') ?></td>
            </tr>
            <tr>
                <td><strong>المحافظة</strong></td>
                <td><?= htmlspecialchars($data['governorate'] ?? '-') ?></td>
            </tr>
            <tr>
                <td><strong>العنوان</strong></td>
                <td><?= htmlspecialchars($data['address'] ?? '-') ?></td>
            </tr>
            <tr>
                <td><strong>أقرب نقطة دالة</strong></td>
                <td><?= htmlspecialchars($data['nearest_landmark'] ?? '-') ?></td>
            </tr>
            <tr>
                <td><strong>عدد التذاكر</strong></td>
                <td>
                    <?php 
                    $regularTickets = $data['regular_tickets'] ?? 0;
                    $vipTickets = $data['vip_tickets'] ?? 0;
                    $offerTickets = $data['offer_tickets'] ?? 0;
                    
                    // للتوافق مع الطلبات القديمة
                    if ($regularTickets == 0 && $vipTickets == 0 && $offerTickets == 0) {
                        $regularTickets = $data['tickets_count'] ?? 1;
                    }
                    
                    $ticketParts = [];
                    if ($regularTickets > 0) $ticketParts[] = "عادي: $regularTickets";
                    if ($vipTickets > 0) $ticketParts[] = "VIP: $vipTickets";
                    if ($offerTickets > 0) $ticketParts[] = "عرض خاص: $offerTickets";
                    echo implode(' | ', $ticketParts) ?: '0';
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong>كود الخصم</strong></td>
                <td><?= htmlspecialchars($data['discount_code'] ?? '-') ?> 
                    <?php if (isset($data['discount_status'])): ?>
                        <span class="label label-<?= $data['discount_status'] === 'صحيح' ? 'success' : ($data['discount_status'] === 'غير صحيح' ? 'danger' : 'default') ?>">
                            <?= $data['discount_status'] ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>تاريخ الطلب</strong></td>
                <td><?= htmlspecialchars($data['order_date'] ?? '-') ?></td>
            </tr>
            <tr class="price-row">
                <td><strong>المبلغ قبل الخصم</strong></td>
                <td><?= number_format($data['total_before_discount'] ?? 0) ?> دينار</td>
            </tr>
            <?php if (($data['discount_amount'] ?? 0) > 0): ?>
            <tr class="discount-row">
                <td><strong>الخصم (<?= $data['discount_percentage'] ?? 0 ?>%)</strong></td>
                <td>- <?= number_format($data['discount_amount'] ?? 0) ?> دينار</td>
            </tr>
            <?php endif; ?>
            <tr class="total-row">
                <td><strong>المبلغ الإجمالي</strong></td>
                <td><?= number_format($data['total_after_discount'] ?? $data['total_before_discount'] ?? 0) ?> دينار</td>
            </tr>
            <tr>
                <td><strong>حالة التسليم</strong></td>
                <td class="<?= ($data['is_delivered'] ?? false) ? 'status-delivered' : 'status-pending' ?>">
                    <?= ($data['is_delivered'] ?? false) ? '✅ تم التسليم' : '⏳ قيد التوصيل' ?>
                </td>
            </tr>
        </tbody>
    </table>
    <p>
        <button class="btn btn-primary" type="button" onclick="pdf()">📄 طباعة الوصل</button>
        <a href="dashboard.php" class="btn btn-default">🔙 العودة للوحة التحكم</a>
    </p>
</div>
<script>
function pdf() {
    var element = document.getElementById('pdf');
    var opt = {
        margin: 10,
        filename: 'order_<?= $data['wasel'] ?? 'ticket' ?>.pdf',
        image: { type: 'jpeg', quality: 1 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).save();
}
</script>
</body>
</html>