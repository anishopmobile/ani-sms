<?php
namespace SMSIR;

/**
 * Class NotifyMe_Controller
 * Handles the Notify Me functionality for out-of-stock products
 */
class NotifyMe_Controller {
    private static $instance = null;
    private $sms_client;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->sms_client = new SMSClient();
        
        // Initialize hooks
        add_action('wp_ajax_smsir_notifyme_subscribe', [$this, 'ajax_subscribe']);
        add_action('wp_ajax_nopriv_smsir_notifyme_subscribe', [$this, 'ajax_subscribe']);
        add_action('wp_ajax_smsir_notifyme_direct_unsubscribe', [$this, 'ajax_direct_unsubscribe']);
        add_action('wp_ajax_nopriv_smsir_notifyme_direct_unsubscribe', [$this, 'ajax_direct_unsubscribe']);
        add_action('wp_ajax_smsir_check_specific_variation_subscription', [$this, 'ajax_check_specific_variation_subscription']);
        add_action('wp_ajax_nopriv_smsir_check_specific_variation_subscription', [$this, 'ajax_check_specific_variation_subscription']);
        
        // Admin AJAX hooks
        add_action('wp_ajax_smsir_get_subscribers', [$this, 'ajax_get_subscribers']);
        
        // Hook into WooCommerce to display the Notify Me button
        add_action('woocommerce_before_single_variation', [$this, 'display_notify_button'], 10);
        add_action('woocommerce_single_product_summary', [$this, 'display_notify_button_for_simple'], 30);
        
        // Hook into WooCommerce stock status changes
        add_action('woocommerce_product_set_stock_status', [$this, 'handle_stock_status_change'], 10, 3);
        add_action('woocommerce_variation_set_stock_status', [$this, 'handle_variation_stock_status_change'], 10, 3);
        
