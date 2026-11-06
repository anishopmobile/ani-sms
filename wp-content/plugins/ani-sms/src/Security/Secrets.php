<?php
/** @package AniSms */
namespace AniSms\Security;

final class Secrets {
	private string $key;
	public function __construct() {
		$encoded = defined( 'ANI_SMS_SECRET_KEY' ) ? ANI_SMS_SECRET_KEY : getenv( 'ANI_SMS_SECRET_KEY' );
		$key     = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
		if ( false === $key || 32 !== strlen( $key ) || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new \RuntimeException( 'Configure ANI_SMS_SECRET_KEY (base64 of 32 random bytes) and sodium.' );
		}
		$this->key = $key;
	}
	public function hash( string $context, string $value ): string {
		return hash_hmac( 'sha256', $context . "\0" . $value, $this->key );
	}
	public function id(): string {
		return substr( $this->hash( 'key-id', '' ), 0, 16 ); }
	public function encrypt( #[\SensitiveParameter] string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key   = hex2bin( $this->hash( 'encryption-key', '' ) );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
	}
	public function decrypt( #[\SensitiveParameter] string $cipher ): string {
		$data = base64_decode( $cipher, true );
		if ( false === $data || strlen( $data ) < 40 ) {
			throw new \RuntimeException( 'Invalid stored credential.' ); }
		$plain = sodium_crypto_secretbox_open( substr( $data, 24 ), substr( $data, 0, 24 ), hex2bin( $this->hash( 'encryption-key', '' ) ) );
		if ( false === $plain ) {
			throw new \RuntimeException( 'Credential key changed. Replace the API key.' ); }
		return $plain;
	}
}
