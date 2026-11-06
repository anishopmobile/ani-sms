<?php
/** @package AniSms */
namespace AniSms\Auth;

use AniSms\Core\Settings;
use AniSms\Infrastructure\Database;
use AniSms\Provider\SmsIr;
use AniSms\Security\Secrets;
use AniSms\Security\RateLimiter;
use AniSms\Security\Limited;

final class Module implements \AniSms\Core\Module {
	public function __construct( private Database $store ) {}
	public function id(): string {
		return 'auth'; }
	public function unavailable_reason(): string {
		try {
			$secrets = new Secrets();
			$local   = \AniSms\Security\LocalTest::active();
			if ( \AniSms\Security\LocalTest::requested() && ! $local ) {
				return __( 'Local OTP testing requires a local environment, a loopback site URL and a loopback connection.', 'ani-sms' ); }
			if ( ! $local && ( '' === Settings::key( $secrets ) || Settings::get()['template'] < 1 ) ) {
				return __( 'Configure SMS.ir credentials and an approved OTP template.', 'ani-sms' ); }
			if ( ! $local && ( ! defined( 'ANI_SMS_LIVE_ENABLED' ) || ! ANI_SMS_LIVE_ENABLED ) ) {
				return __( 'SMS sending is disabled in server configuration.', 'ani-sms' ); }
			if ( ! Accounts::compatible() ) {
				return __( 'Another authentication handler requires a reviewed compatibility adapter. Use the standard WordPress login.', 'ani-sms' ); }
			if ( '' === get_privacy_policy_url() ) {
				return __( 'Publish and configure a privacy policy page before enabling mobile authentication.', 'ani-sms' ); }
			return '';
		} catch ( \Throwable $error ) {
			return __( 'Configure the server secret key and sodium encryption.', 'ani-sms' ); }
	}
	public function boot(): void {
		add_action( 'wp_ajax_ani_sms_auth', array( $this, 'endpoint' ) );
		add_action( 'wp_ajax_nopriv_ani_sms_auth', array( $this, 'endpoint' ) );
	}
	public function endpoint(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		try {
			Browser::origin();
			if ( ! Settings::enabled() || '' !== $this->unavailable_reason() ) {
				throw new \RuntimeException( 'Unavailable.' ); }
			$input = json_decode( file_get_contents( 'php://input', false, null, 0, 4097 ), true );
			if ( ! is_array( $input ) || strlen( wp_json_encode( $input ) ) > 4096 ) {
				throw new \InvalidArgumentException( 'Invalid request.' ); }
			$secrets = new Secrets();
			$browser = new Browser( $secrets );
			$op      = $input['op'] ?? '';
			$rate    = new RateLimiter( $this->store, $secrets );
			$rate->hit( 'browser-all', Browser::ip(), 180, 900 );
			if ( 'bootstrap' === $op ) {
				$binding = $browser->binding( true );
				wp_send_json_success(
					array(
						'csrf'    => $browser->csrf( $binding ),
						'nonce'   => is_user_logged_in() ? wp_create_nonce( 'ani_sms_auth' ) : '',
						'purpose' => is_user_logged_in() ? 'link' : 'login',
					)
				);
			}
			$binding = $browser->binding();
			$browser->verify( $binding );
			$purpose   = is_user_logged_in() ? 'link' : 'login';
			$actor     = get_current_user_id();
			$transport = \AniSms\Security\LocalTest::active() ? new \AniSms\Provider\LocalTestTransport() : \AniSms\Core\ProviderRegistry::sms_ir( $secrets );
			$otp       = new Otp( $this->store, $secrets, $transport, new Accounts( $this->store ) );
			if ( 'issue' === $op ) {
				if ( $actor ) {
					$user     = wp_get_current_user();
					$password = $input['password'] ?? '';
					if ( ! is_string( $password ) || ! Accounts::allowed( $user ) ) {
						throw new \InvalidArgumentException( 'Account policy declined.' ); }
					$rate->hit( 'reauth', (string) $actor, 5, 900 );
					$result = wp_authenticate( $user->user_login, $password );
					if ( is_wp_error( $result ) || $result->ID !== $actor ) {
						throw new \InvalidArgumentException( 'Account policy declined.' ); }
				}
				if ( ! is_string( $input['phone'] ?? null ) || ! is_string( $input['request'] ?? null ) ) {
					throw new \InvalidArgumentException( 'Invalid request.' ); }
				wp_send_json_success( $otp->issue( $input['phone'], $binding, Browser::ip(), $input['request'], $purpose, $actor ) );
			}
			if ( 'verify' === $op && is_string( $input['challenge'] ?? null ) && is_string( $input['code'] ?? null ) ) {
				$id = $otp->verify( $input['challenge'], self::digits( $input['code'] ), $binding, Browser::ip(), $purpose, $actor );
				if ( 'login' === $purpose ) {
					Accounts::sign_in( $id ); }
				// Never accept a redirect from a public request.
				wp_send_json_success(
					array(
						'redirect' => home_url( '/' ),
						'linked'   => 'link' === $purpose,
					)
				);
			}
			throw new \InvalidArgumentException( 'Invalid request.' );
		} catch ( Limited $error ) {
			header( 'Retry-After: ' . $error->retry_after );
			wp_send_json_error(
				array(
					'message'     => __( 'Too many requests. Wait before trying again.', 'ani-sms' ),
					'retry_after' => $error->retry_after,
				),
				429
			);
		} catch ( ChallengeRejected $error ) {
			$message = match ( $error->reason ) {
				'expired' => __( 'The code has expired. Request a new code.', 'ani-sms' ),
				'locked' => __( 'Too many incorrect codes. Request a new code when the resend timer ends.', 'ani-sms' ),
				default => __( 'The code is incorrect. Please try again.', 'ani-sms' ),
			};
			wp_send_json_error(
				array(
					'message' => $message,
					'code'    => 'otp_' . $error->reason,
				),
				400
			);
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( array( 'message' => __( 'The request or code is invalid, expired, or unavailable for this account.', 'ani-sms' ) ), 400 );
		} catch ( \Throwable $error ) {
			wp_send_json_error( array( 'message' => __( 'Authentication is unavailable. Use the standard login or try again later.', 'ani-sms' ) ), 503 );
		}
	}
	private static function digits( string $code ): string {
		return strtr( trim( $code ), array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
	}
}
