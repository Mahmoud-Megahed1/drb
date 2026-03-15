<?php
/**
 * View Notes - سجل الملاحظات
 * عرض كل سجل الملاحظات والتحذيرات
 */

session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

$currentUser = $_SESSION['user'];
$isRoot = (is_object($currentUser) ? ($currentUser->username ?? '') : ($currentUser['username'] ?? '')) === 'root';
$userRole = (is_object($currentUser) ? ($currentUser->role ?? '') : ($currentUser['role'] ?? '')) ?: ($isRoot ? 'root' : 'viewer');
if ($isRoot) $userRole = 'root'; // Force root role if username is root
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') $userRole = 'root';

$canView = in_array($userRole, ['root', 'admin', 'approver', 'notes']);

if (!$canView) {
    header('Location: ../dashboard.php');
    exit;
}

$message = '';
$messageType = '';

// Handle Delete Single Note/Warning
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_single']) && $canView) {
    try {
        require_once '../include/db.php';
        $pdo = \db();
        $deleteId = intval($_POST['delete_id'] ?? 0);
        $deleteType = $_POST['delete_type'] ?? '';
        
        if ($deleteId > 0 && in_array($deleteType, ['warning', 'info', 'blocker'])) {
            if ($deleteType === 'warning') {
                $pdo->prepare("DELETE FROM warnings WHERE id = ?")->execute([$deleteId]);
            } else {
                $pdo->prepare("DELETE FROM notes WHERE id = ?")->execute([$deleteId]);
            }
            $message = "تم حذف السجل بنجاح!";
            $messageType = "success";
        }
    } catch (\Exception $e) {
        $message = "خطأ أثناء الحذف: " . $e->getMessage();
        $messageType = "error";
    }
}

// Handle Clear Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all_logs']) && $isRoot) {
    try {
        require_once '../include/db.php';
        $pdo = \db();
        $pdo->exec("DELETE FROM warnings");
        $pdo->exec("DELETE FROM notes");
        $logsFile = __DIR__ . '/data/notes_logs.json';
        if (file_exists($logsFile)) {
            file_put_contents($logsFile, json_encode([], JSON_PRETTY_PRINT));
        }
        
        require_once '../include/AdminLogger.php';
        $adminLogger = new AdminLogger();
        $adminLogger->log('settings_change', 'root', 'قام بمسح سجل الملاحظات والتحذيرات', []);
        
        $message = "تم مسح السجلات بنجاح!";
        $messageType = "success";
    } catch (\Exception $e) {
        $message = "حدث خطأ أثناء مسح السجلات: " . $e->getMessage();
        $messageType = "error";
    }
}

require_once '../include/db.php';

$allNotes = [];

try {
    $pdo = db();
    
    // Union Query to get both Warnings and Notes
    $sql = "
    SELECT 
        'warning' as type,
        w.id,
        w.member_id as participant_id,
        m.name as participant_name,
        m.permanent_code as participant_code,
        w.warning_text as text,
        w.severity,
        0 as rating,
        '' as image_path,
        IFNULL(u.username, 'System') as created_by_name,
        w.created_at,
        STRFTIME('%s', w.created_at) as timestamp
    FROM warnings w
    LEFT JOIN members m ON w.member_id = m.id
    LEFT JOIN users u ON w.created_by = u.id

    UNION ALL

    SELECT 
        n.note_type as type,
        n.id,
        n.member_id as participant_id,
        m.name as participant_name,
        m.permanent_code as participant_code,
        n.note_text as text,
        n.priority as severity,
        0 as rating,
        '' as image_path,
        IFNULL(u.username, 'System') as created_by_name,
        n.created_at,
        STRFTIME('%s', n.created_at) as timestamp
    FROM notes n
    LEFT JOIN members m ON n.member_id = m.id
    LEFT JOIN users u ON n.created_by = u.id
    ";
    
    $stmt = $pdo->query($sql);
    $dbNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($dbNotes as $note) {
        $allNotes[] = [
            'source' => 'db',
            'db_id' => $note['id'],
            'db_type' => $note['type'],
            'type' => $note['type'],
            'participant_name' => $note['participant_name'],
            'participant_id' => $note['participant_code'] ?? $note['participant_id'],
            'text' => $note['text'],
            'rating' => $note['rating'],
            'image_path' => $note['image_path'],
            'created_by' => $note['created_by_name'],
            'timestamp' => $note['timestamp'] ?: strtotime($note['created_at'])
        ];
    }

} catch (Exception $e) {
    error_log("ViewNotes DB Error: " . $e->getMessage());
}

