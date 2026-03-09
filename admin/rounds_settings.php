<?php
/**
 * Rounds Settings Page (JSON Version)
 * Admin page for managing rounds using admin/data/rounds.json
 */

require_once '../include/auth.php';
require_once '../include/AdminLogger.php';

$roundsFile = __DIR__ . '/data/rounds.json';
$logsFile = __DIR__ . '/data/round_logs.json';
$dataFile = __DIR__ . '/data/data.json';

// Ensure files exist
if (!file_exists($roundsFile)) file_put_contents($roundsFile, '[]');

$message = '';
$error = '';

// Helper to save rounds
function saveRounds($rounds) {
    global $roundsFile;
    file_put_contents($roundsFile, json_encode(array_values($rounds), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $rounds = json_decode(file_get_contents($roundsFile), true) ?? [];
        
        switch ($action) {
            case 'add_round':
                $name = trim($_POST['round_name'] ?? '');
                $number = intval($_POST['round_number'] ?? 0);
                
                if (empty($name) || !$number) {
                    throw new Exception('اسم ورقم الجولة مطلوبان');
                }
                
                // Check dupes
                foreach ($rounds as $r) {
                    if ($r['round_number'] == $number) throw new Exception('رقم الجولة موجود مسبقاً');
                }
                
                $rounds[] = [
                    'id' => $number, // Simple ID strategy: use number
                    'round_number' => $number,
                    'round_name' => $name,
                    'is_active' => 1
                ];
                
                // Sort by number
                usort($rounds, function($a, $b) { return $a['round_number'] - $b['round_number']; });
                
                saveRounds($rounds);
                $message = 'تم إضافة الجولة بنجاح';
                break;
                
            case 'update_round':
                $id = intval($_POST['round_id'] ?? 0);
                $name = trim($_POST['round_name'] ?? '');
                $isActive = isset($_POST['is_active']) ? 1 : 0; // If coming from checkbox/form
                 // Fix: Logic for toggle button typically sends the NEW state or toggles existing
                if (isset($_POST['toggle_active'])) { 
                    // Special handling for toggle button
                    // Logic handled inside loop
                }
                
                $found = false;
                foreach ($rounds as &$r) {
                    if ($r['id'] == $id) {
                        if (!empty($name)) $r['round_name'] = $name;
                        if (isset($_POST['is_active'])) $r['is_active'] = $_POST['is_active'];
                        $found = true;
                        break;
                    }
                }
                unset($r);
                
                if ($found) {
                    saveRounds($rounds);
                    $message = 'تم تحديث الجولة';
                }
                break;
                
            case 'delete_round':
                $id = intval($_POST['round_id'] ?? 0);
                
                // Check logs
                $logs = file_exists($logsFile) ? json_decode(file_get_contents($logsFile), true) : [];
                $hasLogs = false;
                foreach ($logs as $l) {
                    if (($l['round_id'] ?? 0) == $id) {
                        $hasLogs = true;
                        break;
                    }
                }
                
                if ($hasLogs) {
                    throw new Exception('لا يمكن حذف جولة بها سجلات');
                }
                
                $rounds = array_filter($rounds, function($r) use ($id) { return $r['id'] != $id; });
                saveRounds($rounds);
                
                // Log deletion
                $user = getCurrentUser();
                $logUsername = $user['username'] ?? $_SESSION['user']->username ?? 'unknown';
                $adminLogger = new AdminLogger();
                $adminLogger->log(
                    AdminLogger::ACTION_ROUND_DELETE,
                    $logUsername,
                    'حذف الجولة رقم ' . $id,
                    ['round_id' => $id]
                );
                
                $message = 'تم حذف الجولة';
                break;
                
            case 'reset_round':
                $id = intval($_POST['round_id'] ?? 0);
                
                // 1. Clear JSON logs
                $logs = file_exists($logsFile) ? json_decode(file_get_contents($logsFile), true) : [];
                $newLogs = array_filter($logs, function($l) use ($id) {
                    return ($l['round_id'] ?? 0) != $id;
                });
                file_put_contents($logsFile, json_encode(array_values($newLogs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                // 2. Clear SQL logs
                require_once '../include/db.php';
                $pdo = db();
                $stmt = $pdo->prepare("DELETE FROM round_logs WHERE round_id = ?");
                $stmt->execute([$id]);
                
                // 3. Audit Log
                require_once '../include/helpers.php';
                auditLog('reset_round', 'rounds', $id, null, 'Round logs cleared');
                
                // 4. Log reset entry in round_logs so it appears in rounds_logs.php
                $user = getCurrentUser();
                $logUsername = $user['username'] ?? $_SESSION['user']->username ?? 'unknown';
                $resetEntry = [
                    'action' => 'round_reset',
                    'round_id' => $id,
                    'participant_id' => 'SYSTEM',
                    'timestamp' => time(),
                    'device' => 'admin_panel',
                    'scanned_by' => $logUsername,
                    'details' => 'تم إعادة تعيين الجولة بواسطة ' . $logUsername
                ];
                $currentLogs = file_exists($logsFile) ? json_decode(file_get_contents($logsFile), true) ?? [] : [];
                $currentLogs[] = $resetEntry;
                file_put_contents($logsFile, json_encode($currentLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                // 5. Log to AdminLogger too
                $adminLogger = new AdminLogger();
                $adminLogger->logRoundReset($logUsername, $id);
                
                $message = 'تم إعادة تعيين الجولة بنجاح';
                break;
                
            case 'sync_participants':
                header('Location: ../api/sync_participants.php');
                exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load Data for View
$rounds = json_decode(file_get_contents($roundsFile), true) ?? [];
$logs = file_exists($logsFile) ? json_decode(file_get_contents($logsFile), true) : [];
$participantsData = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
$totalParticipants = count($participantsData);

// Calculate Stats per Round
foreach ($rounds as &$r) {
    $rid = $r['id'];
    // Naive count of actions
    $enterCount = 0;
    $exitCount = 0;
    
    // Better logic: Unique participants who have entered/exited? 
    // Or plain count of events? The previous SQL was COUNT(DISTINCT participant_id) WHERE action='enter/exit'
    
    $enteredPids = [];
    $exitedPids = [];
    
    foreach ($logs as $l) {
        if (($l['round_id'] ?? 0) == $rid) {
            if (($l['action'] ?? '') == 'enter') $enteredPids[$l['participant_id']] = true;
            if (($l['action'] ?? '') == 'exit') $exitedPids[$l['participant_id']] = true;
        }
    }
    
    $r['total_entered'] = count($enteredPids);
    $r['total_exited'] = count($exitedPids);
}
unset($r);

// Sort rounds
usort($rounds, function($a, $b) { return $a['round_number'] - $b['round_number']; });
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚙️ إعدادات الجولات</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
        }
        .header {
            background: rgba(0,0,0,0.3);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 20px; }
        .header a { color: #aaa; text-decoration: none; }
        
        .container { padding: 20px; max-width: 900px; margin: 0 auto; }
        
        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .message.success { background: rgba(40,167,69,0.2); border: 1px solid #28a745; }
        .message.error { background: rgba(220,53,69,0.2); border: 1px solid #dc3545; }
        
        .card {
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: rgba(0,0,0,0.2);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-card .number { font-size: 32px; font-weight: bold; }
        .stat-card .label { font-size: 14px; opacity: 0.7; }
        .stat-card.green .number { color: #28a745; }
        .stat-card.blue .number { color: #007bff; }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        th { opacity: 0.7; font-weight: 600; }
        
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            margin: 2px;
        }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #000; }
        
        .form-row {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        .form-row input {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
        }
        .form-row input[type="number"] { width: 80px; flex: none; }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
        }
        .badge.active { background: #28a745; }
        .badge.inactive { background: #6c757d; }
        
        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
    </style>
</head>
<body>
    <?php include '../include/navbar-custom.php'; ?>
    <div class="header">
        <h1><i class="fa-solid fa-flag-checkered"></i> إعدادات الجولات (JSON)</h1>
        
    </div>
    
    <div class="container">
        <?php if ($message): ?>
        <div class="message success"><i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="message error"><i class="fa-solid fa-times-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="number"><?= count($rounds) ?></div>
                <div class="label">عدد الجولات</div>
            </div>
            <div class="stat-card green">
                <div class="number"><?= $totalParticipants ?></div>
                <div class="label">المشاركين</div>
            </div>
        </div>
        
        <!-- Add Round -->
        <div class="card">
            <h2><i class="fa-solid fa-plus-circle"></i> إضافة جولة جديدة</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_round">
                <div class="form-row">
                    <input type="number" name="round_number" placeholder="الرقم" min="1" required value="<?= count($rounds) + 1 ?>">
                    <input type="text" name="round_name" placeholder="اسم الجولة (مثال: الجولة الرابعة)" required>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus"></i> إضافة</button>
                </div>
            </form>
        </div>
        
        <!-- Rounds List -->
        <div class="card">
            <h2><i class="fa-solid fa-list-ol"></i> قائمة الجولات</h2>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الاسم</th>
                        <th>دخلوا (Unique)</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rounds as $round): ?>
                    <tr>
                        <td><?= $round['round_number'] ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="update_round">
                                <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
                                <input type="text" name="round_name" value="<?= htmlspecialchars($round['round_name']) ?>" 
                                       style="background:transparent; border:none; color:white; font-size:14px;">
                            </form>
                        </td>
                        <td style="color: #28a745;"><?= $round['total_entered'] ?></td>
                        <td>
                            <span class="badge <?= $round['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $round['is_active'] ? 'مفعّلة' : 'معطّلة' ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="update_round">
                                <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
                                <input type="hidden" name="round_name" value="<?= htmlspecialchars($round['round_name']) ?>">
                                <input type="hidden" name="is_active" value="<?= $round['is_active'] ? 0 : 1 ?>">
                                <button type="submit" class="btn <?= $round['is_active'] ? 'btn-warning' : 'btn-success' ?>" title="<?= $round['is_active'] ? 'تعطيل' : 'تفعيل' ?>">
                                    <?= $round['is_active'] ? '<i class="fa-solid fa-pause"></i>' : '<i class="fa-solid fa-play"></i>' ?>
                                </button>
                            </form>
                            
                            <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ هل أنت متأكد من مسح سجلات هذه الجولة بالكامل؟\n\nلا يمكن التراجع عن هذا الإجراء!');">
                                <input type="hidden" name="action" value="reset_round">
                                <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
                                <button type="submit" class="btn btn-warning" title="إعادة تعيين الجولة">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </button>
                            </form>

                            <?php if ($round['total_entered'] == 0): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('حذف هذه الجولة؟');">
                                <input type="hidden" name="action" value="delete_round">
                                <input type="hidden" name="round_id" value="<?= $round['id'] ?>">
                                <button type="submit" class="btn btn-danger" title="حذف">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>




