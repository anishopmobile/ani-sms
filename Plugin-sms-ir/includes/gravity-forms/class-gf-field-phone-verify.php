<?php

namespace SMSIR;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('GF_Field')) {
    return;
}


class GF_Field_Phone_Verify extends \GF_Field {
    

    public $type = 'smsir_phone_verify';
    

    public function get_form_editor_field_title() {
        return esc_attr__('Phone Verification', 'sms-ir');
    }
    

    public function get_form_editor_button() {
        return [
            'group' => 'standard_fields',
            'text'  => $this->get_form_editor_field_title()
        ];
    }
    

    public function get_form_editor_field_settings() {
        return [
            'label_setting',
            'description_setting',
            'rules_setting',
            'error_message_setting',
            'css_class_setting',
            'visibility_setting',
            'conditional_logic_field_setting',
        ];
    }
    

    public function get_field_input($form, $value = '', $entry = null) {
        $form_id = $form['id'];
        $id = (int) $this->id;
        $field_id = "input_{$form_id}_{$id}";
        $container_id = "smsir_phone_verify_{$form_id}_{$id}";
        
        $class_suffix = $this->isRequired ? ' gfield_contains_required' : '';
        
        $html = "<div id='{$container_id}' class='ginput_container ginput_container_phone smsir-phone-verify-container'>";
        
        $html .= "<div class='smsir-phone-input-section'>";
        $html .= "<input type='tel' name='input_{$id}' id='{$field_id}' value='" . esc_attr($value) . "' class='smsir-phone-input medium' placeholder='" . esc_attr__('Enter phone number', 'sms-ir') . "' />";
        $html .= "<button type='button' class='smsir-request-otp-btn' id='smsir_request_otp_{$form_id}_{$id}'>" . esc_html__('Send Code', 'sms-ir') . "</button>";
        $html .= "</div>";
        
        $html .= "<div class='smsir-otp-section' style='display:none;'>";
        $html .= "<div class='smsir-otp-input-wrapper'>";
        $html .= "<input type='text' class='smsir-otp-input medium' id='smsir_otp_{$form_id}_{$id}' placeholder='" . esc_attr__('Enter verification code', 'sms-ir') . "' />";
        $html .= "<button type='button' class='smsir-verify-otp-btn' id='smsir_verify_otp_{$form_id}_{$id}'>" . esc_html__('Verify', 'sms-ir') . "</button>";
        $html .= "</div>";
        
        $html .= "<div class='smsir-resend-container'>";
        $html .= "<span class='smsir-countdown' id='smsir_countdown_{$form_id}_{$id}'></span>";
        $html .= "<button type='button' class='smsir-resend-otp-btn' id='smsir_resend_otp_{$form_id}_{$id}' style='display:none;'>" . esc_html__('Resend Code', 'sms-ir') . "</button>";
        $html .= "</div>";
        $html .= "</div>";
        
        // Status message container
        $html .= "<div class='smsir-status-message' id='smsir_status_{$form_id}_{$id}'></div>";
        
        $html .= "<input type='hidden' name='smsir_verified_{$id}' id='smsir_verified_{$form_id}_{$id}' value='0' />";
        
        $html .= "</div>";
        
        $html .= $this->get_inline_script_markup($container_id, $form_id, $id);
        
        return $html;
    }
    
 
    private function get_inline_script_markup($container_id, $form_id, $field_id) {
        $nonce = wp_create_nonce('smsir_gf_verify');
        
        $script = "<script type='text/javascript'>
            jQuery(document).ready(function($) {
                const container = $('#{$container_id}');
                const phoneInput = $('#input_{$form_id}_{$field_id}');
                const requestBtn = $('#smsir_request_otp_{$form_id}_{$field_id}');
                const otpSection = container.find('.smsir-otp-section');
                const otpInput = $('#smsir_otp_{$form_id}_{$field_id}');
                const verifyBtn = $('#smsir_verify_otp_{$form_id}_{$field_id}');
                const resendBtn = $('#smsir_resend_otp_{$form_id}_{$field_id}');
                const countdownEl = $('#smsir_countdown_{$form_id}_{$field_id}');
                const statusEl = $('#smsir_status_{$form_id}_{$field_id}');
                const verifiedField = $('#smsir_verified_{$form_id}_{$field_id}');
                
                // Debug check to ensure the field exists
                if (verifiedField.length === 0) {
                    console.error('Verified field not found: smsir_verified_{$form_id}_{$field_id}');
                } else {
                    console.log('Initial verified field value: ' + verifiedField.val());
                }
                
                let countdownInterval;
                let countdownTime = 120; // seconds
                
                function startCountdown() {
                    clearInterval(countdownInterval);
                    countdownTime = 120;
                    resendBtn.hide();
                    
                    countdownInterval = setInterval(function() {
                        countdownTime--;
                        const minutes = Math.floor(countdownTime / 60);
                        const seconds = countdownTime % 60;
                        countdownEl.text(minutes + ':' + (seconds < 10 ? '0' + seconds : seconds));
                        
                        if (countdownTime <= 0) {
                            clearInterval(countdownInterval);
                            countdownEl.text('');
                            resendBtn.show();
                        }
                    }, 1000);
                }
                
                function showStatus(message, isError = false) {
                    statusEl.removeClass('error success').addClass(isError ? 'error' : 'success').text(message).show();
                    
                    setTimeout(function() {
                        statusEl.fadeOut();
                    }, 5000);
                }
                
                requestBtn.on('click', function() {
                    const phone = phoneInput.val().trim();
                    
                    if (!phone) {
                        showStatus('" . esc_js(__('Please enter a valid phone number', 'sms-ir')) . "', true);
                        return;
                    }
                    
                    requestBtn.prop('disabled', true).text('" . esc_js(__('Sending...', 'sms-ir')) . "');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'smsir_gf_request_otp',
                            phone: phone,
                            form_id: {$form_id},
                            field_id: {$field_id},
                            nonce: '{$nonce}'
                        },
                        success: function(response) {
                            requestBtn.prop('disabled', false).text('" . esc_js(__('Send Code', 'sms-ir')) . "');
                            
                            if (response.success) {
                                otpSection.show();
                                startCountdown();
                                showStatus(response.data.message);
                            } else {
                                showStatus(response.data.message, true);
                            }
                        },
                        error: function() {
                            requestBtn.prop('disabled', false).text('" . esc_js(__('Send Code', 'sms-ir')) . "');
                            showStatus('" . esc_js(__('An error occurred. Please try again.', 'sms-ir')) . "', true);
                        }
                    });
                });
                
