<?php
namespace SMSIR;

class CF7_Notifications {
    private $sms_client;
    
    public function __construct() {
        $this->sms_client = new SMSClient();
        add_action('wpcf7_before_send_mail', [$this, 'handle_form_submission'], 10, 3);
    }
    
    public function handle_form_submission($contact_form, &$abort, $submission) {
        $form_id = $contact_form->id();
        $rows = get_option('sms_ir_form_notification_rows', '[]');
        $rows_array = json_decode(stripslashes($rows), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('SMSIR: Invalid JSON in form notification rows');
            return;
        }

        $posted_data = $submission->get_posted_data();
        
        foreach ($rows_array as $row) {
            if ($row['form_type'] !== 'contact_form_7' || $row['form_id'] != $form_id) {
                continue;
            }

            if (in_array('admin', $row['recipient_types'])) {
                $admin_mobile_numbers = $row['config']['admin_mobile_numbers'];
                $template_id = $row['config']['admin_template_id'];
                $message = $row['config']['admin_message'] ?? '';
                
                if (empty($admin_mobile_numbers) || empty($template_id)) {
                    error_log('SMSIR: Admin notification skipped - missing mobile numbers or template ID');
                    continue;
                }

                $parameters = [];
                if (preg_match_all('/#([^#]+)#/', $message, $matches)) {
                    foreach ($matches[1] as $param) {
                        if (isset($posted_data[$param])) {
                            $value = $posted_data[$param];
                            $parameters[$param] = is_array($value) ? implode(', ', $value) : $value;
                        }
                    }
                }

                // Send to all admin numbers (comma separated)
                $admin_numbers = array_map('trim', explode(',', $admin_mobile_numbers));
                foreach ($admin_numbers as $admin_number) {
                    if (!empty($admin_number)) {
                        $this->send_sms($admin_number, $template_id, $parameters);
                    }
                }
            }
            
            if (in_array('user', $row['recipient_types'])) {
                $user_phone_field = $row['config']['user_phone_field'];
                $user_template_id = $row['config']['user_template_id'];
                $user_message = $row['config']['user_message'] ?? '';
                
                if (empty($user_phone_field) || empty($user_template_id)) {
                    continue;
                }

                $user_parameters = [];
                if (preg_match_all('/#([^#]+)#/', $user_message, $matches)) {
                    foreach ($matches[1] as $param) {
                        if (isset($posted_data[$param])) {
                            $value = $posted_data[$param];
                            $user_parameters[$param] = is_array($value) ? implode(', ', $value) : $value;
                        }
                    }
                }
                
                $user_phone = isset($posted_data[$user_phone_field]) ? $posted_data[$user_phone_field] : '';
                
                if (empty($user_phone)) {
                    continue;
                }

                $this->send_sms($user_phone, $user_template_id, $user_parameters);
            }
        }
    }
    
    private function send_sms($phone, $template_id, $parameters) {
        try {
            $response = $this->sms_client->send_verification_code($phone, $parameters, $template_id);
            
        } catch (\Exception $e) {
            error_log("Error sending SMS to {$phone}: " . $e->getMessage());
        }
    }
} 