// 2. Fetch from Legacy JSON
$jsonFile = __DIR__ . '/data/notes_logs.json';
if (file_exists($jsonFile)) {
    $jsonData = json_decode(file_get_contents($jsonFile), true) ?? [];
    
    // Load local registrations for name lookup if needed
    $regFile = __DIR__ . '/data/data.json';
    $registrations = [];
    if (file_exists($regFile)) {
        $rData = json_decode(file_get_contents($regFile), true) ?? [];
        foreach ($rData as $reg) $registrations[$reg['wasel']] = $reg;
    }
    
    foreach ($jsonData as $jNote) {
        $pName = $jNote['participant_name'] ?? '';
        if (!$pName && isset($registrations[$jNote['participant_id']])) {
            $pName = $registrations[$jNote['participant_id']]['full_name'] ?? 'غير معروف';
        }
        
        $allNotes[] = [
            'source' => 'json',
            'type' => $jNote['note_type'] ?? 'info',
            'participant_name' => $pName ?: 'غير معروف',
            'participant_id' => $jNote['participant_id'] ?? '-',
            'text' => $jNote['note'] ?? $jNote['note_text'] ?? '',
            'rating' => $jNote['rating'] ?? 0,
            'image_path' => $jNote['image_path'] ?? '',
            'created_by' => $jNote['recorded_by'] ?? 'System',
            'timestamp' => $jNote['timestamp'] ?? time()
        ];
    }
}

