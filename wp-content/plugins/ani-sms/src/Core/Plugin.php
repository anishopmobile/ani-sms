<?php
/**
 * Composition root. No auth endpoint is exposed by this initial scaffold.
 *
 * @package AniSms
 */

namespace AniSms\Core;

final class Plugin {
	private ModuleRegistry $modules;

	public function __construct() {
		$this->modules = new ModuleRegistry();
	}

	public function boot(): void {
		$enabled = get_option( 'ani_sms_modules', array() );
		$this->modules->boot( is_array( $enabled ) ? $enabled : array() );
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	public function menu(): void {
		add_menu_page( 'ANI-SMS', 'ANI-SMS', 'manage_options', 'ani-sms', array( $this, 'page' ), 'dashicons-email-alt' );
	}

	public function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ani-sms' ) );
		}
		echo '<div class="wrap"><h1>ANI-SMS</h1><p>';
		echo esc_html__( 'Development checkpoint: core bootstrap is installed. SMS sending and mobile authentication are not available yet.', 'ani-sms' );
		echo '</p></div>';
	}
}
