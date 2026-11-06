<?php
namespace SMSIR;

class SMSIR_Leave_Cart {
    private $client;
    private $settings;

    public function __construct() {
        $this->client =  new SMSClient();;
        $this->settings = get_option('sms_ir_leave_cart_settings', array(
            'leave_cart_sms' => false,
            'time_after' => 30,
            'template_id' => '',
            'discount_code' => '',
            'message_template' => ''
        ));

        // Add AJAX handlers
        add_action('wp_ajax_sms_ir_get_leave_cart_users', array($this, 'get_leave_cart_users'));
        add_action('wp_ajax_sms_ir_get_leave_cart_settings', array($this, 'get_leave_cart_settings'));
        add_action('wp_ajax_sms_ir_save_leave_cart_settings', array($this, 'save_leave_cart_settings'));
        add_action('wp_ajax_sms_ir_send_leave_cart_sms', array($this, 'send_leave_cart_sms'));

        // Add WooCommerce hooks
        add_action('woocommerce_add_to_cart', array($this, 'track_cart_activity'), 10, 6);
        add_action('woocommerce_cart_item_removed', array($this, 'track_cart_removal'), 10, 2);
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        
        // Schedule cron job for sending SMS
        add_action('sms_ir_send_abandoned_cart_sms', array($this, 'send_abandoned_cart_sms'));
        
        // Make sure the cron event is scheduled
        if (!wp_next_scheduled('sms_ir_send_abandoned_cart_sms')) {
            // Clear any existing schedules first to avoid duplicates
            wp_clear_scheduled_hook('sms_ir_send_abandoned_cart_sms');
            // Schedule a new event
            wp_schedule_event(time() + 60, 'hourly', 'sms_ir_send_abandoned_cart_sms');
        }
    }

    public function get_leave_cart_users() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sms_ir_leave_cart';

        // Get pagination parameters
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? max(1, intval($_POST['per_page'])) : 20;
        $offset = ($page - 1) * $per_page;

        // Get filter parameters
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        // Build the query - فقط سبدهای رها شده نمایش داده شود
        $query = "SELECT * FROM $table_name WHERE (status = 'abandon' OR status = 'abandoned')";
        $count_query = "SELECT COUNT(*) FROM $table_name WHERE (status = 'abandon' OR status = 'abandoned')";
        $where_clauses = [];
        $params = [];

        // Add SMS status filter
        if ($status !== 'all') {
            $where_clauses[] = "sms_status = %s";
            $params[] = $status;
        }

        // Add search filter
        if (!empty($search)) {
            $where_clauses[] = "(phone LIKE %s OR order_id LIKE %s)";
            $search_param = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_param;
            $params[] = $search_param;
        }

        // Add where clauses to the queries
        if (!empty($where_clauses)) {
            $query .= " AND " . implode(" AND ", $where_clauses);
            $count_query .= " AND " . implode(" AND ", $where_clauses);
        }

        // Add order by
        $query .= " ORDER BY last_activity DESC";

        // Get total count
        $count_sql = count($params) > 0 ? $wpdb->prepare($count_query, $params) : $count_query;
        $total_items = $wpdb->get_var($count_sql);
        $total_pages = ceil($total_items / $per_page);

        // Add pagination
        $query .= " LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        // Get users
        $users = $wpdb->get_results($wpdb->prepare($query, $params));
        
        // Add display name for each user
        foreach ($users as $user) {
            if ($user->user_id) {
                $wp_user = get_userdata($user->user_id);
                $user->name = !empty($wp_user->display_name) ? $wp_user->display_name : __('User', 'sms-ir');
            } else {
                $user->name = __('Guest', 'sms-ir');
            }
        }

