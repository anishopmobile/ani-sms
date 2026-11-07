<?php
/** Composition root; features depend on core infrastructure. @package AniSms */
namespace AniSms\Core;
use AniSms\Infrastructure\Database;
final class Plugin {
	public function boot(): void {
		global $wpdb;
		$store = new Database( $wpdb );
		try {
			if ( Database::VERSION !== get_option( 'ani_sms_schema' ) ) {
				$store->migrate(); }
		} catch ( \Throwable $error ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'ANI-SMS storage is unavailable. Verify MySQL InnoDB support and database privileges.', 'ani-sms' ) . '</p></div>';
				}
			);
			return;
		}
		load_plugin_textdomain( 'ani-sms', false, 'ani-sms/languages' );
		add_action( 'ani_sms_cleanup', array( $store, 'cleanup' ) );
		$scheduled = wp_get_scheduled_event( 'ani_sms_cleanup' );
		if ( $scheduled && 'hourly' !== $scheduled->schedule ) {
			wp_clear_scheduled_hook( 'ani_sms_cleanup' ); }
		if ( ! wp_next_scheduled( 'ani_sms_cleanup' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'ani_sms_cleanup' ); }
		( new \AniSms\Admin\Admin( $store ) )->boot();
		( new \AniSms\Privacy\Privacy( $store ) )->boot();
		( new \AniSms\Privacy\Jobs( $store ) )->boot();
		$queue = new \AniSms\Infrastructure\Queue( $store );
		$queue->boot();
		add_action( 'ani_sms_dispatch_soon', array( $queue, 'drain' ) );
		$auth    = new \AniSms\Auth\Module( $store );
		$modules = new ModuleRegistry();
		$modules->register( $auth );
		$available = class_exists( 'WooCommerce' );
		if ( (bool) get_option( 'ani_sms_wc_available', false ) !== $available ) {
			update_option( 'ani_sms_wc_generation', bin2hex( random_bytes( 16 ) ), false );
			update_option( 'ani_sms_wc_available', $available, false );
		}
		$modules->register( new \AniSms\WooCommerce\Module( $store, $queue ) );
		$enabled = get_option( 'ani_sms_modules', array() );
		$modules->boot( is_array( $enabled ) ? $enabled : array() );
		$reason = Settings::enabled() ? $auth->unavailable_reason() : __( 'Mobile authentication is disabled.', 'ani-sms' );
		( new \AniSms\Auth\Frontend( $reason, $store ) )->boot();
	}
}
