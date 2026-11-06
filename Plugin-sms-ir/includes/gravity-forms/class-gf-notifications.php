<?php
namespace SMSIR;

if (!defined('ABSPATH')) {
    exit;
}


class GF_Notifications {
    
    private $sms_client;

    public function __construct() {
        $this->sms_client = new SMSClient();
        add_action('gform_after_submission', [$this, 'handle_form_submission'], 10, 2);
        
        // Listen to the existing payment gateway hooks
        add_action('gform_post_payment_status', [$this, 'handle_payment_status'], 10, 8);

        
        // Keep the original hooks for compatibility with other gateways
        add_action('gform_post_payment_completed', [$this, 'handle_payment_completed'], 10, 2);
        add_action('gform_post_payment_failed', [$this, 'handle_payment_failed'], 10, 2);
    }


    public function handle_payment_status($config, $entry, $status, $transaction_id, $subscriber_id, $amount, $pending_reason, $reason) {
        $status = strtolower($status);
        
        if ($status === 'completed' || $status === 'paid' || $status === 'active') {
            // Create action data array for compatibility
            $action_data = array(
                'transaction_id' => $transaction_id,
                'amount' => $amount,
                'payment_status' => $status
            );
            $this->handle_payment_completed($entry, $action_data);
        } elseif ($status === 'failed' || $status === 'cancelled' || $status === 'canceled') {
            // Create action data array for compatibility
            $action_data = array(
                'transaction_id' => $transaction_id,
                'payment_status' => $status,
                'error_message' => $reason
            );
            $this->handle_payment_failed($entry, $action_data);
        }
    }


    public function handle_form_submission($entry, $form) {
        $form_id = $form['id'];
        $rows = get_option('sms_ir_form_notification_rows', '[]');
        $rows_array = json_decode(stripslashes($rows), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('SMSIR: Invalid JSON in form notification rows');
            return;
        }
        foreach ($rows_array as $row) {
            if ($row['form_type'] !== 'gravity_forms' || $row['form_id'] != $form_id) {
                continue;
            }
            $config = $row['config'];
            $recipient_types = $row['recipient_types'] ?? [];
            // Admin notification
            if (in_array('admin', $recipient_types)) {
                $admin_mobile_numbers = $config['admin_mobile_numbers'] ?? '';
                $template_id = $config['admin_template_id'] ?? '';
                $message = $config['admin_message'] ?? '';
                if (!empty($admin_mobile_numbers) && !empty($template_id)) {
                    $parameters = $this->extract_parameters($form, $entry, $message);
                  
                    $admin_numbers = array_map('trim', explode(',', $admin_mobile_numbers));
                    foreach ($admin_numbers as $admin_number) {
                        if (!empty($admin_number)) {
                            $this->send_sms($admin_number, $template_id, $parameters);
                        }
                    }
                }
            }
            // User notification
            if (in_array('user', $recipient_types)) {
                $user_phone_field = $config['user_phone_field'] ?? '';
                $user_template_id = $config['user_template_id'] ?? '';
                $user_message = $config['user_message'] ?? '';
                if (!empty($user_phone_field) && !empty($user_template_id)) {
                    $user_parameters = $this->extract_parameters($form, $entry, $user_message);
                    $user_phone = $this->get_user_mobile_from_entry($form, $entry, $user_phone_field);
                    if (!empty($user_phone)) {
                        $this->send_sms($user_phone, $user_template_id, $user_parameters);
                    }
                }
            }
        }
    }

    public function handle_payment_completed($entry, $action) {
        $form = \GFAPI::get_form($entry['form_id']);
        $form_id = $form['id'];
        $rows = get_option('sms_ir_form_notification_rows', '[]');
        $rows_array = json_decode(stripslashes($rows), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('SMSIR: Invalid JSON in form notification rows');
            return;
        }
        foreach ($rows_array as $row) {
            if ($row['form_type'] !== 'gravity_forms' || $row['form_id'] != $form_id) {
                continue;
            }
            $config = $row['config'];
            $recipient_types = $row['recipient_types'] ?? [];
            // Admin payment success
            if (in_array('admin', $recipient_types) && !empty($config['admin_send_on_payment_success'])) {
                $admin_mobile_numbers = $config['admin_mobile_numbers'] ?? '';
                $template_id = $config['admin_payment_success_template_id'] ?? '';
                $message = $config['admin_payment_success_message'] ?? '';
                if (!empty($admin_mobile_numbers) && !empty($template_id)) {
                    $parameters = $this->extract_payment_parameters($form, $entry, $message);
                    $admin_numbers = array_map('trim', explode(',', $admin_mobile_numbers));
                    foreach ($admin_numbers as $admin_number) {
                        if (!empty($admin_number)) {
                            $this->send_sms($admin_number, $template_id, $parameters);
                        }
                    }
                }
            }
            // User payment success
            if (in_array('user', $recipient_types) && !empty($config['user_send_on_payment_success'])) {
                $user_phone_field = $config['user_phone_field'] ?? '';
                $template_id = $config['user_payment_success_template_id'] ?? '';
                $message = $config['user_payment_success_message'] ?? '';
                if (!empty($user_phone_field) && !empty($template_id)) {
                    $parameters = $this->extract_payment_parameters($form, $entry, $message);
                    $user_phone = $this->get_user_mobile_from_entry($form, $entry, $user_phone_field);
                    if (!empty($user_phone)) {
                        $this->send_sms($user_phone, $template_id, $parameters);
                    }
                }
            }
        }
    }