// Sort all notes by timestamp desc
usort($allNotes, function($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

$notesLogs = $allNotes;

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>سجل الملاحظات والتحذيرات</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-rtl@3.4.0/dist/css/bootstrap-rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            padding-top: 70px;
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #f5f5f5;
        }
        .panel-warning { border-color: #f0ad4e; }
        .panel-warning > .panel-heading { background: linear-gradient(135deg, #f0ad4e, #ec971f); color: #fff; }
        .note-type-info { background: #17a2b8; color: #fff; padding: 4px 10px; border-radius: 5px; }
        .note-type-warning { background: #ffc107; color: #000; padding: 4px 10px; border-radius: 5px; }
        .note-type-blocker { background: #dc3545; color: #fff; padding: 4px 10px; border-radius: 5px; }
        .badge-id { 
            background: #333; 
            color: #0f0; 
            padding: 4px 10px; 
            border-radius: 5px; 
            font-family: monospace;
            font-size: 13px;
        }
        .rating-stars {
            font-size: 16px;
        }
        .recorded-by {
            background: #6c757d;
            color: #fff;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<?php include '../include/navbar.php'; ?>
<div class="container-fluid" style="margin-top: 20px;">

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <div class="panel panel-warning">
        <div class="panel-heading" style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <strong><i class="fa-solid fa-triangle-exclamation"></i> سجل الملاحظات والتحذيرات</strong>
                <span class="badge"><?= count($notesLogs) ?></span>
            </div>
            
            <?php if ($isRoot && count($notesLogs) > 0): ?>
            <div>
                <form method="POST" onsubmit="return confirm('تأكيد نهائي: هل أنت متأكد من مسح جميع السجلات؟ هذا الإجراء لا يمكن التراجع عنه!');" style="margin:0;">
                    <input type="hidden" name="clear_all_logs" value="1">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash-can"></i> مسح جميع السجلات</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="panel-body" style="overflow-x: auto;">
            <?php if (count($notesLogs) > 0): ?>
            <table id="notesTable" class="table table-bordered table-striped table-hover">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th>المشارك</th>
                        <th style="width: 120px;">الكود</th>
                        <th style="width: 100px;">النوع</th>
                        <th style="width: 100px;">الأولوية</th>
                        <th>الملاحظة</th>
                        <th style="width: 100px;">التقييم</th>
                        <th style="width: 70px;">الصورة</th>
                        <th style="width: 120px;">سُجلت بواسطة</th>
                        <th style="width: 140px;">التاريخ</th>
                        <th style="width: 70px;">إجراء</th>
                    </tr>
                </thead>
                <tbody>
        <?php foreach ($notesLogs as $index => $note): 
            // Type formatting
            $typeClass = 'note-type-info';
            $typeLabel = 'معلومة ℹ️';
            $nType = $note['type'] ?? 'info';
            
            if ($nType === 'warning') {
                $typeClass = 'note-type-warning';
                $typeLabel = 'تحذير ⚠️';
            } elseif ($nType === 'blocker') {
                $typeClass = 'note-type-blocker';
                $typeLabel = 'مانع 🛑';
            }

            // Severity/Priority formatting
            $sev = $note['severity'] ?? 'low';
            $sevLabel = 'عادي';
            $sevClass = 'label-default';
            
            if ($sev === 'high') {
                $sevLabel = 'مهم';
                $sevClass = 'label-danger';
            } elseif ($sev === 'medium') {
                $sevLabel = 'متوسط';
                $sevClass = 'label-warning';
            }
            
            // Rating
            $rating = intval($note['rating'] ?? 0);
            $ratingStars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
            $ratingColor = $rating >= 4 ? '#28a745' : ($rating >= 3 ? '#ffc107' : '#dc3545');
        ?>
        <tr>
            <td><?= $index + 1 ?></td>
            <td><strong><?= htmlspecialchars($note['participant_name']) ?></strong></td>
            <td><span class="badge-id"><?= htmlspecialchars($note['participant_id']) ?></span></td>
            <td><span class="<?= $typeClass ?>"><?= $typeLabel ?></span></td>
            <td><span class="label <?= $sevClass ?>"><?= $sevLabel ?></span></td>
            <td style="max-width: 300px; word-wrap: break-word;"><?= htmlspecialchars($note['text']) ?></td>
            <td>
                <?php if ($rating > 0): ?>
                <span class="rating-stars" style="color: <?= $ratingColor ?>;" title="<?= $rating ?> من 5">
                    <?= $ratingStars ?>
                </span>
                <?php else: ?>
                <span class="text-muted">-</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($note['image_path']) && file_exists(__DIR__ . '/../' . $note['image_path'])): ?>
                <img src="../<?= htmlspecialchars($note['image_path']) ?>" 
                     style="width: 60px; height: 60px; object-fit: cover; border-radius: 5px; cursor: pointer;"
                     onclick="showImage('<?= htmlspecialchars($note['image_path']) ?>')">
                <?php else: ?>
                <span style="color: #999;">-</span>
                <?php endif; ?>
            </td>
            <td><span class="recorded-by"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($note['created_by']) ?></span></td>
            <td><small><?= date('Y-m-d H:i', $note['timestamp']) ?></small></td>
            <td>
                <?php if (!empty($note['db_id'])): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا السجل؟')">
                    <input type="hidden" name="delete_single" value="1">
                    <input type="hidden" name="delete_id" value="<?= $note['db_id'] ?>">
                    <input type="hidden" name="delete_type" value="<?= htmlspecialchars($note['db_type']) ?>">
                    <button type="submit" class="btn btn-danger btn-xs" title="حذف"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php else: ?>
                <span class="text-muted">-</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
            </table>
            <?php else: ?>
            <div class="alert alert-info text-center" style="margin-top:20px; margin-bottom:0;">
                <h4><i class="fa-solid fa-info-circle"></i> لا توجد ملاحظات</h4>
                <p>لم يتم تسجيل أي ملاحظات بعد</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">عرض الصورة</h4>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" style="max-width: 100%; max-height: 80vh;">
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/js/bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap.min.js"></script>

<script>
$(document).ready(function() {
    $('#notesTable').DataTable({
        order: [[8, 'desc']],
        pageLength: 25,
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json'
        }
    });
});

function showImage(path) {
    document.getElementById('modalImage').src = '../' + path;
    $('#imageModal').modal('show');
}
</script>
</body>
</html>
