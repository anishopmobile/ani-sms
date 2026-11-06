<?php
require __DIR__ . '/bootstrap.php';

use AniSms\Infrastructure\Database;
use AniSms\Auth\Accounts;
use AniSms\Auth\Otp;
use AniSms\Security\Secrets;
use AniSms\Provider\SmsIr;

$mode = $argv[1] ?? '';
$store = new Database( $wpdb );
if ( 'worker' === $mode ) {
	$folder = $argv[2]; $index = $argv[3]; $operation = $argv[4];
	file_put_contents( $folder . '/ready-' . $index, 'ready' );
	$deadline = microtime( true ) + 20;
	while ( ! is_file( $folder . '/go' ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $folder ) {
		$payload = json_decode( $args['body'] ?? '{}', true );
		file_put_contents( $folder . '/sent', wp_json_encode( $payload ) . "\n", FILE_APPEND | LOCK_EX );
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '{"status":1,"data":{"messageId":987,"cost":1}}' );
	}, 10, 3 );
	$otp = new Otp( $store, new Secrets(), new SmsIr( 'fake-test-key' ), new Accounts( $store ) );
	try {
		$result = 'issue' === $operation ? $otp->issue( '+989121234588', 'race-browser', '127.0.0.2', str_repeat( 'a', 32 ) ) : $otp->verify( $argv[5], $argv[6], 'race-browser', '127.0.0.2' );
		file_put_contents( $folder . '/result-' . $index, wp_json_encode( array( 'ok' => true, 'result' => $result ) ) );
	} catch ( Throwable $error ) { file_put_contents( $folder . '/result-' . $index, '{"ok":false}' ); }
	exit;
}

function race( string $operation, array $args = array() ): array {
	$folder = sys_get_temp_dir() . '/ani-sms-race-' . bin2hex( random_bytes( 8 ) );
	mkdir( $folder );
	$processes = array();
	for ( $i = 0; $i < 2; ++$i ) {
		$command = array_merge( array( PHP_BINARY, __FILE__, 'worker', $folder, (string) $i, $operation ), $args );
		$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$processes[] = array( $process, $pipes );
	}
	$deadline = microtime( true ) + 20;
	while ( ( ! is_file( $folder . '/ready-0' ) || ! is_file( $folder . '/ready-1' ) ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	file_put_contents( $folder . '/go', 'go' );
	foreach ( $processes as list( $process, $pipes ) ) {
		fclose( $pipes[0] ); $output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'Worker failed: ' . $error . $output ); }
	}
	return array( $folder, json_decode( file_get_contents( $folder . '/result-0' ), true ), json_decode( file_get_contents( $folder . '/result-1' ), true ) );
}

foreach ( array( 'challenges', 'rates', 'logs' ) as $name ) { $store->query( "DELETE FROM {$store->table( $name )}" ); }
$store->query( "DELETE FROM {$store->table( 'identities' )} WHERE phone='+989121234588'" );
update_option( 'users_can_register', 1 );
update_option( 'ani_sms_modules', array( 'auth' ) );
update_option( 'ani_sms_settings', array_merge( \AniSms\Core\Settings::defaults(), array( 'registration' => true, 'template' => 123 ) ) );
list( $folder, $first, $second ) = race( 'issue' );
ani_check( $first['ok'] && $second['ok'], 'both idempotent issuance clients succeed' );
ani_check( $first['result']['challenge'] === $second['result']['challenge'], 'one challenge for concurrent identical request' );
$sent = file( $folder . '/sent', FILE_IGNORE_NEW_LINES );
ani_check( 1 === count( $sent ), 'exactly one mocked send for concurrent issue' );
$code = json_decode( $sent[0], true )['parameters'][0]['value'];
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users" );
list( $verify_folder, $first_verify, $second_verify ) = race( 'verify', array( $first['result']['challenge'], $code ) );
ani_check( 1 === (int) $first_verify['ok'] + (int) $second_verify['ok'], 'exactly one successful concurrent verify' );
ani_check( $before + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users" ), 'concurrent registration creates exactly one account' );
ani_check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$store->table( 'identities' )} WHERE phone='+989121234588'" ), 'unique identity after concurrent verification' );
foreach ( array( $folder, $verify_folder ) as $directory ) { foreach ( glob( $directory . '/*' ) as $file ) { unlink( $file ); } rmdir( $directory ); }
echo 'PASS concurrency: ' . $GLOBALS['ani_checks'] . " checks; two independent PHP/MySQL clients, all SMS mocked.\n";
