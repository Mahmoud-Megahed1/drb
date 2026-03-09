<?php
/**
 * Member Profile Page - ???? ??????? ?????
 * 
 * ????:
 * - ??????? ?????
 * - ???? ????????
 * - ?????????
 * - ??? ??????/??????
 */

session_start();

$memberCode = $_GET['code'] ?? $_GET['token'] ?? $_GET['badge_id'] ?? '';

// ??????? ????? ?? URL ??? ??? ??????
if (strpos($memberCode, 'http') !== false) {
    $queryString = parse_url($memberCode, PHP_URL_QUERY);
    parse_str($queryString ?? '', $params);
    $memberCode = $params['token'] ?? $params['badge_id'] ?? $params['code'] ?? $memberCode;
}

// ????? ?? ?????
$member = null;
$isCurrentParticipant = false;

$dataFile = __DIR__ . '/admin/data/data.json';
$membersFile = __DIR__ . '/admin/data/members.json';

// ????? ?? ????????? ???????
if (file_exists($dataFile)) {
    $registrations = json_decode(file_get_contents($dataFile), true) ?? [];
    foreach ($registrations as $reg) {
        $matches = [
            $reg['wasel'] ?? '',
            $reg['registration_code'] ?? '',
            $reg['badge_token'] ?? '',
            $reg['badge_id'] ?? ''
        ];
        
        if (in_array($memberCode, $matches, true) || in_array(strval($memberCode), array_map('strval', $matches), true)) {
            $member = $reg;
            $isCurrentParticipant = ($reg['status'] ?? '') === 'approved';
            break;
        }
    }
}

// ????? ?? ????? ??????? ???????
if (!$member && file_exists($membersFile)) {
    $members = json_decode(file_get_contents($membersFile), true) ?? [];
    if (isset($members[$memberCode])) {
        $member = $members[$memberCode];
        $member['registration_code'] = $memberCode;
    } else {
        foreach ($members as $regCode => $m) {
            if (($m['badge_token'] ?? '') === $memberCode || ($m['badge_id'] ?? '') === $memberCode) {
                $member = $m;
                $member['registration_code'] = $regCode;
                break;
            }
        }
    }
}

// ???????? ???????
$roundsEntered = 0;
$roundLogsFile = __DIR__ . '/admin/data/round_logs.json';
if ($member && file_exists($roundLogsFile)) {
    $roundLogs = json_decode(file_get_contents($roundLogsFile), true) ?? [];
    $memberRounds = array_filter($roundLogs, function($log) use ($member, $memberCode) {
        return ($log['participant_id'] ?? '') === ($member['wasel'] ?? $memberCode) && 
               ($log['action'] ?? '') === 'enter';
    });
    $roundsEntered = count(array_unique(array_column($memberRounds, 'round_id')));
}

// ?????????
$violations = [];
$violationsFile = __DIR__ . '/admin/data/violations.json';
if ($member && file_exists($violationsFile)) {
    $allViolations = json_decode(file_get_contents($violationsFile), true) ?? [];
    $violations = array_filter($allViolations, function($v) use ($member, $memberCode) {
        return ($v['member_code'] ?? '') === ($member['registration_code'] ?? $memberCode) && 
               !($v['resolved'] ?? false);
    });
}

// ??? ??????
$entryLogs = [];
$entryLogsFile = __DIR__ . '/admin/data/entry_exit_logs.json';
if ($member && file_exists($entryLogsFile)) {
    $allLogs = json_decode(file_get_contents($entryLogsFile), true) ?? [];
    $entryLogs = array_filter($allLogs, function($log) use ($member, $memberCode) {
        return ($log['member_code'] ?? '') === ($member['registration_code'] ?? $memberCode);
    });
    $entryLogs = array_slice(array_values($entryLogs), 0, 5);
}

