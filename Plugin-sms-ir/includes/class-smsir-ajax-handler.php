<?php
namespace SMSIR;

if (!defined('ABSPATH')) {
    exit;
}

class SMSIR_Ajax_Handler {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_sms_ir_get_error_logs', [$this, 'get_error_logs']);
        add_action('wp_ajax_sms_ir_clear_error_logs', [$this, 'clear_error_logs']);
    }

    public function init() {
        // Initialize any required setup
    }

    public function get_error_logs() {
        try {
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You do not have permission to view error logs.', 'sms-ir')]);
                return;
            }

            $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
            $per_page = isset($_POST['per_page']) ? max(1, intval($_POST['per_page'])) : 20;

            $logger = SMSIR_Error_Logger::get_instance();
            $logs = $logger->get_logs($page, $per_page);

            // Debug: Log the response data
            error_log('SMSIR AJAX: Logs data: ' . print_r($logs, true));

            wp_send_json_success($logs);
        } catch (\Exception $e) {
            error_log('SMSIR AJAX: Error getting logs: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function clear_error_logs() {
        try {
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You do not have permission to clear error logs.', 'sms-ir')]);
                return;
            }

            $logger = SMSIR_Error_Logger::get_instance();
            $logger->clear_logs();

            wp_send_json_success(['message' => __('Error logs cleared successfully.', 'sms-ir')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function log_api_error($type, $message, $context = []) {
        $logger = SMSIR_Error_Logger::get_instance();
        $logger->log($type, $message, $context);
    }
} 