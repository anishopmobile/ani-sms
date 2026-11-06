<?php
namespace SMSIR;

class FormsIntegration {
    private static $instance = null;
    private $sms_client;
    private $notifications;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->sms_client = new SMSClient();
        
        if (class_exists('WPCF7')) {
            require_once SMSIR_DIR . 'includes/contact-form-7/class-cf7-notifications.php';
            $this->notifications['cf7'] = new CF7_Notifications();
        }
        
        if (class_exists('WPForms')) {
            require_once SMSIR_DIR . 'includes/wpforms/class-wpforms-notifications.php';
            $this->notifications['wpforms'] = new WPForms_Notifications();
        }

    }
    
} 