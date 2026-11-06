<?php
namespace SMSIR;

use SMSIR\Translations;

class AuthController {
    private static $instance = null;
    private $sms_client;
    private $ban_manager;
    
    const MAX_ATTEMPTS = 5;
    const BAN_DURATION = 600; 
    const ATTEMPTS_WINDOW = 600; 

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->sms_client = new SMSClient();
        $this->ban_manager = BanManager::get_instance();
        $settings = get_option('smsir_settings', []);
        $change_admin = !empty($settings['settings']['change_admin']);
        add_action('wp_ajax_smsir_request_otp', [$this, 'ajax_request_otp']);
        add_action('wp_ajax_nopriv_smsir_request_otp', [$this, 'ajax_request_otp']);
        add_action('wp_ajax_smsir_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_smsir_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_smsir_login_email', [$this, 'ajax_login_email']);
        add_action('wp_ajax_nopriv_smsir_login_email', [$this, 'ajax_login_email']);
        add_action('wp_ajax_smsir_forgot_password', [$this, 'ajax_forgot_password']);
        add_action('wp_ajax_nopriv_smsir_forgot_password', [$this, 'ajax_forgot_password']);
        add_action('wp_ajax_smsir_reset_password', [$this, 'ajax_reset_password']);
        add_action('wp_ajax_nopriv_smsir_reset_password', [$this, 'ajax_reset_password']);
        add_action('wp_ajax_smsir_save_custom_fields', [$this, 'ajax_save_custom_fields']);
        add_action('wp_ajax_nopriv_smsir_save_custom_fields', [$this, 'ajax_save_custom_fields']);
        add_action('wp_ajax_smsir_upload_custom_field_file', [$this, 'ajax_upload_custom_field_file']);
        add_action('wp_ajax_nopriv_smsir_upload_custom_field_file', [$this, 'ajax_upload_custom_field_file']);
        add_action('wp_ajax_smsir_complete_login', [$this, 'ajax_complete_login']);
        add_action('wp_ajax_nopriv_smsir_complete_login', [$this, 'ajax_complete_login']);
        if($change_admin){
            add_action('init', [$this, 'prevent_wp_login']);
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_public_styles']);

        add_action('wp_ajax_smsir_get_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_nopriv_smsir_get_settings', [$this, 'ajax_get_settings']);

        add_action('wp_ajax_smsir_sync_digits_users', [$this, 'ajax_sync_digits_users']);
        
        add_action('user_register', [$this, 'sync_new_user_phone'], 10, 1);

        add_action('wp_ajax_smsir_request_change_phone_otp', [$this, 'ajax_request_change_phone_otp']);
        add_action('wp_ajax_smsir_verify_change_phone', [$this, 'ajax_verify_change_phone']);
        add_action('wp_ajax_smsir_get_user_roles', [$this, 'ajax_get_user_roles']);

        // Show and save custom registration fields on user edit/profile in admin
        add_action('show_user_profile', [$this, 'render_smsir_custom_fields_section']);
        add_action('edit_user_profile', [$this, 'render_smsir_custom_fields_section']);
        add_action('personal_options_update', [$this, 'save_smsir_custom_fields_section']);
        add_action('edit_user_profile_update', [$this, 'save_smsir_custom_fields_section']);
        
        // Cleanup expired OTPs periodically
        add_action('init', [$this, 'cleanup_expired_otps']);
    }

    public static function plugin_activate() {
        $login_page = get_page_by_path('login');
        
        if (!$login_page) {
            $page_data = array(
                'post_title'    => __('Login', 'sms-ir'),
                'post_content'  => '[smsir_login]',
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_name'     => 'login'
            );
            
            wp_insert_post($page_data);
        }
    }