        wp_send_json_success(array(
            'items' => $users,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'per_page' => $per_page
        ));
    }

    public function get_leave_cart_settings() {

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }

        wp_send_json_success($this->settings);
    }

    public function save_leave_cart_settings() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }

        $settings = json_decode(stripslashes($_POST['settings']), true);
        
        // Validate time settings
        if (isset($settings['time_after'])) {
            $settings['time_after'] = max(1, intval($settings['time_after']));
        }

        update_option('sms_ir_leave_cart_settings', $settings);
        $this->settings = $settings;

        wp_send_json_success('Settings saved successfully');
    }

    public function track_cart_activity() {
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return;
        }

        $user_id = get_current_user_id();
        $phone = '';
        $name = '';

        // Get user info
        if ($user_id) {
            $user = get_userdata($user_id);
            $phone = get_user_meta($user_id, 'billing_phone', true);
            if (strpos($phone, '989') === 0) {
                $phone = '0' . substr($phone, 2);
            } elseif (strpos($phone, '98') === 0) {
                $phone = substr($phone, 2);
            }
            $name = $user->display_name;
        } else {
            // For guest users, try to get phone from session or previous orders
            $session = WC()->session;
            if ($session) {
                $phone = $session->get('billing_phone');
                if (strpos($phone, '989') === 0) {
                    $phone = '0' . substr($phone, 2);
                } elseif (strpos($phone, '98') === 0) {
                    $phone = substr($phone, 2);
                }
                $name = $session->get('billing_first_name') . ' ' . $session->get('billing_last_name');
            }
        }

        if (!$phone) {
            return;
        }

        // Calculate cart total from cart contents
        $cart_total = 0;
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $price = $product->get_price();
            $quantity = $cart_item['quantity'];
            $cart_total += $price * $quantity;
        }

        $cart_items = array();
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $cart_items[] = $product->get_name() . ' (' . $cart_item['quantity'] . ')';
        }
        $cart_items_text = implode(', ', $cart_items);

        global $wpdb;
        $table_name = $wpdb->prefix . 'sms_ir_leave_cart';

        $existing_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND status = 'abandon'",
            $user_id
        ));

        if ($existing_cart) {
            $wpdb->update(
                $table_name,
                array(
                    'last_activity' => current_time('mysql'),
                    'cart_total' => $cart_total,
                    'cart_items' => $cart_items_text
                ),
                array('id' => $existing_cart->id)
            );
        } else {
            $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'phone' => $phone,
                    'last_activity' => current_time('mysql'),
                    'status' => 'abandon',
                    'cart_total' => $cart_total,
                    'cart_items' => $cart_items_text
                )
            );
        }
    }

    public function send_abandoned_cart_sms() {
        $settings = get_option('sms_ir_leave_cart_settings', array());

        if (empty($settings['leave_cart_sms']) || !$settings['leave_cart_sms']) {
            return;
        }

        if (empty($settings['template_id']) || empty($settings['message_template']) || empty($settings['time_after'])) {
            return;
        }

        $hours = intval($settings['time_after']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'sms_ir_leave_cart';


        // Use proper placeholders in SQL query
        $query = "SELECT * FROM $table_name 
                 WHERE status = %s 
                 AND sms_status = %s 
                 AND last_activity <= DATE_SUB(NOW(), INTERVAL %d HOUR)";
        
        $abandoned_carts = $wpdb->get_results(
            $wpdb->prepare(
                $query,
                'abandon',
                'pending',
                $hours
            )
        );
        
        
        if (empty($abandoned_carts)) {
            return;
        }
        
        foreach ($abandoned_carts as $cart) {
            $parameters = array();
            preg_match_all('/#([^#]+)#/', $settings['message_template'], $matches);
            
            if (!empty($matches[1])) {
                foreach ($matches[1] as $param) {
                    switch ($param) {
                        case 'UserName':
                            if ($cart->user_id) {
                                $user = get_userdata($cart->user_id);
                                $parameters[$param] = !empty($user->display_name) ? $user->display_name : __('User', 'smsir');
                            } else {
                                $parameters[$param] = __('User', 'smsir');
                            }
                            break;
                        case 'CartItems':
                            $parameters[$param] = $cart->cart_items;
                            break;
                        case 'CartTotal':
                            $formatted_price = $cart->cart_total;
                            $parameters[$param] = number_format($formatted_price, 0, '.', ',');
                            break;
                        case 'DiscountCode':
                            $parameters[$param] = !empty($settings['discount_code']) ? $settings['discount_code'] : '';
                            break;
                    }
                }
            }

            $sms_client = new SMSClient();
            $result = $sms_client->send_verification_code(
                $cart->phone,
                $parameters,
                $settings['template_id']
            );

            // Update cart status
            $wpdb->update(
                $table_name,
                array(
                    'status' => 'abandoned',
                    'sms_status' => $result ? 'sent' : 'failed',
                    'message_sent' => $settings['message_template'],
                    'sms_sent_at' => current_time('mysql')
                ),
                array('id' => $cart->id)
            );
        }
    }

    public function track_cart_removal($cart_item_key, $cart) {
        if (!is_user_logged_in()) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sms_ir_leave_cart';
        $user_id = get_current_user_id();

        if (empty($cart->get_cart_contents_count())) {
            // Delete the cart log when cart becomes empty
            $wpdb->delete(
                $table_name,
                array('user_id' => $user_id, 'status' => 'abandon'),
                array('%d', '%s')
            );
        }
    }

    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sms_ir_leave_cart';
        $user_id = $order->get_user_id();
        $phone = $order->get_billing_phone();

        if ($new_status === 'completed') {
            // Remove the cart log when order is completed
            $wpdb->delete(
                $table_name,
                array('user_id' => $user_id, 'status' => 'abandon'),
                array('%d', '%s')
            );
        } elseif ($new_status === 'failed' || $new_status === 'cancelled') {
            $wpdb->replace(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'order_id' => $order_id,
                    'phone' => $phone,
                    'status' => $new_status,
                    'last_activity' => current_time('mysql'),
                    'sms_status' => 'pending'
                )
            );
        }
    }

    public function create_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sms_ir_leave_cart';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            order_id bigint(20) DEFAULT NULL,
            phone varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            last_activity datetime NOT NULL,
            cart_total decimal(10,2) DEFAULT 0.00,
            cart_items text,
            sms_status varchar(20) DEFAULT 'pending',
            sms_sent_at datetime DEFAULT NULL,
            message_sent text,
            discount_code varchar(50) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY status (status),
            KEY sms_status (sms_status),
            KEY last_activity (last_activity)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function send_leave_cart_sms() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }

        $user_id = intval($_POST['user_id']);
        $message = sanitize_textarea_field($_POST['message']);

        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        if (empty($message)) {
            wp_send_json_error('Message cannot be empty');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sms_ir_leave_cart';
        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $user_id
        ));

        if (!$user) {
            wp_send_json_error('User not found');
        }

        $sms_client = new SMSClient();
        
        // Only use send_bulk_sms for all cases
        $result = $sms_client->send_bulk_sms(
            array($user->phone),
            $message
        );

        if ($result['success']) {
            $wpdb->update(
                $table_name,
                array(
                    'sms_status' => 'sent',
                    'sms_sent_at' => current_time('mysql'),
                    'message_sent' => $message,
                ),
                array('id' => $user_id)
            );

            wp_send_json_success('SMS sent successfully');
        } else {
            wp_send_json_error($result['message'] ?? 'Failed to send SMS');
        }
    }
}