$hasBlocker = false;
foreach ($violations as $v) {
    if (($v['type'] ?? '') === 'blocker') {
        $hasBlocker = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>??????? ?????</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .profile-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .profile-header {
            padding: 30px 20px;
            text-align: center;
            color: white;
        }
        .profile-header.participant { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        .profile-header.non-participant { background: linear-gradient(135deg, #6c757d 0%, #495057 100%); }
        .profile-header.blocked { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }
        .profile-header.not-found { background: linear-gradient(135deg, #6c757d 0%, #343a40 100%); }
        
        .avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.3);
            margin: 0 auto 15px;
            background: #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: #999;
            overflow: hidden;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        
        .name { font-size: 22px; font-weight: bold; margin-bottom: 5px; }
        .code { font-size: 14px; opacity: 0.9; }
        .status {
            display: inline-block;
            padding: 8px 20px;
            background: rgba(255,255,255,0.2);
            border-radius: 25px;
            margin-top: 15px;
            font-size: 14px;
        }
        
        .profile-body { padding: 20px; color: #333; }
        
        .stats-row {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .stat-box {
            flex: 1;
            text-align: center;
            padding: 15px 10px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        .stat-box .number { font-size: 28px; font-weight: bold; }
        .stat-box .label { font-size: 12px; color: #666; }
        .stat-box.danger .number { color: #dc3545; }
        .stat-box.success .number { color: #28a745; }
        .stat-box.info .number { color: #007bff; }
        
        .info-section { margin-bottom: 20px; }
        .info-section h4 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .info-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 10px;
        }
        .info-item .label { font-size: 11px; color: #888; }
        .info-item .value { font-size: 14px; font-weight: 600; }
        
        .violation-item {
            background: #fff5f5;
            border: 1px solid #feb2b2;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .violation-item.blocker { background: #fed7d7; border-color: #fc8181; }
        .violation-item .type { font-size: 12px; color: #c53030; font-weight: bold; }
        .violation-item .desc { font-size: 13px; margin-top: 5px; }
        .violation-item .meta { font-size: 11px; color: #999; margin-top: 5px; }
        
        .log-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 8px;
        }
        .log-item .icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }
        .log-item .icon.entry { background: #28a745; }
        .log-item .icon.exit { background: #dc3545; }
        .log-item .info { flex: 1; }
        .log-item .action { font-weight: bold; font-size: 13px; }
        .log-item .gate { font-size: 11px; color: #666; }
        .log-item .time { font-size: 12px; color: #888; }
        
        .not-found-msg {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        .not-found-msg i { font-size: 60px; color: #ddd; margin-bottom: 20px; }
        .not-found-msg h3 { color: #333; margin-bottom: 10px; }
        
        .back-btn {
            display: block;
            text-align: center;
            padding: 15px;
            background: #007bff;
            color: white;
            text-decoration: none;
            font-weight: bold;
            border-radius: 0 0 20px 20px;
        }
    </style>
</head>
<body>
    <div class="profile-card">
        <?php if ($member): ?>
        
        <div class="profile-header <?= $hasBlocker ? 'blocked' : ($isCurrentParticipant ? 'participant' : 'non-participant') ?>">
            <div class="avatar">
                <?php 
                $photoPathRaw = $member['images']['personal_photo'] ?? $member['personal_photo'] ?? '';
                $photoUrl = '';
                if (!empty($photoPathRaw)) {
                    $clean = ltrim(str_replace('../', '', $photoPathRaw), '/');
                    if (file_exists($clean) && !is_dir($clean)) {
                        $photoUrl = $clean;
                    } elseif (file_exists('admin/' . $clean) && !is_dir('admin/' . $clean)) {
                        $photoUrl = 'admin/' . $clean;
                    }
                }
                if ($photoUrl): 
                ?>
                <img src="<?= htmlspecialchars($photoUrl) ?>" alt="">
                <?php else: ?>
                <i class="fa-solid fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="name"><?= htmlspecialchars($member['full_name'] ?? $member['name'] ?? '???') ?></div>
            <div class="code">?????: <?= htmlspecialchars($member['registration_code'] ?? $memberCode) ?></div>
            <div class="status">
                <?php if ($hasBlocker): ?>
                    ?? ?????
                <?php elseif ($isCurrentParticipant): ?>
                    ? ????? ?? ??????? ???????
                <?php else: ?>
                    ? ??? ????? ??????
                <?php endif; ?>
            </div>
        </div>
        
        <div class="profile-body">
            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-box danger">
                    <div class="number"><?= count($violations) ?></div>
                    <div class="label">???????</div>
                </div>
                <div class="stat-box info">
                    <div class="number"><?= $roundsEntered ?></div>
                    <div class="label">?????</div>
                </div>
                <div class="stat-box success">
                    <div class="number"><?= $member['championships_participated'] ?? 1 ?></div>
                    <div class="label">??????</div>
                </div>
            </div>
            
            <!-- Info -->
            <div class="info-section">
                <h4><i class="fa-solid fa-car"></i> ??????? ???????</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="label">?????</div>
                        <div class="value"><?= htmlspecialchars($member['car_type'] ?? '-') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">???????</div>
                        <div class="value"><?= htmlspecialchars($member['car_year'] ?? '-') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">??????</div>
                        <div class="value"><?= htmlspecialchars($member['plate_full'] ?? '-') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">????????</div>
                        <div class="value"><?= htmlspecialchars($member['governorate'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($member['participation_type_label']) || !empty($member['participation_type'])): ?>
            <div class="info-section">
                <h4><i class="fa-solid fa-trophy"></i> ??? ????????</h4>
                <div class="info-item" style="background:#e8f5e9;border:1px solid #a5d6a7">
                    <div class="value" style="color:#2e7d32">
                        <?= htmlspecialchars($member['participation_type_label'] ?? $member['participation_type'] ?? '-') ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Violations -->
            <?php if (!empty($violations)): ?>
            <div class="info-section">
                <h4><i class="fa-solid fa-triangle-exclamation" style="color:#dc3545"></i> ????????? ??????</h4>
                <?php foreach ($violations as $v): ?>
                <div class="violation-item <?= ($v['type'] ?? '') === 'blocker' ? 'blocker' : '' ?>">
                    <div class="type"><?= ($v['type'] ?? '') === 'blocker' ? '?? ???' : '?? ?????' ?></div>
                    <div class="desc"><?= htmlspecialchars($v['description'] ?? '') ?></div>
                    <div class="meta">??????: <?= htmlspecialchars($v['added_by'] ?? '') ?> | <?= $v['added_at'] ?? '' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Entry Logs -->
            <?php if (!empty($entryLogs)): ?>
            <div class="info-section">
                <h4><i class="fa-solid fa-clock-rotate-left"></i> ??? ???????</h4>
                <?php 
                $gateNames = ['main' => '????????', 'arena' => '??????', 'vip' => 'VIP', 'parking' => '??????'];
                foreach ($entryLogs as $log): 
                ?>
                <div class="log-item">
                    <div class="icon <?= $log['action'] ?>">
                        <?= $log['action'] === 'entry' ? '?' : '?' ?>
                    </div>
                    <div class="info">
                        <div class="action"><?= $log['action'] === 'entry' ? '????' : '????' ?></div>
                        <div class="gate"><?= $gateNames[$log['gate']] ?? $log['gate'] ?></div>
                    </div>
                    <div class="time"><?= date('H:i', $log['timestamp'] ?? 0) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Attached Documents -->
            <?php 
            $hasId = !empty($member['images']['id_front']) || !empty($member['images']['national_id_front']) || !empty($member['images']['id_back']) || !empty($member['images']['national_id_back']);
            $hasLicense = !empty($member['images']['license_front']) || !empty($member['images']['license_back']);
            if ($hasId || $hasLicense): 
                function getDocUrl($rawPath) {
                    if (empty($rawPath)) return '';
                    $clean = ltrim(str_replace('../', '', $rawPath), '/');
                    if (file_exists($clean) && !is_dir($clean)) return $clean;
                    if (file_exists('admin/' . $clean) && !is_dir('admin/' . $clean)) return 'admin/' . $clean;
                    // Fallback to directly linking the clean path, allowing the browser to resolve it
                    return $clean; 
                }
            ?>
            <div class="info-section">
                <h4><i class="fa-solid fa-file-image"></i> المستندات المرفقة</h4>
                <div class="info-grid">
                    <?php 
                    $idFront = getDocUrl($member['images']['id_front'] ?? $member['images']['national_id_front'] ?? '');
                    if ($idFront): 
                    ?>
                    <div class="info-item" style="grid-column: span 2; display: flex; align-items: center; justify-content: space-between;">
                        <div class="label"><i class="fa-solid fa-id-card"></i> صورة وجه الهوية</div>
                        <a href="<?= htmlspecialchars($idFront) ?>" target="_blank" class="value" style="color: #007bff; text-decoration: none; font-size: 13px;"><i class="fa-solid fa-eye"></i> عرض الصورة</a>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $idBack = getDocUrl($member['images']['id_back'] ?? $member['images']['national_id_back'] ?? '');
                    if ($idBack): 
                    ?>
                    <div class="info-item" style="grid-column: span 2; display: flex; align-items: center; justify-content: space-between;">
                        <div class="label"><i class="fa-solid fa-id-card"></i> صورة ظهر الهوية</div>
                        <a href="<?= htmlspecialchars($idBack) ?>" target="_blank" class="value" style="color: #007bff; text-decoration: none; font-size: 13px;"><i class="fa-solid fa-eye"></i> عرض الصورة</a>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $licFront = getDocUrl($member['images']['license_front'] ?? '');
                    if ($licFront): 
                    ?>
                    <div class="info-item" style="grid-column: span 2; display: flex; align-items: center; justify-content: space-between;">
                        <div class="label"><i class="fa-solid fa-car-side"></i> صورة وجه إجازة السوق</div>
                        <a href="<?= htmlspecialchars($licFront) ?>" target="_blank" class="value" style="color: #007bff; text-decoration: none; font-size: 13px;"><i class="fa-solid fa-eye"></i> عرض الصورة</a>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $licBack = getDocUrl($member['images']['license_back'] ?? '');
                    if ($licBack): 
                    ?>
                    <div class="info-item" style="grid-column: span 2; display: flex; align-items: center; justify-content: space-between;">
                        <div class="label"><i class="fa-solid fa-car-side"></i> صورة ظهر إجازة السوق</div>
                        <a href="<?= htmlspecialchars($licBack) ?>" target="_blank" class="value" style="color: #007bff; text-decoration: none; font-size: 13px;"><i class="fa-solid fa-eye"></i> عرض الصورة</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        
        <div class="profile-header not-found">
            <div class="avatar"><i class="fa-solid fa-question"></i></div>
            <div class="name">??? ?????</div>
        </div>
        <div class="not-found-msg">
            <i class="fa-solid fa-user-xmark"></i>
            <h3>????? ??? ?????</h3>
            <p>????? ???????: <?= htmlspecialchars($memberCode ?: '(????)') ?></p>
        </div>
        
        <?php endif; ?>
        
        <a href="javascript:history.back()" class="back-btn">
            <i class="fa-solid fa-arrow-right"></i> ????
        </a>
    </div>
</body>
</html>
