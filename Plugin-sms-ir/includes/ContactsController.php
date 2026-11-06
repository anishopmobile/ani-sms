<?php
namespace SMSIR;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class ContactsController
 * Handles AJAX requests related to contacts management.
 */
class ContactsController {

    private static $instance = null;
    private $sms_client;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->sms_client = new SMSClient(); // Assuming SMSClient is available in this scope

        // AJAX hook for getting contacts
        add_action('wp_ajax_smsir_get_contacts', [$this, 'ajax_get_contacts']);

        // AJAX hook for exporting contacts
        add_action('wp_ajax_smsir_export_contacts', [$this, 'ajax_export_contacts']);

        // AJAX hook for sending bulk SMS
        add_action('wp_ajax_smsir_send_bulk_sms', [$this, 'ajax_send_bulk_sms']);
    }

    /**
     * AJAX handler to get contacts.
     */
    public function ajax_get_contacts() {
        check_ajax_referer('sms_ir_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to access this data.', 'sms-ir')], 403);
            return;
        }

        $plugin_settings = get_option('smsir_settings', []);
        $custom_fields_settings = isset($plugin_settings['settings']['custom_fields']) && is_array($plugin_settings['settings']['custom_fields']) 
                                ? $plugin_settings['settings']['custom_fields'] 
                                : [];

        $users_data = [];
        $users = get_users(['fields' => ['ID', 'display_name', 'user_email']]); // Added user_email

        foreach ($users as $user) {
            $phone = get_user_meta($user->ID, 'smsir_phone', true);
            if (!$phone) {
                $phone = get_user_meta($user->ID, 'phone', true);
            }

            $processed_custom_fields = [];
            if (is_array($custom_fields_settings)) { // Ensure it's an array before looping
                foreach ($custom_fields_settings as $field_definition) {
                    if (!empty($field_definition['meta_key'])) {
                        $meta_key = $field_definition['meta_key'];
                        $label = !empty($field_definition['label']) ? $field_definition['label'] : $meta_key;
                        $value = get_user_meta($user->ID, $meta_key, true);
                        
                        $processed_custom_fields[] = [
                            'key'   => $meta_key,
                            'label' => $label,
                            'value' => $value ? $value : ''
                        ];
                    }
                }
            }

            $users_data[] = [
                'id'            => $user->ID,
                'name'          => $user->display_name,
                'email'         => $user->user_email, // Included email
                'phone'         => $phone ? $phone : '',
                'custom_fields' => $processed_custom_fields
            ];
        }

        wp_send_json_success($users_data);
    }

    /**
     * AJAX handler to export contacts to CSV.
     */
    public function ajax_export_contacts() {
        // Nonce check for GET request from window.open
        if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field($_GET['nonce']), 'sms_ir_admin')) {
            wp_die(__('Nonce verification failed.', 'sms-ir'), '', ['response' => 403]);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to export this data.', 'sms-ir'), '', ['response' => 403]);
        }

        $users = get_users(['fields' => ['ID', 'display_name', 'user_email']]);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=smsir-contacts-' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        // CSV Header
        // TODO: Allow customization of these fields via settings, including custom meta keys
        $csv_header = [
            __('User ID', 'sms-ir'),
            __('Name', 'sms-ir'),
            __('Email', 'sms-ir'),
            __('Phone', 'sms-ir'),
            // Add more headers for custom fields if defined
        ];
        fputcsv($output, $csv_header);

        foreach ($users as $user) {
            $phone = get_user_meta($user->ID, 'smsir_phone', true);
            if (!$phone) {
                $phone = get_user_meta($user->ID, 'phone', true);
            }

            $row = [
                $user->ID,
                $user->display_name,
                $user->user_email,
                $phone ? $phone : 'N/A',
                // Fetch and add custom meta values here
            ];
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }

    /**
     * AJAX handler to send bulk SMS.
     */
    public function ajax_send_bulk_sms() {
        check_ajax_referer('sms_ir_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'sms-ir')], 403);
            return;
        }

        $contact_ids_json = isset($_POST['contact_ids']) ? stripslashes($_POST['contact_ids']) : '[]';
        $message_content = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

        if (empty($contact_ids_json) || empty($message_content)) {
            wp_send_json_error(['message' => __('Missing contact IDs or message content.', 'sms-ir')], 400);
            return;
        }

        $contact_ids = json_decode($contact_ids_json, true);
        if (empty($contact_ids) || !is_array($contact_ids)) {
            wp_send_json_error(['message' => __('Invalid contact IDs format.', 'sms-ir')], 400);
            return;
        }

        $target_phones = [];
        $invalid_users = [];

        foreach ($contact_ids as $user_id) {
            $user_id = intval($user_id);
            $user = get_userdata($user_id);
            if (!$user) {
                $invalid_users[] = ['user_id' => $user_id, 'reason' => 'User not found'];
                continue;
            }

            $phone = get_user_meta($user_id, 'smsir_phone', true);
            if (!$phone) {
                $phone = get_user_meta($user_id, 'phone', true);
            }

            if ($phone) {
                $target_phones[] = $phone;
            } else {
                $invalid_users[] = ['user_id' => $user_id, 'reason' => 'Phone number not found'];
            }
        }

        if (empty($target_phones)) {
            wp_send_json_error([
                'message' => __('No valid phone numbers found for the selected contacts.', 'sms-ir'),
                'invalid_users' => $invalid_users
            ], 400);
            return;
        }

        // Remove duplicate phone numbers to avoid sending multiple times to the same number if users share phones
        $unique_target_phones = array_unique($target_phones);

        try {
            $result = $this->sms_client->send_bulk_sms($unique_target_phones, $message_content);

            if (isset($result['success']) && $result['success']) {
                wp_send_json_success([
                    'message' => sprintf(
                        __('Bulk SMS submitted successfully to %d unique phone numbers. Invalid contacts: %d.', 'sms-ir'), 
                        count($unique_target_phones), 
                        count($invalid_users)
                    ),
                    'sent_count' => count($unique_target_phones),
                    'failed_pre_check' => count($invalid_users),
                    'invalid_user_details' => $invalid_users,
                    'api_response' => $result // Include full API response if needed
                ]);
            } else {
                wp_send_json_error([
                    'message' => __('SMS API Error when sending bulk SMS.', 'sms-ir'),
                    'failed_pre_check' => count($invalid_users),
                    'invalid_user_details' => $invalid_users,
                    'api_response' => $result // Include API response for debugging
                ], 500);
            }
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => __('An exception occurred: ', 'sms-ir') . $e->getMessage(),
                'failed_pre_check' => count($invalid_users),
                'invalid_user_details' => $invalid_users
            ], 500);
        }
    }
} 