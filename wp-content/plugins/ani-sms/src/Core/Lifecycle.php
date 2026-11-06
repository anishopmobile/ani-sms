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
		return version_compare( PHP_VERSION, '8.3', '>=' )
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
	}

	public static function deactivate(): void {
		// No dispatch jobs exist in this milestone. Preserve settings and users.
		do_action( 'ani_sms_deactivated' );
	}

	public static function notice(): void {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'ANI-SMS requires WordPress 6.8 and PHP 8.3 or later.', 'ani-sms' ) . '</p></div>';
	}
}
