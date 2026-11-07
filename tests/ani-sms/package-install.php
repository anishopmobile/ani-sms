<?php
/** Verify the physical allowlisted ZIP installs on a disposable WordPress site. */
define( 'ANI_SMS_TEST_PACKAGE', true );
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$file = getenv( 'ANI_SMS_TEST_ZIP' );
if ( ! $file || ! is_file( $file ) ) { throw new RuntimeException( 'Set ANI_SMS_TEST_ZIP.' ); }
$zip = new ZipArchive();
if ( true !== $zip->open( $file ) ) { throw new RuntimeException( 'Invalid ZIP.' ); }
for ( $i = 0; $i < $zip->numFiles; ++$i ) {
	$name = $zip->getNameIndex( $i );
	if ( ! str_starts_with( $name, 'ani-sms/' ) || str_contains( $name, '..' ) || str_contains( $name, '\\' ) ) { throw new RuntimeException( 'Unsafe package path.' ); }
}
ani_check( $zip->extractTo( WP_PLUGIN_DIR ), 'archive extracts safely' );
$zip->close();
$result = activate_plugin( 'ani-sms/ani-sms.php' );
ani_check( ! is_wp_error( $result ) && is_plugin_active( 'ani-sms/ani-sms.php' ), 'packaged plugin activates' );
$class_file = ( new ReflectionClass( AniSms\Core\Lifecycle::class ) )->getFileName();
ani_check( str_starts_with( wp_normalize_path( $class_file ), wp_normalize_path( WP_PLUGIN_DIR . '/ani-sms/' ) ), 'loaded classes come from installed ZIP' );
$metadata = get_plugin_data( WP_PLUGIN_DIR . '/ani-sms/ani-sms.php', false, false );
ani_check( '1.5.0-rc1' === $metadata['Version'], 'package version metadata' );
deactivate_plugins( 'ani-sms/ani-sms.php' );
ani_check( ! is_plugin_active( 'ani-sms/ani-sms.php' ), 'package deactivates cleanly' );
$result = activate_plugin( 'ani-sms/ani-sms.php' );
ani_check( ! is_wp_error( $result ), 'package reactivates idempotently' );
echo 'PASS package install: ' . $GLOBALS['ani_checks'] . " checks; test site only.\n";
