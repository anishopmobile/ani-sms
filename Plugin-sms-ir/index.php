<?php
/*
Plugin Name: پیامک SMS.ir
Description: پیامک SMS.ir نسخه حرفه ای
Plugin URI:  https://www.sms.ir
Version:     1.7.3
Author:      Mohammad Kafi
Author URI:  https://www.sms.ir
Text Domain: sms-ir
Requires Plugins: woocommerce 
 */
defined('ABSPATH') || exit("no access");

require_once __DIR__ . '/vendor/autoload.php';

use SMSIR\Main;
use SMSIR\AuthController;
use SMSIR\BanManager;
use SMSIR\GFIntegration;
use SMSIR\FormsIntegration;
use SMSIR\Translations;
use SMSIR\WCIntegration;
use SMSIR\GravityAdmin;
use SMSIR\SMSIR_Leave_Cart;
use SMSIR\SMSIR_Error_Logger;
use SMSIR\SMSIR_Excel_Uploader;
use SMSIR\NotifyMe_Controller;

define('SMSIR_DIR', plugin_dir_path(__FILE__));
define('SMSIR_ASSETS', trailingslashit(plugin_dir_url(__FILE__)) . 'assets');
define('SMSIR_FILE', __FILE__);
define('SMSIR_VERSION', '1.7.3');


load_plugin_textdomain('sms-ir', false, dirname(plugin_basename(__FILE__)) . '/languages');

$smsir_instance_counter = 0;

function smsir_login_admin_enq_scripts()
{
    if (get_current_screen()->id === 'toplevel_page_sms_ir_login') {
        wp_enqueue_style('sms-ir-login', SMSIR_ASSETS . '/app/app.css', [], SMSIR_VERSION);
        
        wp_enqueue_media();
        
        wp_enqueue_script('wp-i18n');
        
        add_action('admin_footer', function() {
            wp_enqueue_script('sms-ir-login', SMSIR_ASSETS . '/app/app.js', ['jquery', 'wp-i18n','wp-hooks','heartbeat','media-upload','wp-backbone','wp-util','media-models','media-views'], SMSIR_VERSION, true);
            wp_localize_script('sms-ir-login', 'sms_ir_login', array(
                'lang' => 'fa',
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sms_ir_admin'),
                'translations' => Translations::get_translations(),
                'pluginUrl' => SMSIR_ASSETS,
                'woocommerce_active' => class_exists('WooCommerce')
            ));
        }, 999);
    }
}
add_action('admin_enqueue_scripts', 'smsir_login_admin_enq_scripts');

function sms_ir_initialize_classes() {
    Main::get_instance();
    AuthController::get_instance();
    if(class_exists('WooCommerce')) {
        WCIntegration::get_instance();
        require_once SMSIR_DIR . 'includes/class-leave-cart.php';
        new SMSIR_Leave_Cart();
        
        NotifyMe_Controller::get_instance();
    }
    if (class_exists('\GFForms')) {
        GFIntegration::get_instance();
    }
    FormsIntegration::get_instance();
    GravityAdmin::get_instance();

    require_once SMSIR_DIR . 'includes/class-smsir-ajax-handler.php';
    SMSIR\SMSIR_Ajax_Handler::get_instance();
    
    require_once SMSIR_DIR . 'includes/class-excel-uploader.php';
    SMSIR_Excel_Uploader::get_instance();

    require_once SMSIR_DIR . 'includes/ContactsController.php';
    SMSIR\ContactsController::get_instance();
}

add_action('plugins_loaded', 'sms_ir_initialize_classes');

function smsir_get_domain_only() {
    $url = site_url();
    $parsed = parse_url($url);
    return $parsed['host'];
}

function smsir_login_shortcode() {
    if(is_user_logged_in()) return;
    
    // Don't load on checkout pages to prevent conflicts with WooCommerce block checkout only
    if (is_checkout() && has_block('woocommerce/checkout')) {
        return '<p>' . __('Login not available on checkout page', 'sms-ir') . '</p>';
    }
    
    global $smsir_instance_counter;
    $smsir_instance_counter++;
    
    wp_enqueue_style('sms-ir-login', SMSIR_ASSETS . '/app/app.css', [], SMSIR_VERSION);
    wp_enqueue_script('jquery');
    wp_enqueue_script('sms-ir-login', SMSIR_ASSETS . '/app/app.js', ['jquery'], SMSIR_VERSION, true);
    
    // Make sure we explicitly use the admin-ajax.php URL
    $ajax_url = admin_url('admin-ajax.php');
    
    $saved = get_option('smsir_settings', array());
    $login_style = isset($saved['settings']['login_style']) ? $saved['settings']['login_style'] : array();
    wp_localize_script('sms-ir-login', 'sms_ir_login', array(
        'lang' => 'fa',
        'ajaxUrl' => $ajax_url,
        'nonce' => wp_create_nonce('sms_ir_login'),
        'translations' => Translations::get_translations(),
        'siteUrl' => smsir_get_domain_only(),
        'siteUrlFull' => site_url(),
        'login_style' => $login_style,
    ));
    
    return '<div class="smsir flex items-center justify-center w-full" id="smsir-login-app-' . $smsir_instance_counter . '"></div>';
}
add_shortcode('smsir_login', 'smsir_login_shortcode');

