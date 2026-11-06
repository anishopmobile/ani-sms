<?php
namespace SMSIR;

class GFIntegration {
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
        
        if (class_exists('\GFForms')) {
            require_once SMSIR_DIR . 'includes/gravity-forms/class-gf-field-phone-verify.php';
            
            $this->register_custom_field();
            
            require_once SMSIR_DIR . 'includes/gravity-forms/smsir-gf-field-registration.php';
            require_once SMSIR_DIR . 'includes/gravity-forms/class-gf-notifications.php';
            $this->notifications = new GF_Notifications();
            // AJAX handlers for OTP verification
            add_action('wp_ajax_smsir_gf_request_otp', [$this, 'ajax_request_otp']);
            add_action('wp_ajax_nopriv_smsir_gf_request_otp', [$this, 'ajax_request_otp']);
            add_action('wp_ajax_smsir_gf_verify_otp', [$this, 'ajax_verify_otp']);
            add_action('wp_ajax_nopriv_smsir_gf_verify_otp', [$this, 'ajax_verify_otp']);
            
            add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
            
            // Cleanup expired OTPs
            add_action('init', [$this, 'cleanup_expired_gf_otps']);
        }
    }
    

    private function register_custom_field() {
        if (class_exists('\GF_Fields')) {
            if (!class_exists('SMSIR\GF_Field_Phone_Verify')) {
                require_once SMSIR_DIR . 'includes/gravity-forms/class-gf-field-phone-verify.php';
            }
            
            \GF_Fields::register(new GF_Field_Phone_Verify());
            
          
        }
    }
    
    public function enqueue_scripts() {
        $has_gf = false;
        
        if (wp_script_is('gform_gravityforms', 'registered') || wp_script_is('gform_gravityforms', 'enqueued')) {
            $has_gf = true;
        }
        
        if (!$has_gf && is_singular()) {
            global $post;
            if ($post && has_shortcode($post->post_content, 'gravityform')) {
                $has_gf = true;
            }
        }
        
        if ($has_gf) {
            wp_enqueue_script('jquery');
            wp_add_inline_script('jquery', 'if (typeof ajaxurl === "undefined") { var ajaxurl = "' . admin_url('admin-ajax.php') . '"; }');
            
            $gf_style_handles = [
                'gforms_reset_css',
                'gforms_formsmain_css',
                'gforms_ready_class_css',
                'gforms_browsers_css',
                'gform_basic',
                'gform_theme',
                'gform_theme_ie11'
            ];
            
            $last_gf_style = '';
            foreach ($gf_style_handles as $handle) {
                if (wp_style_is($handle, 'registered') || wp_style_is($handle, 'enqueued')) {
                    $last_gf_style = $handle;
                }
            }
            
     
            if (!empty($last_gf_style)) {
                wp_register_style(
                    'smsir-gf-phone-verify', 
                    false, // No actual file
                    [$last_gf_style], // Dependency on the last GF style
                    SMSIR_VERSION
                );
            } else {
                wp_register_style('smsir-gf-phone-verify', false, [], SMSIR_VERSION);
            }
            
            wp_enqueue_style('smsir-gf-phone-verify');
            
            wp_add_inline_style('smsir-gf-phone-verify', '
                .smsir-phone-verify-container {
                    margin-bottom: 15px;
                }
                .smsir-phone-input-section {
                    display: flex;
                    gap: 10px;
                    margin-bottom: 10px;
                }
                .smsir-phone-input {
                    flex: 1;
                    padding: 8px 12px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
                .smsir-request-otp-btn,
                .smsir-verify-otp-btn,
                .smsir-resend-otp-btn {
                    background-color: #2271b1;
                    color: white;
                    border: none;
                    padding: 8px 15px;
                    border-radius: 4px;
                    cursor: pointer;
                    white-space: nowrap;
                }
                .smsir-request-otp-btn:hover,
                .smsir-verify-otp-btn:hover,
                .smsir-resend-otp-btn:hover {
                    background-color: #135e96;
                }
                .smsir-request-otp-btn:disabled,
                .smsir-verify-otp-btn:disabled,
                .smsir-resend-otp-btn:disabled {
                    background-color: #ccc;
                    cursor: not-allowed;
                }
                .smsir-otp-input-wrapper {
                    display: flex;
                    gap: 10px;
                    margin-bottom: 10px;
                }
                .smsir-otp-input {
                    flex: 1;
                    padding: 8px 12px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    text-align: center;
                    letter-spacing: 2px;
                    font-size: 16px;
                }
                .smsir-resend-container {
                    display: flex;
                    justify-content: flex-end;
                    font-size: 14px;
                }
                .smsir-countdown {
                    color: #666;
                }
                .smsir-resend-otp-btn {
                    background: none;
                    color: #2271b1;
                    padding: 0;
                    border: none;
                    text-decoration: underline;
                    cursor: pointer;
                }
                .smsir-resend-otp-btn:hover {
                    background: none;
                    color: #135e96;
                }
                .smsir-status-message {
                    margin-top: 10px;
                    padding: 8px;
                    border-radius: 4px;
                    display: none;
                }
                .smsir-status-message.error {
                    background-color: #f8d7da;
                    color: #721c24;
                    border: 1px solid #f5c6cb;
                }
                .smsir-status-message.success {
                    background-color: #d4edda;
                    color: #155724;
                    border: 1px solid #c3e6cb;
                }
            ');
            
            // Alternatively, we can also use JavaScript to ensure our styles take precedence
            // by adding them at the end of the document
            wp_add_inline_script('jquery', "
                jQuery(document).ready(function($) {
                    // This ensures our styles are applied last
                    $('head').append('<style id=\"smsir-late-styles\">" . 
                        esc_js('
                            .smsir-phone-verify-container {
                                margin-bottom: 15px;
                            }
                            .smsir-phone-input-section {
                                display: flex;
                                gap: 10px;
                                margin-bottom: 10px;
                            }
                            .smsir-phone-input {
                                flex: 1;
                                padding: 8px 12px;
                                border: 1px solid #ddd;
                                border-radius: 4px;
                            }
                            .smsir-request-otp-btn,
                            .smsir-verify-otp-btn,
                            .smsir-resend-otp-btn {
                                background-color: #2271b1;
                                color: white;
                                border: none;
                                padding: 8px 15px;
                                border-radius: 4px;
                                cursor: pointer;
                                white-space: nowrap;
                            }
                            .smsir-request-otp-btn:hover,
                            .smsir-verify-otp-btn:hover,
                            .smsir-resend-otp-btn:hover {
                                background-color: #135e96;
                            }
                            .smsir-request-otp-btn:disabled,
                            .smsir-verify-otp-btn:disabled,
                            .smsir-resend-otp-btn:disabled {
                                background-color: #ccc;
                                cursor: not-allowed;
                            }
                            .smsir-otp-input-wrapper {
                                display: flex;
                                gap: 10px;
                                margin-bottom: 10px;
                            }
                            .smsir-otp-input {
                                flex: 1;
                                padding: 8px 12px;
                                border: 1px solid #ddd;
                                border-radius: 4px;
                                text-align: center;
                                letter-spacing: 2px;
                                font-size: 16px;
                            }
                            .smsir-resend-container {
                                display: flex;
                                justify-content: flex-end;
                                font-size: 14px;
                            }
                            .smsir-countdown {
                                color: #666;
                            }
                            .smsir-resend-otp-btn {
                                background: none;
                                color: #2271b1;
                                padding: 0;
                                border: none;
                                text-decoration: underline;
                                cursor: pointer;
                            }
                            .smsir-resend-otp-btn:hover {
                                background: none;
                                color: #135e96;
                            }
                            .smsir-status-message {
                                margin-top: 10px;
                                padding: 8px;
                                border-radius: 4px;
                                display: none;
                            }
                            .smsir-status-message.error {
                                background-color: #f8d7da;
                                color: #721c24;
                                border: 1px solid #f5c6cb;
                            }
                            .smsir-status-message.success {
                                background-color: #d4edda;
                                color: #155724;
                                border: 1px solid #c3e6cb;
                            }
                        ') . 
                    "</style>');
                });
            ");
        }
    }
    
    public function enqueue_admin_scripts() {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        
        // Only load on Gravity Forms editor pages
        if ($screen && strpos($screen->id, 'gf_edit_forms') !== false) {
            wp_enqueue_script('smsir-gf-admin', SMSIR_ASSETS . '/js/gf-admin.js', ['jquery'], '1.0', true);
        }
    }
    
    public function ajax_request_otp() {
        check_ajax_referer('smsir_gf_verify', 'nonce');
        
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $form_id = intval($_POST['form_id'] ?? 0);
        $field_id = intval($_POST['field_id'] ?? 0);
        
        if (empty($phone)) {
            wp_send_json_error([
                'message' => __('Please enter a valid phone number', 'sms-ir')
            ]);
            return;
        }
        
        $phone = preg_replace('/^\+/', '', $phone);
        $phone = preg_replace('/^98\+/', '98', $phone);
        
        $settings = get_option('smsir_settings', []);
        $otp_length = isset($settings['settings']['otp_length']) ? intval($settings['settings']['otp_length']) : 5;
        
        $min = pow(10, $otp_length - 1);
        $max = pow(10, $otp_length) - 1;
        $otp = wp_rand($min, $max);
        
        $option_name = 'smsir_gf_otp_' . $phone . '_' . $form_id . '_' . $field_id;
        $expiry = isset($settings['settings']['otp_expiry']) ? intval($settings['settings']['otp_expiry']) : 90;
        
        // Store OTP in database directly (bypass cache)
        $otp_data = [
            'otp' => $otp,
            'expires' => time() + $expiry
        ];
        
        delete_option($option_name);
        if (!add_option($option_name, $otp_data, '', 'no')) {
            wp_send_json_error([
                'message' => __('Failed to save verification code', 'sms-ir')
            ]);
            return;
        }
        
        $params = [
            'Code' => $otp,
        ];
        $response = $this->sms_client->send_verification_code($phone, $otp);
        
        if ($response['success']) {
            wp_send_json_success([
                'message' => __('Verification code sent successfully', 'sms-ir')
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to send verification code', 'sms-ir')
            ]);
        }
    }
    
    public function ajax_verify_otp() {
        check_ajax_referer('smsir_gf_verify', 'nonce');
        
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $otp = sanitize_text_field($_POST['otp'] ?? '');
        $form_id = intval($_POST['form_id'] ?? 0);
        $field_id = intval($_POST['field_id'] ?? 0);
        
        if (empty($phone) || empty($otp)) {
            wp_send_json_error([
                'message' => __('Phone number and verification code are required', 'sms-ir')
            ]);
            return;
        }
        
        $phone = preg_replace('/^\+/', '', $phone);
        $phone = preg_replace('/^98\+/', '98', $phone);
        
        // Get stored OTP from database
        $option_name = 'smsir_gf_otp_' . $phone . '_' . $form_id . '_' . $field_id;
        $otp_data = get_option($option_name, false);
        
        if (!$otp_data || !isset($otp_data['otp']) || !isset($otp_data['expires'])) {
            wp_send_json_error([
                'message' => __('Invalid verification code', 'sms-ir')
            ]);
            return;
        }
        
        // Check if OTP has expired
        if (time() > $otp_data['expires']) {
            delete_option($option_name);
            wp_send_json_error([
                'message' => __('Verification code has expired', 'sms-ir')
            ]);
            return;
        }
        
        // Check if OTP matches
        if ($otp_data['otp'] != $otp) {
            wp_send_json_error([
                'message' => __('Invalid verification code', 'sms-ir')
            ]);
            return;
        }
        
        // OTP is valid, delete the option
        delete_option($option_name);
        
        // Return success
        wp_send_json_success([
            'message' => __('Phone number verified successfully', 'sms-ir'),
            'verified' => true
        ]);
    }
    
    /**
     * Cleanup expired Gravity Forms OTPs from database
     * Runs once per hour to prevent database bloat
     */
    public function cleanup_expired_gf_otps() {
        // Only run cleanup once per hour
        $last_cleanup = get_option('smsir_last_gf_otp_cleanup', 0);
        if (time() - $last_cleanup < 3600) {
            return;
        }
        
        global $wpdb;
        $current_time = time();
        
        // Get all options that start with smsir_gf_otp_
        $otp_options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
            WHERE option_name LIKE 'smsir_gf_otp_%'"
        );
        
        foreach ($otp_options as $option) {
            $data = maybe_unserialize($option->option_value);
            
            // Check if it's an expired OTP
            if (is_array($data) && isset($data['expires']) && $current_time > $data['expires']) {
                delete_option($option->option_name);
            }
        }
        
        // Update last cleanup timestamp
        update_option('smsir_last_gf_otp_cleanup', $current_time, 'no');
    }
}