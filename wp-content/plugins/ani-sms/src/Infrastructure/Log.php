<?php
/** @package AniSms */
namespace AniSms\Infrastructure;

final class Log {
	public function __construct( private Database $store ) {}
	public static function mask( string $phone ): string {
		return '' === $phone ? '' : substr( $phone, 0, 5 ) . '•••••' . substr( $phone, -2 ); }
	public function record( string $operation, string $state, string $error = '', string $phone = '', string $provider_id = '', string $correlation = '' ): void {
		// Only allowlisted metadata enters storage. Never accept arbitrary context or payloads.
		$data = array(
			'correlation' => preg_match( '/^[a-f0-9]{32}$/D', $correlation ) ? $correlation : bin2hex( random_bytes( 16 ) ),
			'operation'   => substr( sanitize_key( $operation ), 0, 24 ),
			'state'       => substr( sanitize_key( $state ), 0, 16 ),
			'error_code'  => substr( sanitize_key( $error ), 0, 32 ),
			'phone_mask'  => self::mask( $phone ),
			'provider_id' => preg_match( '/^[0-9]{1,64}$/D', $provider_id ) ? $provider_id : '',
			'created'     => time(),
		);
		if ( false === $this->store->insert( 'logs', $data ) ) {
			throw new \RuntimeException( 'ANI-SMS log unavailable.' ); }
	}
}
