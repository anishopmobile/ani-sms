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
		$modules->register( new \AniSms\Stock\Module( $store, $queue ) );
		( new \AniSms\Stock\Frontend( $store ) )->boot();
		( new \AniSms\Stock\Privacy( $store ) )->boot();
		$modules->register( new \AniSms\Marketing\Module( $store, $queue ) );
		( new \AniSms\Marketing\Frontend( $store ) )->boot();
		( new \AniSms\Marketing\Privacy( $store ) )->boot();
		add_action(
			'update_option_ani_sms_modules',
			static function ( $old, $next ) use ( $store ) {
				if ( is_array( $old ) && in_array( 'stock', $old, true ) && ( ! is_array( $next ) || ! in_array( 'stock', $next, true ) ) ) {
					$store->query( "UPDATE {$store->table( 'jobs' )} SET state='cancelled',error_code='module_disabled',payload='' WHERE module='stock' AND state='queued'" );
					$store->query( "UPDATE {$store->table( 'stock_requests' )} SET state='cancelled',phone='' WHERE state IN ('waiting','queued')" );
					wp_unschedule_hook( 'ani_sms_stock_scan' );
					wp_unschedule_hook( 'ani_sms_stock_children' );
					wp_clear_scheduled_hook( 'ani_sms_stock_recovery' );
				}
				if ( is_array( $old ) && in_array( 'marketing', $old, true ) && ( ! is_array( $next ) || ! in_array( 'marketing', $next, true ) ) ) {
					$store->query( "UPDATE {$store->table( 'jobs' )} SET state='cancelled',error_code='module_disabled',payload='' WHERE module='marketing' AND state IN ('queued','paused')" );
					$store->query( "UPDATE {$store->table( 'campaigns' )} SET state='cancelled' WHERE state IN ('approved','paused')" );
					$store->query( "UPDATE {$store->table( 'campaign_recipients' )} SET state='cancelled' WHERE state IN ('queued','paused')" );
				}
			},
			10,
			2
		);
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
