<?php
namespace SMSIR;

if (!defined('ABSPATH')) {
    exit;
}

require_once(dirname(__FILE__) . '/class-sms-client.php');

/**
 * Class for Excel file uploads using Spout library
 */
class SMSIR_Excel_Uploader {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_smsir_process_excel_file', [$this, 'process_excel_file']);
        add_action('wp_ajax_smsir_send_bulk_tracking_codes', [$this, 'send_bulk_tracking_codes']);
        add_action('wp_ajax_smsir_get_upload_history', [$this, 'get_upload_history']);
        add_action('wp_ajax_smsir_get_history_data', [$this, 'get_history_data']);
        add_action('wp_ajax_smsir_save_tracking_template_id', [$this, 'save_tracking_template_id']);
        add_action('wp_ajax_smsir_get_tracking_template_id', [$this, 'get_tracking_template_id']);
        
        // Setup WordPress hooks
        
        // Load Spout library
        $this->load_spout_library();
        
        // Initialize history with demo data if empty
        $this->initialize_history();
    }

    /**
     * Initialize upload history with demo data if it doesn't exist
     */
    private function initialize_history() {
        $history = get_option('smsir_excel_upload_history', []);
        
        // Create demo data if no history exists
        if (empty($history)) {
            $demo_history = $this->get_dummy_history_data();
            update_option('smsir_excel_upload_history', $demo_history);
            
         
        } else {
            // Ensure history size is limited even for existing data
            $this->limit_history_size();
        }
    }

    /**
     * Limit history to 10 latest entries
     */
    private function limit_history_size() {
        $history = get_option('smsir_excel_upload_history', []);
        
        if (count($history) > 10) {
            $this->debug_log('Trimming history size from ' . count($history) . ' to 10 entries');
            $history = array_slice($history, 0, 10);
            update_option('smsir_excel_upload_history', $history);
        }
    }

    /**
     * Load Spout library from plugin's lib directory
     */
    private function load_spout_library() {
        $autoload_path = dirname(dirname(__FILE__)) . '/lib/spout/src/Spout/Autoloader/autoload.php';
        
        if (file_exists($autoload_path)) {
            require_once $autoload_path;
        }
    }

 
    
    /**
     * Upgrade old upload history data to new format
     */
    private function upgrade_upload_history() {
        $history = get_option('smsir_excel_upload_history', []);
        $updated = false;
        
        foreach ($history as &$entry) {
            // Add missing fields with default values
            if (!isset($entry['file_name'])) {
                $entry['file_name'] = isset($entry['file_type']) ? 'File.' . $entry['file_type'] : 'Unknown';
                $updated = true;
            }
            
            if (!isset($entry['file_size'])) {
                $entry['file_size'] = 0;
                $updated = true;
            }
            
            // Ensure id is set
            if (!isset($entry['id'])) {
                $entry['id'] = uniqid();
                $updated = true;
            }
        }
        
        if ($updated) {
            update_option('smsir_excel_upload_history', $history);
        }
    }

    /**
     * Process the uploaded Excel file
     */
    public function process_excel_file() {
        try {
            // Check user permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You do not have permission to upload files.', 'sms-ir')]);
                return;
            }

            // Check if a file was uploaded
            if (empty($_FILES['file'])) {
                wp_send_json_error(['message' => __('No file was uploaded.', 'sms-ir')]);
                return;
            }

            $file = $_FILES['file'];
          
            // Check for upload errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error_message = $this->get_upload_error_message($file['error']);
             
                wp_send_json_error(['message' => $error_message]);
                return;
            }

          

            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $valid_extensions = ['csv', 'xlsx', 'xls', 'txt'];
            
            if (!in_array($file_ext, $valid_extensions)) {
                wp_send_json_error(['message' => __('Invalid file type. Please upload an Excel, CSV, or TXT file.', 'sms-ir')]);
                return;
            }

            // Process the file
            $data = $this->parse_file($file);

            if (empty($data)) {
                wp_send_json_error(['message' => __('No valid data found in the file.', 'sms-ir')]);
                return;
            }

            // Update post meta for each order
            $updated_data = $this->update_order_tracking_codes($data);

            // Return the processed data
            wp_send_json_success($updated_data);
        } catch (\Exception $e) {
    
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Parse the uploaded file using Spout
     */
    private function parse_file($file) {
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $tmp_file = $file['tmp_name'];
        $data = [];

        try {
            // Create reader based on file extension
            $reader = \Box\Spout\Reader\Common\Creator\ReaderEntityFactory::createReaderFromFile($file['name']);
            
            // Open the file
            $reader->open($tmp_file);
            
            // Get the first sheet
            $first_sheet = true;
            $header_row = null;
            $columns = null;
            
            // Process each sheet
            foreach ($reader->getSheetIterator() as $sheet) {
                // Only process the first sheet
                if (!$first_sheet) {
                    break;
                }
                
                // Process each row
                foreach ($sheet->getRowIterator() as $row_index => $row) {
                    $row_array = [];
                    
                    // Convert row to array
                    foreach ($row->getCells() as $cell) {
                        $row_array[] = $cell->getValue();
                    }
                    
                    // Skip empty rows
                    if (empty(array_filter($row_array))) {
                        continue;
                    }
                    
                    // First row is header
                    if ($header_row === null) {
                        $header_row = $row_array;
                        $columns = $this->map_columns($header_row);
                        
                        // Check if required columns are found
                        if ($columns['orderId'] === false) {
                            throw new \Exception(__('Order ID column not found in the file.', 'sms-ir'));
                        }

                        if ($columns['trackingCode'] === false) {
                            throw new \Exception(__('Tracking code column not found in the file.', 'sms-ir'));
                        }
                        
                        continue;
                    }
                    
                    // Process data row
                    $record = $this->process_row($row_array, $columns);
                    if ($record) {
                        $data[] = $record;
                    }
                }
                
                $first_sheet = false;
            }
            
            // Close the reader
            $reader->close();
        } catch (\Box\Spout\Common\Exception\SpoutException $e) {
            throw new \Exception(__('Error reading file: ', 'sms-ir') . $e->getMessage());
        }
        
        return $data;
    }
    
    /**
     * Map the CSV header columns to expected fields
     */
    private function map_columns($header) {
        $columns = [
            'orderId' => false,
            'trackingCode' => false
        ];
        
        foreach ($header as $index => $column_name) {
            if (empty($column_name)) {
                continue;
            }
            
            $column_name = strtolower(trim($column_name));
            
            // Order ID column detection
            if (strpos($column_name, 'order') !== false || 
                strpos($column_name, 'order id') !== false || 
                strpos($column_name, 'orderid') !== false || 
                strpos($column_name, 'سفارش') !== false ||
                strpos($column_name, 'شماره سفارش') !== false ||
                strpos($column_name, 'شماره') !== false) {
                $columns['orderId'] = $index;
            }
            // Tracking code column detection
            elseif (strpos($column_name, 'tracking') !== false || 
                  strpos($column_name, 'track') !== false || 
                  strpos($column_name, 'code') !== false || 
                  strpos($column_name, 'barcode') !== false || 
                  strpos($column_name, 'رهگیری') !== false || 
                  strpos($column_name, 'کد') !== false || 
                  strpos($column_name, 'کد رهگیری') !== false) {
                $columns['trackingCode'] = $index;
            }
        }
        
        // If only one column is found, guess the other one
        if ($columns['orderId'] !== false && $columns['trackingCode'] === false && count($header) > 1) {
            // Assume the next column after order ID is tracking code
            $next_index = $columns['orderId'] + 1;
            if (isset($header[$next_index])) {
                $columns['trackingCode'] = $next_index;
            }
        } elseif ($columns['orderId'] === false && $columns['trackingCode'] !== false && count($header) > 1) {
            // Assume the previous column before tracking code is order ID
            $prev_index = $columns['trackingCode'] - 1;
            if (isset($header[$prev_index]) && $prev_index >= 0) {
                $columns['orderId'] = $prev_index;
            }
        }
        
        // If still no mapping and exactly 2 columns, assume first=orderID, second=trackingCode
        if ($columns['orderId'] === false && $columns['trackingCode'] === false && count($header) === 2) {
            $columns['orderId'] = 0;
            $columns['trackingCode'] = 1;
        }
        
        return $columns;
    }
    
    /**
     * Process a single data row
     */
    private function process_row($row, $columns) {
        // Skip empty rows
        if (empty($row) || count(array_filter($row)) === 0) {
            return false;
        }
        
        $record = [
            'orderId' => isset($columns['orderId']) && $columns['orderId'] !== false && isset($row[$columns['orderId']]) ? $row[$columns['orderId']] : '',
            'trackingCode' => isset($columns['trackingCode']) && $columns['trackingCode'] !== false && isset($row[$columns['trackingCode']]) ? $row[$columns['trackingCode']] : ''
        ];
        
        // Validate order ID
        if (empty($record['orderId'])) {
            return false;
        }
        
        // Clean order ID (ensure it's numeric)
        $record['orderId'] = $this->clean_order_id($record['orderId']);
        
        return $record;
    }
    
    /**
     * Clean and format order ID
     */
    private function clean_order_id($order_id) {
        // Remove any non-digit characters
        return preg_replace('/[^0-9]/', '', $order_id);
    }
    
    /**
     * Update order tracking codes and related meta data
     */
    private function update_order_tracking_codes($data) {
        $updated_data = [];
        
        foreach ($data as $item) {
            $order_id = $item['orderId'];
            $tracking_code = $item['trackingCode'];
            
            // Skip if missing required data
            if (empty($order_id) || empty($tracking_code)) {
                continue;
            }
            
            try {
                // Get order
                $order = wc_get_order($order_id);
                if (!$order) {
                    // Add to updated data with error indication
                    $updated_data[] = [
                        'orderId' => $order_id,
                        'username' => __('Order not found', 'sms-ir'),
                        'trackingCode' => $tracking_code,
                        'phoneNumber' => __('N/A', 'sms-ir'),
                        'status' => 'error'
                    ];
                    continue;
                }
                
                // Update tracking code - use HPOS compatible methods
                $order->update_meta_data('_smsir_tracking_code', $tracking_code);
                $order->update_meta_data('_smsir_tracking_code_updated', current_time('timestamp'));
                $order->save();
                
                // Get user data from order
                $user_id = $order->get_user_id();
                $username = '';
                if ($user_id > 0) {
                    $user = get_userdata($user_id);
                    $username = $user ? $user->display_name : '';
                } 
                
                if (empty($username)) {
                    $username = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                }
                
                // Get phone number from order
                $phone_number = $order->get_billing_phone();
                
                // Format phone number: if starts with 989, replace with 09
                if (substr($phone_number, 0, 3) === '989') {
                    $phone_number = '0' . substr($phone_number, 2);
                }
                
                // Save to order notes
                $order->add_order_note(
                    sprintf(
                        __('Tracking code "%s" was added via bulk upload.', 'sms-ir'),
                        $tracking_code
                    )
                );
                
                // Add to updated data
                $updated_data[] = [
                    'orderId' => $order_id,
                    'username' => $username,
                    'trackingCode' => $tracking_code,
                    'phoneNumber' => $phone_number,
                    'status' => 'success'
                ];
            } catch (\Exception $e) {
                error_log('Error updating tracking code for order ' . $order_id . ': ' . $e->getMessage());
                
                // Add to updated data with error indication
                $updated_data[] = [
                    'orderId' => $order_id,
                    'username' => __('Error updating order', 'sms-ir'),
                    'trackingCode' => $tracking_code,
                    'phoneNumber' => __('N/A', 'sms-ir'),
                    'status' => 'error'
                ];
            }
        }
        
        // Save to upload history
        $this->add_to_upload_history($updated_data);
        
        return $updated_data;
    }
    
    /**
     * Add upload data to history
     */
    private function add_to_upload_history($data) {
        if (empty($data)) {
            return;
        }
        
        $history = get_option('smsir_excel_upload_history', []);
        
        // Count stats
        $success_count = 0;
        $error_count = 0;
        
        foreach ($data as $item) {
            if (isset($item['status']) && $item['status'] === 'error') {
                $error_count++;
            } else {
                $success_count++;
            }
        }
        
        // Get file information
        $file_type = '';
        $file_name = '';
        $file_size = 0;
        
        if (isset($_FILES['file'])) {
            $file_type = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $file_name = sanitize_file_name($_FILES['file']['name']);
            $file_size = $_FILES['file']['size'];
        }
        
        // Create history entry
        $history_entry = [
            'id' => uniqid(),
            'timestamp' => current_time('timestamp'),
            'date' => current_time('mysql'),
            'total_records' => count($data),
            'success_count' => $success_count,
            'error_count' => $error_count,
            'user_id' => get_current_user_id(),
            'file_type' => $file_type,
            'file_name' => $file_name,
            'file_size' => $file_size,
            'sample_data' => array_slice($data, 0, 5) // Store only first 5 records as sample
        ];
        
        // Add to beginning of array
        array_unshift($history, $history_entry);
        
        // Keep only last 10 uploads
        if (count($history) > 10) {
            $history = array_slice($history, 0, 10);
        }
        
        update_option('smsir_excel_upload_history', $history);
    }
    


    /**
     * Get upload history via AJAX
     */
    public function get_upload_history() {
        try {
            // Check user permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You do not have permission to access this data.', 'sms-ir')]);
                return;
            }
            
            $history = get_option('smsir_excel_upload_history', []);
            
            // Ensure history size is limited
            $this->limit_history_size();
            
         
            
            // If still empty (unlikely but possible), create demo data
            if (empty($history)) {
                $history = $this->get_dummy_history_data();
                update_option('smsir_excel_upload_history', $history);
                
             
            }
            
            // Add user info to each entry
            foreach ($history as &$entry) {
                if (isset($entry['user_id'])) {
                    $user = get_userdata($entry['user_id']);
                    $entry['user_name'] = $user ? $user->display_name : __('Unknown', 'sms-ir');
                } else {
                    $entry['user_name'] = __('System', 'sms-ir');
                }
            }
            
            wp_send_json_success($history);
        } catch (\Exception $e) {
     
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Get dummy history data for demonstration
     */
    private function get_dummy_history_data() {
        $current_user_id = get_current_user_id();
        $current_time = current_time('timestamp');
        
        // Get recent WooCommerce orders if available
        $example_orders = [];
        
        try {
            if (function_exists('wc_get_orders')) {
                // Use a compatibility approach for both HPOS and traditional data stores
                $query_args = [
                    'limit' => 3,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'return' => 'ids',  // Just get IDs first to prevent issues
                ];
                
                $order_ids = wc_get_orders($query_args);
                
                if (!empty($order_ids) && is_array($order_ids)) {
                    foreach ($order_ids as $order_id) {
                        $order = wc_get_order($order_id);
                        if ($order) {
                            $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                            $phone_number = $order->get_billing_phone();
                            
                            if (!empty($phone_number)) {
                                $example_orders[] = [
                                    'orderId' => $order_id,
                                    'username' => $customer_name,
                                    'trackingCode' => sprintf('TRK-%06d', rand(10000, 999999)),
                                    'phoneNumber' => $phone_number,
                                    'status' => 'success'
                                ];
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // If there's an error, use fallback sample data
            error_log('Error getting sample order data: ' . $e->getMessage());
        }
        
        // If no orders were found or there was an error, use fallback sample data
        if (empty($example_orders)) {
            $example_orders = [
                [
                    'orderId' => '1000',
                    'username' => 'John Doe',
                    'trackingCode' => sprintf('TRK-%06d', rand(10000, 999999)),
                    'phoneNumber' => '09123456789',
                    'status' => 'success'
                ],
                [
                    'orderId' => '1001',
                    'username' => 'Jane Smith',
                    'trackingCode' => sprintf('TRK-%06d', rand(10000, 999999)),
                    'phoneNumber' => '09198765432',
                    'status' => 'success'
                ]
            ];
        }
        
        // Create sample history entries
        $history = [
            [
                'id' => uniqid(),
                'timestamp' => $current_time - 86400 * 2, // 2 days ago
                'date' => date('Y-m-d H:i:s', $current_time - 86400 * 2),
                'total_records' => count($example_orders),
                'success_count' => count($example_orders),
                'error_count' => 0,
                'user_id' => $current_user_id,
                'file_type' => 'xlsx',
                'file_name' => 'orders_export.xlsx',
                'file_size' => 15240,
                'sample_data' => $example_orders
            ]
        ];
        
        return $history;
    }

    /**
     * Get error message for file upload errors
     */
    private function get_upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return __('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'sms-ir');
            case UPLOAD_ERR_FORM_SIZE:
                return __('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'sms-ir');
            case UPLOAD_ERR_PARTIAL:
                return __('The uploaded file was only partially uploaded.', 'sms-ir');
            case UPLOAD_ERR_NO_FILE:
                return __('No file was uploaded.', 'sms-ir');
            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Missing a temporary folder.', 'sms-ir');
            case UPLOAD_ERR_CANT_WRITE:
                return __('Failed to write file to disk.', 'sms-ir');
            case UPLOAD_ERR_EXTENSION:
                return __('A PHP extension stopped the file upload.', 'sms-ir');
            default:
                return __('Unknown upload error.', 'sms-ir');
        }
    }

    /**
     * Send tracking code SMS using the existing SMS.ir functionality
     */
    private function send_tracking_code_sms($phone_number, $tracking_code, $order) {
        // Format phone number to standard format
        $formatted_phone = preg_replace('/[^0-9]/', '', $phone_number);
        if (empty($formatted_phone)) {
            return false;
        }
        
        // Get customer name from order
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        
        // Get the tracking code message template from settings
        $message_template = get_option('smsir_tracking_code_template', __('Dear customer {customer_name}, your order tracking code: {tracking_code}', 'sms-ir'));
        
        // Replace placeholders
        $message = str_replace(
            ['{customer_name}', '{tracking_code}', '{order_id}'],
            [$customer_name, $tracking_code, $order->get_id()],
            $message_template
        );
        
        // Use SMSClient for sending
        $sms_client = new SMSClient();
        $result = $sms_client->send_bulk_sms(
            array($formatted_phone),
            $message
        );
        
        return isset($result['success']) && $result['success'];
    }

    /**
     * Handle sending SMS messages for the tracking codes
     */
    public function send_bulk_tracking_codes() {
        try {
            // Check user permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You do not have permission to send SMS messages.', 'sms-ir')]);
                return;
            }

            // Check if data was provided
            if (empty($_POST['data'])) {
                wp_send_json_error(['message' => __('No data was provided.', 'sms-ir')]);
                return;
            }

            $data = json_decode(stripslashes($_POST['data']), true);
     
            
            if (!is_array($data)) {
                wp_send_json_error(['message' => __('Invalid data format.', 'sms-ir')]);
                return;
            }

            $success_count = 0;
            $failed_count = 0;

            $sms_client = new SMSClient();
            $template_id = get_option('smsir_tracking_template_id', '');

            foreach ($data as $item) {
                $order_id = isset($item['orderId']) ? $this->clean_order_id($item['orderId']) : '';
                $phone_number = isset($item['phoneNumber']) ? $item['phoneNumber'] : '';
                $tracking_code = isset($item['trackingCode']) ? $item['trackingCode'] : '';

                if (empty($order_id) || empty($phone_number) || empty($tracking_code)) {
                    $failed_count++;
                    continue;
                }

                // Get order details if needed
                try {
                    $order = wc_get_order($order_id);
                    if (!$order) {
                        $failed_count++;
                        continue;
                    }
                } catch (\Exception $e) {
                    error_log('Error getting order ' . $order_id . ': ' . $e->getMessage());
                    $failed_count++;
                    continue;
                }

                // Format phone number
                $formatted_phone = preg_replace('/[^0-9]/', '', $phone_number);
                if (empty($formatted_phone)) {
                    $failed_count++;
                    continue;
                }
                if (substr($formatted_phone, 0, 3) === '989') {
                    $formatted_phone = '0' . substr($formatted_phone, 2);
                }

                // Prepare parameters for verification code
                $params = [
                    'SMSIRTrackingCode' => $tracking_code
                ];

                $result = $sms_client->send_verification_code($formatted_phone, $params, $template_id);
                if (isset($result['success']) && $result['success']) {
                    $success_count++;
                } else {
                    $failed_count++;
                }
            }

            // Return results
            wp_send_json_success([
                'message' => sprintf(__('%d messages sent successfully, %d failed.', 'sms-ir'), $success_count, $failed_count),
                'success_count' => $success_count,
                'failed_count' => $failed_count
            ]);
        } catch (\Exception $e) {
   
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Get detailed data for a specific history entry
     */
    public function get_history_data() {
        try {
            // Check user permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You do not have permission to access this data.', 'sms-ir')]);
                return;
            }
            
            $history_id = isset($_POST['history_id']) ? sanitize_text_field($_POST['history_id']) : '';
            
            if (empty($history_id)) {
                wp_send_json_error(['message' => __('Invalid history ID.', 'sms-ir')]);
                return;
            }
            
            $history = get_option('smsir_excel_upload_history', []);
            $entry = null;
            
            // Find the specific history entry
            foreach ($history as $item) {
                if (isset($item['id']) && $item['id'] === $history_id) {
                    $entry = $item;
                    break;
                }
            }
            
            if (!$entry) {
                wp_send_json_error(['message' => __('History entry not found.', 'sms-ir')]);
                return;
            }
            
            // If sample_data is stored, return it
            if (isset($entry['sample_data'])) {
                wp_send_json_success($entry['sample_data']);
                return;
            }
            
            // Otherwise return empty array
            wp_send_json_success([]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Save tracking template ID
     */
    public function save_tracking_template_id() {
        try {
            // Check user permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You do not have permission to save tracking template ID.', 'sms-ir')]);
                return;
            }

            // Check if data was provided
            if (empty($_POST['template_id'])) {
                wp_send_json_error(['message' => __('No template ID was provided.', 'sms-ir')]);
                return;
            }

            $template_id = sanitize_text_field($_POST['template_id']);

            // Save the template ID to options
            update_option('smsir_tracking_template_id', $template_id);

            wp_send_json_success(['message' => __('Tracking template ID saved successfully.', 'sms-ir')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Get tracking template ID
     */
    public function get_tracking_template_id() {
        try {
            // Check user permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You do not have permission to get tracking template ID.', 'sms-ir')]);
                return;
            }

            // Get the template ID from options
            $template_id = get_option('smsir_tracking_template_id', '');

            wp_send_json_success($template_id);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}

// Initialize the class
SMSIR_Excel_Uploader::get_instance(); 