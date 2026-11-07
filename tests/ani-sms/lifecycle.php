<?php
require __DIR__ . '/bootstrap.php';
use AniSms\Infrastructure\Database;
use AniSms\Core\Settings;
use AniSms\Core\Lifecycle;

$store = new Database( $wpdb );
$store->migrate();
$users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users" );
$subscribers=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$store->table('subscribers')}");
wp_schedule_single_event(time()+300,'ani_sms_stock_scan',array(12345));
update_option( 'ani_sms_credential', 'test-encrypted-placeholder' );
update_option( 'ani_sms_settings', Settings::defaults() );
Lifecycle::deactivate();
ani_check( 'test-encrypted-placeholder' === get_option( 'ani_sms_credential' ), 'deactivation preserves credential' );
ani_check( false === wp_next_scheduled( 'ani_sms_cleanup' ), 'cleanup unscheduled' );
ani_check(false===wp_next_scheduled('ani_sms_dispatch')&&false===wp_next_scheduled('ani_sms_dispatch_soon'),'dispatch hooks unscheduled');
ani_check(false===wp_next_scheduled('ani_sms_stock_scan',array(12345))&&false===wp_next_scheduled('ani_sms_stock_recovery'),'stock hooks unscheduled for all arguments');
ani_check($subscribers===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$store->table('subscribers')}"),'deactivation preserves consent/suppression records');
define( 'WP_UNINSTALL_PLUGIN', 'ani-sms/ani-sms.php' );
require dirname( __DIR__, 2 ) . '/wp-content/plugins/ani-sms/uninstall.php';
ani_check( Database::VERSION === get_option( 'ani_sms_schema' ), 'uninstall preserves data by default' );
ani_check( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $store->table( 'identities' ) ) ) ) === $store->table( 'identities' ), 'identity table retained' );
update_option( 'ani_sms_settings', array_merge( Settings::defaults(), array( 'purge' => true ) ) );
require dirname( __DIR__, 2 ) . '/wp-content/plugins/ani-sms/uninstall.php';
ani_check( false === get_option( 'ani_sms_credential' ) && false === get_option( 'ani_sms_schema' ), 'explicit purge removes options and credential' );
ani_check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $store->table( 'identities' ) ) ) ), 'explicit purge removes only plugin tables' );
foreach(array('jobs','event_states','subscribers','consents','campaigns','campaign_recipients','stock_requests')as $table){ani_check(null===$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($store->table($table)))),'explicit purge removes '.$table);}
ani_check(false===get_option('ani_sms_marketing_key_id'),'explicit purge removes marketing key guard');
ani_check( $users === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users" ), 'users preserved on purge' );
$store->migrate();
echo 'PASS lifecycle: ' . $GLOBALS['ani_checks'] . " checks; test database only.\n";
