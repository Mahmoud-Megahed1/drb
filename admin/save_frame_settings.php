<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

$settingsFile = 'data/frame_settings.json';

// Handle different actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Handle overlay upload
    if (isset($_POST['action']) && $_POST['action'] === 'upload_overlay') {
        if (isset($_FILES['overlay']) && $_FILES['overlay']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/overlays/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $filename = time() . '_' . basename($_FILES['overlay']['name']);
            $targetPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['overlay']['tmp_name'], $targetPath)) {
                // Load current settings
                $settings = [];
                if (file_exists($settingsFile)) {
                    $settings = json_decode(file_get_contents($settingsFile), true);
                }
                
                if (!isset($settings['overlays'])) {
                    $settings['overlays'] = [];
                }
                
                // Add new overlay
                $settings['overlays'][] = [
                    'image' => 'uploads/overlays/' . $filename,
                    'x_percent' => 50,
                    'y_percent' => 50,
                    'size_percent' => 20
                ];
                
                file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'فشل رفع الملف']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'لم يتم اختيار ملف']);
        }
        exit;
    }
    
    // Handle overlay removal
    if (isset($_POST['action']) && $_POST['action'] === 'remove_overlay') {
        $index = intval($_POST['index']);
        
        $settings = [];
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true);
        }
        
        if (isset($settings['overlays'][$index])) {
            // Delete file
            $imagePath = '../' . $settings['overlays'][$index]['image'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
            
            // Remove from array
            array_splice($settings['overlays'], $index, 1);
            
            file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'الصورة غير موجودة']);
        }
        exit;
    }
    
    // Handle settings save (JSON body)
    $input = file_get_contents('php://input');
    $newSettings = json_decode($input, true);
    
    if ($newSettings) {
        // Load existing settings to preserve overlays
        $existingSettings = [];
        if (file_exists($settingsFile)) {
            $existingSettings = json_decode(file_get_contents($settingsFile), true);
        }
        
        // Merge settings
        $settings = array_merge($existingSettings, $newSettings);
        
        // Preserve overlays if not in new settings
        if (!isset($newSettings['overlays']) && isset($existingSettings['overlays'])) {
            $settings['overlays'] = $existingSettings['overlays'];
        }
        
        if (file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            // AUTO-SYNC: Update all registrations with new frame settings
            $dataFile = 'data/data.json';
            if (file_exists($dataFile)) {
                $data = json_decode(file_get_contents($dataFile), true);
                if (is_array($data)) {
                    $updated = 0;
                    foreach ($data as $index => $reg) {
                        if (($reg['status'] ?? '') === 'approved') {
                            $data[$index]['saved_frame_settings'] = $settings;
                            $updated++;
                        }
                    }
                    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'تم حفظ الإعدادات وتحديث التسجيلات']);
        } else {
            echo json_encode(['success' => false, 'message' => 'فشل حفظ الملف']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صالحة']);
}
?>
