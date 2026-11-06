<?php
namespace SMSIR;

class WCIntegration {
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
        
        if (class_exists('WooCommerce')) {
            add_action('wp_ajax_sms_ir_get_wc_notification_rows', [$this, 'ajax_get_wc_notification_rows']);
            add_action('wp_ajax_sms_ir_save_wc_notification_rows', [$this, 'ajax_save_wc_notification_rows']);
            add_action('wp_ajax_sms_ir_get_wc_order_statuses', [$this, 'ajax_get_wc_order_statuses']);
            add_action('wp_ajax_sms_ir_send_tracking_sms', [$this, 'ajax_send_tracking_sms']);
            
            add_action('woocommerce_order_status_changed', [$this, 'handle_order_status_change'], 10, 4);
            add_action('smsir_wc_scheduled_notification', [$this, 'run_scheduled_notification'], 10, 3);
            
            // Add hooks for new order creation
            add_action('woocommerce_new_order', [$this, 'handle_new_order'], 10, 1);
            add_action('woocommerce_checkout_order_processed', [$this, 'handle_checkout_order_processed'], 10, 1);
            
            // Add metabox to shop_order edit screen
            add_action('add_meta_boxes', [$this, 'add_tracking_metabox']);
            add_action('save_post_shop_order', [$this, 'save_tracking_code'], 10, 2);
            $settings = get_option('smsir_settings', []);
            $change_checkout = !empty($settings['settings']['change_checkout']);
            if(!is_admin() && $change_checkout){
                add_filter('woocommerce_checkout_fields', [$this, 'modify_checkout_fields']);
                add_filter('woocommerce_billing_fields', [$this, 'modify_billing_fields']);
                add_action('woocommerce_checkout_process', [$this, 'validate_checkout_fields']);
                if(!get_current_user_id()){
                    add_action('woocommerce_checkout_process', [$this, 'validate_phone_number']);
        
                }
            }
            
            // Cleanup expired processing flags
            add_action('init', [$this, 'cleanup_expired_processing_flags']);
        }

      
        //hotfix
        //add_action('woocommerce_checkout_update_order_meta', [$this, 'save_phone_number']);
    }

    public function modify_checkout_fields($fields) {
        // Check if cart contains only virtual products
        $is_virtual = true;
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = wc_get_product($cart_item['product_id']);
            if (!$product->is_virtual()) {
                $is_virtual = false;
                break;
            }
        }

        if ($is_virtual) {
            // Make all billing fields optional except phone
            foreach ($fields['billing'] as $key => $field) {
                if ($key !== 'billing_phone') {
                    $fields['billing'][$key]['required'] = false;
                }
            }
        }

        return $fields;
    }

    public function modify_billing_fields($fields) {
        // Check if cart contains only virtual products
        $is_virtual = true;
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = wc_get_product($cart_item['product_id']);
            if (!$product->is_virtual()) {
                $is_virtual = false;
                break;
            }
        }

        if ($is_virtual) {
            // Make all billing fields optional except phone
            foreach ($fields as $key => $field) {
                if ($key !== 'billing_phone') {
                    $fields[$key]['required'] = false;
                }
            }
        }

        return $fields;
    }

    public function validate_phone_number() {
        $phone = isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '';
        
        if (empty($phone)) {
            wc_add_notice(__('Phone number is required', 'sms-ir'), 'error');
            return;
        }

        // Format phone number
        $phone = preg_replace('/^\+/', '', $phone);
        $phone = preg_replace('/^98\+/', '98', $phone);
        
        if (!preg_match('/^98[0-9]{10}$/', $phone)) {
            wc_add_notice(__('Invalid phone number format', 'sms-ir'), 'error');
        }
    }

    public function save_phone_number($order_id) {
        if ($order_id && isset($_POST['billing_phone'])) {
            $phone = sanitize_text_field($_POST['billing_phone']);
            
            // Format phone number
            $phone = preg_replace('/^\+/', '', $phone);
            $phone = preg_replace('/^98\+/', '98', $phone);
            
            update_post_meta($order_id, '_billing_phone', $phone);
            
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                update_user_meta($user_id, 'phone', '0' . $phone);
                update_user_meta($user_id, 'smsir_phone', '0' . $phone);
                update_user_meta($user_id, 'billing_phone',  '98' . $phone);
            }
        }
    }

    public function validate_checkout_fields() {
  
        $is_virtual = true;
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = wc_get_product($cart_item['product_id']);
            if (!$product->is_virtual()) {
                $is_virtual = false;
                break;
            }
        }

        if ($is_virtual) {
            remove_action('woocommerce_checkout_process', 'wc_checkout_fields_validation');
            add_action('woocommerce_checkout_process', [$this, 'validate_phone_number']);
        }
    
    }

    public function ajax_get_wc_notification_rows() {
        // Get the notification rows from the database using serialized data
        $notification_rows = get_option('sms_ir_wc_notification_rows', array());
        
        // If it's not an array (maybe it was previously stored as JSON), initialize it
        if (!is_array($notification_rows)) {
            $notification_rows = array();
        }
        
        wp_send_json_success($notification_rows);
    }
    

    public function ajax_save_wc_notification_rows() {
        if (!isset($_POST['rows'])) {
            wp_send_json_error(__('Notification rows data is required', 'sms-ir'));
            return;
        }
        
        $rows_json = wp_unslash($_POST['rows']);
        $rows = json_decode($rows_json, true);
        
        if (!is_array($rows)) {
            wp_send_json_error(__('Invalid data format', 'sms-ir'));
            return;
        }
        
        foreach ($rows as &$row) {
            if (isset($row['order_status'])) {
                $row['order_status'] = sanitize_text_field($row['order_status']);
            }
            
            if (isset($row['schedule_type'])) {
                $row['schedule_type'] = ($row['schedule_type'] === 'delayed') ? 'delayed' : 'immediate';
            } else {
                $row['schedule_type'] = 'immediate';
            }
            if (isset($row['schedule_delay_days'])) {
                $row['schedule_delay_days'] = max(0, absint($row['schedule_delay_days']));
            } else {
                $row['schedule_delay_days'] = 0;
            }
            
            if (isset($row['recipient_type'])) {
                if (is_array($row['recipient_type'])) {
                    $sanitized_types = array();
                    foreach ($row['recipient_type'] as $type) {
                        $sanitized_types[] = sanitize_text_field($type);
                    }
                    $row['recipient_type'] = $sanitized_types;
                } else {
                    $row['recipient_type'] = sanitize_text_field($row['recipient_type']);
                }
            }
            
            if (isset($row['config']) && is_array($row['config'])) {
                $new_config = array();
                
                // Handle admin configuration
                if (in_array('admin', $row['recipient_type'])) {
                    $new_config['admin_mobile_numbers'] = sanitize_text_field($row['config']['admin_mobile_numbers'] ?? '');
                    $new_config['admin_template_id'] = sanitize_text_field($row['config']['admin_template_id'] ?? '');
                    $new_config['admin_message'] = wp_kses_post($row['config']['admin_message'] ?? '');
                    
                    // Extract admin parameters
                    if (!empty($new_config['admin_message'])) {
                        $matches = array();
                        preg_match_all('/#([^#]+)#/', $new_config['admin_message'], $matches);
                        if (!empty($matches[1])) {
                            $params = array();
                            foreach ($matches[1] as $param) {
                                $params[$param] = $param;
                            }
                            $new_config['admin_params'] = $params;
                        }
                    }
                }
                
                // Handle customer configuration
                if (in_array('customer', $row['recipient_type'])) {
                    $new_config['customer_template_id'] = sanitize_text_field($row['config']['customer_template_id'] ?? '');
                    $new_config['customer_message'] = wp_kses_post($row['config']['customer_message'] ?? '');
                    
                    // Extract customer parameters
                    if (!empty($new_config['customer_message'])) {
                        $matches = array();
                        preg_match_all('/#([^#]+)#/', $new_config['customer_message'], $matches);
                        if (!empty($matches[1])) {
                            $params = array();
                            foreach ($matches[1] as $param) {
                                $params[$param] = $param;
                            }
                            $new_config['customer_params'] = $params;
                        }
                    }
                }
                
                $row['config'] = $new_config;
            }
        }
        
 
        
        update_option('sms_ir_wc_notification_rows', $rows);
        
        wp_send_json_success(__('Settings saved successfully', 'sms-ir'));
    }
    

    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        // Check if we've already processed this order for this status (for immediate only)
        $processed_key = "smsir_processed_{$order_id}_{$new_status}";
        $processed_data = get_option($processed_key, false);
        
        // Check if already processed and not expired
        if ($processed_data && isset($processed_data['expires']) && time() < $processed_data['expires']) {
            return; // Already processed, skip
        }
        
        // Set flag to prevent duplicate processing (expires in 1 hour)
        delete_option($processed_key);
        add_option($processed_key, ['expires' => time() + HOUR_IN_SECONDS], '', 'no');
        
        
        $notification_rows = get_option('sms_ir_wc_notification_rows', array());
     
        if (empty($notification_rows) || !is_array($notification_rows)) {
            return;
        }

        foreach ($notification_rows as $row_index => $row) {
            if ($row['order_status'] !== $new_status || empty($row['config'])) {
                continue;
            }
            $schedule_type = isset($row['schedule_type']) ? $row['schedule_type'] : 'immediate';
            $schedule_delay_days = isset($row['schedule_delay_days']) ? (int) $row['schedule_delay_days'] : 0;

            if ($schedule_type === 'delayed' && $schedule_delay_days > 0) {
                $this->schedule_delayed_notification($order_id, $row_index, $new_status);
            } else {
                $this->process_notification_row($row, $order);
            }
        }
    }

    /**
     * Schedule a delayed notification for an order (e.g. X days after status change).
     *
     * @param int    $order_id   Order ID.
     * @param int    $row_index  Index of the notification row in options.
     * @param string $status     Order status that triggered the schedule.
     */
    private function schedule_delayed_notification($order_id, $row_index, $status) {
        $notification_rows = get_option('sms_ir_wc_notification_rows', array());
        if (empty($notification_rows) || !isset($notification_rows[ $row_index ])) {
            return;
        }
        $row = $notification_rows[ $row_index ];
        $schedule_delay_days = isset($row['schedule_delay_days']) ? (int) $row['schedule_delay_days'] : 0;
        if ($schedule_delay_days <= 0) {
            return;
        }
        $run_at = time() + ( $schedule_delay_days * DAY_IN_SECONDS );
        $args = array( $order_id, $row_index, $status );
        $existing = wp_next_scheduled('smsir_wc_scheduled_notification', $args);
        if ($existing) {
            wp_unschedule_event($existing, 'smsir_wc_scheduled_notification', $args);
        }
        wp_schedule_single_event($run_at, 'smsir_wc_scheduled_notification', $args);
    }

    /**
     * Run a scheduled notification (called by cron when delay time is reached).
     *
     * @param int    $order_id   Order ID.
     * @param int    $row_index  Index of the notification row.
     * @param string $status     Status the notification was scheduled for.
     */
    public function run_scheduled_notification($order_id, $row_index, $status) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== $status) {
            return;
        }
        $notification_rows = get_option('sms_ir_wc_notification_rows', array());
        if (empty($notification_rows) || !isset($notification_rows[ $row_index ])) {
            return;
        }
        $row = $notification_rows[ $row_index ];
        if ($row['order_status'] !== $status || empty($row['config'])) {
            return;
        }
        $sent_meta_key = '_smsir_scheduled_sent_' . $row_index . '_' . $status;
        if ($order->get_meta($sent_meta_key)) {
            return;
        }
        $this->process_notification_row($row, $order);
        $order->update_meta_data($sent_meta_key, time());
        $order->save();
    }

    /**
     * Handle new order creation
     * This hook is triggered when a new order is created
     */
    public function handle_new_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order_status = $order->get_status();
        
        // Check if we've already processed this order for this status
        $processed_key = "smsir_processed_{$order_id}_{$order_status}";
        $processed_data = get_option($processed_key, false);
        
        // Check if already processed and not expired
        if ($processed_data && isset($processed_data['expires']) && time() < $processed_data['expires']) {
            return; // Already processed, skip
        }
        
        // Set flag to prevent duplicate processing (expires in 1 hour)
        delete_option($processed_key);
        add_option($processed_key, ['expires' => time() + HOUR_IN_SECONDS], '', 'no');
        

        // Process notifications for the new order status
        $this->process_order_notifications($order, $order_status);
    }

    /**
     * Handle checkout order processed
     * This hook is triggered after checkout is processed
     */
    public function handle_checkout_order_processed($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order_status = $order->get_status();
        
        // Check if we've already processed this order for this status
        $processed_key = "smsir_processed_{$order_id}_{$order_status}";
        $processed_data = get_option($processed_key, false);
        
        // Check if already processed and not expired
        if ($processed_data && isset($processed_data['expires']) && time() < $processed_data['expires']) {
            return; // Already processed, skip
        }
        
        // Set flag to prevent duplicate processing (expires in 1 hour)
        delete_option($processed_key);
        add_option($processed_key, ['expires' => time() + HOUR_IN_SECONDS], '', 'no');
        

        // Process notifications for the order status
        $this->process_order_notifications($order, $order_status);
    }

    /**
     * Process order notifications for a specific status
     */
    private function process_order_notifications($order, $order_status) {
        $notification_rows = get_option('sms_ir_wc_notification_rows', array());
     
        if (empty($notification_rows) || !is_array($notification_rows)) {
            return;
        }

        $order_id = $order->get_id();
        foreach ($notification_rows as $row_index => $row) {
            if ($row['order_status'] !== $order_status || empty($row['config'])) {
                continue;
            }
            $schedule_type = isset($row['schedule_type']) ? $row['schedule_type'] : 'immediate';
            $schedule_delay_days = isset($row['schedule_delay_days']) ? (int) $row['schedule_delay_days'] : 0;

            if ($schedule_type === 'delayed' && $schedule_delay_days > 0) {
                $this->schedule_delayed_notification($order_id, $row_index, $order_status);
            } else {
                $this->process_notification_row($row, $order);
            }
        }
    }

    /**
     * Clear processing flags for an order (useful for testing or manual resets)
     */
    public function clear_order_processing_flags($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $order_status = $order->get_status();
        $processed_key = "smsir_processed_{$order_id}_{$order_status}";
        
        return delete_option($processed_key);
    }
    

    private function process_notification_row($row, $order) {
        if (empty($row['recipient_type']) || empty($row['config'])) {
            return;
        }
        
        $config = $row['config'];
        $recipient_type = $row['recipient_type'];
  
        $is_admin = is_array($recipient_type) 
            ? in_array('admin', $recipient_type) 
            : $recipient_type === 'admin' || $recipient_type === 'both';
            
        $is_customer = is_array($recipient_type) 
            ? in_array('customer', $recipient_type) 
            : $recipient_type === 'customer' || $recipient_type === 'both';
    
         
        if ($is_admin && !empty($config['admin_mobile_numbers'])) {
            $admin_message = $config['admin_message'] ?? '';
            $admin_template_id = $config['admin_template_id'] ?? '';
            
            if (!empty($admin_template_id)) {
                $admin_params = isset($config['admin_params']) ? $config['admin_params'] : [];
                
                if (!empty($admin_params)) {
                    foreach ($admin_params as $key => $value) {
                        $admin_params[$key] = $this->get_order_value($key, $order);
                    }
                }
                
                $admin_numbers = explode(',', $config['admin_mobile_numbers']);
                foreach ($admin_numbers as $number) {
                    $number = trim($number);
                    if (!empty($number)) {
                        $this->sms_client->send_verification_code($number, $admin_params, $admin_template_id);
                    }
                }
            }
        }
        
        // Send to customer
        if ($is_customer) {
            $customer_message = $config['customer_message'] ?? '';
            $customer_template_id = $config['customer_template_id'] ?? '';
            
            if (!empty($customer_template_id)) {
                $customer_params = isset($config['customer_params']) ? $config['customer_params'] : [];
                
                if (!empty($customer_params)) {
                    foreach ($customer_params as $key => $value) {
                        $customer_params[$key] = $this->get_order_value($key, $order);
                    }
                }
                
                $phone = $this->get_customer_phone($order);
                if (!empty($phone)) {
                    $this->sms_client->send_verification_code($phone, $customer_params, $customer_template_id);
                }
            }
        }
    }
    
    // Extract parameters from message and get their values
    private function extract_params_from_message($message, $order) {
        $params = [];
        $matches = [];
        
        preg_match_all('/#([^#]+)#/', $message, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $key) {
                $params[$key] = $this->get_order_value($key, $order);
            }
        }
        
        return $params;
    }
    
    // Get value for a specific order parameter
    private function get_order_value($key, $order) {
        switch ($key) {
            case 'OrderId':
                return $order->get_id();
                
            case 'OrderTotal':
                $total = $order->get_total();
                $currency = $order->get_currency();
                $decimals = wc_get_price_decimals();
                $decimal_separator = wc_get_price_decimal_separator();
                $thousand_separator = wc_get_price_thousand_separator();
                
                return number_format($total, $decimals, $decimal_separator, $thousand_separator);
                
            case 'CustomerName':
                return $order->get_formatted_billing_full_name();
                
            case 'CustomerFirstName':
                return $order->get_billing_first_name();
                
            case 'CustomerLastName':
                return $order->get_billing_last_name();
                
            case 'OrderDate':
                return $order->get_date_created()->date_i18n(get_option('date_format'));
                
            case 'PaymentMethod':
                return $order->get_payment_method_title();
                
            case 'ShippingMethod':
                return $order->get_shipping_method();
                
            case 'OrderItems':
                $items = $order->get_items();
                $formatted_items = array();
                
                foreach ($items as $item) {
                    $item_name = $item->get_name();
                    $quantity = $item->get_quantity();
                    
                    $variation_data = '';
                    $item_meta_data = $item->get_formatted_meta_data();
                    
                    if (!empty($item_meta_data)) {
                        $meta_details = array();
                        foreach ($item_meta_data as $meta) {
                            if (isset($meta->hidden) && $meta->hidden) {
                                continue;
                            }
                            
                            $meta_details[] = $meta->display_key . ': ' . strip_tags($meta->display_value);
                        }
                        
                        if (!empty($meta_details)) {
                            $variation_data = ' (' . implode(', ', $meta_details) . ')';
                        }
                    }
                    
                    $formatted_items[] = $quantity . ' عدد ' . $item_name . $variation_data;
                }
                
                return implode(', ', $formatted_items);
                
            default:
                // Try to get custom field from order meta
                $meta_value = $order->get_meta($key);
                if (!empty($meta_value)) {
                    return $meta_value;
                }
                
                return '#' . $key . '#'; // Return original placeholder if no match
        }
    }

    private function get_customer_phone($order) {
        $customer_id = $order->get_customer_id();
        
        if ($customer_id) {
            $meta_keys = ['billing_phone', 'smsir_phone', 'phone'];
            
            foreach ($meta_keys as $meta_key) {
                $phone = get_user_meta($customer_id, $meta_key, true);
                if (!empty($phone)) {
                    $phone = $this->format_phone_number($phone);
                    return $phone;
                }
            }
        }
        
        $phone = $order->get_billing_phone();
        
        return $this->format_phone_number($phone);
    }
    

    private function format_phone_number($phone) {
        if (empty($phone)) {
            return '';
        }
        
        // Remove any leading + sign
        $phone = preg_replace('/^\+/', '', $phone);
        
        
        return $phone;
    }

    public function ajax_get_wc_order_statuses() {
  

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'sms-ir'));
            return;
        }

        $wc_statuses = wc_get_order_statuses();
        
        $formatted_statuses = array();
        foreach ($wc_statuses as $status => $label) {
            $status = str_replace('wc-', '', $status);
            
            $formatted_statuses[] = array(
                'value' => $status,
                'label' => $label
            );
        }

        wp_send_json_success($formatted_statuses);
    }

    /**
     * Add tracking code metabox to WooCommerce orders
     */
    public function add_tracking_metabox() {
        // Determine the correct screen for the metabox based on WooCommerce version and settings
        $screen = 'shop_order';
        
        // Check if the WooCommerce custom orders table is available and enabled
        if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            // Using a try/catch to prevent errors if the container or class is not available
            try {
                $container = wc_get_container();
                if ($container) {
                    $custom_orders_controller = $container->get('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController');
                    if ($custom_orders_controller && method_exists($custom_orders_controller, 'custom_orders_table_usage_is_enabled') && 
                        $custom_orders_controller->custom_orders_table_usage_is_enabled()) {
                        $screen = wc_get_page_screen_id('shop-order');
                    }
                }
            } catch (\Exception $e) {
                // Fallback to traditional orders if there's any error
                $screen = 'shop_order';
            }
        }
        
        // Add metabox to the determined screen
        add_meta_box(
            'smsir_tracking_code',
            __('SMS.IR Tracking Code', 'sms-ir'),
            [$this, 'render_tracking_metabox'],
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render the tracking code metabox content
     * 
     * @param mixed $post The post object or order
     */
    public function render_tracking_metabox($post) {
        // Get proper order object using WooCommerce's API
        $order = null;
        
        // Check if we're receiving a post object or an order object
        if (is_a($post, 'WC_Order')) {
            $order = $post;
        } else {
            // Handle the case when we receive a post object
            $order = wc_get_order($post->ID);
        }
        
        if (!$order) {
            echo '<p>' . __('Order not found', 'sms-ir') . '</p>';
            return;
        }
        
        $order_id = $order->get_id();
        $tracking_code = $order->get_meta('_smsir_tracking_code');
        $sent_tracking_sms = $order->get_meta('_smsir_tracking_sms_sent');
        
        // Get tracking code template ID from settings
        $template_id = get_option('smsir_tracking_template_id', '');
        
        wp_nonce_field('smsir_tracking_nonce', 'smsir_tracking_nonce');
        
        ?>
        <style>
            .smsir-tracking-metabox label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            .smsir-tracking-metabox .widefat {
                margin-bottom: 10px;
            }
            .smsir-sent-info {
                margin-top: 8px;
                font-style: italic;
                color: green;
            }
        </style>
        
        <div class="smsir-tracking-metabox">
            <p>
                <label for="smsir_tracking_code_<?php echo esc_attr($order_id); ?>"><?php _e('Tracking Code', 'sms-ir'); ?></label>
                <input type="text" id="smsir_tracking_code_<?php echo esc_attr($order_id); ?>" name="smsir_tracking_code" value="<?php echo esc_attr($tracking_code); ?>" class="widefat" />
            </p>
            
            <?php if (empty($template_id)): ?>
            <div class="notice notice-warning inline">
                <p><?php _e('Template ID is not set. Please set it in SMS.IR settings.', 'sms-ir'); ?></p>
            </div>
            <?php endif; ?>
            
            <div id="smsir-tracking-response" style="margin: 10px 0;"></div>
            
            <div class="smsir-actions">
                <button type="button" id="smsir_send_tracking_sms" class="button button-primary" data-order-id="<?php echo esc_attr($order_id); ?>"><?php _e('Send Tracking SMS', 'sms-ir'); ?></button>
            </div>
            
            <?php if ($sent_tracking_sms) : ?>
                <div class="smsir-sent-info">
                    <small><?php echo __('SMS sent on', 'sms-ir') . ' ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $sent_tracking_sms); ?></small>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 10px;">
                <small><?php _e('Available parameter: <code>SMSIRTrackingCode</code>', 'sms-ir'); ?></small>
            </div>
        </div>
        
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var templateId = '<?php echo esc_js($template_id); ?>';
                
                $('#smsir_send_tracking_sms').on('click', function() {
                    var orderId = $(this).data('order-id');
                    var trackingCode = $(this).closest('.smsir-tracking-metabox').find('input[name="smsir_tracking_code"]').val().trim();
                    var responseArea = $('#smsir-tracking-response');

                    if (!trackingCode) {
                        responseArea.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Please enter a tracking code', 'sms-ir')); ?></p></div>');
                        return;
                    }
                    
                    if (!templateId) {
                        responseArea.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Please enter a template ID in settings', 'sms-ir')); ?></p></div>');
                        return;
                    }
                    
                    $(this).prop('disabled', true).text('<?php echo esc_js(__('Sending...', 'sms-ir')); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'sms_ir_send_tracking_sms',
                            order_id: orderId,
                            tracking_code: trackingCode,
                            template_id: templateId,
                            security: '<?php echo wp_create_nonce('smsir_tracking_sms_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                responseArea.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                                // Refresh the page to show the updated SMS sent date
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                responseArea.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                            }
                        },
                        error: function() {
                            responseArea.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('An error occurred while sending the SMS', 'sms-ir')); ?></p></div>');
                        },
                        complete: function() {
                            $('#smsir_send_tracking_sms').prop('disabled', false).text('<?php echo esc_js(__('Send Tracking SMS', 'sms-ir')); ?>');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Save tracking code when order is saved
     * 
     * @param int $post_id The post ID
     * @param WP_Post $post The post object
     */
    public function save_tracking_code($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
    
        if (!isset($_POST['smsir_tracking_nonce']) || !wp_verify_nonce($_POST['smsir_tracking_nonce'], 'smsir_tracking_nonce')) {
            return;
        }
    
        if (!current_user_can('edit_shop_order', $post_id)) {
            return;
        }
    
        if (isset($_POST['smsir_tracking_code'])) {
            $tracking_code = sanitize_text_field($_POST['smsir_tracking_code']);
    
            remove_action('save_post_shop_order', [$this, 'save_tracking_code'], 10);
    
            $order = wc_get_order($post_id);
            $order->update_meta_data('_smsir_tracking_code', $tracking_code);
            $order->save();
    
            add_action('save_post_shop_order', [$this, 'save_tracking_code'], 10, 2);
        }
    }
    

    /**
     * Handle AJAX request to send tracking SMS
     */
    public function ajax_send_tracking_sms() {
        check_ajax_referer('smsir_tracking_sms_nonce', 'security');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(__('Permission denied', 'sms-ir'));
            return;
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $tracking_code = isset($_POST['tracking_code']) ? sanitize_text_field($_POST['tracking_code']) : '';
        $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : '';
        
        if (!$order_id || !$tracking_code || !$template_id) {
            wp_send_json_error(__('Missing required fields', 'sms-ir'));
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Order not found', 'sms-ir'));
            return;
        }
        
        // Save the tracking code to the order meta
        $order->update_meta_data('_smsir_tracking_code', $tracking_code);
        $order->save();
        // Get customer phone number
        $phone = $this->get_customer_phone($order);
        if (empty($phone)) {
            wp_send_json_error(__('Customer phone number not found', 'sms-ir'));
            return;
        }
        
        // Prepare parameters for SMS
        $params = [
            'SMSIRTrackingCode' => $tracking_code,
        ];
        
        // Send SMS
        $response = $this->sms_client->send_verification_code($phone, $params, $template_id);
        
        if (is_array($response) && isset($response['success']) && $response['success']) {
            // Update order meta to track that SMS was sent
            update_post_meta($order_id, '_smsir_tracking_sms_sent', time());
            wp_send_json_success(__('Tracking SMS sent successfully', 'sms-ir'));
        } else {
            $error_message = is_array($response) && isset($response['message']) ? $response['message'] : __('Unknown error', 'sms-ir');
            wp_send_json_error(__('Failed to send tracking SMS', 'sms-ir') . ': ' . $error_message);
        }
    }
    
    /**
     * Cleanup expired processing flags from database
     * Runs once per hour to prevent database bloat
     */
    public function cleanup_expired_processing_flags() {
        // Only run cleanup once per hour
        $last_cleanup = get_option('smsir_last_wc_processing_cleanup', 0);
        if (time() - $last_cleanup < 3600) {
            return;
        }
        
        global $wpdb;
        $current_time = time();
        
        // Get all options that start with smsir_processed_
        $processing_options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
            WHERE option_name LIKE 'smsir_processed_%'"
        );
        
        foreach ($processing_options as $option) {
            $data = maybe_unserialize($option->option_value);
            
            // Check if it's an expired flag
            if (is_array($data) && isset($data['expires']) && $current_time > $data['expires']) {
                delete_option($option->option_name);
            }
        }
        
        // Update last cleanup timestamp
        update_option('smsir_last_wc_processing_cleanup', $current_time, 'no');
    }
}