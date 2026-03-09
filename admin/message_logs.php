<?php
/**
 * Message Logs - سجلات الرسائل
 * تم دمجها في صفحة whatsapp_log.php - هذه الصفحة تعيد التوجيه
 */

require_once '../include/auth.php';
requireAuth();

// Redirect to unified WhatsApp log page
header('Location: whatsapp_log.php');
exit;
