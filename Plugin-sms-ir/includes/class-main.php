<?php
namespace SMSIR;


class Main
{
    private static $instance        = null;
    private static $_settings_cache = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->define_hooks();
        add_action('init', [$this, 'add_endpoints']);
        
        // Only add WooCommerce hooks if WooCommerce is active
        if ($this->is_woocommerce_active()) {
            add_action('woocommerce_edit_account_form', [$this, 'add_phone_to_edit_account'],20);
            add_action('woocommerce_save_account_details', [$this, 'save_phone_account_details']);
            add_filter('woocommerce_save_account_details_required_fields', [$this, 'add_phone_required_field']);
            add_action('wp_enqueue_scripts', [$this, 'add_account_styles']);
            add_action('wp_enqueue_scripts', [$this, 'add_account_scripts']);
            add_action('init', [$this, 'remove_wc_login_actions']);
            add_filter('woocommerce_locate_template', [$this, 'override_wc_template'], 10, 3);
        }
        
        add_action('wp_ajax_sms_ir_get_messages', [$this, 'sms_ir_get_messages_ajax']);
        add_action('wp_ajax_sms_ir_save_phone', [$this, 'sms_ir_save_phone_ajax']);
        add_action('wp_ajax_nopriv_sms_ir_save_phone', [$this, 'sms_ir_save_phone_ajax']);
        add_action('elementor/widgets/register', [$this, 'register_widgets']);
        add_action('wp_ajax_sms_ir_get_newsletter_phones', [$this, 'sms_ir_get_newsletter_phones_ajax']);
        add_action('wp_ajax_sms_ir_delete_newsletter_phone', [$this, 'sms_ir_delete_newsletter_phone_ajax']);

