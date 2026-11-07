<?php
/**
 * Safe activation and deactivation. No user data is removed.
 *
 * @package AniSms
 */

namespace AniSms\Core;

final class Lifecycle {
	public static function supported(): bool {
		global $wp_version;
		return version_compare( (string) phpversion(), '8.3', '>=' )
			&& version_compare( (string) $wp_version, '6.8', '>=' );
	}

	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide || is_multisite() ) {
			wp_die( esc_html__( 'ANI-SMS multisite activation is not supported in this development build.', 'ani-sms' ) );
		}
		if ( ! self::supported() ) {
			wp_die( esc_html__( 'ANI-SMS requires WordPress 6.8 and PHP 8.3 or later.', 'ani-sms' ) );
		}
		add_option( 'ani_sms_modules', array(), '', false );
		global $wpdb;
		try {
			( new \AniSms\Infrastructure\Database( $wpdb ) )->migrate(); } catch ( \Throwable $error ) {
			wp_die( esc_html__( 'ANI-SMS requires InnoDB storage and database schema privileges.', 'ani-sms' ) );
			}
	}

	public static function deactivate(): void {
		global $wpdb;
		wp_clear_scheduled_hook( 'ani_sms_cleanup' );
		wp_clear_scheduled_hook( 'ani_sms_cart_scan' );
		wp_unschedule_hook( 'ani_sms_stock_scan' );
		wp_unschedule_hook( 'ani_sms_stock_children' );
		wp_clear_scheduled_hook( 'ani_sms_stock_recovery' );
		wp_unschedule_hook( 'ani_sms_discount_scan' );
		wp_unschedule_hook( 'ani_sms_discount_children' );
		wp_unschedule_hook( 'ani_sms_discount_boundary' );
		wp_clear_scheduled_hook( 'ani_sms_discount_recovery' );
		wp_clear_scheduled_hook( 'ani_sms_dispatch' );
		wp_clear_scheduled_hook( 'ani_sms_dispatch_soon' );
		update_option( 'ani_sms_wc_generation', bin2hex( random_bytes( 16 ) ), false );
		if ( \AniSms\Infrastructure\Database::VERSION === get_option( 'ani_sms_schema' ) ) {
			$store = new \AniSms\Infrastructure\Database( $wpdb );
			$store->query( "UPDATE {$store->table('carts')} SET state='cancelled',job_key=''" );
			$store->query( "UPDATE {$store->table( 'discount_requests' )} SET state='cancelled',phone='' WHERE state IN ('waiting','queued')" );
			$store->query( "UPDATE {$store->table( 'stock_requests' )} SET state='cancelled',phone='' WHERE state IN ('waiting','queued')" );
			$store->query( "UPDATE {$store->table( 'campaigns' )} SET state='cancelled' WHERE state IN ('approved','paused')" );
			$store->query( "UPDATE {$store->table( 'campaign_recipients' )} SET state='cancelled' WHERE state IN ('queued','paused')" );
			$store->query( "UPDATE {$store->table( 'jobs' )} SET state='cancelled',error_code='deactivated',payload='' WHERE state IN ('queued','paused')" );
			$store->query( "UPDATE {$store->table( 'challenges' )} SET state='expired',digest='' WHERE state IN ('active','pending_send')" );
		}
		do_action( 'ani_sms_deactivated' );
	}

	public static function notice(): void {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'ANI-SMS requires WordPress 6.8 and PHP 8.3 or later.', 'ani-sms' ) . '</p></div>';
	}
}
