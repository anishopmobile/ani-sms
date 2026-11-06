<?php
require __DIR__ . '/bootstrap.php';

use AniSms\Auth\Accounts;
use AniSms\Auth\Otp;
use AniSms\Core\Settings;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Log;
use AniSms\Security\Secrets;
use AniSms\Security\RateLimiter;
use AniSms\Provider\SmsIr;
use AniSms\Provider\Provider;
use AniSms\Provider\Result;

$store = new Database( $wpdb );
$store->migrate();
$store->migrate();
require_once ABSPATH . 'wp-admin/includes/user.php';
$test_admin = get_user_by( 'login', 'ani_test_admin' );
foreach ( get_users( array( 'exclude' => array( $test_admin->ID ), 'fields' => 'ID' ) ) as $test_user_id ) { wp_delete_user( (int) $test_user_id ); }
ani_check( Database::VERSION === get_option( 'ani_sms_schema' ), 'idempotent migration' );
foreach ( array( 'identities', 'challenges', 'rates', 'logs' ) as $name ) { $store->query( "DELETE FROM {$store->table( $name )}" ); }
update_option( 'ani_sms_settings', array_merge( Settings::defaults(), array( 'template' => 123, 'registration' => true ) ) );
update_option( 'ani_sms_modules', array( 'auth' ) );
update_option( 'users_can_register', 1 );
$secrets = new Secrets();
$encrypted = $secrets->encrypt( 'fake-test-api-key' );
ani_check( 'fake-test-api-key' === $secrets->decrypt( $encrypted ) && ! str_contains( $encrypted, 'fake-test-api-key' ), 'secret encryption roundtrip' );
ani_rejects( fn() => $secrets->decrypt( 'invalid' ), 'corrupt credential rejected' );

// Real WP HTTP API is intercepted before transport. No network request or paid SMS.
$response = array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'status' => 1, 'data' => array( 'messageId' => 321, 'cost' => 1 ) ) ) );
$calls = array();
$http = function ( $pre, $args, $url ) use ( &$response, &$calls ) { $calls[] = array( $args, $url ); return $response; };
add_filter( 'pre_http_request', $http, 10, 3 );
$sms = new SmsIr( 'fake-test-api-key' );
ani_check( $sms->send_template( '+989123456789', 123, 'Code', '000123' )->accepted(), 'valid verify response' );
$body = json_decode( $calls[0][0]['body'], true );
ani_check( '09123456789' === $body['mobile'] && '000123' === $body['parameters'][0]['value'], 'payload casing and leading zero' );
ani_check( 0 === $calls[0][0]['redirection'] && true === $calls[0][0]['sslverify'] && 10 === $calls[0][0]['timeout'], 'secure bounded transport' );
foreach ( array( 429, 500, 302 ) as $status ) {
	$response['response']['code'] = $status;
	ani_check( 'unknown' === $sms->send_template( '+989123456789', 123, 'Code', '000123' )->state, 'ambiguous POST never retried' );
}
$response['response']['code'] = 401;
ani_check( 'failed' === $sms->send_template( '+989123456789', 123, 'Code', '000123' )->state, 'credential rejection' );
$response = new WP_Error( 'timeout', 'deliberately includes sensitive fake-data' );
ani_check( 'unknown' === $sms->send_template( '+989123456789', 123, 'Code', '000123' )->state, 'transport result' );
foreach ( array( '{', 'null', '{}', '{"status":1}', '{"status":1,"data":{"messageId":"321"}}' ) as $bad ) {
	$response = array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => $bad );
	ani_check( 'unknown' === $sms->send_template( '+989123456789', 123, 'Code', '000123' )->state, 'malformed successful HTTP rejected' );
}
$response['body'] = '{"status":2,"data":null}';
ani_check( 'failed' === $sms->send_template( '+989123456789', 123, 'Code', '000123' )->state, 'provider rejects' );
$response['body'] = '{"status":1,"data":125.5}';
ani_check( 125.5 === $sms->connection()->credit, 'credit response' );
remove_filter( 'pre_http_request', $http );

