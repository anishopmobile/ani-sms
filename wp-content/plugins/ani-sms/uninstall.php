<?php
/**
 * Preserve data by default; purge only explicitly enabled plugin-owned data.
 *
 * @package AniSms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'ani_sms_cleanup' );
wp_clear_scheduled_hook( 'ani_sms_dispatch' );
wp_clear_scheduled_hook( 'ani_sms_dispatch_soon' );
$ani_settings = get_option( 'ani_sms_settings', array() );
if ( ! is_array( $ani_settings ) || empty( $ani_settings['purge'] ) || is_multisite() ) {
	return; }
global $wpdb;
foreach ( array( 'identities', 'challenges', 'rates', 'logs', 'jobs', 'event_states', 'subscribers', 'consents', 'campaigns', 'campaign_recipients' ) as $ani_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ani_sms_' . $ani_table ) );
}
foreach ( array( 'ani_sms_settings', 'ani_sms_modules', 'ani_sms_schema', 'ani_sms_credential', 'ani_sms_last_cleanup', 'ani_sms_wc_settings', 'ani_sms_wc_generation', 'ani_sms_wc_available', 'ani_sms_marketing_key_id' ) as $ani_option ) {
	delete_option( $ani_option ); }
delete_transient( 'ani_sms_account' );