    public function handle_payment_failed($entry, $action) {
        $form = \GFAPI::get_form($entry['form_id']);
        $form_id = $form['id'];
        $rows = get_option('sms_ir_form_notification_rows', '[]');
        $rows_array = json_decode(stripslashes($rows), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('SMSIR: Invalid JSON in form notification rows');
            return;
        }
        foreach ($rows_array as $row) {
            if ($row['form_type'] !== 'gravity_forms' || $row['form_id'] != $form_id) {
                continue;
            }
            $config = $row['config'];
            $recipient_types = $row['recipient_types'] ?? [];
            // Admin payment failure
            if (in_array('admin', $recipient_types) && !empty($config['admin_send_on_payment_failure'])) {
                $admin_mobile_numbers = $config['admin_mobile_numbers'] ?? '';
                $template_id = $config['admin_payment_failure_template_id'] ?? '';
                $message = $config['admin_payment_failure_message'] ?? '';
                if (!empty($admin_mobile_numbers) && !empty($template_id)) {
                    $parameters = $this->extract_payment_parameters($form, $entry, $message);
                    $admin_numbers = array_map('trim', explode(',', $admin_mobile_numbers));
                    foreach ($admin_numbers as $admin_number) {
                        if (!empty($admin_number)) {
                            $this->send_sms($admin_number, $template_id, $parameters);
                        }
                    }
                }
            }
            // User payment failure
            if (in_array('user', $recipient_types) && !empty($config['user_send_on_payment_failure'])) {
                $user_phone_field = $config['user_phone_field'] ?? '';
                $template_id = $config['user_payment_failure_template_id'] ?? '';
                $message = $config['user_payment_failure_message'] ?? '';
                if (!empty($user_phone_field) && !empty($template_id)) {
                    $parameters = $this->extract_payment_parameters($form, $entry, $message);
                    $user_phone = $this->get_user_mobile_from_entry($form, $entry, $user_phone_field);
                    if (!empty($user_phone)) {
                        $this->send_sms($user_phone, $template_id, $parameters);
                    }
                }
            }
        }
    }

    private function get_user_mobile_from_entry($form, $entry, $user_phone_field) {
        // $user_phone_field is the field id (e.g. 3 or #phone3#)
        $field_id = $user_phone_field;
        if (preg_match('/#([a-zA-Z]+)(\d+)#/', $user_phone_field, $matches)) {
            $field_id = $matches[2];
        }
        if (!$field_id) return null;
        if (isset($entry[$field_id])) {
            return preg_replace('/-|\s|\(|\)/', '', $entry[$field_id]);
        }
        return null;
    }

    private function extract_parameters($form, $entry, $message) {
        $parameters = [];
        if (preg_match_all('/#([^#]+)#/', $message, $matches)) {
            foreach ($matches[1] as $param) {
                // Try to find the field in entry by id (strip type prefix)
                if (preg_match('/([a-zA-Z]+)(\d+)/', $param, $pmatch)) {
                    $field_id = $pmatch[2];
                    // Find the field in the form definition
                    $field_obj = null;
                    foreach ($form['fields'] as $f) {
                        if ($f['id'] == $field_id) {
                            $field_obj = $f;
                            break;
                        }
                    }
                    
                    if ($field_obj && $field_obj['type'] === 'checkbox') {
                        // Collect all checked values for this checkbox field
                        $checked = [];
                        if (!empty($field_obj['inputs'])) {
                            foreach ($field_obj['inputs'] as $input) {
                                $input_id = $input['id']; // e.g., 3.1, 3.2
                                if (isset($entry[$input_id]) && $entry[$input_id]) {
                                    $checked[] = $entry[$input_id];
                                }
                            }
                        }
                        $parameters[$param] = implode(', ', $checked);
                    } elseif ($field_obj && $field_obj['type'] === 'option') {
                        // Handle option fields similar to checkbox fields
                        $options = [];
                        if (!empty($field_obj['inputs'])) {
                            foreach ($field_obj['inputs'] as $input) {
                                $input_id = $input['id']; // e.g., 3.1, 3.2
                                if (isset($entry[$input_id]) && $entry[$input_id]) {
                                    $options[] = $entry[$input_id];
                                }
                            }
                        }
                        $parameters[$param] = implode(', ', $options);
                    } elseif (isset($entry[$field_id])) {
                        $parameters[$param] = is_array($entry[$field_id]) ? implode(', ', $entry[$field_id]) : $entry[$field_id];
                    }
                }
            }
        }
        return $parameters;
    }

    private function extract_payment_parameters($form, $entry, $message) {
        // Standard payment params: id, formid, amount, transaction, gateway
        $parameters = [];
        $map = [
            'id' => $entry['id'] ?? '',
            'formid' => $form['id'] ?? '',
            'amount' => isset($entry['payment_amount']) ? number_format($entry['payment_amount']) : '',
            'transaction' => $entry['transaction_id'] ?? '',
            'gateway' => $entry['payment_method'] ?? ''
        ];
        foreach ($map as $key => $val) {
            $parameters[$key] = $val;
        }
        // Also extract any custom placeholders from the message
        if (preg_match_all('/#([^#]+)#/', $message, $matches)) {
            foreach ($matches[1] as $param) {
                if (!isset($parameters[$param]) && preg_match('/([a-zA-Z]+)(\d+)/', $param, $pmatch)) {
                    $field_id = $pmatch[2];
                    if (isset($entry[$field_id])) {
                        $parameters[$param] = is_array($entry[$field_id]) ? implode(', ', $entry[$field_id]) : $entry[$field_id];
                    }
                }
            }
        }
        return $parameters;
    }

    private function send_sms($phone, $template_id, $parameters) {
        try {
            $this->sms_client->send_verification_code($phone, $parameters, $template_id);
        } catch (\Exception $e) {
            error_log("Error sending SMS to {$phone}: " . $e->getMessage());
        }
    }
}