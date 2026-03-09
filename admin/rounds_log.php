<?php
/**
 * Rounds Activity Log Viewer
 * ??? ??? ?????? ???????
 */
session_start();
require_once '../include/auth.php';
require_once '../include/RoundsLogger.php';

requireAuth('../rounds_gate.php');

// Only root and admins can view logs
$currentUser = getCurrentUser();
$isRoot = ($currentUser['username'] ?? '') === 'root' || ($currentUser['role'] ?? '') === 'root';
$isAdmin = ($currentUser['role'] ?? '') === 'admin';

if (!$isRoot && !$isAdmin) {
    header('Location: ../rounds_gate.php');
    exit;
}

$logger = new RoundsLogger();

// Get filters
$action = $_GET['action'] ?? '';
$username = $_GET['username'] ?? '';
$roundId = $_GET['round_id'] ?? '';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

// Apply filters
$filters = array_filter([
    'action' => $action,
    'username' => $username,
    'round_id' => $roundId,
    'from_date' => $fromDate,
    'to_date' => $toDate
]);

$logs = $logger->getLogs($filters);
$stats = $logger->getStats();

// Action labels
$actionLabels = [
    'create_round' => '?? ????? ????',
    'update_round' => '?? ????? ????',
    'delete_round' => '??? ??? ????',
    'activate_round' => '? ????? ????',
    'deactivate_round' => '? ????? ????? ????',
    'scan_entry' => '?? ???? ????',
    'scan_exit' => '?? ???? ?? ????',
    'reset_rounds' => '?? ????? ????? ???????',
    'config_update' => '?? ????? ?????????'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>??? ?????? ???????</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; }
        body { background: #f5f5f5; padding-top: 20px; }
        .log-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .stat-card { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .log-entry {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-right: 4px solid #007bff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .log-entry.create { border-right-color: #28a745; }
        .log-entry.update { border-right-color: #ffc107; }
        .log-entry.delete { border-right-color: #dc3545; }
        .log-entry.scan { border-right-color: #17a2b8; }
        .log-entry.reset { border-right-color: #e83e8c; }
        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .log-action {
            font-weight: bold;
            font-size: 16px;
        }
        .log-time {
            color: #666;
            font-size: 12px;
        }
        .log-details {
            font-size: 14px;
            color: #555;
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .badge-user {
            background: #667eea;
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
        }
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
<div class="log-container">
    <div class="page-header">
        <h1><i class="fa fa-history"></i> ??? ?????? ???????</h1>
        <a href="../rounds_gate.php" class="btn btn-default"><i class="fa fa-arrow-right"></i> ????</a>
    </div>
    
    <!-- Statistics -->
    <div class="row">
        <div class="col-md-3">
            <div class="stat-card text-center">
                <h2><?= number_format($stats['total']) ?></h2>
                <p>?????? ???????</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <h2><?= number_format(count($stats['by_action'])) ?></h2>
                <p>????? ???????</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <h2><?= number_format(count($stats['by_user'])) ?></h2>
                <p>??? ??????????</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <h2><?= count($logs) ?></h2>
                <p>??????? ????????</p>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="filter-section">
        <h4><i class="fa fa-filter"></i> ???????</h4>
        <form method="GET" class="form-inline">
            <div class="form-group" style="margin-left: 10px;">
                <label>??? ?????:</label>
                <select name="action" class="form-control">
                    <option value="">????</option>
                    <?php foreach ($actionLabels as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $action === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-left: 10px;">
                <label>????????:</label>
                <input type="text" name="username" class="form-control" placeholder="??? ????????" value="<?= htmlspecialchars($username) ?>">
            </div>
            <div class="form-group" style="margin-left: 10px;">
                <label>?? ?????:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>">
            </div>
            <div class="form-group" style="margin-left: 10px;">
                <label>??? ?????:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> ???</button>
            <a href="rounds_log.php" class="btn btn-default"><i class="fa fa-times"></i> ????? ??????</a>
        </form>
    </div>
    
    <!-- Log Entries -->
    <div class="stat-card">
        <h4><i class="fa fa-list"></i> ??????? (<?= count($logs) ?>)</h4>
        <hr>
        
        <?php if (empty($logs)): ?>
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i> ?? ???? ????? ????? ?????? ??????
        </div>
        <?php else: ?>
        <div id="logsList">
            <?php foreach ($logs as $log): ?>
            <?php 
            $actionType = explode('_', $log['action'])[0];
            $cssClass = match($actionType) {
                'create' => 'create',
                'update' => 'update',
                'delete' => 'delete',
                'scan' => 'scan',
                'reset' => 'reset',
                default => ''
            };
            ?>
            <div class="log-entry <?= $cssClass ?>">
                <div class="log-header">
                    <div class="log-action">
                        <?= $actionLabels[$log['action']] ?? $log['action'] ?>
                        <?php if ($log['round_id']): ?>
                        <small style="color: #666;">(???? #<?= $log['round_id'] ?>)</small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="badge-user"><?= htmlspecialchars($log['username']) ?></span>
                        <span class="log-time"><?= $log['timestamp'] ?></span>
                    </div>
                </div>
                
                <?php if (!empty($log['details'])): ?>
                <div class="log-details">
                    <?php foreach ($log['details'] as $key => $value): ?>
                    <div><strong><?= htmlspecialchars($key) ?>:</strong> <?= htmlspecialchars(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>
</html>
