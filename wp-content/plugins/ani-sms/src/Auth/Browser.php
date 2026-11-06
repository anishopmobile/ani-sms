<?php
/** Browser binding is minted by an uncached same-origin POST, never in page HTML. @package AniSms */
namespace AniSms\Auth;
use AniSms\Security\Secrets;

final class Browser {
	public function __construct( private Secrets $secrets ) {}
	public static function origin(): void {
		$origin   = $_SERVER['HTTP_ORIGIN'] ?? '';
		$home     = wp_parse_url( home_url() );
		$expected = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || $origin !== $expected || 'cross-site' === ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) ) {
			throw new \RuntimeException( 'Same-origin POST required.' ); }
		if ( ! is_ssl() && ! ( defined( 'ANI_SMS_ALLOW_LOCAL_HTTP' ) && ANI_SMS_ALLOW_LOCAL_HTTP && in_array( $home['host'], array( 'localhost', '127.0.0.1' ), true ) ) ) {
			throw new \RuntimeException( 'HTTPS required.' ); }
	}
	public function binding( bool $create = false ): string {
		$cookie = $_COOKIE['ani_sms_browser'] ?? '';
		if ( is_string( $cookie ) && preg_match( '/^([a-f0-9]{64})\.([0-9]{10})\.([a-f0-9]{64})$/D', $cookie, $matches ) && (int) $matches[2] > time() && (int) $matches[2] <= time() + 900 && hash_equals( $this->secrets->hash( 'browser', $matches[1] . '.' . $matches[2] ), $matches[3] ) ) {
			return $cookie; }
		if ( ! $create ) {
			throw new \RuntimeException( 'Browser session expired.' ); }
		$base   = bin2hex( random_bytes( 32 ) ) . '.' . ( time() + 900 );
		$cookie = $base . '.' . $this->secrets->hash( 'browser', $base );
		setcookie(
			'ani_sms_browser',
			$cookie,
			array(
				'expires'  => time() + 900,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
		return $cookie;
	}
	public function csrf( string $binding ): string {
		return $this->secrets->hash( 'csrf', $binding ); }
	public function verify( string $binding ): void {
		$token = $_SERVER['HTTP_X_ANI_CSRF'] ?? '';
		if ( ! is_string( $token ) || ! hash_equals( $this->csrf( $binding ), $token ) ) {
			throw new \RuntimeException( 'Invalid browser token.' ); }
		if ( is_user_logged_in() && ! wp_verify_nonce( $_SERVER['HTTP_X_ANI_NONCE'] ?? '', 'ani_sms_auth' ) ) {
			throw new \RuntimeException( 'Invalid account nonce.' ); }
	}
	public static function ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		// Forwarded headers are intentionally ignored. Configure the trusted proxy at the web server.
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}
}
