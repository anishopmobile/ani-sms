<?php
/** Disposable integration database only. Never load a production wp-config. */
$ani_test_root = getenv( 'ANI_SMS_TEST_WP' );
if ( ! $ani_test_root || ! is_file( $ani_test_root . '/wp-load.php' ) ) { throw new RuntimeException( 'Set ANI_SMS_TEST_WP to a disposable WordPress installation.' ); }
require_once $ani_test_root . '/wp-load.php';
if ( ! defined( 'ANI_SMS_TEST_ENV' ) || ! ANI_SMS_TEST_ENV || 'local' !== wp_get_environment_type() || ! str_ends_with( DB_NAME, '_test' ) ) { throw new RuntimeException( 'Refusing a non-test database.' ); }
if ( ! defined( 'ANI_SMS_TEST_PACKAGE' ) ) { require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/ani-sms/src/autoload.php'; }

function ani_check( bool $condition, string $message ): void {
	$GLOBALS['ani_checks'] = ( $GLOBALS['ani_checks'] ?? 0 ) + 1;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
}

function ani_rejects( callable $work, string $message ): void {
	$failed = false;
	try { $work(); } catch ( Throwable $error ) { $failed = true; }
	ani_check( $failed, $message );
}
