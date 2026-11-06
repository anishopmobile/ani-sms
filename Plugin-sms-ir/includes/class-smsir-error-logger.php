<?php
namespace SMSIR;

if (!defined('ABSPATH')) {
    exit;
}

class SMSIR_Error_Logger {
    private static $instance = null;
    private $table_name;
    private $max_logs = 1000; // Maximum number of logs to keep

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sms_ir_error_logs';
    }

    public function create_table() {
        global $wpdb;
        
        // Check if table already exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        if ($table_exists) {
            error_log('SMSIR: Table already exists');
            return true;
        }

        $charset_collate = $wpdb->get_charset_collate();
        error_log('SMSIR: Charset collate: ' . $charset_collate);

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp int(11) NOT NULL,
            message text NOT NULL,
            context longtext,
            PRIMARY KEY  (id),
            KEY timestamp (timestamp)
        ) $charset_collate;";


        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);

        if ($wpdb->last_error) {
            return false;
        }

        return true;
    }

    public function log($message, $context = []) {
        global $wpdb;

        // Check if table exists, if not create it
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        if (!$table_exists) {
            $this->create_table();
        }

        // Clean up old logs if we're at the limit
        $this->cleanup_old_logs();

        $data = [
            'timestamp' => time(),
            'message' => sanitize_text_field($message),
            'context' => maybe_serialize($context)
        ];

        $result = $wpdb->insert($this->table_name, $data);

        if ($result === false) {
            return false;
        }

        return true;
    }

    public function get_logs($page = 1, $per_page = 20) {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        if (!$table_exists) {
            return [
                'logs' => [],
                'pagination' => [
                    'total' => 0,
                    'per_page' => $per_page,
                    'current_page' => $page,
                    'last_page' => 1
                ]
            ];
        }

        $offset = ($page - 1) * $per_page;
        
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                ORDER BY timestamp DESC 
                LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        if ($wpdb->last_error) {
            error_log('SMSIR: Error fetching logs: ' . $wpdb->last_error);
            return [
                'logs' => [],
                'pagination' => [
                    'total' => 0,
                    'per_page' => $per_page,
                    'current_page' => $page,
                    'last_page' => 1
                ]
            ];
        }

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        return [
            'logs' => array_map(function($log) {
                $log->context = maybe_unserialize($log->context);
                return $log;
            }, $logs),
            'pagination' => [
                'total' => (int)$total,
                'per_page' => $per_page,
                'current_page' => $page,
                'last_page' => ceil($total / $per_page)
            ]
        ];
    }

    public function clear_logs() {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        if (!$table_exists) {
            return false;
        }
        
        $result = $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        
        if ($result === false) {
            return false;
        }
        
        return true;
    }

    private function cleanup_old_logs() {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        if (!$table_exists) {
            return;
        }
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        if ($count >= $this->max_logs) {
            $delete_count = $count - ($this->max_logs * 0.8); // Keep 80% of max logs
            $result = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table_name} 
                    ORDER BY timestamp ASC 
                    LIMIT %d",
                    $delete_count
                )
            );


        }
    }
} 