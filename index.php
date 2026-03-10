<?php
// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
// Check registration status
function checkRegistrationStatus() {
    $settingsFile = 'admin/data/registration_settings.json';
    $frameSettingsFile = 'admin/data/frame_settings.json';
    $dataFile = 'admin/data/data.json';
    
    // Default settings
    $settings = [
        'max_registrations' => 0,
        'is_open' => true,
        'closed_message' => 'عذراً، انتهت صلاحية التسجيل'
    ];
    
    if (file_exists($settingsFile)) {
        $json = file_get_contents($settingsFile);
        $loaded = json_decode($json, true);
        if (is_array($loaded)) {
            $settings = $loaded;
        }
    }
    
    // Check frame settings for form status
    if (file_exists($frameSettingsFile)) {
        $frameSettings = json_decode(file_get_contents($frameSettingsFile), true);
        $formSettings = $frameSettings['form_settings'] ?? [];
        
        // Check if form is manually closed from frame_settings (REMOVED)
        // These checks have been removed because they are managed via registration_settings.json
        // and saving frame settings was inadvertently disabling the form.
    }
    
    // Check if manually closed
    if (!$settings['is_open']) {
        return ['open' => false, 'message' => $settings['closed_message']];
    }
    
    // Check max registrations
    if ($settings['max_registrations'] > 0) {
        $currentCount = 0;
        if (file_exists($dataFile)) {
            $data = json_decode(file_get_contents($dataFile), true);
            $currentCount = is_array($data) ? count($data) : 0;
        }
        
        if ($currentCount >= $settings['max_registrations']) {
            return ['open' => false, 'message' => $settings['closed_message']];
        }
    }
    
    return ['open' => true, 'message' => ''];
}

// Get banner image
function getBannerImage() {
    $settingsFile = 'admin/data/site_settings.json';
    $defaultBanner = 'images/redbull_logos.png';
    
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        if (!empty($settings['banner_url']) && file_exists($settings['banner_url'])) {
            return $settings['banner_url'];
        }
    }
    
    return $defaultBanner;
}

// Get rules from JSON file
function getRules() {
    $rulesFile = 'admin/data/rules.json';
    $defaultRules = [
        'main_rules' => [],
        'warning_message' => '',
        'important_note' => '',
        'additional_notes' => []
    ];
    
    if (file_exists($rulesFile)) {
        $loaded = json_decode(file_get_contents($rulesFile), true);
        if ($loaded) {
            $rules = array_merge($defaultRules, $loaded);
            // Sort by order
            usort($rules['main_rules'], fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
            usort($rules['additional_notes'], fn($a, $b) => ($a['order'] ?? 0) - ($b['order'] ?? 0));
            return $rules;
        }
    }
    
    return $defaultRules;
}

// Get form titles from settings
function getFormTitles() {
    $frameSettingsFile = 'admin/data/frame_settings.json';
    $defaults = [
        'main_title' => 'استمارة تسجيل',
        'sub_title' => 'حفلة رأس السنة على حلبة نادي بلاد الرافدين لرياضة المحركات'
    ];
    
    if (file_exists($frameSettingsFile)) {
        $settings = json_decode(file_get_contents($frameSettingsFile), true);
        if (!empty($settings['form_titles'])) {
            return array_merge($defaults, $settings['form_titles']);
        }
    }
    
    return $defaults;
}

// Get participation types
function getParticipationTypes() {
    $settingsFile = 'admin/data/registration_settings.json';
    $defaults = [
        ['id' => 'free_show', 'label' => 'المشاركة بالاستعراض الحر', 'enabled' => true],
        ['id' => 'special_car', 'label' => 'المشاركة كسيارة مميزة فقط بدون استعراض', 'enabled' => true],
        ['id' => 'burnout', 'label' => 'المشاركة بفعالية Burnout (عدد محدود)', 'enabled' => true]
    ];
    
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        if (!empty($settings['participation_types'])) {
            return array_filter($settings['participation_types'], fn($t) => $t['enabled'] ?? true);
        }
    }
    
    return $defaults;
}

// Get Group Link
function getGroupLink() {
    $settingsFile = 'admin/data/registration_settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        if (!empty($settings['group_link'])) {
            return $settings['group_link'];
        }
    }
    return 'https://chat.whatsapp.com/BkV9UgvH01m1MzPTEpVlqJ';
}


// Get Support Number
function getSupportNumber() {
    $settingsFile = 'admin/data/registration_settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        if (!empty($settings['support_number'])) {
            return $settings['support_number'];
        }
    }
    return '9647736000096'; // Default Fallback
}

// Get Form Fields Settings
function getFormFieldsSettings() {
    $settingsFile = 'admin/data/form_fields_settings.json';
    
    $defaults = [
        'personal_photo_enabled' => true,
        'personal_photo_required' => true,
        'instagram_enabled' => true,
        'instagram_required' => false,
        'license_images_enabled' => false,
        'license_images_required' => false,
        'id_front_enabled' => true,
        'id_front_required' => true,
        'id_back_enabled' => true,
        'id_back_required' => true
    ];
    
    if (file_exists($settingsFile)) {
        $loaded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($loaded)) {
            return array_merge($defaults, $loaded);
        }
    }
    
    return $defaults;
}

$groupLink = getGroupLink();
$supportNumber = getSupportNumber();
$registrationStatus = checkRegistrationStatus();
$bannerImage = getBannerImage();
$rules = getRules();
$formTitles = getFormTitles();
$participationTypes = getParticipationTypes();
$fieldSettings = getFormFieldsSettings();
?>
<script>
    window.formFieldSettings = <?= json_encode($fieldSettings) ?>;
