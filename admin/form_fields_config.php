<?php
/**
 * Form Fields Configuration
 * ??????? ???? ?????????
 * 
 * ??? ????? ????? ??? ????????? ??????? ??????? ?????????
 */

// Load from settings file
function getFormFieldsSettings() {
    $settingsFile = __DIR__ . '/data/form_fields_settings.json';
    
    $defaults = [
        // Personal Photo
        'personal_photo_enabled' => true,
        'personal_photo_required' => true,
        
        // Instagram
        'instagram_enabled' => true,
        'instagram_required' => false,
        
        // License Images
        'license_images_enabled' => false,
        'license_images_required' => false,
        
        // Other fields
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

function saveFormFieldsSettings($settings) {
    $settingsFile = __DIR__ . '/data/form_fields_settings.json';
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
