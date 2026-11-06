<?php
/** Explicit loopback-only OTP testing; never an alternate SMS gateway. @package AniSms */
namespace AniSms\Security;

final class LocalTest {
	public static function requested(): bool {
		return defined( 'ANI_SMS_LOCAL_TEST' ) && true === ANI_SMS_LOCAL_TEST; }
	public static function eligible( string $environment, string $host, string $peer ): bool {
		return 'local' === $environment
			&& in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true )
			&& in_array( $peer, array( '127.0.0.1', '::1' ), true );
	}
	public static function active(): bool {
		return self::requested() && self::eligible( wp_get_environment_type(), (string) wp_parse_url( home_url(), PHP_URL_HOST ), (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}
	public static function key_id( Secrets $secrets ): string {
		return self::active() ? substr( $secrets->hash( 'local-test-key', '' ), 0, 16 ) : $secrets->id();
	}
	public static function code( Secrets $secrets, string $id, string $purpose ): string {
		if ( ! self::active() ) {
			throw new \LogicException( 'Local OTP testing is unavailable.' ); }
		return str_pad( (string) ( hexdec( substr( $secrets->hash( 'local-test-code', $id . ':' . $purpose ), 0, 8 ) ) % 1000000 ), 6, '0', STR_PAD_LEFT );
	}
}
