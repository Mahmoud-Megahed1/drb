<?php
/**
 * Participation Statistics Widget
 * ???????? ????? ????????
 */

// Load data
$dataFile = __DIR__ . '/admin/data/data.json';
$inputs = [];
if (file_exists($dataFile)) {
    $inputs = json_decode(file_get_contents($dataFile), true) ?? [];
}

// Load participation types from registration settings
$regSettingsFile = __DIR__ . '/admin/data/registration_settings.json';
$participationLabels = [];
if (file_exists($regSettingsFile)) {
    $regSettings = json_decode(file_get_contents($regSettingsFile), true) ?? [];
    $participationTypes = $regSettings['participation_types'] ?? [];
    foreach ($participationTypes as $pt) {
        $participationLabels[$pt['id']] = $pt['label'];
    }
}

// Fallback to defaults
if (empty($participationLabels)) {
    $participationLabels = [
        'free_show' => '??????? ??',
        'special_car' => '????? ?????',
        'burnout' => 'Burnout'
    ];
}

// Calculate statistics
$participationStats = [];
foreach ($inputs as $input) {
    $status = $input['status'] ?? 'pending';
    $pType = $input['participation_type'] ?? 'unknown';
    
    if (!isset($participationStats[$pType])) {
        $participationStats[$pType] = ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
    }
    $participationStats[$pType]['total']++;
    if ($status === 'approved') {
        $participationStats[$pType]['approved']++;
    } elseif ($status === 'pending') {
        $participationStats[$pType]['pending']++;
    } else {
        $participationStats[$pType]['rejected']++;
    }
}

// Color palette for stats
$colors = [
    ['#667eea', '#764ba2'],
    ['#f093fb', '#f5576c'],
    ['#4facfe', '#00f2fe'],
    ['#43e97b', '#38f9d7'],
    ['#fa709a', '#fee140'],
    ['#a8edea', '#fed6e3']
];
$colorIndex = 0;
?>

<?php if (!empty($participationStats)): ?>
<div class="panel panel-default" style="margin-top: 15px;">
    <div class="panel-heading">
        <h4 style="margin: 0;">?? ???????? ??? ??? ????????</h4>
    </div>
    <div class="panel-body">
        <div class="row">
            <?php foreach ($participationStats as $type => $stats): 
                $typeLabel = $participationLabels[$type] ?? $type;
                $color = $colors[$colorIndex % count($colors)];
                $colorIndex++;
            ?>
            <div class="col-md-3 col-sm-6" style="margin-bottom: 15px;">
                <div style="background: linear-gradient(135deg, <?= $color[0] ?> 0%, <?= $color[1] ?> 100%); 
                            padding: 20px; border-radius: 10px; text-align: center; color: white; 
                            box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                    <h4 style="margin: 0 0 10px 0; font-size: 16px;"><?= htmlspecialchars($typeLabel) ?></h4>
                    <h2 style="margin: 10px 0; font-size: 32px; font-weight: bold;"><?= number_format($stats['total']) ?></h2>
                    <p style="margin: 0; font-size: 12px;">
                        ? <?= $stats['approved'] ?> ????? | 
                        ? <?= $stats['pending'] ?> ???????
                    </p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
