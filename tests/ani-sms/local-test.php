<?php
require __DIR__ . '/bootstrap.php';
use AniSms\Security\LocalTest;
use AniSms\Security\Secrets;
use AniSms\Infrastructure\Database;
use AniSms\Auth\Otp;
use AniSms\Auth\Accounts;
use AniSms\Core\Settings;
use AniSms\Provider\LocalTestTransport;

ani_check( ! LocalTest::requested(), 'local mode defaults off' );
define( 'ANI_SMS_LOCAL_TEST', true );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
foreach ( array( array( 'production', 'localhost', '127.0.0.1' ), array( 'staging', 'localhost', '127.0.0.1' ), array( 'local', 'example.com', '127.0.0.1' ), array( 'local', 'localhost.evil.test', '127.0.0.1' ), array( 'local', 'localhost', '192.168.1.2' ), array( 'local', 'localhost', '' ) ) as $bad ) {
	ani_check( ! LocalTest::eligible( ...$bad ), 'unsafe local context rejected' );
}
ani_check( LocalTest::eligible( 'local', 'localhost', '127.0.0.1' ) && LocalTest::eligible( 'local', '127.0.0.1', '::1' ), 'loopback contexts accepted' );
ani_check( LocalTest::active(), 'explicit test mode active in disposable loopback site' );
$secrets = new Secrets();
$store = new Database( $wpdb );
$store->migrate();
foreach ( array( 'rates', 'challenges' ) as $name ) { $store->query( "DELETE FROM {$store->table( $name )}" ); }
$phone = '+989121234590';
$store->query( $wpdb->prepare( "DELETE FROM {$store->table( 'identities' )} WHERE phone=%s", $phone ) );
update_option( 'ani_sms_settings', array_merge( Settings::defaults(), array( 'registration' => true, 'template' => 0 ) ) );
update_option( 'ani_sms_modules', array( 'auth' ) );
update_option( 'users_can_register', 1 );
$network = 0;
$interceptor = static function () use ( &$network ) { ++$network; return new WP_Error( 'unexpected_network', 'No HTTP allowed.' ); };
add_filter( 'pre_http_request', $interceptor, -1000 );
ani_check( 'local_test_enabled' === ( new \AniSms\Provider\SmsIr( 'not-a-real-key' ) )->send_template( $phone, 123, 'Code', '123456' )->error, 'real transport blocked whenever test flag is requested' );
$otp = new Otp( $store, $secrets, new LocalTestTransport(), new Accounts( $store ) );
$request = str_repeat( 'b', 32 );
$first = $otp->issue( $phone, 'local-browser', '127.0.0.1', $request );
ani_check( preg_match( '/^[0-9]{6}$/D', $first['local_test_code'] ) === 1, 'test code returned only in local issue response' );
$repeat = $otp->issue( $phone, 'local-browser', '127.0.0.1', $request );
ani_check( $repeat['local_test_code'] === $first['local_test_code'] && $repeat['challenge'] === $first['challenge'], 'network retry preserves code without plaintext storage' );
$row = $store->row( $wpdb->prepare( "SELECT * FROM {$store->table( 'challenges' )} WHERE id=%s", $first['challenge'] ) );
ani_check( $row['digest'] !== $first['local_test_code'] && $row['key_id'] !== $secrets->id(), 'digest storage and test/live separation' );
$_SERVER['REMOTE_ADDR'] = '192.168.1.2';
ani_check( ! LocalTest::active(), 'nonloopback request disables disclosure' );
ani_rejects( fn() => LocalTest::code( $secrets, $first['challenge'], 'login' ), 'no remote code disclosure' );
ani_rejects( fn() => ( new LocalTestTransport() )->send_template( $phone, 0, 'Code', '123456' ), 'transport fails closed remotely' );
ani_rejects( fn() => $otp->verify( $first['challenge'], $first['local_test_code'], 'local-browser', '127.0.0.1' ), 'test code cannot verify in live/nonlocal context' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ani_rejects( fn() => $otp->verify( $first['challenge'], $first['local_test_code'] === '000000' ? '111111' : '000000', 'local-browser', '127.0.0.1' ), 'wrong code still rejected' );
ani_rejects( fn() => $otp->issue( $phone, 'second-browser', '127.0.0.1', str_repeat( 'c', 32 ) ), 'cooldown is not bypassed' );
$user = $otp->verify( $first['challenge'], $first['local_test_code'], 'local-browser', '127.0.0.1' );
ani_check( $user > 0 && in_array( 'subscriber', get_user_by( 'id', $user )->roles, true ), 'normal local account registration' );
ani_rejects( fn() => $otp->verify( $first['challenge'], $first['local_test_code'], 'local-browser', '127.0.0.1' ), 'test OTP replay rejected' );
ani_check( $network === 0, 'zero external or loopback HTTP sends' );
$log = $store->row( "SELECT * FROM {$store->table( 'logs' )} WHERE operation='otp_local_test' ORDER BY id DESC LIMIT 1" );
ani_check( $log && '' === $log['provider_id'] && ! in_array( $first['local_test_code'], $log, true ), 'test log explicitly labeled without OTP' );
remove_filter( 'pre_http_request', $interceptor, -1000 );
echo 'PASS local test: ' . $GLOBALS['ani_checks'] . " checks; no SMS or HTTP sent.\n";