        // Initialize the newsletter shortcode
        require_once SMSIR_DIR . 'includes/shortcodes/newsletter.php';
        new \SMSIR\Shortcodes\SMSIR_Newsletter_Shortcode();
    }

    public function define_hooks()
    {
        add_action('init', [$this, 'sms_ir_localize_handler']);
     
        add_action('admin_menu', function () {

               $pluginIcon = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMTciIHZpZXdCb3g9IjAgMCAyMCAxNyIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTIwIDE2LjA0NjVDMTkuMTYyNiAxNS42MDgxIDE4LjI1OTQgMTUuMjc4NSAxNy4zMDg0IDE1LjA3NzZDMTYuNjA1MiAxNC45MjkzIDE1Ljg3NjYgMTQuODUxNCAxNS4xMjkzIDE0Ljg1MTRDMTQuNTQzNSAxNC44NTE0IDEzLjk2ODkgMTQuODk5MiAxMy40MDk0IDE0Ljk5MjJDMTIuNzQ2NiAxNS4xMDExIDEyLjEwNTMgMTUuMjcyIDExLjQ5MDQgMTUuNDk5MkMxMS4zNDg2IDE1LjU1MTcgMTEuMjA3OCAxNS42MDcxIDExLjA2ODkgMTUuNjY1M0MxMC4zMjQ0IDE1Ljk3OCA5LjU0Nzk1IDE2LjIyMDIgOC43NDYxOSAxNi4zMjUzQzguMzYxMjYgMTYuMzc2IDcuOTY3ODkgMTYuNDAyMyA3LjU2ODg5IDE2LjQwMjNDNi45OTYyIDE2LjQwMjMgNi40MzU3MSAxNi4zNDc5IDUuODk0MDEgMTYuMjQ0NkM1LjUyNTA0IDE2LjE3NjEgNS4xNjQ1MyAxNi4wODMxIDQuODEzNDEgMTUuOTY4NkM0LjY5NzkzIDE1LjkzMiA0LjU4NTI3IDE1Ljg5MjUgNC40NzE2NyAxNS44NTAzQzQuMjczNTggMTUuNzc4IDQuMDc5MjQgMTUuNjk4MiAzLjg4NzcyIDE1LjYxMThDMy43NTYyOCAxNS41NDA1IDMuNjI3NjYgMTUuNDY2MyAzLjUwMDkyIDE1LjM4NzRDMy4yOTE1NSAxNS4yNjA3IDMuMDg4NzcgMTUuMTIzNiAyLjg5MDY3IDE0Ljk4QzIuMzcyNDQgMTQuNjAzNSAxLjg5NjQ1IDE0LjE3MDcgMS40NzIwOSAxMy42OTE5QzAuODczMTE2IDEzLjAxOTcgMC4zNzQ1OTUgMTIuMjU1NSAwIDExLjQyMzdDMS4zNzQ0NSAxMi4xNyAyLjk0OTgyIDEyLjU5MjUgNC42MjQ3IDEyLjU5MzVDNC45NzM5NSAxMi41OTM1IDUuMzE4NSAxMi41NzQ3IDUuNjU3NDIgMTIuNTM5QzUuNzg1MSAxMi41MjU5IDUuOTExODQgMTIuNTEwOCA2LjAzNzY1IDEyLjQ5MjFDNi4zMDE0NiAxMi40NTQ1IDYuNTYxNTIgMTIuNDA0OCA2LjgxNzgyIDEyLjM0NjVMNi44NTA2OCAxMi4zMzlMNi44NjU3IDEyLjMzNTNDNi45NTMwMSAxMi4zMTU2IDcuMDQwMzIgMTIuMjkzIDcuMTI2NyAxMi4yNjk2TDcuMTMzMjcgMTIuMjY3N0w4LjQ0ODU4IDExLjkzNjNMMTAuNTg5MSAxMS4zOTY0QzEwLjU4OTEgMTEuMzk2NCAxMC41OTEgMTEuMzk2NCAxMC41OTE5IDExLjM5NDZIMTAuNTk0OEwxMC42MDEzIDExLjM5MjdMMTAuNzc3OCAxMS4zNDg2QzExLjQ4MSAxMS4xNzY4IDEyLjIxNjEgMTEuMDg1NyAxMi45NzA5IDExLjA4NTdDMTMuMzA4OSAxMS4wODU3IDEzLjY0NDEgMTEuMTA0NSAxMy45NzI3IDExLjE0MDFDMTQuNTA1OSAxMS4yMzMxIDE1LjAyMjMgMTEuMzczIDE1LjUxOCAxMS41NTdDMTUuNjE4NSAxMS41OTM2IDE1LjcxOCAxMS42MzIxIDE1LjgxNjYgMTEuNjcyNUMxNS45MTc5IDExLjcyNDEgMTYuMDE3NSAxMS43Nzg2IDE2LjExNiAxMS44MzQ5QzE2LjEyODIgMTEuODQxNSAxNi4xNDA0IDExLjg0NzEgMTYuMTUxNyAxMS44NTQ2QzE3LjgzMzIgMTIuODExMyAxOS4xODUxIDE0LjI3ODcgMTkuOTk3MiAxNi4wNDc0TDIwIDE2LjA0NjVaIiBmaWxsPSIjRkZCRTAwIi8+CjxwYXRoIGQ9Ik0xNy45MTE3IDEyLjQxODlDMTcuMjk2OCAxMS45MDM1IDE2LjYwNjggMTEuNDY2IDE1Ljg1MzggMTEuMTI3MUMxNS4yOTggMTAuODc2NCAxNC43MDY2IDEwLjY4MDIgMTQuMDg2OSAxMC41NDRDMTMuNjAxNSAxMC40Mzc5IDEzLjExNjIgMTAuMzczMiAxMi42MzQ1IDEwLjM0ODhDMTIuMDY0NyAxMC4zMTg3IDExLjUwMTQgMTAuMzQ0MSAxMC45NTAzIDEwLjQyMTFDMTAuODAxIDEwLjQ0MTcgMTAuNjUxNyAxMC40NjcxIDEwLjUwMzQgMTAuNDk1MkMxMC4yNjMxIDEwLjU0MTIgMTAuMDIyNyAxMC41OTEgOS43Nzk1NSAxMC42MTA3QzkuMDQ1MzggMTAuNjcwOCA4LjI5MDU2IDEwLjYyNDggNy41MzI5MiAxMC40NTk1QzcuMDU3ODcgMTAuMzU1MyA2LjYwMjUzIDEwLjIwODkgNi4xNzE2MSAxMC4wMjQ5QzUuODc3NzUgOS45MDA5NCA1LjU5NTE2IDkuNzU4MjMgNS4zMjQ3OCA5LjYwMDUxQzUuMjM1NTkgOS41NDk4MSA1LjE0OTIyIDkuNDk2MyA1LjA2Mjg0IDkuNDQwOTFDNC45MTE2OSA5LjM0NTE1IDQuNzY1MjMgOS4yNDM3NSA0LjYyMTU5IDkuMTM2NzJDNC41MjU4MyA5LjA1NDExIDQuNDMxOTUgOC45Njg2NyA0LjM0MDg4IDguODgwNDJDNC4xODk3MyA4LjczNzcyIDQuMDQ2MDkgOC41ODc1MSAzLjkwOTAyIDguNDMxNjZDMy41NDc1NyA4LjAyNTE0IDMuMjMxMTggNy41ODAxNCAyLjk2NTQ5IDcuMTA2MDJDMi41ODk5NSA2LjQzOTQ1IDIuMzE1ODEgNS43MTU2MSAyLjE1NTI3IDQuOTU3MDNDMy4xNjA3NyA1LjgyNTQ1IDQuMzkwNjQgNi40NjE5OCA1Ljc4MDEyIDYuNzY1MjNDNi4wNzAyMiA2LjgyODEzIDYuMzU4NDQgNi44NzYwMSA2LjY0NjY2IDYuOTA3OTNDNi43NTQ2MyA2LjkyMDE0IDYuODYyNTkgNi45MzA0NiA2Ljk3MDU2IDYuOTM3OTdDNy4xOTU4OCA2Ljk1MzkzIDcuNDIxMiA2Ljk2MDUxIDcuNjQ0NjQgNi45NTg2M0g3LjY3MjgxSDcuNjg1OTVDNy43NjIgNi45NTg2MyA3LjgzODA0IDYuOTU0ODcgNy45MTQwOSA2Ljk1MTEySDcuOTE5NzJMOS4wNzA3MyA2LjkxNDVMMTAuOTQ0NiA2Ljg1NDQyQzEwLjk0NDYgNi44NTQ0MiAxMC45NDY1IDYuODU0NDIgMTAuOTQ3NSA2Ljg1NDQySDEwLjk1MDNIMTAuOTU1OUwxMS4xMDk5IDYuODQ5NzJDMTEuNzIzOSA2LjgzNDcgMTIuMzUxIDYuODkyOTEgMTIuOTc3MiA3LjAyOTk4QzEzLjI1NzkgNy4wOTEgMTMuNTMyMSA3LjE2NzA1IDEzLjc5ODcgNy4yNTYyNEMxNC4yMjQgNy40Mjk5MiAxNC42Mjc3IDcuNjQwMjIgMTUuMDA1MSA3Ljg4MjQ0QzE1LjA4MjEgNy45MzAzMiAxNS4xNTcyIDcuOTgxMDIgMTUuMjMxNCA4LjAzMjY1QzE1LjMwNTUgOC4wOTM2OCAxNS4zNzg4IDguMTU2NTggMTUuNDUxMSA4LjIyMTM2QzE1LjQ2MDQgOC4yMjg4NyAxNS40Njg5IDguMjM2MzggMTUuNDc3MyA4LjI0Mzg5QzE2LjY5ODggOS4zNDIzMyAxNy41NTUgMTAuODA1IDE3LjkwOCAxMi40MTk4TDE3LjkxMTcgMTIuNDE4OVoiIGZpbGw9IiNGRkJFMDAiLz4KPHBhdGggZD0iTTE2LjYyODMgOC43Njg3MkMxNi4xOTc0IDguMjM5MjEgMTUuNjkwNCA3Ljc2MjI5IDE1LjExNTggNy4zNTg1OUMxNC42OTE1IDcuMDYwMDQgMTQuMjI4NiA2Ljc5OTk4IDEzLjczMjkgNi41ODc4QzEzLjM0NDMgNi40MjA2OSAxMi45NDkgNi4yODkyNSAxMi41NSA2LjE5MDY4QzEyLjA3ODcgNi4wNzQyNiAxMS42MDM3IDYuMDA0NzkgMTEuMTMwNSA1Ljk4MDM4QzExLjAwMTkgNS45NzM4MSAxMC44NzMzIDUuOTcwOTkgMTAuNzQ1NiA1Ljk3MDk5QzEwLjUzNzEgNS45NzA5OSAxMC4zMjg3IDUuOTczODEgMTAuMTIxMiA1Ljk1MTI3QzkuNDk3ODYgNS44ODM2OCA4Ljg3MzUzIDUuNzI0MDggOC4yNjc5OSA1LjQ2NDAyQzcuODg3NzYgNS4zMDA2NiA3LjUzMSA1LjEwNTM4IDcuMTk5NTkgNC44ODE5NEM2Ljk3NDI3IDQuNzMwNzkgNi43NjExNiA0LjU2NjQ5IDYuNTYwMjUgNC4zOTA5M0M2LjQ5NDUzIDQuMzMzNjYgNi40Mjk3NSA0LjI3NTQ1IDYuMzY2ODQgNC4yMTUzN0M2LjI1NjA2IDQuMTExMTYgNi4xNDk5NyA0LjAwMjI1IDYuMDQ2NyAzLjg5MDUzQzUuOTgwMDUgMy44MDYwNCA1LjkxNTI3IDMuNzE5NjYgNS44NTMzIDMuNjMxNDFDNS43NTAwMyAzLjQ4Nzc3IDUuNjU0MjcgMy4zMzg1IDUuNTY0MTQgMy4xODY0MUM1LjMyNzU1IDIuNzg4MzQgNS4xMzQxNSAyLjM2NTg2IDQuOTg4NjQgMS45MjY0OUM0Ljc4MjA5IDEuMzA4NzQgNC42Njg0OSAwLjY1OTA2MiA0LjY1NzIzIDBDNS4zNTc2IDAuODg3MTk5IDYuMjg0MjMgMS42MTc2MSA3LjM5Njc1IDIuMDk0NTRDNy42Mjg2NCAyLjE5NDA2IDcuODYyNDEgMi4yNzk0OSA4LjA5ODA2IDIuMzUyNzJDOC4xODYzMSAyLjM3OTk1IDguMjc0NTYgMi40MDYyMyA4LjM2Mzc1IDIuNDI5N0M4LjU0OTY0IDIuNDc5NDYgOC43MzY0NiAyLjUyMDc3IDguOTIzMjkgMi41NTQ1N0w4Ljk0Njc2IDIuNTU4MzJMOC45NTgwMyAyLjU2MDJDOS4wMjE4NyAyLjU3MTQ3IDkuMDg1NzEgMi41ODE4IDkuMTUwNDkgMi41OTExOEg5LjE1NTE5TDEwLjEyMzEgMi43NDcwM0wxMS42OTg1IDIuOTk3N0MxMS42OTg1IDIuOTk3NyAxMS42OTk0IDIuOTk3NyAxMS43MDEzIDIuOTk3N0gxMS43MDMyTDExLjcwNzkgMi45OTg2NEwxMS44Mzc0IDMuMDE5MjlDMTIuMzUzOCAzLjEwNDczIDEyLjg2NzMgMy4yNTQ5NCAxMy4zNjk2IDMuNDY5OTNDMTMuNTk0IDMuNTY2NjMgMTMuODEwOSAzLjY3MzY2IDE0LjAxOTMgMy43OTEwMkMxNC4zNDY5IDQuMDA0MTMgMTQuNjUwMiA0LjI0NTQxIDE0LjkyNzEgNC41MDgyOEMxNC45ODM1IDQuNTYwODYgMTUuMDM4OSA0LjYxNTMxIDE1LjA5MjQgNC42Njk3NkMxNS4xNDUgNC43MzI2NyAxNS4xOTU2IDQuNzk3NDUgMTUuMjQ1NCA0Ljg2MzE2QzE1LjI1MiA0Ljg3MDY4IDE1LjI1NzYgNC44NzgxOSAxNS4yNjMyIDQuODg1N0MxNi4xMDczIDYuMDAwMDkgMTYuNTg3OSA3LjM2MDQ2IDE2LjYyMzYgOC43NjY4NEwxNi42MjgzIDguNzY4NzJaIiBmaWxsPSIjRkZCRTAwIi8+Cjwvc3ZnPgo=';
            add_menu_page(
                'پیامک SMS.ir',
                'پیامک SMS.ir',
                'edit_pages',
                'sms_ir_login',
                [$this, 'dashboard_index'],
                $pluginIcon,
                25
            );
        });
        add_action('init', [$this, 'smsـir_register_custom_ajax_routes']);
    }

 

    public function register_widgets($widgets_manager) {
        require_once SMSIR_DIR . 'includes/widgets/newsletter.php';
        $widget = new \SMSIR\Widgets\SMSIR_Newsletter_Sign_Widget();
        $widgets_manager->register($widget);
    }

    public function sms_ir_save_phone_ajax() {

        $phone = sanitize_text_field($_POST['phone']);
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            wp_send_json_error(__('Please enter a valid Iranian mobile number.', 'sms-ir'));
        }

        $phone_numbers = get_option('smsir_collected_phones', array());
        $phone_numbers = maybe_unserialize($phone_numbers);
        if (!in_array($phone, $phone_numbers)) {
            $phone_numbers[] = $phone;
            update_option('smsir_collected_phones', $phone_numbers);
        }

        wp_send_json_success();
    }

    public function sms_ir_localize_handler()
    {
        load_plugin_textdomain(
            'sms-ir',
            false,
            dirname(plugin_basename(SMSIR_FILE)) . '/languages'
        );
    }

    public function dashboard_index()
    {
        require_once SMSIR_DIR . 'templates/dashboard.php';
    }
    public function smsـir_register_custom_ajax_routes()
    {
        add_action('wp_ajax_sms_ir_get_settings', [$this, 'sms_ir_get_settings_ajax']);
        add_action('wp_ajax_sms_ir_save_settings', [$this, 'sms_ir_save_settings']);

        add_action('wp_ajax_sms_ir_get_templates', [$this, 'sms_ir_get_templates_ajax']);
        add_action('wp_ajax_sms_ir_add_template', [$this, 'sms_ir_add_template_ajax']);
        add_action('wp_ajax_sms_ir_update_template', [$this, 'sms_ir_update_template_ajax']);
        add_action('wp_ajax_sms_ir_delete_template', [$this, 'sms_ir_delete_template_ajax']);

        add_action('wp_ajax_sms_ir_get_account_stats', [$this, 'sms_ir_get_account_stats_ajax']);
    }

    public static function sms_ir_get_settings()
    {
        $settings = get_option('smsir_settings');

        if ($settings) {
            return json_encode($settings);
        } else {
            return json_encode([]);
        }
    }

    public static function sms_ir_get_settings_ajax()
    {
        echo self::sms_ir_get_settings();
        wp_die();
    }
    public function sms_ir_save_settings()
    {
        $settings = isset($_POST) ? $_POST : [];

        $allowed_indexes   = ['settings'];
        $filtered_settings = array_intersect_key($settings, array_flip($allowed_indexes));

        array_walk_recursive($filtered_settings, function (&$value) {
            if ($value === "true") {
                $value = true;
            } elseif ($value === "false") {
                $value = false;
            }
        });

        update_option('smsir_settings', $filtered_settings);

        wp_send_json_success(['success' => true, 'message' => 'تنظیمات با موفقیت ذخیره شد !']);
    }

    public static function sms_ir_get_settings_global()
    {
        if (is_null(self::$_settings_cache)) {
            $settings = self::sms_ir_get_settings();
            $decoded  = json_decode($settings, true);

            if (is_object($decoded)) {
                $decoded = json_decode(json_encode($decoded), true);
            }

            self::$_settings_cache = $decoded ?? [];
        }

        return self::$_settings_cache;
    }

    public function add_endpoints()
    {
        add_rewrite_endpoint('edit-phone', EP_ROOT | EP_PAGES);
    }

    public function add_phone_menu_item($items)
    {
        $logout = $items['customer-logout'] ?? false;
        unset($items['customer-logout']);

        $items['edit-phone'] = __('Change Phone Number', 'sms-ir');

        if ($logout) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    public function phone_endpoint_content()
    {
        echo do_shortcode('[smsir_change_phone]');
    }

    public function add_phone_to_edit_account()
    {
        $user_id = get_current_user_id();
        $phone   = get_user_meta($user_id, 'phone', true);

        echo '<div class="smsir form-row form-row-wide">';
        echo '<label>' . __('Phone Number', 'sms-ir') . '</label>';
        echo '<div id="smsir-edit-phone-field"></div>';
        echo '</div>';

        echo '<input type="hidden" name="verified_phone" id="verified-phone" value="' . esc_attr($phone) . '" />';
    }

    public function save_phone_account_details($user_id)
    {
        if (isset($_POST['verified_phone']) && ! empty($_POST['verified_phone'])) {
            $phone       = sanitize_text_field($_POST['verified_phone']);
            $clean_phone = preg_replace('/^\+?(?:98)?0*/', '', $phone);

            update_user_meta($user_id, 'phone', '0' . $clean_phone);
            update_user_meta($user_id, 'smsir_phone', '0' . $clean_phone);
            update_user_meta($user_id, 'billing_phone', '98' . $clean_phone);
        }
    }

    public function add_phone_required_field($required_fields)
    {
        return $required_fields;
    }

    public function add_account_styles()
    {
        if (is_account_page()) {
            wp_add_inline_style('woocommerce-inline', '
                .woocommerce-EditAccountForm input#account_phone {
                    background-color: #f2f2f2;
                    cursor: not-allowed;
                }
                .woocommerce-EditAccountForm .phone-change-note {
                    font-size: 0.85em;
                    color: #666;
                    margin-top: 0.5em;
                }
            ');
        }
    }

    public function add_account_scripts()
    {
        // Check if this is a WooCommerce checkout block page (new block-based checkout)
        $is_checkout_block = is_checkout() && has_block('woocommerce/checkout');
        
        // Only load on account pages, avoid loading on checkout to prevent conflicts
        if (is_account_page()) {
            wp_enqueue_style('sms-ir', SMSIR_ASSETS . '/app/app.css', [], SMSIR_VERSION);
            wp_enqueue_script('sms-ir', SMSIR_ASSETS . '/app/app.js', ['jquery'], SMSIR_VERSION, true);

            $user_id = get_current_user_id();
            $phone   = get_user_meta($user_id, 'smsir_phone', true);

            // Make sure we explicitly use the admin-ajax.php URL
            $ajax_url = admin_url('admin-ajax.php');

            require_once(dirname(__FILE__) . '/translations.php');
            
            // Make sure translations are available for both Vue's i18n and direct t() function usage
            wp_localize_script('sms-ir', 'smsir', [
                'ajaxUrl'      => $ajax_url,
                'nonce'        => wp_create_nonce('sms_ir_login'),
                'currentPhone' => $phone,
                'translations' => Translations::get_translations(),
            ]);
            
            // Add additional object for login translations to ensure compatibility
            wp_localize_script('sms-ir', 'sms_ir_login', [
                'lang' => 'fa',
                'ajaxUrl' => $ajax_url,
                'nonce' => wp_create_nonce('sms_ir_login'),
                'translations' => Translations::get_translations(),
                'siteUrl' => smsir_get_domain_only(),
                'siteUrlFull' => site_url(),
            ]);
        }
        // Handle checkout page separately with minimal scripts to avoid conflicts
        else if (is_checkout() && !$is_checkout_block) {
            // Only add minimal jQuery scripts for checkout functionality without Vue app
            wp_add_inline_script('jquery', '
                jQuery(function($) {
                    $(".showlogin").on("click", function(e) {
                        e.preventDefault();
                        $(".woocommerce-form-login").slideToggle();
                    });
                });
            ');
        }
    }

    private function is_woocommerce_active()
    {
        // Check if WooCommerce class exists
        if (!class_exists('WooCommerce')) {
            return false;
        }

        // Check if WooCommerce is active
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        return is_plugin_active('woocommerce/woocommerce.php');
    }
    

    public function remove_wc_login_actions()
    {
        remove_action('woocommerce_login_form', 'woocommerce_login_form');
        remove_action('woocommerce_register_form', 'woocommerce_register_form');
    }

    public function override_wc_template($template, $template_name, $template_path)
    {
        $settings = get_option('smsir_settings', []);
        $change_admin = !empty($settings['settings']['change_admin']);
        
        if ($template_name === 'checkout/form-login.php') {
            $template = SMSIR_DIR . 'templates/woocommerce/checkout/form-login.php';
        }
        if ($template_name === 'checkout/form-billing.php') {
            $template = SMSIR_DIR . 'templates/woocommerce/checkout/form-billing.php';
        }
        if ($template_name === 'myaccount/form-login.php' && $change_admin) {
            $template = SMSIR_DIR . 'templates/woocommerce/myaccount/form-login.php';
        }

        return $template;
    }

    public function sms_ir_get_templates_ajax()
    {

        $page_number = max(1, (int)($_POST['pageNumber'] ?? 1));
        $page_size = min(100, max(1, (int)($_POST['pageSize'] ?? 40)));

        $settings = self::sms_ir_get_settings_global();
        $api_key  = $settings['settings']['api_key'] ?? null;

        if (!$api_key) {
            wp_send_json_error(__('SMS.ir API key is not configured.', 'sms-ir'), 400);
        }

        require_once SMSIR_DIR . 'includes/class-template-manager.php';
        $template_manager = new SMSIR_Template_Manager($api_key);
        try {
            $templates = $template_manager->get_templates($page_number, $page_size);
            if (is_wp_error($templates)) {
                wp_send_json_error($templates->get_error_message(), 400);
            }
            wp_send_json_success($templates);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage(), 500);
        }

        wp_die();
    }

    private function validate_template_text($template_text) {
        if (!preg_match('/#[a-zA-Z0-9_]+#/', $template_text)) {
            return new \WP_Error(
                'invalid_template',
                'templates.variable_required',
                array(
                    'message' => __('Template text must contain at least one variable in format #variable#', 'sms-ir')
                )
            );
        }
        return true;
    }

    public function sms_ir_add_template_ajax()
    {

        check_ajax_referer('sms_ir_admin', 'nonce');

        $title         = sanitize_text_field($_POST['title'] ?? '');
        $template_text = sanitize_textarea_field($_POST['template_text'] ?? '');
        if (empty($title) || empty($template_text)) {
            wp_send_json_error(__('Title and template text are required.', 'sms-ir'), 400);
        }

        $validation_result = $this->validate_template_text($template_text);
        if (is_wp_error($validation_result)) {
            wp_send_json_error($validation_result->get_error_message(), 400);
        }

        $settings = self::sms_ir_get_settings_global();
        $api_key  = $settings['settings']['api_key'] ?? null;

        if (! $api_key) {
            wp_send_json_error(__('SMS.ir API key is not configured.', 'sms-ir'), 400);
        }

        require_once SMSIR_DIR . 'includes/class-template-manager.php';
        $template_manager = new SMSIR_Template_Manager($api_key);

        try {
            $result = $template_manager->add_template($title, $template_text);
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message(), 400);
            }
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage(), 500);
        }

        wp_die();
    }

    public function sms_ir_update_template_ajax()
    {
        check_ajax_referer('sms_ir_admin', 'nonce');

        $template_id   = intval($_POST['id'] ?? 0);
        $title         = sanitize_text_field($_POST['title'] ?? '');
        $template_text = sanitize_textarea_field($_POST['template_text'] ?? '');

        if (!$template_id || empty($title) || empty($template_text)) {
            wp_send_json_error(__('Template ID, title and template text are required.', 'sms-ir'), 400);
        }

        // Validate template text
        $validation_result = $this->validate_template_text($template_text);
        if (is_wp_error($validation_result)) {
            wp_send_json_error($validation_result->get_error_message(), 400);
        }

        $settings = self::sms_ir_get_settings_global();
        $api_key  = $settings['settings']['api_key'] ?? null;

        if (!$api_key) {
            wp_send_json_error(__('SMS.ir API key is not configured.', 'sms-ir'), 400);
        }

        require_once SMSIR_DIR . 'includes/class-template-manager.php';
        $template_manager = new SMSIR_Template_Manager($api_key);

        try {
            $result = $template_manager->update_template($template_id, $title, $template_text);
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message(), 400);
            }
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage(), 500);
        }

        wp_die();
    }

    public function sms_ir_delete_template_ajax()
    {
    
        $template_id = intval($_POST['id'] ?? 0);
        $settings = self::sms_ir_get_settings_global();
        $api_key  = $settings['settings']['api_key'] ?? null;

        if (! $api_key) {
            wp_send_json_error(__('SMS.ir API key is not configured.', 'sms-ir'), 400);
        }

        require_once SMSIR_DIR . 'includes/class-template-manager.php';
        $template_manager = new SMSIR_Template_Manager($api_key);

        $result = $template_manager->delete_template($template_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message(), $result->get_error_code());
        }

        wp_send_json_success(['deleted' => $result]);
        wp_die();
    }

    public function sms_ir_get_account_stats_ajax()
    {

        $settings = self::sms_ir_get_settings_global();
        $api_key  = $settings['settings']['api_key'] ?? null;

        if (! $api_key) {
            wp_send_json_error(__('SMS.ir API key is not configured.', 'sms-ir'), 400);
        }

        $type = sanitize_text_field($_POST['type'] ?? 'credit');

        require_once SMSIR_DIR . 'includes/class-account-stats.php';

        $account_stats = new Account_Stats($api_key);

        $stats = $account_stats->get_account_stats($type);

        if (is_wp_error($stats)) {
            wp_send_json_error($stats->get_error_message(), $stats->get_error_code());
        }

        wp_send_json_success($stats);
        wp_die();
    }

    public function sms_ir_get_messages_ajax() {
        $type = sanitize_text_field($_GET['type'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $page_size = min(100, max(1, (int)($_GET['pageSize'] ?? 20)));

        if (!in_array($type, ['send/live', 'receive/live'])) {
            wp_send_json_error(__('Invalid message type', 'sms-ir'), 400);
        }

        $settings = self::sms_ir_get_settings_global();
        $api_key = $settings['settings']['api_key'] ?? null;

        if (!$api_key) {
            wp_send_json_error(__('SMS.ir API key is not configured.', 'sms-ir'), 400);
        }

        require_once SMSIR_DIR . 'includes/class-message-reports.php';
        $reports = new Message_Reports($api_key);

        try {
            if ($type === 'receive/live') {
                $messages = $reports->get_received_messages($page, $page_size);
            } else {
                $messages = $reports->get_sent_messages($page, $page_size);
            }

            if (is_wp_error($messages)) {
                wp_send_json_error($messages->get_error_message(), 400);
            }

            wp_send_json_success($messages);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage(), 500);
        }

        wp_die();
    }

    public function sms_ir_get_newsletter_phones_ajax() {

        try {
            $phones = get_option('smsir_collected_phones', []);
            if (!is_array($phones)) {
                $phones = unserialize($phones);
            }
            
            wp_send_json_success($phones);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function sms_ir_delete_newsletter_phone_ajax() {

        if (!isset($_POST['phone'])) {
            wp_send_json_error('Phone number is required');
        }

        try {
            $phone = sanitize_text_field($_POST['phone']);
            $phones = get_option('smsir_collected_phones', []);
            
            if (!is_array($phones)) {
                $phones = unserialize($phones);
            }

            $phones = array_filter($phones, function($p) use ($phone) {
                return $p !== $phone;
            });

            update_option('smsir_collected_phones', serialize($phones));
            
            wp_send_json_success('Phone number deleted successfully');
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