</script>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>تسجيل سيارات الاستعراض الحر</title>
  <link rel="icon" href="favicon.ico" type="image/x-icon" />
  
  <!-- DNS Preconnect for faster CDN connections -->
  <link rel="preconnect" href="https://code.jquery.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  
  <!-- Preload critical CSS -->
  <?php $cssVersion = time(); ?>
  <link rel="preload" href="css/redbull-style.css?v=<?= $cssVersion ?>" as="style">
  <link rel="stylesheet" type="text/css" href="css/redbull-style.css?v=<?= $cssVersion ?>">
  
  <!-- Optimized Google Fonts loading with font-display -->
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
  
  <!-- Deferred jQuery for non-blocking load -->
  <script src="https://code.jquery.com/jquery-3.6.4.min.js" defer></script>
  
  <!-- GSAP TweenMax - Deferred -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/2.1.3/TweenMax.min.js" defer></script>
  <!-- MorphSVG Plugin - Deferred -->
  <script src="https://assets.codepen.io/16327/MorphSVGPlugin.min.js" defer></script>
</head>

<body>
  <div class="redbull-container">
    
    <!-- Banner Section with Car Animation Wrapper -->
    <div class="banner-animation-wrapper">
      <!-- SVG Car Animation -->
      <svg class="mainSVG" viewBox="0 0 800 600" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <path id="puff" d="M4.5,8.3C6,8.4,6.5,7,6.5,7s2,0.7,2.9-0.1C10,6.4,10.3,4.1,9.1,4c2-0.5,1.5-2.4-0.1-2.9c-1.1-0.3-1.8,0-1.8,0
          s-1.5-1.6-3.4-1C2.5,0.5,2.1,2.3,2.1,2.3S0,2.3,0,4.4c0,1.1,1,2.1,2.2,2.1C2.2,7.9,3.5,8.2,4.5,8.3z" fill="#fff" />
          <circle id="dot" cx="0" cy="0" r="5" fill="#fff" />
        </defs>

        <circle id="mainCircle" fill="none" stroke="none" stroke-width="2" stroke-miterlimit="10" cx="400" cy="300" r="280" />
        <circle id="circlePath" fill="none" stroke="none" stroke-width="2" stroke-miterlimit="10" cx="400" cy="300" r="80" />

        <g id="mainContainer">
          <g id="car">
            <path id="carRot" fill="#FFF" d="M45.6,16.9l0-11.4c0-3-1.5-5.5-4.5-5.5L3.5,0C0.5,0,0,1.5,0,4.5l0,13.4c0,3,0.5,4.5,3.5,4.5l37.6,0
            C44.1,22.4,45.6,19.9,45.6,16.9z M31.9,21.4l-23.3,0l2.2-2.6l14.1,0L31.9,21.4z M34.2,21c-3.8-1-7.3-3.1-7.3-3.1l0-13.4l7.3-3.1
            C34.2,1.4,37.1,11.9,34.2,21z M6.9,1.5c0-0.9,2.3,3.1,2.3,3.1l0,13.4c0,0-0.7,1.5-2.3,3.1C5.8,19.3,5.1,5.8,6.9,1.5z M24.9,3.9
            l-14.1,0L8.6,1.3l23.3,0L24.9,3.9z" />
          </g>
        </g>
      </svg>
      
      <!-- Banner Image in Center -->
      <div class="banner-section">
        <img src="<?= htmlspecialchars($bannerImage) ?>" alt="Event Banner" class="banner-image" loading="eager">
      </div>
    </div>

    <!-- Event Title -->
    <div class="event-title-section">
      <h1 class="main-title"><?= htmlspecialchars($formTitles['main_title']) ?></h1>
      <p class="event-subtitle"><?= htmlspecialchars($formTitles['sub_title']) ?></p>
    </div>

    <?php if (!$registrationStatus['open']): ?>
    <!-- Registration Closed Message -->
    <div class="registration-closed">
      <div class="closed-icon">🚫</div>
      <h2>التسجيل مغلق</h2>
      <p><?= htmlspecialchars($registrationStatus['message']) ?></p>
    </div>
    <?php else: ?>
    
    <!-- Important Notes Section - Collapsible -->
    <div class="important-notes-section" id="termsSection">
      <div class="notes-header terms-toggle" onclick="toggleTerms()" style="cursor: pointer;">
        <span class="notes-icon">📋</span>
        <h3>شروط وقوانين المشاركة في الاستعراض الحر</h3>
        <span class="toggle-arrow" id="termsArrow">▼</span>
      </div>
      
      <div class="terms-content" id="termsContent" style="display: none;">
        <div class="rules-list">
          <?php 
          $numberEmojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
          foreach ($rules['main_rules'] as $i => $rule): 
            $emoji = $numberEmojis[$i] ?? (($i + 1) . '.');
            $isWarning = ($rule['type'] ?? '') === 'warning';
          ?>
          <div class="rule-item<?= $isWarning ? ' warning' : '' ?>">
            <span class="rule-icon"><?= $emoji ?></span>
            <span><?= htmlspecialchars($rule['text']) ?></span>
          </div>
          <?php endforeach; ?>
          
          <?php if (!empty($rules['warning_message'])): ?>
          <div class="rule-item warning" style="background: rgba(255, 0, 0, 0.15); border-left: 4px solid #ff0000;">
            <span class="rule-icon">⚠️</span>
            <span><strong><?= htmlspecialchars($rules['warning_message']) ?></strong></span>
          </div>
          <?php endif; ?>
        </div>
        
        <hr style="border-color: rgba(255,255,255,0.2); margin: 25px 0;">
        
        <div class="subscriber-area-note">
          <?php if (!empty($rules['important_note'])): ?>
          <div class="note-header">
            <span class="note-icon">ℹ️</span>
            <h4>ملاحظة مهمة</h4>
          </div>
          <p class="note-main"><?= htmlspecialchars($rules['important_note']) ?></p>
          <?php endif; ?>
          
          <?php if (!empty($rules['additional_notes'])): ?>
          <div class="notes-header" style="margin-top: 20px;">
            <span class="notes-icon">📝</span>
            <h4 style="color: #ffc107; margin: 0;">ملاحظات مهمة</h4>
          </div>
          
          <div class="additional-notes" style="margin-top: 15px;">
            <?php foreach ($rules['additional_notes'] as $i => $note): 
              $emoji = $numberEmojis[$i] ?? (($i + 1) . '.');
              $isWarning = ($note['type'] ?? '') === 'warning';
            ?>
            <div class="note-item"<?= $isWarning ? ' style="background: rgba(255, 0, 0, 0.1); padding: 10px; border-radius: 8px;"' : '' ?>>
              <span class="bullet"><?= $emoji ?></span>
              <span><?= $isWarning ? '<strong>' : '' ?><?= htmlspecialchars($note['text']) ?><?= $isWarning ? '</strong>' : '' ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Registration Form -->
    <div class="form-container">
      <form name="registrationForm" method="post" enctype="multipart/form-data" onsubmit="submitForm(event)" id="mainForm">

        <!-- Quick Registration Section -->
        <div class="form-section" style="background: linear-gradient(135deg, rgba(0,123,255,0.1), rgba(0,200,150,0.1)); border: 2px solid rgba(0,123,255,0.3);">
          <h3 class="section-title" style="color: #00bfff;">
            <span class="section-number" style="background: linear-gradient(135deg, #007bff, #00c896);">⚡</span>
            تسجيل سريع (اختياري)
          </h3>
          <p style="color: #aaa; font-size: 13px; margin-bottom: 15px;">هل سجلت معنا من قبل؟ أدخل كود التسجيل السابق لملء بياناتك تلقائياً</p>
          
          <div class="form-field">
            <label for="registration_code">كود التسجيل السابق</label>
            <div style="display: flex; gap: 10px;">
              <input type="text" id="registration_code" placeholder="مثال: DR2X7K" 
                     style="flex: 1; text-transform: uppercase; letter-spacing: 2px; font-weight: bold;"
                     maxlength="6" oninput="this.value = this.value.toUpperCase()">
              <button type="button" onclick="lookupCode()" 
                      style="padding: 10px 20px; background: linear-gradient(135deg, #007bff, #0056b3); color: white; border: none; border-radius: 8px; cursor: pointer; font-family: inherit;">
                🔍 بحث
              </button>
            </div>
            <p id="codeLookupStatus" style="margin-top: 10px; font-size: 12px;"></p>
          </div>
        </div>

        <!-- Section: Participation Type -->
        <div class="form-section">
          <h3 class="section-title">
            <span class="section-number">1</span>
            نوع المشاركة
          </h3>
          
          <div class="form-field">
            <label for="participation_type">اختر نوع المشاركة في الفعالية</label>
            <select name="participation_type" id="participation_type" required>
              <option value="">اختر نوع المشاركة</option>
              <?php foreach ($participationTypes as $type): ?>
              <option value="<?= htmlspecialchars($type['id']) ?>"><?= htmlspecialchars($type['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Section: Personal Info -->
        <div class="form-section">
          <h3 class="section-title">
            <span class="section-number">2</span>
            بيانات المشترك
          </h3>

          <div class="form-field">
            <label for="full_name">الاسم الثلاثي باللغة العربية</label>
            <input type="text" name="full_name" id="full_name" required 
                   placeholder="مثال: أحمد محمد علي"
                   pattern="[\u0621-\u064A\u0660-\u0669\s]+"
                   title="يرجى كتابة الاسم باللغة العربية فقط">
          </div>

          <div class="form-field">
            <label for="phone">رقم الهاتف (10 أرقام)</label>
            <div class="phone-input-group">
              <select name="country_code" id="country_code" class="country-code-select">
                <option value="+964" selected>🇮🇶 +964</option>
                <option value="+218">🇱🇾 +218</option>
                <option value="+20">🇪🇬 +20</option>
                <option value="+966">🇸🇦 +966</option>
                <option value="+971">🇦🇪 +971</option>
                <option value="+965">🇰🇼 +965</option>
                <option value="+974">🇶🇦 +974</option>
                <option value="+968">🇴🇲 +968</option>
                <option value="+973">🇧🇭 +973</option>
                <option value="+962">🇯🇴 +962</option>
                <option value="+961">🇱🇧 +961</option>
                <option value="+963">🇸🇾 +963</option>
              </select>
              <input type="tel" name="phone" id="phone" required 
                     placeholder="ادخل رقم الهاتف" 
                     maxlength="15"
                     oninput="validatePhoneInput(this)"
                     title="يرجى إدخال رقم هاتف صحيح">
              <small id="phoneError" style="color: #dc3545; display: none; margin-top: 5px;"></small>
            </div>
          </div>

          <div class="form-field">
            <label for="governorate">محافظة المشترك</label>
            <select name="governorate" id="governorate" required>
              <option value="">اختر المحافظة</option>
              <option value="بغداد">بغداد (11)</option>
              <option value="البصرة">البصرة (14)</option>
              <option value="نينوى">نينوى (12)</option>
              <option value="الأنبار">الأنبار (15)</option>
              <option value="كربلاء">كربلاء (19)</option>
              <option value="النجف">النجف (30)</option>
              <option value="ذي قار">ذي قار (27)</option>
              <option value="القادسية">القادسية (16)</option>
              <option value="المثنى">المثنى (17)</option>
              <option value="واسط">واسط (31)</option>
              <option value="صلاح الدين">صلاح الدين (26)</option>
              <option value="ديالى">ديالى (20)</option>
              <option value="ميسان">ميسان (13)</option>
              <option value="بابل">بابل (18)</option>
              <option value="كركوك">كركوك (25)</option>
              <option value="أربيل">أربيل (22)</option>
              <option value="السليمانية">السليمانية (21)</option>
              <option value="دهوك">دهوك (24)</option>
              <option value="حلبجة">حلبجة (23)</option>
            </select>
          </div>

          <?php if ($fieldSettings['instagram_enabled']): ?>
          <div class="form-field">
            <label for="instagram">حساب الانستقرام</label>
            <div style="position: relative;">
               <i class="fa-brands fa-instagram" style="position: absolute; right: 15px; top: 15px; color: #aaa;"></i>
               <input type="text" name="instagram" id="instagram" 
                      placeholder="username" style="padding-right: 40px; text-align: left; direction: ltr;"
                      <?= $fieldSettings['instagram_required'] ? 'required' : '' ?>>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Section: Car Info -->
        <div class="form-section">
          <h3 class="section-title">
            <span class="section-number">3</span>
            بيانات السيارة
          </h3>

          <div class="form-field">
            <label for="car_type">نوع السيارة</label>
            <input type="text" name="car_type" id="car_type" required placeholder="مثال: BMW M3">
          </div>

          <div class="form-row">
            <div class="form-field half">
              <label for="car_year">سنة الصنع</label>
              <input type="number" name="car_year" id="car_year" min="1980" max="2025" required placeholder="مثال: 2023">
            </div>

            <div class="form-field half">
              <label for="car_color">لون السيارة</label>
              <input type="text" name="car_color" id="car_color" required placeholder="مثال: أحمر">
            </div>
          </div>

          <div class="form-field">
            <label for="engine_size">حجم المحرك</label>
            <select name="engine_size" id="engine_size" required>
              <option value="">اختر حجم المحرك</option>
              <option value="8_cylinder_natural">8 سلندر تنفس طبيعي</option>
              <option value="8_cylinder_boost">8 سلندر بوست</option>
              <option value="6_cylinder_natural">6 سلندر تنفس طبيعي</option>
              <option value="6_cylinder_boost">6 سلندر بوست</option>
              <option value="4_cylinder">4 سلندر</option>
              <option value="4_cylinder_boost">4 سلندر بوست</option>
              <option value="other">أخرى</option>
            </select>
          </div>

          <div class="form-field">
            <label>بيانات لوحة السيارة</label>
            <div class="plate-inputs">
              <div class="plate-field">
                <select name="plate_governorate" id="plate_governorate" required>
                  <option value="">المحافظة</option>
                  <option value="بغداد">بغداد (11)</option>
                  <option value="البصرة">البصرة (14)</option>
                  <option value="نينوى">نينوى (12)</option>
                  <option value="الأنبار">الأنبار (15)</option>
                  <option value="كربلاء">كربلاء (19)</option>
                  <option value="النجف">النجف (30)</option>
                  <option value="ذي قار">ذي قار (27)</option>
                  <option value="القادسية">القادسية (16)</option>
                  <option value="المثنى">المثنى (17)</option>
                  <option value="واسط">واسط (31)</option>
                  <option value="صلاح الدين">صلاح الدين (26)</option>
                  <option value="ديالى">ديالى (20)</option>
                  <option value="ميسان">ميسان (13)</option>
                  <option value="بابل">بابل (18)</option>
                  <option value="كركوك">كركوك (25)</option>
                  <option value="أربيل">أربيل (22)</option>
                  <option value="السليمانية">السليمانية (21)</option>
                  <option value="دهوك">دهوك (24)</option>
                  <option value="حلبجة">حلبجة (23)</option>
                </select>
              </div>
              <div class="plate-field">
                <input type="text" name="plate_letter" id="plate_letter" maxlength="3" required placeholder="الرمز (A)">
              </div>
              <div class="plate-field">
                <input type="text" name="plate_number" id="plate_number" maxlength="6" required placeholder="الرقم (12345)">
              </div>
            </div>
          </div>
        </div>

        <!-- Section: Image Uploads -->
        <div class="form-section">
          <h3 class="section-title">
            <span class="section-number">4</span>
            رفع الصور المطلوبة
          </h3>

          <div class="upload-grid">
            <div class="upload-field">
              <label for="front_image">
                <span class="upload-icon">📸</span>
                صورة أمام السيارة مع ظهور اللوحة
              </label>
              <input type="file" name="front_image" id="front_image" accept="image/*" required>
              <div class="file-preview" id="front_image_preview"></div>
            </div>

            <div class="upload-field">
              <label for="back_image">
                <span class="upload-icon">📸</span>
                صورة خلف السيارة مع ظهور اللوحة
              </label>
              <input type="file" name="back_image" id="back_image" accept="image/*" required>
              <div class="file-preview" id="back_image_preview"></div>
            </div>

            <?php if ($fieldSettings['id_front_enabled']): ?>
            <div class="upload-field">
              <label for="id_front">
                <span class="upload-icon">🪪</span>
                صورة وجه الهوية
              </label>
              <input type="file" name="id_front" id="id_front" accept="image/*" <?= $fieldSettings['id_front_required'] ? 'required' : '' ?>>
              <div class="file-preview" id="id_front_preview"></div>
            </div>
            <?php endif; ?>

            <?php if ($fieldSettings['id_back_enabled']): ?>
            <div class="upload-field">
              <label for="id_back">
                <span class="upload-icon">🪪</span>
                صورة ظهر الهوية
              </label>
              <input type="file" name="id_back" id="id_back" accept="image/*" <?= $fieldSettings['id_back_required'] ? 'required' : '' ?>>
              <div class="file-preview" id="id_back_preview"></div>
            </div>
            <?php endif; ?>

            <?php if ($fieldSettings['license_images_enabled']): ?>
            <div class="upload-field">
              <label for="license_front">
                <span class="upload-icon">🚗</span>
                صورة إجازة السوق (الوجه)
              </label>
              <input type="file" name="license_front" id="license_front" accept="image/*" <?= $fieldSettings['license_images_required'] ? 'required' : '' ?>>
              <div class="file-preview" id="license_front_preview"></div>
            </div>

            <div class="upload-field">
              <label for="license_back">
                <span class="upload-icon">🚗</span>
                صورة إجازة السوق (الظهر)
              </label>
              <input type="file" name="license_back" id="license_back" accept="image/*" <?= $fieldSettings['license_images_required'] ? 'required' : '' ?>>
              <div class="file-preview" id="license_back_preview"></div>
            </div>
            <?php endif; ?>

            <?php if ($fieldSettings['personal_photo_enabled']): ?>
            <div class="upload-field">
              <label for="personal_photo">
                <span class="upload-icon">👤</span>
                صورة شخصية <?= $fieldSettings['personal_photo_required'] ? '' : '(اختياري)' ?>
              </label>
              <input type="file" name="personal_photo" id="personal_photo" accept="image/*" <?= $fieldSettings['personal_photo_required'] ? 'required' : '' ?>>
              <div class="file-preview" id="personal_photo_preview"></div>
            </div>
            <?php endif; ?>
          </div>

          <p class="upload-note">⚠️ الحد الأقصى لحجم كل ملف: 100 ميجابايت</p>
        </div>

        <!-- Status Message -->
        <p class="status-message"></p>

        <!-- Terms Agreement Checkbox -->
        <div class="terms-agreement" style="margin: 20px 0; padding: 15px; background: rgba(255,193,7,0.1); border-radius: 10px; border: 1px solid rgba(255,193,7,0.3);">
          <label class="checkbox-container" style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
            <input type="checkbox" id="agreeTerms" name="agreeTerms" required style="width: 20px; height: 20px; cursor: pointer;">
            <span style="color: #fff; font-size: 14px;">
              ✅ أوافق على <span style="color: #ffc107; text-decoration: underline; cursor: pointer;" onclick="scrollToTerms(); event.preventDefault();">الشروط والقوانين</span> المذكورة أعلاه
            </span>
          </label>
        </div>

        <!-- Submit Button -->
        <button class="submit-button" id="btnsubmit" type="submit">
          إرسال الطلب
        </button>
        
        <a href="https://wa.me/<?= $supportNumber ?>" target="_blank" class="help-button">
          📞 مركز المساعدة
        </a>
      </form>
    </div>
    <?php endif; ?>

  </div>

  <script>
    // Toggle Terms function - open/close when clicking header
    function toggleTerms() {
      var content = document.getElementById('termsContent');
      var arrow = document.getElementById('termsArrow');
      
      if (content.style.display === 'none') {
        content.style.display = 'block';
        arrow.textContent = '▲';
      } else {
        content.style.display = 'none';
        arrow.textContent = '▼';
      }
    }
    
    // Scroll to terms and open them (called from checkbox link)
    function scrollToTerms() {
      var content = document.getElementById('termsContent');
      var arrow = document.getElementById('termsArrow');
      var termsSection = document.getElementById('termsSection');
      
      // Open the terms if closed
      if (content.style.display === 'none') {
        content.style.display = 'block';
        arrow.textContent = '▲';
      }
      
      // Scroll to terms section smoothly
      if (termsSection) {
        termsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

    // Global variable to store previous images
    var previousImages = null;
    var usedRegistrationCode = null;

    // Lookup registration code and auto-fill form
    function lookupCode() {
      var code = document.getElementById('registration_code').value.trim().toUpperCase();
      var statusEl = document.getElementById('codeLookupStatus');
      
      if (!code || code.length < 6) {
        statusEl.innerHTML = '<span style="color: #ffc107;">⚠️ يرجى إدخال كود مكون من 6 أحرف</span>';
        return;
      }
      
      statusEl.innerHTML = '<span style="color: #00bfff;">🔄 جاري البحث...</span>';
      
      fetch('lookup_code.php?code=' + encodeURIComponent(code) + '&t=' + Date.now())
        .then(function(response) { return response.json(); })
        .then(function(result) {
          if (result.success && result.data) {
            var data = result.data;
            
            // Store the registration code for later use
            usedRegistrationCode = data.registration_code || code;
            
            // Auto-fill form fields
            if (data.full_name) document.getElementById('full_name').value = data.full_name;
            if (data.phone) document.getElementById('phone').value = data.phone;
            if (data.country_code) document.getElementById('country_code').value = data.country_code;
            if (data.governorate) document.getElementById('governorate').value = data.governorate;
            if (data.car_type) document.getElementById('car_type').value = data.car_type;
            if (data.car_year) document.getElementById('car_year').value = data.car_year;
            if (data.car_color) document.getElementById('car_color').value = data.car_color;
            if (data.engine_size) document.getElementById('engine_size').value = data.engine_size;
            if (data.plate_letter) document.getElementById('plate_letter').value = data.plate_letter;
            if (data.plate_number) document.getElementById('plate_number').value = data.plate_number;
            if (data.plate_governorate) document.getElementById('plate_governorate').value = data.plate_governorate;
            if (data.participation_type) document.getElementById('participation_type').value = data.participation_type;
            
            // Fill instagram if available
            var instagramField = document.getElementById('instagram');
            if (data.instagram && instagramField) instagramField.value = data.instagram;
            
            // Handle images - show previews and store paths
            if (data.images && Object.keys(data.images).length > 0) {
              previousImages = data.images;
              
              // Show image previews for each image field
              var imageFields = ['front_image', 'back_image', 'id_front', 'id_back', 'personal_photo', 'license_front', 'license_back'];
              imageFields.forEach(function(field) {
                if (data.images[field]) {
                  var previewEl = document.getElementById(field + '_preview');
                  if (previewEl) {
                    previewEl.innerHTML = '<img src="' + data.images[field] + '" alt="صورة سابقة" style="max-width: 100%; border-radius: 8px; border: 2px solid #28a745;">' +
                                         '<span style="display: block; color: #28a745; font-size: 11px; margin-top: 5px;">✅ صورة سابقة - يمكنك استبدالها</span>';
                  }
                  // Remove required from file input
                  var fileInput = document.getElementById(field);
                  if (fileInput) {
                    fileInput.removeAttribute('required');
                  }
                }
              });
            } else {
              previousImages = null;
            }
            
            // Check if already registered in current championship
            if (data.is_registered_current) {
              var hasImages = data.images && Object.keys(data.images).some(function(k) { return data.images[k]; });
              var photoMsg = '';
              if (!hasImages) {
                photoMsg = '<div style="margin-top: 12px; padding: 12px; background: rgba(0,123,255,0.15); border: 1px solid rgba(0,123,255,0.4); border-radius: 8px;">' +
                  '<span style="color: #00bfff; font-size: 13px;">📷 <strong>لا توجد لديك صور في النظام!</strong></span><br>' +
                  '<span style="color: #ccc; font-size: 12px;">لرفع صورك: أكمل ملء الاستمارة أدناه وأرفق الصور المطلوبة ثم اضغط إرسال.</span>' +
                  '</div>';
              }
              statusEl.innerHTML = '<span style="color: #ffc107;">⚠️ <strong>أنت مسجل بالفعل بهذه البطولة!</strong> رقم تسجيلك: #' + data.current_wasel + '</span><br>' +
                                   '<span style="color: #aaa; font-size: 12px;">يمكنك تعديل بياناتك وصورك وإرسال الطلب من جديد</span>' + photoMsg;
            } else {
              var hasImages2 = data.images && Object.keys(data.images).some(function(k) { return data.images[k]; });
              var photoMsg2 = '';
              if (!hasImages2) {
                photoMsg2 = '<div style="margin-top: 12px; padding: 12px; background: rgba(255,193,7,0.15); border: 1px solid rgba(255,193,7,0.4); border-radius: 8px;">' +
                  '<span style="color: #ffc107; font-size: 13px;">📷 <strong>ملاحظة:</strong> لا توجد لديك صور مرفوعة سابقاً</span><br>' +
                  '<span style="color: #ccc; font-size: 12px;">يرجى رفع الصور المطلوبة في الحقول أدناه لإكمال تسجيلك بنجاح.</span>' +
                  '</div>';
              }
              statusEl.innerHTML = '<span style="color: #28a745;">✅ تم ملء البيانات بنجاح!</span><br>' +
                                   '<span style="color: #aaa; font-size: 12px;">الصور السابقة معروضة - يمكنك استبدالها أو الإبقاء عليها</span>' + photoMsg2;
            }
          } else {
            statusEl.innerHTML = '<span style="color: #dc3545;">❌ ' + (result.error || 'الكود غير موجود') + '</span>';
            previousImages = null;
          }
        })
        .catch(function(error) {
          statusEl.innerHTML = '<span style="color: #dc3545;">❌ خطأ في الاتصال</span>';
          console.error(error);
          previousImages = null;
        });
    }

    // Wait for jQuery and DOM to be ready
    function initFormHandlers() {
      const MAX_FILE_SIZE = 100 * 1024 * 1024; // 100MB

      // File preview handler
      $('input[type="file"]').on('change', function() {
        const file = this.files[0];
        const previewId = this.id + '_preview';
        const $preview = $('#' + previewId);
        
        if (file) {
          // Check file size
          if (file.size > MAX_FILE_SIZE) {
            alert('حجم الملف كبير جداً! الحد الأقصى هو 100 ميجابايت');
            $(this).val('');
            $preview.html('');
            return;
          }
          // Generate compressed preview and store the compressed file on the input object
          compressImageJS(file, 1200, 0.7, function(compressedBlob) {
            // Update preview to use compressed data
            const reader = new FileReader();
            reader.onload = function(e) {
              $preview.html('<img src="' + e.target.result + '" alt="Preview">');
            }
            reader.readAsDataURL(compressedBlob);
            
            // Convert Blob to File and attach it to the DOM element for later use
            const compressedFile = new File([compressedBlob], file.name.replace(/\.[^/.]+$/, "") + ".webp", {
                type: "image/webp",
                lastModified: new Date().getTime()
            });
            // Store it as a custom property on the input element
            document.getElementById(this.id).compressedFile = compressedFile;
          }.bind(this));
        } else {
          $preview.html('');
        }
      });
      
      // Client-side image compression function
      function compressImageJS(file, maxWidth, quality, callback) {
          const reader = new FileReader();
          reader.readAsDataURL(file);
          reader.onload = function(event) {
              const img = new Image();
              img.src = event.target.result;
              img.onload = function() {
                  let width = img.width;
                  let height = img.height;

                  if (width > height) {
                      if (width > maxWidth) {
                          height = Math.round((height *= maxWidth / width));
                          width = maxWidth;
                      }
                  } else {
                      if (height > maxWidth) {
                          width = Math.round((width *= maxWidth / height));
                          height = maxWidth;
                      }
                  }

                  const canvas = document.createElement('canvas');
                  canvas.width = width;
                  canvas.height = height;
                  const ctx = canvas.getContext('2d');
                  ctx.drawImage(img, 0, 0, width, height);

                  canvas.toBlob(function(blob) {
                      callback(blob);
                  }, 'image/webp', quality); // Convert to WebP for massive size reduction
              };
          };
      }

      // Form submission
      window.submitForm = function(event) {
        event.preventDefault();
        $('#btnsubmit').prop('disabled', true);

        // Validate form
        const form = document.getElementById('mainForm');
        if (!form.checkValidity()) {
          form.reportValidity();
          $('#btnsubmit').prop('disabled', false);
          return;
        }

        // Build dynamic required files list based on settings
        const settings = window.formFieldSettings || {};
        const requiredFiles = [];
        const fileLabels = {
            'front_image': 'صورة أمام السيارة',
            'back_image': 'صورة خلف السيارة',
            'id_front': 'صورة وجه الهوية',
            'id_back': 'صورة ظهر الهوية',
            'personal_photo': 'الصورة الشخصية',
            'license_front': 'صورة إجازة السوق (الوجه)',
            'license_back': 'صورة إجازة السوق (الظهر)'
        };

        // Always required car images
        requiredFiles.push('front_image');
        requiredFiles.push('back_image');

        if (settings.id_front_required) requiredFiles.push('id_front');
        if (settings.id_back_required) requiredFiles.push('id_back');
        if (settings.personal_photo_required) requiredFiles.push('personal_photo');
        if (settings.license_images_required) {
            requiredFiles.push('license_front');
            requiredFiles.push('license_back');
        }

        // Check files
        for (const fileId of requiredFiles) {
          // Skip check if field is disabled
          if (fileId === 'id_front' && !settings.id_front_enabled) continue;
          if (fileId === 'id_back' && !settings.id_back_enabled) continue;
          if (fileId === 'personal_photo' && !settings.personal_photo_enabled) continue;
          if ((fileId === 'license_front' || fileId === 'license_back') && !settings.license_images_enabled) continue;

          const fileInput = document.getElementById(fileId);
          if (!fileInput) continue; // Should not happen if HTML is correct

          const file = fileInput.files[0];
          const hasPreviousImage = previousImages && previousImages[fileId];
          
          // If no new file AND no previous image, require upload
          if (!file && !hasPreviousImage) {
            alert('يرجى رفع: ' + fileLabels[fileId]);
            $('#btnsubmit').prop('disabled', false);
            return;
          }
          
          // Check file size if new file uploaded
          if (file && file.size > MAX_FILE_SIZE) {
            alert('حجم الملف كبير جداً: ' + fileLabels[fileId]);
            $('#btnsubmit').prop('disabled', false);
            return;
          }
        }
        
        // Also check if any optional file is uploaded but too large
        const allFileInputs = document.querySelectorAll('input[type="file"]');
        for (const input of allFileInputs) {
            if (input.files[0] && input.files[0].size > MAX_FILE_SIZE) {
                alert('حجم الملف كبير جداً: ' + (fileLabels[input.id] || input.id));
                 $('#btnsubmit').prop('disabled', false);
                 return;
            }
        }

        $('.status-message').html('جاري إرسال البيانات والصور ...').css('color', '#ffffff');

        const formData = new FormData();
        // Manually append all text fields from the form
        for (const [key, value] of new FormData(form).entries()) {
            // Skip the file inputs from the raw form data
            if (value instanceof File) continue;
            formData.append(key, value);
        }
        
        // Append COMPRESSED files
        const allFileInputsForUpload = document.querySelectorAll('input[type="file"]');
        for (const input of allFileInputsForUpload) {
            if (input.compressedFile) {
                // We have a compressed version ready
                formData.append(input.name, input.compressedFile, input.compressedFile.name);
            } else if (input.files[0]) {
                // Fallback to original if compression failed or didn't run
                formData.append(input.name, input.files[0], input.files[0].name);
            }
        }
        
        // Add previous images paths to form data (if using quick registration)
        if (previousImages) {
          formData.append('previous_images', JSON.stringify(previousImages));
        }
        
        // Add registration code if used
        if (usedRegistrationCode) {
          formData.append('used_registration_code', usedRegistrationCode);
        }

        $.ajax({
          url: 'process.php',
          type: 'POST',
          data: formData,
          contentType: false,
          processData: false,
          xhr: function() {
            var xhr = new window.XMLHttpRequest();
            xhr.upload.addEventListener('progress', function(e) {
              if (e.lengthComputable) {
                var percent = Math.round((e.loaded / e.total) * 100);
                $('.status-message').html('جاري رفع الصور: ' + percent + '%').css('color', '#ffffff');
              }
            });
            return xhr;
          },
          success: function(response) {
            var lines = (response || '').split('\n');
            var waselNum = '';
            var regCode = usedRegistrationCode || '';
            for (var i = 0; i < lines.length; i++) {
              if (lines[i].indexOf('رقم التسجيل:') !== -1) {
                waselNum = lines[i].replace('رقم التسجيل:', '').trim();
              }
              if (lines[i].indexOf('كود التسجيل:') !== -1) {
                regCode = lines[i].replace('كود التسجيل:', '').trim();
              }
            }
            
            // Get the phone number for check_status redirect
            var phoneVal = document.getElementById('phone') ? document.getElementById('phone').value : '';
            
            var successMsg = '✅ تم إرسال طلبك بنجاح!<br>سيتم مراجعة طلبك وإرسال رسالة لك عند القبول';
            if (regCode) {
              successMsg += '<div style="margin-top: 15px; padding: 15px; background: rgba(0,123,255,0.15); border: 1px solid rgba(0,123,255,0.4); border-radius: 10px; text-align: center;">' +
                '<span style="color: #00bfff; font-size: 14px;">🔑 كود التسجيل الخاص بك:</span><br>' +
                '<span style="color: #fff; font-size: 22px; font-weight: bold; letter-spacing: 3px; display: inline-block; margin: 8px 0; padding: 8px 20px; background: rgba(255,255,255,0.1); border-radius: 8px; cursor: pointer;" onclick="navigator.clipboard.writeText(\'' + regCode + '\'); this.style.color=\'#4CAF50\'; this.innerHTML=\'' + regCode + ' ✓ تم النسخ\'">' + regCode + '</span><br>' +
                '<span style="color: #aaa; font-size: 12px;">📌 احتفظ بهذا الكود! يمكنك استخدامه في البطولات القادمة لملء بياناتك تلقائياً</span>' +
                '</div>';
            }
            
            
            $('.status-message').html(successMsg).css('color', '#00ff00');
            
            $('#mainForm')[0].reset();
            $('.file-preview').html('');
            $('#btnsubmit').prop('disabled', true);
          },
          error: function(xhr, status, error) {
            console.error(error);
            console.error(xhr.responseText);
            $('.status-message').html('❌ حدث خطأ أثناء الإرسال. يرجى المحاولة مرة أخرى.<br>' + (xhr.responseText || error)).css('color', '#ff0000');
            $('#btnsubmit').prop('disabled', false);
          }
        });
      }
    }
    
    // Initialize when jQuery is ready
    if (typeof jQuery !== 'undefined') {
      $(document).ready(function() {
        initFormHandlers();
        autoFillFromUrl();
      });
    } else {
      document.addEventListener('DOMContentLoaded', function checkJQuery() {
        if (typeof jQuery !== 'undefined') {
          $(document).ready(function() {
            initFormHandlers();
            autoFillFromUrl();
          });
        } else {
          setTimeout(checkJQuery, 50);
        }
      });
    }

    // Auto-fill form from URL parameter ?code=XXXXXX (used for re-registration after rejection)
    function autoFillFromUrl() {
      var urlParams = new URLSearchParams(window.location.search);
      var codeParam = urlParams.get('code');
      if (codeParam && codeParam.length >= 4) {
        var codeInput = document.getElementById('registration_code');
        if (codeInput) {
          codeInput.value = codeParam.toUpperCase();
          // Auto-trigger lookup after a small delay
          setTimeout(function() {
            lookupCode();
          }, 500);
        }
      }
    }
  </script>

  <!-- GSAP Car Animation Script -->
  <script>
    // Wait for GSAP libraries to load
    function initCarAnimation() {
    let rValue;
    if (window.innerWidth <= 768) {
      rValue = 200; // شاشة موبايل - دائرة كبيرة خارج البانر
    } else {
      rValue = 280; // شاشة كمبيوتر - دائرة كبيرة خارج البانر
    }

    TweenMax.set('#circlePath', {
      attr: {
        r: rValue
      }
    });

    MorphSVGPlugin.convertToPath('#circlePath');

    var xmlns = "http://www.w3.org/2000/svg",
      xlinkns = "http://www.w3.org/1999/xlink",
      select = function (s) {
        return document.querySelector(s);
      },
      selectAll = function (s) {
        return document.querySelectorAll(s);
      },
      mainCircle = select('#mainCircle'),
      mainContainer = select('#mainContainer'),
      car = select('#car'),
      mainSVG = select('.mainSVG'),
      mainCircleRadius;

    if (window.innerWidth <= 768) {
      mainCircleRadius = 200; // شاشة موبايل - نفس rValue
    } else {
      mainCircleRadius = 280; // شاشة كمبيوتر
    }

    numDots = mainCircleRadius / 2,
      step = 360 / numDots,
      dotMin = 0,
      circlePath = select('#circlePath')

    TweenMax.set('svg', {
      visibility: 'visible'
    })

    const isMobile = window.innerWidth <= 768;
    const scaleValue = isMobile ? 2.8 : 3.2;

    TweenMax.set([car], {
      transformOrigin: '50% 50%',
      scale: scaleValue
    });

    TweenMax.set('#carRot', {
      transformOrigin: '0% 0%',
      rotation: 30
    })

    var circleBezier = MorphSVGPlugin.pathDataToBezier(circlePath.getAttribute('d'), {
      offsetX: -20,
      offsetY: -5
    })

    var mainTl = new TimelineMax();

    function makeDots() {
      var d, angle, tl;
      for (var i = 0; i < numDots; i++) {
        d = select('#puff').cloneNode(true);
        mainContainer.appendChild(d);
        angle = step * i;
        TweenMax.set(d, {
          x: (Math.cos(angle * Math.PI / 180) * mainCircleRadius) + 400,
          y: (Math.sin(angle * Math.PI / 180) * mainCircleRadius) + 300,
          rotation: Math.random() * 360,
          transformOrigin: '50% 50%'
        })

        const isMobile = window.innerWidth <= 768;
        const extraScale = isMobile ? 4 : 3.5;

        tl = new TimelineMax({
          repeat: -1
        });
        tl
          .from(d, 0.2, {
            scale: 0,
            ease: Power4.easeIn
          })
          .to(d, 1.8, {
            scale: Math.random() + extraScale,
            alpha: 0,
            ease: Power4.easeOut
          })

        mainTl.add(tl, i / (numDots / tl.duration()))
      }

      var carTl = new TimelineMax({
        repeat: -1
      });
      carTl.to(car, tl.duration(), {
        bezier: {
          type: "cubic",
          values: circleBezier,
          autoRotate: true
        },
        ease: Linear.easeNone
      })
      mainTl.add(carTl, 0.05)
    }

    makeDots();
    mainTl.time(120);

    TweenMax.to(mainContainer, 20, {
      rotation: -360,
      svgOrigin: '400 300',
      repeat: -1,
      ease: Linear.easeNone
    });

    mainTl.timeScale(1.1)
    } // End initCarAnimation
    
    // Initialize animation when GSAP is ready
    function checkGSAP() {
      if (typeof TweenMax !== 'undefined' && typeof MorphSVGPlugin !== 'undefined') {
        initCarAnimation();
      } else {
        setTimeout(checkGSAP, 50);
      }
    }
    
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', checkGSAP);
    } else {
      checkGSAP();
    }
    
// Phone validation function
    function validatePhoneInput(input) {
      if (!input) input = document.getElementById('phone');
      
      const countryCode = document.getElementById('country_code').value;
      const errorEl = document.getElementById('phoneError');
      
      // Remove non-digits
      let value = input.value.replace(/\D/g, '');
      input.value = value;
      
      // Specific Rules for Iraq (+964)
      if (countryCode === '+964') {
        // Auto-normalize Iraqi formats
        if (value.startsWith('964')) {
          value = value.substring(3);
          input.value = value;
        }
        
        // If it starts with 07 and is 11 digits, strip the 0 to make it 10 digits
        if (value.length === 11 && value.startsWith('07')) {
          value = value.substring(1);
          input.value = value;
        }

        if (value.length === 0) {
            errorEl.style.display = 'none';
            input.style.borderColor = '';
        } else if (value.length !== 10) {
            errorEl.textContent = 'يجب إدخال 10 أرقام (مثال: 780xxxxxxx)';
            errorEl.style.display = 'block';
            input.style.borderColor = '#dc3545';
        } else if (!value.startsWith('7')) {
            errorEl.textContent = 'رقم الهاتف العراقي يجب أن يبدأ بـ 7';
            errorEl.style.display = 'block';
            input.style.borderColor = '#dc3545';
        } else {
            errorEl.style.display = 'none';
            input.style.borderColor = '#28a745';
        }
      } else {
        // International Rules
        if (value.length > 0 && (value.length < 8 || value.length > 15)) {
            errorEl.textContent = 'رقم الهاتف يجب أن يكون بين 8 و 15 رقم';
            errorEl.style.display = 'block';
            input.style.borderColor = '#dc3545';
        } else {
            errorEl.style.display = 'none';
            input.style.borderColor = (value.length > 0) ? '#28a745' : '';
        }
      }
    }

    // Add listener for country change
    document.getElementById('country_code').addEventListener('change', function() {
        validatePhoneInput(document.getElementById('phone'));
    });
  </script>
</body>
</html>