    public function ajax_request_otp() {
        try {
            check_ajax_referer('sms_ir_login', 'nonce');
            
            $phone = sanitize_text_field($_POST['phone']);
            
            $phone = preg_replace('/^\+/', '', $phone);
            $phone = preg_replace('/^98\+/', '98', $phone);
            
            $settings = get_option('smsir_settings', []);
            $otp_length = isset($settings['settings']['otp_length']) ? intval($settings['settings']['otp_length']) : 4;
            
            $min = pow(10, $otp_length - 1);
            $max = pow(10, $otp_length) - 1;
            $otp = wp_rand($min, $max);
            
            // Store OTP in database directly (bypass cache)
            $otp_data = [
                'otp' => $otp,
                'expires' => time() + ($settings['settings']['otp_expiry'] ?? 120)
            ];
            
            // Use autoload=false to prevent caching issues
            delete_option('smsir_otp_' . $phone);
            if (!add_option('smsir_otp_' . $phone, $otp_data, '', 'no')) {
                throw new \Exception('Failed to save OTP');
            }
            
            $params = [
                'Code' => $otp,
            ];
            $response = $this->sms_client->send_verification_code($phone, $params);
            if ($response['success'] && $response['response']['status'] == 1) {
                wp_send_json_success([
                    'message' => __('OTP sent successfully', 'sms-ir')
                ]);
            } else {
                throw new \Exception(__('Failed to send OTP please Notify the site administrator', 'sms-ir'));
            }

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function ajax_verify_otp() {
        try {
            check_ajax_referer('sms_ir_login', 'nonce');
            
            $phone = sanitize_text_field($_POST['phone']);
            $otp = sanitize_text_field($_POST['otp']);
            $is_modal = isset($_POST['is_modal']) && $_POST['is_modal'] === 'true';

            $phone = preg_replace('/^\+/', '', $phone);
            $phone = preg_replace('/^98\+/', '98', $phone);
            
            
            $clean_phone = $this->formatPhoneNumber($phone);

            // Verify OTP code from database
            $otp_data = get_option('smsir_otp_' . $phone, false);
            
            if (!$otp_data || !isset($otp_data['otp']) || !isset($otp_data['expires'])) {
                throw new \Exception(__('Invalid verification code', 'sms-ir'));
            }
            
            // Check if OTP has expired
            if (time() > $otp_data['expires']) {
                delete_option('smsir_otp_' . $phone);
                throw new \Exception(__('Verification code has expired', 'sms-ir'));
            }
            
            // Check if OTP matches
            if ($otp_data['otp'] != $otp) {
                throw new \Exception(__('Invalid verification code', 'sms-ir'));
            }
            
            // Delete OTP after successful verification
            delete_option('smsir_otp_' . $phone);

            $user = get_users([
                'meta_key' => 'smsir_phone',
                'meta_value' => $clean_phone,
                'number' => 1
            ]);

            if (!empty($user)) {
                // User exists - log them in and return immediately
                wp_set_auth_cookie($user[0]->ID);
                do_action('gtm_smsir_after_login', $user[0]->ID);
                $redirect_url = $this->get_redirect_url($is_modal);
                
                wp_send_json_success([
                    'message' => __('Login successful', 'sms-ir'),
                    'redirect_url' => $redirect_url,
                    'user_exists' => true
                ]);
                return;
            }

            // Continue with new user registration logic...
            $settings = get_option('smsir_settings', []);
            $custom_fields = isset($settings['settings']['custom_fields']) ? 
                $settings['settings']['custom_fields'] : [];
            $require_email = isset($settings['settings']['require_email']) ? 
                (bool)$settings['settings']['require_email'] : false;
            $require_first_name = isset($settings['settings']['require_first_name']) ? 
                (bool)$settings['settings']['require_first_name'] : false;
            $require_last_name = isset($settings['settings']['require_last_name']) ? 
                (bool)$settings['settings']['require_last_name'] : false;

            // If no fields are required, create user directly
            if (!$require_email && !$require_first_name && !$require_last_name && empty($custom_fields)) {
                // Create new user
                $user_data = [
                    'user_login' => $clean_phone,
                    'user_pass' => wp_generate_password(),
                    'role' => $this->get_default_user_role()
                ];

                $user_id = wp_insert_user($user_data);
                
                if (is_wp_error($user_id)) {
                    throw new \Exception($user_id->get_error_message());
                }

                update_user_meta($user_id, 'phone',  $clean_phone);
                update_user_meta($user_id, 'smsir_phone',  $clean_phone);
                update_user_meta($user_id, 'billing_phone', '98' . preg_replace('/^0/', '', $clean_phone));

                do_action('gtm_smsir_after_signup', $user_id);
                // Log the user in
                wp_set_auth_cookie($user_id);
                do_action('gtm_smsir_after_login', $user_id);

                // Get redirect URL
                $redirect_url = $this->get_redirect_url($is_modal);

                wp_send_json_success([
                    'message' => __('Login successful', 'sms-ir'),
                    'redirect_url' => $redirect_url,
                    'user_exists' => false
                ]);
                return;
            }

            wp_send_json_success([
                'message' => __('Please complete your profile', 'sms-ir'),
                'needs_user_info' => $require_email || $require_first_name || $require_last_name,
                'require_email' => $require_email,
                'require_first_name' => $require_first_name,
                'require_last_name' => $require_last_name,
                'needs_custom_fields' => !empty($custom_fields),
                'custom_fields' => $custom_fields,
                'user_exists' => false
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function ajax_login_email() {
        try {
            check_ajax_referer('sms_ir_login', 'nonce');

            $email = sanitize_email($_POST['email']);
            
            if ($this->ban_manager->is_banned($email)) {
                $remaining = $this->ban_manager->get_remaining_ban_time($email);
                wp_send_json_error([
                    'message' => sprintf(__('Too many attempts. Please try again after %d minutes.', 'sms-ir'), ceil($remaining / 60)),
                    'code' => 'rate_limited'
                ]);
                return;
            }

            $password = $_POST['password'];
            $user = wp_authenticate($email, $password);
            
            if (is_wp_error($user)) {
                $this->ban_manager->record_attempt($email, 'email');
                wp_send_json_error([
                    'message' => __('Invalid credentials', 'sms-ir'),
                    'code' => 'invalid_credentials'
                ]);
                return;
            }

            $this->ban_manager->clear_attempts($email);

            wp_set_auth_cookie($user->ID);
            do_action('gtm_smsir_after_login', $user->ID);
            
            $redirect_url = $this->get_redirect_url(
                isset($_POST['is_modal']) && $_POST['is_modal'] === 'true'
            );

            wp_send_json_success([
                'message' => __('Login successful', 'sms-ir'),
                'redirect_url' => $redirect_url
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function ajax_forgot_password() {
        try {
            check_ajax_referer('sms_ir_login', 'nonce');

            $email = sanitize_email($_POST['email']);
            
            if (empty($email)) {
                wp_send_json_error([
                    'message' => __('Email is required', 'sms-ir')
                ]);
                return;
            }

            $user = get_user_by('email', $email);
            
            if (!$user) {
                wp_send_json_error([
                    'message' => __('No user found with this email address', 'sms-ir')
                ]);
                return;
            }

            $key = get_password_reset_key($user);
            
            if (is_wp_error($key)) {
                wp_send_json_error([
                    'message' => $key->get_error_message()
                ]);
                return;
            }

            $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
            
            $reset_link = add_query_arg([
                'action' => 'rp',
                'key' => $key,
                'login' => rawurlencode($user->user_login)
            ], home_url('/login/'));

            $subject = sprintf(__('[%s] Password Reset'), $site_name);

            $message = __('Someone has requested a password reset for the following account:') . "\r\n\r\n";
            $message .= sprintf(__('Site Name: %s'), $site_name) . "\r\n\r\n";
            $message .= sprintf(__('Username: %s'), $user->user_login) . "\r\n\r\n";
            $message .= __('If this was a mistake, ignore this email and nothing will happen.') . "\r\n\r\n";
            $message .= __('To reset your password, visit the following address:') . "\r\n\r\n";
            $message .= $reset_link . "\r\n";

            $mail_sent = wp_mail($user->user_email, $subject, $message);

            if (!$mail_sent) {
                wp_send_json_error([
                    'message' => __('Failed to send password reset email', 'sms-ir')
                ]);
                return;
            }

            wp_send_json_success([
                'message' => __('Password reset link has been sent to your email', 'sms-ir')
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function validate_phone($phone) {
        try {
            $cleaned_phone = preg_replace('/[^0-9]/', '', $phone);

            if (substr($cleaned_phone, 0, 1) === '0' && strlen($cleaned_phone) === 11) {
      
                if (ctype_digit($cleaned_phone)) {
                    return true; 
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
     
            return false;
        }
    }


    public function prevent_wp_login() {
        global $pagenow;
        
        if ($pagenow === 'wp-login.php' && !is_user_logged_in()) {
            $action = isset($_GET['action']) ? $_GET['action'] : '';
            
            $allowed_actions = ['logout', 'lostpassword'];
            
            if (!in_array($action, $allowed_actions)) {
                // Check if custom login plugin is active and has custom path set
                $custom_login_settings = get_option('zhaket-updater-custom-login');
                if (!empty($custom_login_settings) && is_array($custom_login_settings)) {
                    $settings_data = $custom_login_settings['settings'] ?? [];
                    $custom_login_enabled = $settings_data['wordpress_admin_address'] ?? false;
                    $custom_path = $settings_data['wordpress_admin_address_path'] ?? '';
                    
                    // If custom login is enabled and path is set, let the custom login plugin handle redirects
                    if ($custom_login_enabled && !empty($custom_path)) {
                        return;
                    }
                }
                
                // Check for other login hiding plugins
                $ithemes_login_slug = get_option('itsec_hide_backend_slug');
                $wps_hide_login_slug = get_option('whl_page');
                
                // If other login hiding plugins are active, let them handle redirects
                if (!empty($ithemes_login_slug) || !empty($wps_hide_login_slug)) {
                    return;
                }
                
                if ($action === 'rp') {
                    $key = isset($_GET['key']) ? $_GET['key'] : '';
                    $login = isset($_GET['login']) ? $_GET['login'] : '';
                    wp_redirect(add_query_arg([
                        'action' => 'rp',
                        'key' => $key,
                        'login' => $login
                    ], home_url('/login/')));
                    exit();
                }
                
                wp_redirect(home_url('/login/'));
                exit();
            }
        }
    }

    public function ajax_reset_password() {
        try {
            check_ajax_referer('sms_ir_login', 'nonce');

            $key = sanitize_text_field($_POST['key']);
            $login = sanitize_text_field($_POST['login']);
            $password = $_POST['password'];

            $user = check_password_reset_key($key, $login);

            if (is_wp_error($user)) {
                wp_send_json_error([
                    'message' => __('This password reset link has expired or is invalid.', 'sms-ir')
                ]);
                return;
            }

            reset_password($user, $password);

            wp_set_auth_cookie($user->ID);
            do_action('gtm_smsir_after_login', $user->ID);
            wp_set_current_user($user->ID);

            update_user_meta($user->ID, 'reset_key', '');

            wp_send_json_success([
                'message' => __('Your password has been reset. You are now logged in.', 'sms-ir'),
                'redirect_url' => home_url()
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function enqueue_public_styles() {
        if (is_page('login')) {
            wp_enqueue_style('smsir-public', SMSIR_ASSETS . '/css/public.css', [], '1.0');
            
            $action = isset($_GET['action']) ? $_GET['action'] : '';
            if ($action === 'rp') {
                $key = isset($_GET['key']) ? $_GET['key'] : '';
                $login = isset($_GET['login']) ? $_GET['login'] : '';
                wp_localize_script('sms-ir-login', 'sms_ir_login', array(
                    'lang' => 'fa',
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('sms_ir_login'),
                    'resetKey' => $key,
                    'resetLogin' => $login,
                    'isResetMode' => true,
                    'translations' => Translations::get_translations()

                ));
                return;
            }
            

            wp_localize_script('sms-ir', 'sms_ir_login', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sms_ir_admin'),
                'lang' => 'fa',
                'translations' => Translations::get_translations()
            ));
        }
    }

    public function ajax_get_settings() {
        try {
            check_ajax_referer('sms_ir_login', 'nonce');
            
            $settings = get_option('smsir_settings', []);
            
            // Check if there's a custom logo in settings first
            if (isset($settings['settings']['custom_logo_id']) && !empty($settings['settings']['custom_logo_id'])) {
                $logo_url = wp_get_attachment_image_url($settings['settings']['custom_logo_id'], 'full');
                $settings['siteLogo'] = $logo_url;
            } else {
                // Check for theme logo second
                $custom_logo_id = get_theme_mod('custom_logo');
                if ($custom_logo_id) {
                    $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
                    $settings['siteLogo'] = $logo_url;
                } else {
                    // Use plugin's logo.svg as final fallback
                    $settings['siteLogo'] = SMSIR_ASSETS . '/img/logo.svg';
                }
            }
            
            $settings['show_logo'] = isset($settings['settings']['show_logo']) ? (bool)$settings['settings']['show_logo'] : true;
            
            $settings['show_copyright'] = isset($settings['settings']['show_copyright']) ? (bool)$settings['settings']['show_copyright'] : true;

            unset($settings['settings']['api_key']);
            
            wp_send_json_success($settings);
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function get_redirect_url($is_modal = false) {
        // Get the current page URL from referer header
        $current_url = wp_get_referer();
        
        // If we have a referer URL, check if it's a single product page or checkout page
        if ($current_url) {
            $parsed_url = wp_parse_url($current_url);
            $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
            
            // Check if current page is checkout
            if (function_exists('wc_get_checkout_url') && strpos($current_url, wc_get_checkout_url()) !== false) {
                return $current_url;
            }
            
            // Check if current page is a single product page
            if (function_exists('wc_get_product_id_by_sku') && $this->is_single_product_page($current_url)) {
                return $current_url;
            }
            
            // Check for WooCommerce shop pages
            if (class_exists('WooCommerce')) {
                // Get shop page URL
                $shop_page_id = wc_get_page_id('shop');
                if ($shop_page_id > 0) {
                    $shop_url = get_permalink($shop_page_id);
                    if (strpos($current_url, $shop_url) !== false) {
                        return $current_url;
                    }
                }
                
                // Check for product category pages
                if (strpos($path, '/product-category/') !== false || 
                    strpos($path, '/product-tag/') !== false ||
                    preg_match('/\/product\/[^\/]+\/?$/', $path)) {
                    return $current_url;
                }
            }
        }
        
        // Default redirect logic for other pages
        $settings = get_option('smsir_settings', []);
        $redirect_url = home_url();

        if (!empty($settings['settings']['custom_redirect'])) {
            $redirect_setting = $is_modal ? 'modal_redirect_url' : 'form_redirect_url';
            $custom_redirect = !empty($settings['settings'][$redirect_setting]) 
                ? $settings['settings'][$redirect_setting] 
                : '';
            
            if ($custom_redirect) {
                if (preg_match('/^https?:\/\//i', $custom_redirect)) {
                    $redirect_url = $custom_redirect;
                } 
                else if (strpos($custom_redirect, '/') === 0) {
                    $redirect_url = home_url($custom_redirect);
                }
                else {
                    $redirect_url = home_url('/' . ltrim($custom_redirect, '/'));
                }
            }
        }

        return $redirect_url;
    }

    /**
     * Check if the given URL is a single product page
     */
    private function is_single_product_page($url) {
        if (!function_exists('url_to_postid')) {
            return false;
        }
        
        $post_id = url_to_postid($url);
        if ($post_id > 0) {
            $post = get_post($post_id);
            return $post && $post->post_type === 'product';
        }
        
        // Fallback: check URL pattern for products
        $parsed_url = wp_parse_url($url);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        
        // Check if URL matches product permalink structure
        return preg_match('/\/product\/[^\/]+\/?$/', $path);
    }

    public function ajax_sync_digits_users() {
        try {
            check_ajax_referer('sms_ir_admin', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception('Permission denied');
            }
            
            $users = get_users(['fields' => 'ID']);
            $synced = 0;
            
            foreach ($users as $user_id) {
                $digits_phone = get_user_meta($user_id, 'digits_phone', true);
                $phone = get_user_meta($user_id, 'phone', true);

                if ($digits_phone) {
                    if ($digits_phone) {
                        $clean_phone = $this->formatPhoneNumber($digits_phone);
                        update_user_meta($user_id, 'smsir_phone', $clean_phone);
                        update_user_meta($user_id, 'billing_phone','98'. preg_replace('/^0/', '', $clean_phone));
                        $synced++;
                    }
                } else if ($phone) {
                    $clean_phone = $this->formatPhoneNumber($phone);
                    update_user_meta($user_id, 'smsir_phone', $clean_phone);
                    update_user_meta($user_id, 'billing_phone', '98' . preg_replace('/^0/', '', $clean_phone));
                    $synced++;
                }
            }
            
            wp_send_json_success([
                'message' => sprintf('هماهنگ سازی با موفقیت انجام شد. تعداد %d کاربر به روز شدند.', $synced)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function sync_new_user_phone($user_id) {
        $digits_phone = get_user_meta($user_id, 'digits_phone', true);
        
        if ($digits_phone) {
            update_user_meta($user_id, 'smsir_phone', '0' . $digits_phone);
        }
        
    }

    private function get_settings() {
        $settings = get_option('smsir_settings', []);
        return [
            'rate_limit_active' => $settings['settings']['rate_limit_active'] ?? true,
            'max_attempts' => $settings['settings']['max_attempts'] ?? 5,
            'ban_duration' => $settings['settings']['ban_duration'] ?? 600,
            'otp_expiry' => $settings['settings']['otp_expiry'] ?? 90,
            'resend_delay' => $settings['settings']['resend_delay'] ?? 90,
            'otp_length' => $settings['settings']['otp_length'] ?? 5
        ];
    }

    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/^\+?0*/', '', $phone);
        $phone = preg_replace('/^98/', '', $phone);
        
        if (strpos($phone, '9') === 0 && strlen($phone) == 10) {
            $phone = '0' . $phone;
        }
    
        return $phone;
    }

    public function ajax_save_custom_fields() {
        try {
            check_ajax_referer('sms_ir_login', 'nonce');

            $phone = sanitize_text_field($_POST['phone']);
            $clean_phone = $this->formatPhoneNumber($phone);
            $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
            $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
            $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
            $custom_fields = isset($_POST['custom_fields']) ? $_POST['custom_fields'] : [];

            $user_data = [
                'user_login' => $clean_phone,
                'user_pass' => wp_generate_password(),
                'role' => $this->get_default_user_role()
            ];

            if ($email) {
                $user_data['user_email'] = $email;
            }
            if ($first_name) {
                $user_data['first_name'] = $first_name;
            }
            if ($last_name) {
                $user_data['last_name'] = $last_name;
            }

            $user_id = wp_insert_user($user_data);
            
            if (is_wp_error($user_id)) {
                throw new \Exception($user_id->get_error_message());
            }
            $clean_phone = $this->formatPhoneNumber($phone);
            update_user_meta($user_id, 'phone', $clean_phone);
            update_user_meta($user_id, 'smsir_phone', $clean_phone);
            update_user_meta($user_id, 'billing_phone',  '98' . preg_replace('/^0/', '', $clean_phone));


            if (is_array($custom_fields)) {
                foreach ($custom_fields as $meta_key => $value) {
                    $field_type = $this->get_custom_field_type($meta_key);
                    
                    switch ($field_type) {
                        case 'number':
                            $value = intval($value);
                            break;
                        case 'checkbox':
                            // Checkbox values should be boolean or 1/0
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                            break;
                        case 'textarea':
                            $value = sanitize_textarea_field($value);
                            break;
                        case 'select':
                        case 'radio':
                            // These are already sanitized as text
                            $value = sanitize_text_field($value);
                            break;
                        case 'uploader':
                            // Value is attachment ID from frontend upload
                            $value = absint($value);
                            if ($value > 0 && get_post_type($value) === 'attachment') {
                                update_user_meta($user_id, sanitize_key($meta_key), $value);
                            }
                            continue 2;
                        case 'text':
                        default:
                            $value = sanitize_text_field($value);
                            break;
                    }
                    
                    update_user_meta($user_id, sanitize_key($meta_key), $value);
                }
            }

            do_action('gtm_smsir_after_signup', $user_id);
            wp_set_auth_cookie($user_id);
            do_action('gtm_smsir_after_login', $user_id);

            $redirect_url = $this->get_redirect_url(
                isset($_POST['is_modal']) && $_POST['is_modal'] === 'true'
            );

            wp_send_json_success([
                'message' => __('Registration successful', 'sms-ir'),
                'redirect_url' => $redirect_url
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Supported file extensions and their mime types for uploader custom fields.
     *
     * @return array<string, string> extension => mime_type
     */
    private function get_uploader_allowed_mime_map() {
        return [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    /**
     * Get allowed mimes for an uploader field from settings (accepted_file_types).
     * If field has no accepted_file_types, returns default map.
     *
     * @param string $meta_key Custom field meta_key
     * @return array<string, string> extension => mime_type
     */
    private function get_uploader_allowed_mimes_for_field($meta_key) {
        $full_map = $this->get_uploader_allowed_mime_map();
        $settings = get_option('smsir_settings', []);
        $custom_fields = isset($settings['settings']['custom_fields']) ? $settings['settings']['custom_fields'] : [];
        foreach ($custom_fields as $field) {
            if (isset($field['meta_key']) && $field['meta_key'] === $meta_key && !empty($field['accepted_file_types'])) {
                $exts = array_map('trim', explode(',', $field['accepted_file_types']));
                $exts = array_map('strtolower', $exts);
                $allowed = [];
                foreach ($exts as $ext) {
                    $ext = ltrim($ext, '.');
                    if (isset($full_map[$ext])) {
                        $allowed[$ext] = $full_map[$ext];
                    }
                }
                return !empty($allowed) ? $allowed : $full_map;
            }
        }
        return $full_map;
    }

    /**
     * Handle file upload for custom field type "uploader".
     * Uses WordPress media handling; returns attachment ID for storing in user meta.
     */
    public function ajax_upload_custom_field_file() {
        try {
            check_ajax_referer('sms_ir_login', 'nonce');

            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                wp_send_json_error(['message' => __('No file was uploaded.', 'sms-ir')]);
                return;
            }

            $file = $_FILES['file'];
            $meta_key = isset($_POST['meta_key']) ? sanitize_key($_POST['meta_key']) : '';

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $allowed_types = $this->get_uploader_allowed_mimes_for_field($meta_key);
            $overrides = [
                'test_form' => false,
                'mimes' => $allowed_types,
            ];

            $upload = wp_handle_upload($file, $overrides);

            if (isset($upload['error'])) {
                wp_send_json_error(['message' => $upload['error']]);
                return;
            }

            $attachment = [
                'post_mime_type' => $upload['type'],
                'post_title' => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
                'post_content' => '',
                'post_status' => 'inherit',
            ];
            $attachment_id = wp_insert_attachment($attachment, $upload['file']);

            if (is_wp_error($attachment_id)) {
                wp_send_json_error(['message' => $attachment_id->get_error_message()]);
                return;
            }

            wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));

            wp_send_json_success([
                'attachment_id' => $attachment_id,
                'url' => wp_get_attachment_url($attachment_id),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function ajax_complete_login() {
        try {
            check_ajax_referer('sms_ir_login', 'nonce');

            $phone = sanitize_text_field($_POST['phone']);
            $clean_phone = $this->formatPhoneNumber($phone);

            $user_id = wp_insert_user([
                'user_login' => $clean_phone,
                'user_pass' => wp_generate_password(),
                'role' => $this->get_default_user_role()
            ]);
            
            if (is_wp_error($user_id)) {
                throw new \Exception(__('Failed to create user', 'sms-ir'));
            }
            
            update_user_meta($user_id, 'phone', $clean_phone);
            update_user_meta($user_id, 'smsir_phone', $clean_phone);
            
            do_action('gtm_smsir_after_signup', $user_id);
            wp_set_auth_cookie($user_id);
            do_action('gtm_smsir_after_login', $user_id);
            
            $redirect_url = $this->get_redirect_url(
                isset($_POST['is_modal']) && $_POST['is_modal'] === 'true'
            );
            
            wp_send_json_success([
                'message' => __('Registration successful', 'sms-ir'),
                'redirect_url' => $redirect_url
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function ajax_request_change_phone_otp() {
        try {
            if (!is_user_logged_in()) {
                throw new \Exception(__('You must be logged in', 'sms-ir'));
            }

            check_ajax_referer('sms_ir_login', 'nonce');

            $phone = sanitize_text_field($_POST['phone']);
            $clean_phone = $this->formatPhoneNumber($phone);
            $user_id = get_current_user_id();
            $current_phone = get_user_meta($user_id, 'smsir_phone', true);

            if ($clean_phone === $current_phone) {
                throw new \Exception(__('New phone number must be different from current one', 'sms-ir'));
            }

            $existing_user = get_users([
                'meta_key' => 'smsir_phone',
                'meta_value' => $clean_phone,
                'number' => 1,
                'exclude' => [$user_id]
            ]);

            if (!empty($existing_user)) {
                throw new \Exception(__('This phone number is already registered', 'sms-ir'));
            }
          
            if (!$this->validate_phone($clean_phone)) {
                throw new \Exception(__('Invalid phone number format', 'sms-ir'));
            }

            $settings = $this->get_settings();
            $otp_length = isset($settings['otp_length']) ? intval($settings['otp_length']) : 4;
            
            $min = pow(10, $otp_length - 1);
            $max = pow(10, $otp_length) - 1;
            $otp = wp_rand($min, $max);
            
            // Store OTP in database directly (bypass cache)
            $otp_data = [
                'otp' => $otp,
                'phone' => $clean_phone,
                'expires' => time() + $settings['otp_expiry']
            ];
            
            // Use autoload=false to prevent caching issues
            delete_option('smsir_change_phone_' . $user_id);
            if (!add_option('smsir_change_phone_' . $user_id, $otp_data, '', 'no')) {
                throw new \Exception('Failed to save OTP');
            }
            
            $params = [
                'Code' => $otp,
            ];
            $response = $this->sms_client->send_verification_code($clean_phone, $params);
            
            if ($response['success']) {
                wp_send_json_success([
                    'message' => __('OTP sent successfully', 'sms-ir')
                ]);
            } else {
                throw new \Exception(__('Failed to send OTP', 'sms-ir'));
            }

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function ajax_verify_change_phone() {
        try {
            if (!is_user_logged_in()) {
                throw new \Exception(__('You must be logged in', 'sms-ir'));
            }

            check_ajax_referer('sms_ir_login', 'nonce');

            $user_id = get_current_user_id();
            $phone = sanitize_text_field($_POST['phone']);
            $clean_phone = $this->formatPhoneNumber($phone);
            $otp = sanitize_text_field($_POST['otp']);

            // Get OTP data from database
            $stored_data = get_option('smsir_change_phone_' . $user_id, false);
            
            if (!$stored_data || !isset($stored_data['otp']) || !isset($stored_data['phone']) || !isset($stored_data['expires'])) {
                throw new \Exception(__('Invalid verification code', 'sms-ir'));
            }
            
            // Check if OTP has expired
            if (time() > $stored_data['expires']) {
                delete_option('smsir_change_phone_' . $user_id);
                throw new \Exception(__('Verification code has expired', 'sms-ir'));
            }
            
            // Verify OTP and phone match
            if ($stored_data['otp'] != $otp || $this->formatPhoneNumber($stored_data['phone']) != $clean_phone) {
                throw new \Exception(__('Invalid verification code', 'sms-ir'));
            }

            update_user_meta($user_id, 'phone', $clean_phone);
            update_user_meta($user_id, 'smsir_phone',  $clean_phone);
            update_user_meta($user_id, 'billing_phone', '98' . preg_replace('/^0/', '', $clean_phone));
            
            // Delete OTP after successful verification
            delete_option('smsir_change_phone_' . $user_id);

            wp_send_json_success([
                'message' => __('Phone number changed successfully', 'sms-ir')
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get the type of a custom field by its meta key
     *
     * @param string $meta_key The meta key to look up
     * @return string The field type (text, number, etc.) or 'text' as default
     */
    private function get_custom_field_type($meta_key) {
        $settings = get_option('smsir_settings', []);
        $custom_fields = isset($settings['settings']['custom_fields']) ? 
            $settings['settings']['custom_fields'] : [];
        
        if (!empty($custom_fields)) {
            foreach ($custom_fields as $field) {
                if ($field['meta_key'] === $meta_key) {
                    return isset($field['type']) ? $field['type'] : 'text';
                }
            }
        }
        
        return 'text'; // Default to text type if not found
    }

    /**
     * Get custom fields configuration from plugin settings
     *
     * @return array
     */
    private function get_custom_fields_config() {
        $settings = get_option('smsir_settings', []);
        return isset($settings['settings']['custom_fields']) ? $settings['settings']['custom_fields'] : [];
    }

    /**
     * Parse options string for select/radio (comma-separated) into array
     *
     * @param string $options
     * @return array
     */
    private function get_field_options_list($options) {
        if (empty($options)) {
            return [];
        }
        return array_map('trim', array_filter(explode(',', $options)));
    }

    /**
     * Render SMS.ir custom registration fields section on user profile / edit user in admin
     *
     * @param \WP_User $user
     */
    public function render_smsir_custom_fields_section($user) {
        $custom_fields = $this->get_custom_fields_config();
        if (empty($custom_fields)) {
            return;
        }

        wp_nonce_field('smsir_save_user_custom_fields', 'smsir_custom_fields_nonce');
        ?>
        <h2><?php esc_html_e('SMS.ir – Custom Registration Fields', 'sms-ir'); ?></h2>
        <p class="description"><?php esc_html_e('Fields collected during registration. Shown for each user.', 'sms-ir'); ?></p>
        <table class="form-table" role="presentation">
            <tbody>
            <?php
            foreach ($custom_fields as $field) {
                $meta_key = isset($field['meta_key']) ? $field['meta_key'] : '';
                $name = isset($field['name']) ? $field['name'] : $meta_key;
                $type = isset($field['type']) ? $field['type'] : 'text';
                $value = get_user_meta($user->ID, $meta_key, true);
                $input_name = 'smsir_cf[' . esc_attr(sanitize_key($meta_key)) . ']';
                ?>
                <tr>
                    <th scope="row"><label for="smsir_cf_<?php echo esc_attr($meta_key); ?>"><?php echo esc_html($name); ?></label></th>
                    <td>
                        <?php
                        if ($type === 'text' || $type === '' || ! $type) {
                            ?>
                            <input name="<?php echo esc_attr($input_name); ?>" id="smsir_cf_<?php echo esc_attr($meta_key); ?>" type="text" value="<?php echo esc_attr($value); ?>" class="regular-text" />
                            <?php
                        } elseif ($type === 'number') {
                            ?>
                            <input name="<?php echo esc_attr($input_name); ?>" id="smsir_cf_<?php echo esc_attr($meta_key); ?>" type="number" value="<?php echo esc_attr($value); ?>" class="regular-text" />
                            <?php
                        } elseif ($type === 'textarea') {
                            ?>
                            <textarea name="<?php echo esc_attr($input_name); ?>" id="smsir_cf_<?php echo esc_attr($meta_key); ?>" rows="4" class="large-text"><?php echo esc_textarea($value); ?></textarea>
                            <?php
                        } elseif ($type === 'select') {
                            $options = $this->get_field_options_list(isset($field['options']) ? $field['options'] : '');
                            ?>
                            <select name="<?php echo esc_attr($input_name); ?>" id="smsir_cf_<?php echo esc_attr($meta_key); ?>">
                                <option value=""><?php esc_html_e('— Select —', 'sms-ir'); ?></option>
                                <?php foreach ($options as $opt) : ?>
                                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($value, $opt); ?>><?php echo esc_html($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php
                        } elseif ($type === 'checkbox') {
                            ?>
                            <label><input name="<?php echo esc_attr($input_name); ?>" id="smsir_cf_<?php echo esc_attr($meta_key); ?>" type="checkbox" value="1" <?php checked($value, '1'); ?> /> <?php echo esc_html(isset($field['checkbox_label']) ? $field['checkbox_label'] : $name); ?></label>
                            <?php
                        } elseif ($type === 'radio') {
                            $options = $this->get_field_options_list(isset($field['options']) ? $field['options'] : '');
                            foreach ($options as $opt) {
                                ?>
                                <label><input name="<?php echo esc_attr($input_name); ?>" type="radio" value="<?php echo esc_attr($opt); ?>" <?php checked($value, $opt); ?> /> <?php echo esc_html($opt); ?></label><br />
                                <?php
                            }
                        } elseif ($type === 'uploader') {
                            if (!empty($value) && absint($value) && get_post_type((int) $value) === 'attachment') {
                                $url = wp_get_attachment_url((int) $value);
                                $filename = basename(get_attached_file((int) $value));
                                echo $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($filename) . '</a>' : esc_html($value);
                            } else {
                                echo '<span class="description">—</span>';
                            }
                        } else {
                            ?>
                            <input name="<?php echo esc_attr($input_name); ?>" id="smsir_cf_<?php echo esc_attr($meta_key); ?>" type="text" value="<?php echo esc_attr($value); ?>" class="regular-text" />
                            <?php
                        }
                        ?>
                    </td>
                </tr>
            <?php
            }
            ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Save SMS.ir custom fields when user profile is updated in admin
     *
     * @param int $user_id
     */
    public function save_smsir_custom_fields_section($user_id) {
        if (!isset($_POST['smsir_custom_fields_nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['smsir_custom_fields_nonce']), 'smsir_save_user_custom_fields')) {
            return;
        }
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        $posted = isset($_POST['smsir_cf']) && is_array($_POST['smsir_cf']) ? $_POST['smsir_cf'] : [];
        if (empty($posted)) {
            return;
        }
        $custom_fields = $this->get_custom_fields_config();
        $meta_keys = [];
        foreach ($custom_fields as $field) {
            if (!empty($field['meta_key'])) {
                $meta_keys[$field['meta_key']] = isset($field['type']) ? $field['type'] : 'text';
            }
        }
        // Checkboxes: when unchecked they are not in $_POST, so set them to 0 first
        foreach ($meta_keys as $mk => $ft) {
            if ($ft === 'checkbox' && !isset($posted[$mk])) {
                $posted[$mk] = '0';
            }
        }
        foreach ($posted as $meta_key => $value) {
            $meta_key = sanitize_key($meta_key);
            if (!isset($meta_keys[$meta_key])) {
                continue;
            }
            $field_type = $meta_keys[$meta_key];
            switch ($field_type) {
                case 'number':
                    $value = intval($value);
                    break;
                case 'checkbox':
                    $value = !empty($value) ? '1' : '0';
                    break;
                case 'textarea':
                    $value = sanitize_textarea_field($value);
                    break;
                case 'select':
                case 'radio':
                    $value = sanitize_text_field($value);
                    break;
                case 'uploader':
                    $value = absint($value);
                    if ($value > 0 && get_post_type($value) === 'attachment') {
                        update_user_meta($user_id, $meta_key, $value);
                    }
                    continue 2;
                case 'text':
                default:
                    $value = sanitize_text_field($value);
                    break;
            }
            update_user_meta($user_id, $meta_key, $value);
        }
    }

    /**
     * Get available WordPress user roles
     */
    public function ajax_get_user_roles() {
        try {
            check_ajax_referer('sms_ir_admin', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new \Exception('Permission denied');
            }
            
            global $wp_roles;
            $roles = [];
            
            foreach ($wp_roles->roles as $role_key => $role) {
                $roles[] = [
                    'value' => $role_key,
                    'label' => translate_user_role($role['name'])
                ];
            }
            
            wp_send_json_success(['roles' => $roles]);
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get the default user role from settings
     *
     * @return string The role slug, defaults to 'subscriber'
     */
    private function get_default_user_role() {
        $settings = get_option('smsir_settings', []);
        return isset($settings['settings']['default_user_role']) && !empty($settings['settings']['default_user_role']) 
            ? $settings['settings']['default_user_role'] 
            : 'subscriber';
    }

    /**
     * Cleanup expired OTPs from database
     * Runs once per hour to prevent database bloat
     */
    public function cleanup_expired_otps() {
        // Only run cleanup once per hour
        $last_cleanup = get_option('smsir_last_otp_cleanup', 0);
        if (time() - $last_cleanup < 3600) {
            return;
        }
        
        global $wpdb;
        $current_time = time();
        
        // Get all options that start with smsir_otp_ or smsir_change_phone_
        $otp_options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
            WHERE option_name LIKE 'smsir_otp_%' 
            OR option_name LIKE 'smsir_change_phone_%'"
        );
        
        foreach ($otp_options as $option) {
            $data = maybe_unserialize($option->option_value);
            
            // Check if it's an expired OTP
            if (is_array($data) && isset($data['expires']) && $current_time > $data['expires']) {
                delete_option($option->option_name);
            }
        }
        
        // Update last cleanup timestamp
        update_option('smsir_last_otp_cleanup', $current_time, 'no');
    }
} 
