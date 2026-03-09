<?php
/**
 * Rules Settings - إدارة الشروط والقوانين
 * يمكن من خلالها إضافة/تعديل/حذف/ترتيب الشروط
 */
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('location:../login.php');
    exit;
}

$rulesFile = 'data/rules.json';
$message = '';
$messageType = '';

// Load current rules
$defaultRules = [
    'main_rules' => [],
    'warning_message' => '',
    'important_note' => '',
    'additional_notes' => []
];

$rules = $defaultRules;
if (file_exists($rulesFile)) {
    $loaded = json_decode(file_get_contents($rulesFile), true);
    if ($loaded) {
        $rules = array_merge($defaultRules, $loaded);
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'add_rule':
            $type = $input['rule_type'] ?? 'main_rules';
            $text = trim($input['text'] ?? '');
            $ruleStyle = $input['style'] ?? 'normal';
            
            if (!empty($text)) {
                $newId = 1;
                if (!empty($rules[$type])) {
                    $maxId = max(array_column($rules[$type], 'id'));
                    $newId = $maxId + 1;
                }
                
                $newRule = [
                    'id' => $newId,
                    'text' => $text,
                    'order' => count($rules[$type]) + 1
                ];
                
                if ($type === 'main_rules') {
                    $newRule['type'] = $ruleStyle;
                }
                if ($type === 'additional_notes' && $ruleStyle === 'warning') {
                    $newRule['type'] = 'warning';
                }
                
                $rules[$type][] = $newRule;
                file_put_contents($rulesFile, json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo json_encode(['success' => true, 'message' => 'تم إضافة الشرط بنجاح']);
            } else {
                echo json_encode(['success' => false, 'message' => 'النص مطلوب']);
            }
            exit;
            
        case 'update_rule':
            $type = $input['rule_type'] ?? 'main_rules';
            $id = intval($input['id'] ?? 0);
            $text = trim($input['text'] ?? '');
            $ruleStyle = $input['style'] ?? 'normal';
            
            if ($id > 0 && !empty($text)) {
                foreach ($rules[$type] as &$rule) {
                    if ($rule['id'] === $id) {
                        $rule['text'] = $text;
                        if ($type === 'main_rules') {
                            $rule['type'] = $ruleStyle;
                        }
                        if ($type === 'additional_notes') {
                            if ($ruleStyle === 'warning') {
                                $rule['type'] = 'warning';
                            } else {
                                unset($rule['type']);
                            }
                        }
                        break;
                    }
                }
                file_put_contents($rulesFile, json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo json_encode(['success' => true, 'message' => 'تم تحديث الشرط']);
            } else {
                echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
            }
            exit;
            
        case 'delete_rule':
            $type = $input['rule_type'] ?? 'main_rules';
            $id = intval($input['id'] ?? 0);
            
            if ($id > 0) {
                $rules[$type] = array_values(array_filter($rules[$type], function($rule) use ($id) {
                    return $rule['id'] !== $id;
                }));
                
                // Re-order
                foreach ($rules[$type] as $i => &$rule) {
                    $rule['order'] = $i + 1;
                }
                
                file_put_contents($rulesFile, json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo json_encode(['success' => true, 'message' => 'تم حذف الشرط']);
            } else {
                echo json_encode(['success' => false, 'message' => 'معرف غير صالح']);
            }
            exit;
            
        case 'reorder':
            $type = $input['rule_type'] ?? 'main_rules';
            $order = $input['order'] ?? [];
            
            if (!empty($order)) {
                $newRules = [];
                foreach ($order as $i => $id) {
                    foreach ($rules[$type] as $rule) {
                        if ($rule['id'] == $id) {
                            $rule['order'] = $i + 1;
                            $newRules[] = $rule;
                            break;
                        }
                    }
                }
                $rules[$type] = $newRules;
                file_put_contents($rulesFile, json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo json_encode(['success' => true, 'message' => 'تم تحديث الترتيب']);
            }
            exit;
            
        case 'update_messages':
            $rules['warning_message'] = trim($input['warning_message'] ?? '');
            $rules['important_note'] = trim($input['important_note'] ?? '');
            file_put_contents($rulesFile, json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo json_encode(['success' => true, 'message' => 'تم حفظ الرسائل']);
            exit;
    }
}

// Sort rules by order
usort($rules['main_rules'], fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
usort($rules['additional_notes'], fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الشروط والقوانين</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #fff;
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .header h1 { color: #ffc107; font-size: 24px; }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-back { background: #6c757d; color: #fff; }
        .btn-primary { background: linear-gradient(135deg, #007bff, #0056b3); color: #fff; }
        .btn-success { background: linear-gradient(135deg, #28a745, #20c997); color: #fff; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }
        
        .card {
            background: rgba(255,255,255,0.05);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .card h3 {
            color: #ffc107;
            margin-bottom: 15px;
            font-size: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .rules-list {
            list-style: none;
            padding: 0;
        }
        
        .rule-item {
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            cursor: grab;
            transition: all 0.3s;
            border-right: 4px solid transparent;
        }
        
        .rule-item:hover { background: rgba(255,255,255,0.05); }
        .rule-item.dragging { opacity: 0.5; background: rgba(0,123,255,0.2); }
        .rule-item.warning { border-right-color: #ffc107; }
        .rule-item.danger { border-right-color: #dc3545; }
        
        .rule-handle {
            color: #666;
            cursor: grab;
            font-size: 18px;
        }
        
        .rule-number {
            min-width: 30px;
            height: 30px;
            background: linear-gradient(135deg, #ffc107, #ff9800);
            color: #000;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        
        .rule-text {
            flex: 1;
            line-height: 1.6;
        }
        
        .rule-actions {
            display: flex;
            gap: 8px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #aaa;
            font-size: 13px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #ffc107;
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .add-form {
            background: rgba(0,123,255,0.1);
            border: 1px dashed rgba(0,123,255,0.3);
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            display: none;
        }
        
        .add-form.show { display: block; }
        
        .radio-group {
            display: flex;
            gap: 15px;
            margin-top: 5px;
        }
        
        .radio-group label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            color: #fff;
        }
        
        .message {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        
        .message.success {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid #28a745;
            color: #28a745;
        }
        
        .message.error {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid #dc3545;
            color: #dc3545;
        }
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-overlay.show { display: flex; }
        
        .modal {
            background: #1a1a2e;
            border-radius: 15px;
            padding: 25px;
            width: 90%;
            max-width: 500px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .modal h3 {
            color: #ffc107;
            margin-bottom: 20px;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .rule-item { flex-wrap: wrap; }
            .rule-actions { width: 100%; justify-content: flex-end; margin-top: 10px; }
        }

        /* Icons */
        .fa-solid, .fa-regular, .fa-brands { margin-left: 8px; }
    </style>


</head>
<body>
    <?php include '../include/navbar-custom.php'; ?>
    <div class="header">
        <h1><i class="fa-solid fa-gavel"></i> إدارة الشروط والقوانين</h1>
        <div>
            
        </div>
    </div>
    
    <div id="messageContainer"></div>
    
    <!-- Main Rules -->
    <div class="card">
        <h3>
            <span><i class="fa-solid fa-scroll"></i> شروط وقوانين المشاركة</span>
            <button class="btn btn-primary btn-sm" onclick="toggleAddForm('main_rules')"><i class="fa-solid fa-plus"></i> إضافة شرط</button>
        </h3>
        
        <ul class="rules-list" id="mainRulesList" data-type="main_rules">
            <?php foreach ($rules['main_rules'] as $i => $rule): ?>
            <li class="rule-item <?= ($rule['type'] ?? '') === 'warning' ? 'warning' : '' ?>" data-id="<?= $rule['id'] ?>">
                <span class="rule-handle"><i class="fa-solid fa-grip-vertical"></i></span>
                <span class="rule-number"><?= $i + 1 ?></span>
                <span class="rule-text"><?= htmlspecialchars($rule['text']) ?></span>
                <div class="rule-actions">
                    <button class="btn btn-warning btn-sm" onclick="editRule('main_rules', <?= $rule['id'] ?>, '<?= addslashes($rule['text']) ?>', '<?= $rule['type'] ?? 'normal' ?>')"><i class="fa-solid fa-edit"></i></button>
                    <button class="btn btn-danger btn-sm" onclick="deleteRule('main_rules', <?= $rule['id'] ?>)"><i class="fa-solid fa-trash"></i></button>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        
        <div class="add-form" id="addForm_main_rules">
            <div class="form-group">
                <label>نص الشرط</label>
                <textarea class="form-control" id="newRuleText_main_rules" placeholder="اكتب نص الشرط هنا..."></textarea>
            </div>
            <div class="form-group">
                <label>نوع الشرط</label>
                <div class="radio-group">
                    <label><input type="radio" name="ruleType_main_rules" value="normal" checked> عادي</label>
                    <label><input type="radio" name="ruleType_main_rules" value="warning"> <i class="fa-solid fa-exclamation-triangle"></i> تحذير</label>
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-success" onclick="addRule('main_rules')"><i class="fa-solid fa-save"></i> حفظ</button>
                <button class="btn btn-back" onclick="toggleAddForm('main_rules')">إلغاء</button>
            </div>
        </div>
    </div>
    
    <!-- Warning Message -->
    <div class="card">
        <h3><i class="fa-solid fa-exclamation-triangle"></i> رسالة التحذير الرئيسية</h3>
        <div class="form-group">
            <textarea class="form-control" id="warningMessage" placeholder="رسالة التحذير الرئيسية..."><?= htmlspecialchars($rules['warning_message']) ?></textarea>
        </div>
        <button class="btn btn-success" onclick="saveMessages()"><i class="fa-solid fa-save"></i> حفظ</button>
    </div>
    
    <!-- Important Note -->
    <div class="card">
        <h3><i class="fa-solid fa-info-circle"></i> ملاحظة مهمة</h3>
        <div class="form-group">
            <textarea class="form-control" id="importantNote" placeholder="الملاحظة المهمة..."><?= htmlspecialchars($rules['important_note']) ?></textarea>
        </div>
        <button class="btn btn-success" onclick="saveMessages()"><i class="fa-solid fa-save"></i> حفظ</button>
    </div>
    
    <!-- Additional Notes -->
    <div class="card">
        <h3>
            <span><i class="fa-solid fa-clipboard-list"></i> ملاحظات إضافية</span>
            <button class="btn btn-primary btn-sm" onclick="toggleAddForm('additional_notes')"><i class="fa-solid fa-plus"></i> إضافة ملاحظة</button>
        </h3>
        
        <ul class="rules-list" id="additionalNotesList" data-type="additional_notes">
            <?php foreach ($rules['additional_notes'] as $i => $note): ?>
            <li class="rule-item <?= ($note['type'] ?? '') === 'warning' ? 'danger' : '' ?>" data-id="<?= $note['id'] ?>">
                <span class="rule-handle"><i class="fa-solid fa-grip-vertical"></i></span>
                <span class="rule-number"><?= $i + 1 ?></span>
                <span class="rule-text"><?= htmlspecialchars($note['text']) ?></span>
                <div class="rule-actions">
                    <button class="btn btn-warning btn-sm" onclick="editRule('additional_notes', <?= $note['id'] ?>, '<?= addslashes($note['text']) ?>', '<?= $note['type'] ?? 'normal' ?>')"><i class="fa-solid fa-edit"></i></button>
                    <button class="btn btn-danger btn-sm" onclick="deleteRule('additional_notes', <?= $note['id'] ?>)"><i class="fa-solid fa-trash"></i></button>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        
        <div class="add-form" id="addForm_additional_notes">
            <div class="form-group">
                <label>نص الملاحظة</label>
                <textarea class="form-control" id="newRuleText_additional_notes" placeholder="اكتب نص الملاحظة هنا..."></textarea>
            </div>
            <div class="form-group">
                <label>نوع الملاحظة</label>
                <div class="radio-group">
                    <label><input type="radio" name="ruleType_additional_notes" value="normal" checked> عادي</label>
                    <label><input type="radio" name="ruleType_additional_notes" value="warning"> <i class="fa-solid fa-exclamation-triangle"></i> تحذير</label>
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-success" onclick="addRule('additional_notes')"><i class="fa-solid fa-save"></i> حفظ</button>
                <button class="btn btn-back" onclick="toggleAddForm('additional_notes')">إلغاء</button>
            </div>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <h3><i class="fa-solid fa-edit"></i> تعديل الشرط</h3>
            <input type="hidden" id="editRuleId">
            <input type="hidden" id="editRuleType">
            <div class="form-group">
                <label>نص الشرط</label>
                <textarea class="form-control" id="editRuleText"></textarea>
            </div>
            <div class="form-group">
                <label>نوع الشرط</label>
                <div class="radio-group">
                    <label><input type="radio" name="editStyle" value="normal"> عادي</label>
                    <label><input type="radio" name="editStyle" value="warning"> <i class="fa-solid fa-exclamation-triangle"></i> تحذير</label>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-back" onclick="closeModal()">إلغاء</button>
                <button class="btn btn-success" onclick="saveEdit()"><i class="fa-solid fa-save"></i> حفظ</button>
            </div>
        </div>
    </div>
    
    <script>
    // Drag and Drop functionality
    let draggedItem = null;
    
    document.querySelectorAll('.rules-list').forEach(list => {
        const items = list.querySelectorAll('.rule-item');
        
        items.forEach(item => {
            item.setAttribute('draggable', true);
            
            item.addEventListener('dragstart', function(e) {
                draggedItem = this;
                this.classList.add('dragging');
            });
            
            item.addEventListener('dragend', function() {
                this.classList.remove('dragging');
                updateOrder(list);
            });
            
            item.addEventListener('dragover', function(e) {
                e.preventDefault();
                const afterElement = getDragAfterElement(list, e.clientY);
                if (afterElement == null) {
                    list.appendChild(draggedItem);
                } else {
                    list.insertBefore(draggedItem, afterElement);
                }
            });
        });
    });
    
    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.rule-item:not(.dragging)')];
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
    
    function updateOrder(list) {
        const type = list.dataset.type;
        const items = list.querySelectorAll('.rule-item');
        const order = Array.from(items).map(item => item.dataset.id);
        
        fetch('rules_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reorder', rule_type: type, order: order })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showMessage('تم تحديث الترتيب', 'success');
                // Update numbers
                items.forEach((item, i) => {
                    item.querySelector('.rule-number').textContent = i + 1;
                });
            }
        });
    }
    
    function toggleAddForm(type) {
        const form = document.getElementById('addForm_' + type);
        form.classList.toggle('show');
    }
    
    function addRule(type) {
        const text = document.getElementById('newRuleText_' + type).value.trim();
        const style = document.querySelector(`input[name="ruleType_${type}"]:checked`).value;
        
        if (!text) {
            showMessage('يرجى إدخال نص الشرط', 'error');
            return;
        }
        
        fetch('rules_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_rule', rule_type: type, text: text, style: style })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                location.reload();
            } else {
                showMessage(data.message, 'error');
            }
        });
    }
    
    function editRule(type, id, text, style) {
        document.getElementById('editRuleType').value = type;
        document.getElementById('editRuleId').value = id;
        document.getElementById('editRuleText').value = text;
        document.querySelector(`input[name="editStyle"][value="${style}"]`).checked = true;
        document.getElementById('editModal').classList.add('show');
    }
    
    function closeModal() {
        document.getElementById('editModal').classList.remove('show');
    }
    
    function saveEdit() {
        const type = document.getElementById('editRuleType').value;
        const id = document.getElementById('editRuleId').value;
        const text = document.getElementById('editRuleText').value.trim();
        const style = document.querySelector('input[name="editStyle"]:checked').value;
        
        if (!text) {
            showMessage('يرجى إدخال نص الشرط', 'error');
            return;
        }
        
        fetch('rules_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_rule', rule_type: type, id: id, text: text, style: style })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                location.reload();
            } else {
                showMessage(data.message, 'error');
            }
        });
    }
    
    function deleteRule(type, id) {
        if (!confirm('هل أنت متأكد من حذف هذا الشرط؟')) return;
        
        fetch('rules_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_rule', rule_type: type, id: id })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                location.reload();
            } else {
                showMessage(data.message, 'error');
            }
        });
    }
    
    function saveMessages() {
        const warningMessage = document.getElementById('warningMessage').value.trim();
        const importantNote = document.getElementById('importantNote').value.trim();
        
        fetch('rules_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_messages', warning_message: warningMessage, important_note: importantNote })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
            } else {
                showMessage(data.message, 'error');
            }
        });
    }
    
    function showMessage(text, type) {
        const container = document.getElementById('messageContainer');
        container.innerHTML = `<div class="message ${type}">${text}</div>`;
        setTimeout(() => container.innerHTML = '', 3000);
    }
    </script>
</body>
</html>