final class FakeSms implements Provider {
	public int $calls = 0;
	public string $code = '';
	public string $state = 'accepted';
	public function capabilities(): array { return array( 'template' => true ); }
	public function connection(): Result { return new Result( 'accepted', credit: 10 ); }
	public function send_template( string $phone, int $template, string $parameter, string $code ): Result { ++$this->calls; $this->code = $code; return new Result( $this->state, message_id: '123' ); }
}
$provider = new FakeSms();
$accounts = new Accounts( $store );
ani_check( Accounts::compatible(), 'core authentication policy compatible' );
$otp = new Otp( $store, $secrets, $provider, $accounts );
$request = fn() => bin2hex( random_bytes( 16 ) );
$phone = '+989121234501';
$a = $otp->issue( $phone, 'browser-one', '127.0.0.1', $req = $request() );
$code = $provider->code;
ani_check( 1 === $provider->calls && strlen( $code ) === 6, 'OTP issued' );
ani_check( $a === $otp->issue( $phone, 'browser-one', '127.0.0.1', $req ) && 1 === $provider->calls, 'idempotent request no resend' );
ani_rejects( fn() => $otp->issue( '+989121234599', 'browser-one', '127.0.0.1', $req ), 'request cannot change phone' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$store->table( 'challenges' )} WHERE id=%s", $a['challenge'] ), ARRAY_A );
ani_check( $row['digest'] !== $code && strlen( $row['digest'] ) === 64, 'OTP is keyed digest' );
ani_rejects( fn() => $otp->verify( $a['challenge'], $code, 'browser-two', '127.0.0.1' ), 'binding mismatch' );
ani_rejects( fn() => $otp->verify( $a['challenge'], $code, 'browser-one', '127.0.0.1', 'link', 1 ), 'purpose mismatch' );
ani_rejects( fn() => $otp->issue( $phone, 'browser-new', '127.0.0.1', $request() ), 'cooldown across browsers' );
$user_id = $otp->verify( $a['challenge'], $code, 'browser-one', '127.0.0.1' );
ani_check( $user_id > 0 && $accounts->identity( $phone ) === $user_id, 'registration creates unique identity' );
$user = get_user_by( 'id', $user_id );
ani_check( array( 'subscriber' ) === $user->roles && '' === $user->user_email && ! str_contains( $user->user_login, '9121234501' ), 'minimal safe account' );
ani_rejects( fn() => $otp->verify( $a['challenge'], $code, 'browser-one', '127.0.0.1' ), 'replay rejected' );

$locked = $otp->issue( '+989121234502', 'browser-one', '127.0.0.1', $request() );
$correct = $provider->code;
for ( $i = 0; $i < 5; ++$i ) { ani_rejects( fn() => $otp->verify( $locked['challenge'], $correct === '000000' ? '999999' : '000000', 'browser-one', '127.0.0.1' ), 'wrong code' ); }
ani_check( 'locked' === $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$store->table( 'challenges' )} WHERE id=%s", $locked['challenge'] ) ), 'attempts persist and lock' );
ani_rejects( fn() => $otp->verify( $locked['challenge'], $correct, 'browser-one', '127.0.0.1' ), 'correct code after lock rejected' );

$expired = $otp->issue( '+989121234503', 'browser-one', '127.0.0.1', $request() );
$expired_code = $provider->code;
$store->query( $wpdb->prepare( "UPDATE {$store->table( 'challenges' )} SET expires=%d WHERE id=%s", time() - 1, $expired['challenge'] ) );
ani_rejects( fn() => $otp->verify( $expired['challenge'], $expired_code, 'browser-one', '127.0.0.1' ), 'expired rejected before cleanup' );

update_option( 'users_can_register', 0 );
$before = $provider->calls;
$dummy = $otp->issue( '+989121234504', 'browser-one', '127.0.0.1', $request() );
ani_check( $before + 1 === $provider->calls && isset( $dummy['challenge'] ), 'uniform provider path with registration disabled' );
ani_rejects( fn() => $otp->verify( $dummy['challenge'], $provider->code, 'browser-one', '127.0.0.1' ), 'ineligible account remains inaccessible despite SMS' );
ani_check( $accounts->eligible( $phone, 'login', 0 ), 'existing login still allowed' );
$admin = get_user_by( 'login', 'ani_test_admin' );
ani_check( $admin && ! Accounts::allowed( $admin ), 'privileged account OTP blocked' );

