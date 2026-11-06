<?php
namespace SMSIR;

class BanManager {
    private static $instance = null;
    private $table_name;

    const MAX_ATTEMPTS = 5;
    const BAN_DURATION = 600;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'smsir_bans';
        $this->create_table();
    }

    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            identifier varchar(255) NOT NULL,
            ip_address varchar(45) NOT NULL,
            attempts int(11) NOT NULL DEFAULT 1,
            last_attempt datetime NOT NULL,
            banned_until datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY identifier (identifier),
            KEY banned_until (banned_until)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function record_attempt($identifier, $type = 'phone') {
        global $wpdb;
        $ip = $this->get_client_ip();
        
        $this->cleanup_old_records();

        $tehran_timezone = new \DateTimeZone('Asia/Tehran');
        $now = new \DateTime('now', $tehran_timezone);
        
        $ban_time = clone $now;
        $ban_time->modify('+' . self::BAN_DURATION . ' seconds');

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE identifier = %s",
            $identifier
        ));

        if ($record) {
            $attempts = $record->attempts + 1;

            $wpdb->update(
                $this->table_name,
                [
                    'attempts' => $attempts,
                    'ip_address' => $ip,
                    'last_attempt' => $now->format('Y-m-d H:i:s'),
                    'banned_until' => $attempts >= self::MAX_ATTEMPTS ? 
                        $ban_time->format('Y-m-d H:i:s') : null
                ],
                ['id' => $record->id]
            );
            
            error_log(sprintf(
                'Login attempt recorded for %s from IP %s. Attempts: %d, Ban status: %s, Ban until: %s (Tehran Time)',
                $identifier,
                $ip,
                $attempts,
                $attempts >= self::MAX_ATTEMPTS ? 'Banned' : 'Not banned',
                $attempts >= self::MAX_ATTEMPTS ? $ban_time->format('Y-m-d H:i:s') : 'N/A'
            ));
        } else {
            $wpdb->insert(
                $this->table_name,
                [
                    'identifier' => $identifier,
                    'ip_address' => $ip,
                    'attempts' => 1,
                    'last_attempt' => $now->format('Y-m-d H:i:s'),
                    'created_at' => $now->format('Y-m-d H:i:s')
                ]
            );

            error_log(sprintf('First login attempt recorded for %s from IP %s at %s (Tehran Time)', 
                $identifier, 
                $ip,
                $now->format('Y-m-d H:i:s')
            ));
        }
    }

    public function is_banned($identifier) {
        global $wpdb;
        
        $tehran_timezone = new \DateTimeZone('Asia/Tehran');
        $now = new \DateTime('now', $tehran_timezone);
        
        $banned = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE identifier = %s 
            AND banned_until > %s",
            $identifier,
            $now->format('Y-m-d H:i:s')
        ));
        
        if ($banned) {
            $ban_until = new \DateTime($banned->banned_until, $tehran_timezone);
            
            error_log(sprintf(
                'Ban check: ID: %s, Current time: %s, Banned until: %s (Tehran Time)',
                $identifier,
                $now->format('Y-m-d H:i:s'),
                $ban_until->format('Y-m-d H:i:s')
            ));
        }

        return !empty($banned);
    }

    public function get_remaining_ban_time($identifier) {
        global $wpdb;

        $tehran_timezone = new \DateTimeZone('Asia/Tehran');
        $now = new \DateTime('now', $tehran_timezone);

        $ban_record = $wpdb->get_row($wpdb->prepare(
            "SELECT banned_until, ip_address FROM {$this->table_name} 
            WHERE identifier = %s 
            AND banned_until > %s",
            $identifier,
            $now->format('Y-m-d H:i:s')
        ));

        if (!$ban_record) {
            return 0;
        }

        $ban_until = new \DateTime($ban_record->banned_until, $tehran_timezone);
        $remaining = $ban_until->getTimestamp() - $now->getTimestamp();

        error_log(sprintf(
            'Remaining ban time for %s: %d seconds (Last attempt from IP: %s)',
            $identifier,
            $remaining,
            $ban_record->ip_address
        ));

        return max(0, $remaining);
    }

    public function clear_attempts($identifier) {
        global $wpdb;
        
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT ip_address FROM {$this->table_name} WHERE identifier = %s",
            $identifier
        ));

        $wpdb->delete(
            $this->table_name,
            ['identifier' => $identifier]
        );

        if ($record) {
            error_log(sprintf(
                'Attempts cleared for %s (Last IP: %s)',
                $identifier,
                $record->ip_address
            ));
        }
    }

    private function cleanup_old_records() {
        global $wpdb;
        
        $tehran_timezone = new \DateTimeZone('Asia/Tehran');
        $now = new \DateTime('now', $tehran_timezone);
        
        $deleted_old = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
            WHERE banned_until IS NULL 
            AND last_attempt < %s",
            (clone $now)->modify('-24 hours')->format('Y-m-d H:i:s')
        ));

        $deleted_expired = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
            WHERE banned_until IS NOT NULL 
            AND banned_until < %s",
            $now->format('Y-m-d H:i:s')
        ));

        if ($deleted_old || $deleted_expired) {
            error_log(sprintf(
                'Cleaned up ban records: %d old records, %d expired bans (Tehran Time: %s)',
                $deleted_old,
                $deleted_expired,
                $now->format('Y-m-d H:i:s')
            ));
        }
    }

    private function get_client_ip() {
        $ip = '';
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
} 