function smsir_login_modal_shortcode() {
    if(is_user_logged_in()) return;
    
    // Don't load on checkout pages to prevent conflicts with WooCommerce block checkout only
    if (class_exists('WooCommerce') && function_exists('is_checkout') && is_checkout() && has_block('woocommerce/checkout')) {
        return '<p>' . __('Login modal not available on checkout page', 'sms-ir') . '</p>';
    }
    
    global $smsir_instance_counter;
    $smsir_instance_counter++;
    
    if (!wp_script_is('sms-ir-login', 'enqueued')) {
        wp_enqueue_style('sms-ir-login', SMSIR_ASSETS . '/app/app.css', [], SMSIR_VERSION);
        wp_enqueue_script('jquery');
        wp_enqueue_script('sms-ir-login', SMSIR_ASSETS . '/app/app.js', ['jquery'], SMSIR_VERSION, true);
        $saved = get_option('smsir_settings', array());
        $login_style = isset($saved['settings']['login_style']) ? $saved['settings']['login_style'] : array();
        wp_localize_script('sms-ir-login', 'sms_ir_login', array(
            'lang' => 'fa',
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sms_ir_login'),
            'translations' => Translations::get_translations(),
            'siteUrl' => smsir_get_domain_only(),
            'siteUrlFull' => site_url(),
            'login_style' => $login_style,
        ));
    }
    
    return sprintf(
        '<div id="smsir-login-modal-app-%d" class="smsir smsir-login-modal"></div>',
        $smsir_instance_counter
    );
}
add_shortcode('smsir_login_modal', 'smsir_login_modal_shortcode');

register_activation_hook(__FILE__, 'smsir_activate_plugin');

function smsir_activate_plugin() {
    
    require_once SMSIR_DIR . 'includes/class-smsir-error-logger.php';
    
    $logger = SMSIR_Error_Logger::get_instance();
    $logger->create_table();
    
    BanManager::get_instance()->create_table();
    AuthController::get_instance()::plugin_activate();
    
    if (class_exists('WooCommerce')) {
        require_once SMSIR_DIR . 'includes/class-leave-cart.php';
        $leave_cart = new SMSIR_Leave_Cart();
        $leave_cart->create_tables();
        
        require_once SMSIR_DIR . 'includes/class-notifyme-controller.php';
        SMSIR\NotifyMe_Controller::create_table();
    }
    
    flush_rewrite_rules();
}


register_deactivation_hook(__FILE__, 'smsir_deactivate_plugin');

function smsir_deactivate_plugin() {
    $login_page = get_page_by_path('login');
    
    if ($login_page) {
        wp_delete_post($login_page->ID, true);
    }
    
    flush_rewrite_rules();
}

function smsir_change_phone_shortcode() {
    if (!is_user_logged_in()) {
        return '';
    }
    
    // Don't load on checkout pages to prevent conflicts with WooCommerce block checkout only
    if (class_exists('WooCommerce') && function_exists('is_checkout') && is_checkout() && has_block('woocommerce/checkout')) {
        return '<p>' . __('Phone change not available on checkout page', 'sms-ir') . '</p>';
    }
    
    wp_enqueue_style('sms-ir-login', SMSIR_ASSETS . '/app/app.css', [], SMSIR_VERSION);
    wp_enqueue_script('jquery');
    wp_enqueue_script('sms-ir-login', SMSIR_ASSETS . '/app/app.js', ['jquery'], SMSIR_VERSION, true);
    
    $user = wp_get_current_user();
    $phone = get_user_meta($user->ID, 'phone', true);
    
    wp_localize_script('sms-ir-login', 'sms_ir_login', array(
        'lang' => 'fa',
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('sms_ir_login'),
        'currentPhone' => $phone,
        'translations' => Translations::get_translations()
    ));
    
    return '<div id="smsir-change-phone-app"></div>';
}
add_shortcode('smsir_change_phone', 'smsir_change_phone_shortcode');

function smsir_ensure_elementor_loaded() {
    if (did_action('plugins_loaded') && !did_action('elementor/loaded')) {
        add_action('elementor/loaded', function() {
            SMSIR\Main::get_instance();
        });
    } else {
        SMSIR\Main::get_instance();
    }
}

add_action('plugins_loaded', 'smsir_ensure_elementor_loaded', 20);

