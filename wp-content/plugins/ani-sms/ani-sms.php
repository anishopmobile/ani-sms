<?php
/**
 * Plugin Name: ANI-SMS
 * Description: Independent modular SMS infrastructure and mobile authentication.
 * Version: 0.1.0-dev
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * Text Domain: ani-sms
 * Domain Path: /languages
 *
 * @package AniSms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, array( \AniSms\Core\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \AniSms\Core\Lifecycle::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! \AniSms\Core\Lifecycle::supported() ) {
			add_action( 'admin_notices', array( \AniSms\Core\Lifecycle::class, 'notice' ) );
			return;
		}
		$plugin = new \AniSms\Core\Plugin();
		$plugin->boot();
	}
);