        // Add scripts and styles for the frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Register the shortcode
        add_shortcode('smsir_notify_me_button', [$this, 'handle_notify_me_shortcode']);
    }

    public function enqueue_scripts() {
        if (!is_product()) {
            return;
        }
        

        
        wp_enqueue_script('smsir-notifyme', SMSIR_ASSETS . '/js/notifyme.js', ['jquery'], SMSIR_VERSION, true); // Removed 'sweetalert2' dependency
        wp_localize_script('smsir-notifyme', 'smsir_notifyme', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('smsir_notifyme'),
            'success_message' => $this->get_success_message(),
            'loading_text' => __('please wait...', 'sms-ir'),
            'popup_title' => __('Inventory notification', 'sms-ir'),
            'popup_text' => __('Enter your mobile number so we can notify you as soon as the product is available.', 'sms-ir'),
            'phone_placeholder' => __('Enter your mobile number.', 'sms-ir'),
            'subscribe_text' => __('Registration', 'sms-ir'),
            'unsubscribe_button_text' => __('Unsubscribe', 'sms-ir'),
            'button_text' => __('Let me know when its available.', 'sms-ir'),
            'unsubscribe_confirm_title' => __('Cancel subscription', 'sms-ir'),
            'unsubscribe_confirm_text' => __('Are you sure you want to cancel this product subscription?', 'sms-ir'),
            'unsubscribe_confirm_button' => __('Yes, cancel!', 'sms-ir'),
            'cancel_button' => __('Leave', 'sms-ir')
        ]);
        
        wp_enqueue_style('smsir-notifyme', SMSIR_ASSETS . '/css/notifyme.css', [], SMSIR_VERSION);
    }

    public function display_notify_button() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $settings = get_option('smsir_settings', []);
        if (empty($settings['settings']['notifyme']['is_active'])) {
            return;
        }
        
        // This hook is for variable products only
        if ($product->is_type('variable')) {
            echo '<div id="smsir-notifyme-variation-container" class="smsir-notifyme-container" style="display: none;">';
            $this->render_notify_button($product->get_id(), true);
            echo '</div>';
        }
    }

    public function display_notify_button_for_simple() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $settings = get_option('smsir_settings', []);
        if (empty($settings['settings']['notifyme']['is_active'])) {
            return;
        }
        
        // This hook is for simple products only
        if ($product->is_type('simple')) {
            if (!$product->is_in_stock()) {
                $this->render_notify_button($product->get_id());
            }
        }
    }
    
    public function render_notify_button($product_id = '', $is_variation = false) {
        $settings = get_option('smsir_settings', []);
        
        $button_text_setting = !empty($settings['settings']['notifyme']['button_text']) ? 
            $settings['settings']['notifyme']['button_text'] : __('Let me know when its available.', 'sms-ir');
        
        $unsubscribe_button_text_setting = !empty($settings['settings']['notifyme']['unsubscribe_button_text']) ? 
            $settings['settings']['notifyme']['unsubscribe_button_text'] : __('Cancel subscription', 'sms-ir');

        $button_text = $button_text_setting;
        $class = 'smsir-notifyme-button';
        
        $user_id = get_current_user_id(); 

  
        if (!$is_variation && $user_id > 0 && $product_id) { 
            global $wpdb;
            $table_name = $wpdb->prefix . 'smsir_stock_notifications';
            $sql = "SELECT id FROM {$table_name} WHERE user_id = %d AND product_id = %d AND variation_id = 0 AND status = 'pending' LIMIT 1";
            $query = $wpdb->prepare($sql, $user_id, $product_id);
            
            if (!empty($query)) {
                $existing_subscription = $wpdb->get_var($query);
                if ($existing_subscription) {
                    $button_text = $unsubscribe_button_text_setting;
                    $class .= ' smsir-notifyme-unsubscribe';
                }
            }
        } 

        
        ?>
        <div class="smsir-notifyme-wrapper">
            <button type="button" class="<?php echo esc_attr($class); ?>" 
                data-product-id="<?php echo esc_attr($product_id); ?>" 
                <?php echo $is_variation ? 'data-is-variation="1"' : ''; ?>>
                <?php echo esc_html($button_text); ?>
            </button>
        </div>
        <?php
    }

    public function handle_notify_me_shortcode($atts = []) {
        global $product;

        if (!is_object($product) || !is_a($product, 'WC_Product')) {
            $current_post_id = get_the_ID();
            if ($current_post_id) {
                $_product_candidate = wc_get_product($current_post_id);
                if ($_product_candidate && is_a($_product_candidate, 'WC_Product')) {
                    $product = $_product_candidate; // Assign to global for consistency or use local
                } else {
                    return '<!-- SMSIR Notify Me: Product not found -->'; 
                }
            } else {
                 return '<!-- SMSIR Notify Me: Product context not found -->'; 
            }
        }

        $this->enqueue_scripts();

        ob_start();

        if ($product->is_type('simple')) {
            if (!$product->is_in_stock()) {
                $this->render_notify_button($product->get_id(), false);
            }
        } else if ($product->is_type('variable')) {
            echo '<div id="smsir-notifyme-variation-container" class="smsir-notifyme-container" style="display: none;">';
            $this->render_notify_button($product->get_id(), true); 
            echo '</div>';
        }

        return ob_get_clean();
    }

    private function normalize_phone_number($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '98' . substr($phone, 1);
        } else if (substr($phone, 0, 1) === '9' && strlen($phone) === 10) {
            $phone = '98' . $phone;
        }
        return $phone;
    }

    public function ajax_subscribe() {
        check_ajax_referer('smsir_notifyme', 'nonce');
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $user_id = get_current_user_id(); // Returns 0 if not logged in

        if (empty($phone)) {
            wp_send_json_error([
                'message' => __('Phone number is required', 'sms-ir')
            ]);
            return;
        }
        
        $normalized_phone = $this->normalize_phone_number($phone);
        
        $product = null;
        if ($variation_id > 0) {
            $product = wc_get_product($variation_id);
        } else {
            $product = wc_get_product($product_id);
        }
        
        if (!$product) {
            wp_send_json_error(['message' => __('Invalid product', 'sms-ir')]);
            return;
        }
        
        if ($product->is_in_stock()) {
            wp_send_json_error(['message' => __('This product is already in stock', 'sms-ir')]);
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'smsir_stock_notifications';
        
        $existing_query = "";
        $existing_args = [];

        if ($user_id > 0) {
            $existing_query = "SELECT id FROM {$table_name} WHERE user_id = %d AND product_id = %d AND variation_id = %d AND status = 'pending'";
            $existing_args = [$user_id, $product_id, $variation_id];
        } else {
            $existing_query = "SELECT id FROM {$table_name} WHERE phone = %s AND product_id = %d AND variation_id = %d AND status = 'pending'";
            $existing_args = [$normalized_phone, $product_id, $variation_id];
        }
        
        $existing = $wpdb->get_var($wpdb->prepare($existing_query, $existing_args));
        
        if ($existing) {
            wp_send_json_success(['message' => $this->get_success_message() ]);
            return;
        }
        
        $insert_data = [
            'phone' => $normalized_phone,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ];
        $insert_formats = ['%s', '%d', '%d', '%s', '%s'];

        if ($user_id > 0) {
            $insert_data['user_id'] = $user_id;
            $insert_formats[] = '%d';
        }

        $result = $wpdb->insert($table_name, $insert_data, $insert_formats);
        
        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to save notification request', 'sms-ir')]);
            return;
        }
        
        wp_send_json_success(['message' => $this->get_success_message()]);
    }

    public function ajax_direct_unsubscribe() {
        check_ajax_referer('smsir_notifyme', 'nonce');

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $user_id = get_current_user_id();

        if (empty($product_id)) {
            wp_send_json_error(['message' => __('Invalid product for unsubscription.', 'sms-ir')]);
            return;
        }

        if ($user_id == 0) {
      
            wp_send_json_error(['message' => __('Direct unsubscription is available for logged-in users only.', 'sms-ir')]);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'smsir_stock_notifications';

        $where = [
            'user_id' => $user_id,
            'product_id' => $product_id,
            'status' => 'pending'
        ];
        $where_format = ['%d', '%d', '%s'];

    
        if ($variation_id > 0) {
            $where['variation_id'] = $variation_id;
            $where_format[] = '%d';
        } else {
            $where['variation_id'] = 0; 
            $where_format[] = '%d';
        }

        $deleted_rows = $wpdb->delete($table_name, $where, $where_format);

        if ($deleted_rows === false) {
            wp_send_json_error(['message' => __('Failed to process unsubscription request.', 'sms-ir')]);
        } else if ($deleted_rows > 0) {
            wp_send_json_success(['message' => __('You have been unsubscribed successfully.', 'sms-ir')]);
        } else {
            wp_send_json_success(['message' => __('No active subscription found for your account to remove.', 'sms-ir')]);
        }
    }

    public function ajax_check_specific_variation_subscription() {
        check_ajax_referer('smsir_notifyme', 'nonce'); 

        $user_id = get_current_user_id();
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;

        if (empty($product_id) || empty($variation_id)) {
            wp_send_json_error(['subscribed' => false, 'message' => __('Missing product or variation ID.', 'sms-ir')]);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'smsir_stock_notifications';
        $is_subscribed = false;

        if ($user_id > 0) {
            $sql = $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE user_id = %d AND product_id = %d AND variation_id = %d AND status = 'pending' LIMIT 1",
                $user_id, $product_id, $variation_id
            );
            if ($wpdb->get_var($sql)) {
                $is_subscribed = true;
            }
        } else {
 
            $is_subscribed = false; 
        }

        wp_send_json_success(['subscribed' => $is_subscribed]);
    }

    public function handle_stock_status_change($product_id, $new_stock_status, $product) {
        if ($new_stock_status !== 'instock') {
            return;
        }

        $this->send_notifications($product_id, 0);
    }

    public function handle_variation_stock_status_change($variation_id, $new_stock_status, $product) {
        if ($new_stock_status !== 'instock') {
            return;
        }

        $variation = wc_get_product($variation_id);
        $this->send_notifications($variation->get_parent_id(), $variation_id);
  
    }

    private function send_notifications($product_id, $variation_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'smsir_stock_notifications';
        
        $query = "";
        $query_args = [];
        
        if ($variation_id > 0) {
            $query = "SELECT * FROM {$table_name} WHERE product_id = %d AND variation_id = %d AND status = 'pending'";
            $query_args = [$product_id, $variation_id];
        } else {
            $query = "SELECT * FROM {$table_name} WHERE product_id = %d AND variation_id = 0 AND status = 'pending'";
            $query_args = [$product_id];
        }

        $notifications = $wpdb->get_results($wpdb->prepare($query, $query_args));
        
        if (empty($notifications)) {
            return;
        }
        
        $settings = get_option('smsir_settings', []);
       
        if (empty($settings['settings']['notifyme']['is_active']) || 
            empty($settings['settings']['notifyme']['notification_text']) ||
            empty($settings['settings']['notifyme']['template_id'])) {
            return;
        }
        
        $template_id = $settings['settings']['notifyme']['template_id'];
        $message_template = $settings['settings']['notifyme']['notification_text'];
        
        $product = null;
        $variation = null;
   
        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                return;
            }
            $product = wc_get_product($product_id);
        } else {
            $product = wc_get_product($product_id);
        }

        if (!$product) {
            return;
        }
        
        $variation_attributes = '';
        if ($variation) {
            $attributes = $variation->get_variation_attributes();
            $variation_details = [];
            
            foreach ($attributes as $attribute => $value) {
                $taxonomy = str_replace('attribute_', '', $attribute);
                $term = get_term_by('slug', $value, $taxonomy);
                $label = wc_attribute_label($taxonomy);
                
                $label = str_replace('pa_', '', $label);
                
                if ($term && !is_wp_error($term)) {
                    $variation_details[] = $label . ': ' . urldecode($term->name);
                } else {
                    $readable_value = str_replace('-', ' ', urldecode($value));
                    $variation_details[] = $label . ': ' . $readable_value;
                }
            }
            
            $variation_attributes = implode(', ', $variation_details);
            $variation_attributes = urldecode($variation_attributes);
        }
        foreach ($notifications as $notification) {
            // 1. Detect all {foo} placeholders from template
            preg_match_all('/\{([^}]+)\}/', $message_template, $braces_placeholders);

            // 2. Also handle legacy #foo#
            preg_match_all('/#([^#]+)#/', $message_template, $hash_placeholders);

            $params = [];

            // Extract key/values for known placeholders
            $base_vars = [
                'productTitle' => $product->get_title(),
                'productUrl'   => get_permalink($product_id),
                'Price'        => wc_price($variation ? $variation->get_price() : $product->get_price()),
                'Variation'    => $variation_attributes,
            ];

            // Fill {foo} style
            if (!empty($braces_placeholders[1])) {
                foreach ($braces_placeholders[1] as $key) {
                    if (isset($base_vars[$key])) {
                        $params[$key] = $base_vars[$key];
                    } else {
                        $params[$key] = '';
                    }
                }
            }
            // Fill #foo# style
            if (!empty($hash_placeholders[1])) {
                foreach ($hash_placeholders[1] as $key) {
                    if (isset($base_vars[$key])) {
                        $params[$key] = $base_vars[$key];
                    } else {
                        $params[$key] = '';
                    }
                }
            }
            // Backward compat: If no placeholders found, fallback to full message as before
            if (empty($params)) {
                $filled_msg = $message_template;
                foreach ($base_vars as $k => $v) {
                    $filled_msg = str_replace(['{' . $k . '}', '#' . $k . '#'], $v, $filled_msg);
                }
                $params = ['Variation' => $filled_msg];
            }
            $this->sms_client->send_verification_code($notification->phone, $params, $template_id);

            $wpdb->update(
                $table_name,
                ['status' => 'sent', 'sent_at' => current_time('mysql')],
                ['id' => $notification->id],
                ['%s', '%s'],
                ['%d']
            );
        }
    }

    private function get_success_message() {            
        return __('Your information has been successfully registered. We will notify you as soon as the product is available.', 'sms-ir');;
    }

    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'smsir_stock_notifications';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            phone varchar(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            variation_id bigint(20) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_product_variation (user_id, product_id, variation_id, status),
            KEY phone_product_variation (phone, product_id, variation_id, status),
            KEY product_variation (product_id, variation_id),
            KEY phone (phone),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function ajax_get_subscribers() {
        check_ajax_referer('sms_ir_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to access this data', 'sms-ir')
            ]);
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'smsir_stock_notifications';
        
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        $offset = ($page - 1) * $per_page;
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $status_filter = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        
        $where_clauses = [];
        $query_args = [];
        
        if (!empty($search)) {
            $where_clauses[] = "phone LIKE %s";
            $query_args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        if (!empty($status_filter)) {
            $where_clauses[] = "status = %s";
            $query_args[] = $status_filter;
        }
        
        $where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);
        
        $count_query = "SELECT COUNT(*) FROM {$table_name} {$where_sql}";
        $total_items = $wpdb->get_var(empty($query_args) ? $count_query : $wpdb->prepare($count_query, $query_args));
        
        $query = "SELECT n.*, 
                 p.post_title as product_name,
                 parent.post_title as parent_product_name
                 FROM {$table_name} n
                 LEFT JOIN {$wpdb->posts} p ON n.variation_id > 0 AND p.ID = n.variation_id
                 LEFT JOIN {$wpdb->posts} parent ON (n.variation_id = 0 AND parent.ID = n.product_id) OR (n.variation_id > 0 AND parent.ID = n.product_id)
                 {$where_sql}
                 ORDER BY n.created_at DESC
                 LIMIT %d OFFSET %d";
        
        $prepared_args = array_merge($query_args, [$per_page, $offset]);
        $subscribers = $wpdb->get_results($wpdb->prepare($query, $prepared_args));
        
        $formatted_subscribers = [];
        foreach ($subscribers as $subscriber) {
            $product_title = $subscriber->parent_product_name;
            
            if (!empty($subscriber->product_name) && $subscriber->variation_id > 0) {
                $variation = wc_get_product($subscriber->variation_id);
                if ($variation) {
                    $attributes = $variation->get_variation_attributes();
                    $variation_details = [];
                    
                    foreach ($attributes as $attribute => $value) {
                        $taxonomy = str_replace('attribute_', '', $attribute);
                        $term = get_term_by('slug', $value, $taxonomy);
                        $label = urldecode(wc_attribute_label($taxonomy));
                        
                        $label = str_replace('pa_', '', $label);
                        
                        if ($term && !is_wp_error($term)) {
                            $variation_details[] = $label . ': ' . urldecode($term->name);
                        } else {
                            $readable_value = str_replace('-', ' ', urldecode($value));
                            $variation_details[] = $label . ': ' . $readable_value;
                        }
                    }
                    
                    if (!empty($variation_details)) {
                        $product_title .= ' - ' . implode(', ', $variation_details);
                    }
                }
            }
            
            $formatted_subscribers[] = [
                'id' => $subscriber->id,
                'phone' => $subscriber->phone,
                'product_id' => $subscriber->product_id,
                'variation_id' => $subscriber->variation_id,
                'product_title' => $product_title, 
                'status' => $subscriber->status,
                'created_at' => $subscriber->created_at,
                'sent_at' => $subscriber->sent_at
            ];
        }
        
        wp_send_json_success([
            'subscribers' => $formatted_subscribers,
            'total' => intval($total_items),
            'pages' => ceil($total_items / $per_page),
            'current_page' => $page
        ]);
    }
}

NotifyMe_Controller::get_instance(); 