                verifyBtn.on('click', function() {
                    const phone = phoneInput.val().trim();
                    const otp = otpInput.val().trim();
                    
                    if (!phone || !otp) {
                        showStatus('" . esc_js(__('Phone number and verification code are required', 'sms-ir')) . "', true);
                        return;
                    }
                    
                    verifyBtn.prop('disabled', true).text('" . esc_js(__('Verifying...', 'sms-ir')) . "');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'smsir_gf_verify_otp',
                            phone: phone,
                            otp: otp,
                            form_id: {$form_id},
                            field_id: {$field_id},
                            nonce: '{$nonce}'
                        },
                        success: function(response) {
                            verifyBtn.prop('disabled', false).text('" . esc_js(__('Verify', 'sms-ir')) . "');
                            
                            if (response.success) {
                                showStatus(response.data.message);
                                
                                // Set the hidden field value to '1' to indicate verification
                                verifiedField.val('1');
                                
                                // Debug check to ensure the value was set
                                console.log('Verification successful, hidden field value set to: ' + verifiedField.val());
                                
                                // Disable inputs after successful verification
                                phoneInput.prop('readonly', true);
                                otpInput.prop('readonly', true);
                                requestBtn.prop('disabled', true);
                                verifyBtn.prop('disabled', true);
                                resendBtn.prop('disabled', true);
                                clearInterval(countdownInterval);
                                
                                // Force the hidden field to be included in form submission
                                // by adding a special attribute
                                verifiedField.attr('data-verified', 'true');
                            } else {
                                showStatus(response.data.message, true);
                            }
                        },
                        error: function() {
                            verifyBtn.prop('disabled', false).text('" . esc_js(__('Verify', 'sms-ir')) . "');
                            showStatus('" . esc_js(__('An error occurred. Please try again.', 'sms-ir')) . "', true);
                        }
                    });
                });
                
                resendBtn.on('click', function() {
                    requestBtn.click();
                });
                
                // Add a form submission handler to ensure the verified field is included
                const form = $('#gform_{$form_id}');
                if (form.length > 0) {
                    form.on('submit', function() {
                        // Double-check that the verified field is included
                        if (verifiedField.val() === '1') {
                            console.log('Form submitted with verified field value: ' + verifiedField.val());
                        }
                    });
                }
            });
        </script>";
        
        return $script;
    }
    

    public function validate($value, $form) {
        $verified = rgpost('smsir_verified_' . $this->id);
        
     
        if ($this->isRequired && empty($value)) {
            $this->failed_validation = true;
            $this->validation_message = empty($this->errorMessage) ? esc_html__('This field is required.', 'sms-ir') : $this->errorMessage;
        } 
        else if ($this->isRequired && $verified !== '1') {
            $this->failed_validation = true;
            $this->validation_message = esc_html__('Please verify your phone number.', 'sms-ir');
        }
    }
    

    public function get_value_submission($field_values, $get_from_post_global_var = true) {
        $value = $this->get_input_value_submission('input_' . $this->id, $this->inputName, $field_values, $get_from_post_global_var);
        return $value;
    }
}