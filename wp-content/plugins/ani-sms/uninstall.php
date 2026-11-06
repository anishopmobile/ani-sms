<?php
/**
 * Preserve all data by default. Destructive cleanup is not offered yet.
 *
 * @package AniSms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Deliberately retain ani_sms_modules. Never remove users or orders.