update_option( 'users_can_register', 1 );
$unknown = $otp->issue( '+989121234505', 'browser-one', '127.0.0.1', $request() );
$old_code = $provider->code;
$store->query( $wpdb->prepare( "UPDATE {$store->table( 'challenges' )} SET created=%d WHERE id=%s", time() - 70, $unknown['challenge'] ) );
$provider->state = 'unknown';
$unknown2 = $otp->issue( '+989121234505', 'browser-one', '127.0.0.1', $request() );
ani_rejects( fn() => $otp->verify( $unknown2['challenge'], $provider->code, 'browser-one', '127.0.0.1' ), 'unknown send not active' );
ani_check( $otp->verify( $unknown['challenge'], $old_code, 'browser-one', '127.0.0.1' ) > 0, 'failed resend preserves previously active code' );
$provider->state = 'accepted';
$link_actor = wp_insert_user( array( 'user_login' => 'ani_link_test', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
$link_challenge = $otp->issue( '+989121234550', 'link-browser', '127.0.0.3', $request(), 'link', $link_actor );
ani_rejects( fn() => $otp->verify( $link_challenge['challenge'], $provider->code, 'link-browser', '127.0.0.3', 'link', $link_actor + 1 ), 'link actor mismatch' );
ani_check( $link_actor === $otp->verify( $link_challenge['challenge'], $provider->code, 'link-browser', '127.0.0.3', 'link', $link_actor ), 'existing account link after OTP' );
ani_check( ! $accounts->eligible( '+989121234551', 'link', $link_actor ), 'changing an already-linked number is blocked' );
ani_check( ! $accounts->eligible( '+989121234550', 'link', $admin->ID ), 'duplicate and privileged linking blocked' );
$rotated = $otp->issue( '+989121234552', 'browser-key', '127.0.0.3', $request() );
$store->query( $wpdb->prepare( "UPDATE {$store->table( 'challenges' )} SET key_id='old-key' WHERE id=%s", $rotated['challenge'] ) );
ani_rejects( fn() => $otp->verify( $rotated['challenge'], $provider->code, 'browser-key', '127.0.0.3' ), 'key rotation invalidates outstanding codes' );
ani_rejects( fn() => Settings::validate( array( 'registration' => array( '1' ) ) ), 'nested boolean setting rejected' );
ani_rejects( fn() => Settings::validate( array( 'expiry' => 1 ) ), 'unsafe expiry rejected' );
ani_rejects( fn() => Settings::validate( array( 'parameter' => '<script>' ) ), 'invalid parameter rejected' );
$new = $otp->issue( '+989121234506', 'browser-one', '127.0.0.1', $request() );
$old = $provider->code;
$store->query( $wpdb->prepare( "UPDATE {$store->table( 'challenges' )} SET created=%d WHERE id=%s", time() - 70, $new['challenge'] ) );
$replacement = $otp->issue( '+989121234506', 'browser-one', '127.0.0.1', $request() );
ani_rejects( fn() => $otp->verify( $new['challenge'], $old, 'browser-one', '127.0.0.1' ), 'accepted resend invalidates old code' );
ani_check( $otp->verify( $replacement['challenge'], $provider->code, 'browser-one', '127.0.0.1' ) > 0, 'replacement code works' );

$blocked = static fn( $user ) => $user;
add_filter( 'authenticate', $blocked, 30 );
ani_check( ! Accounts::compatible(), 'unknown auth handler fails closed' );
remove_filter( 'authenticate', $blocked, 30 );

$limiter = new RateLimiter( $store, $secrets );
$limiter->hit( 'test', 'shared', 1, 900 );
ani_rejects( fn() => $limiter->hit( 'test', 'shared', 1, 900 ), 'rate cap across requests' );
$logs = wp_json_encode( $wpdb->get_results( "SELECT * FROM {$store->table( 'logs' )}", ARRAY_A ) );
ani_check( ! str_contains( $logs, 'fake-test-api-key' ) && ! str_contains( $logs, $phone ), 'no raw phone or API key in logs' );
$privacy = new \AniSms\Privacy\Privacy( $store );
wp_update_user( array( 'ID' => $user_id, 'user_email' => 'ani-member@example.test' ) );
ani_check( 1 === count( $privacy->export( 'ani-member@example.test' )['data'] ), 'privacy export' );
$privacy->erase( 'ani-member@example.test' );
ani_check( 0 === $accounts->identity( $phone ) && get_user_by( 'id', $user_id )->exists(), 'privacy erases mapping not account' );

$store->query( "UPDATE {$store->table( 'rates' )} SET expires=1" );
$store->query( "UPDATE {$store->table( 'challenges' )} SET expires=1" );
$store->query( "UPDATE {$store->table( 'logs' )} SET created=1" );
$store->cleanup();
ani_check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$store->table( 'challenges' )}" ), 'retention cleanup' );
\AniSms\Core\Lifecycle::deactivate();
ani_check( false === wp_next_scheduled( 'ani_sms_cleanup' ), 'deactivation clears cron' );
echo 'PASS integration: ' . $GLOBALS['ani_checks'] . " checks; all SMS mocked.\n";
