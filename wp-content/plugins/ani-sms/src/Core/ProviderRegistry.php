<?php
/** Internal registry, without user-selectable deferred providers. @package AniSms */
namespace AniSms\Core;
use AniSms\Provider\Provider;
final class ProviderRegistry {
	private array $providers = array();
	public function register( string $id, Provider $provider ): void {
		if ( ! preg_match( '/^[a-z][a-z0-9-]*$/D', $id ) || isset( $this->providers[ $id ] ) ) {
			throw new \LogicException( 'Invalid or duplicate provider.' ); }
		$this->providers[ $id ] = $provider;
	}
	public function get( string $id ): Provider {
		if ( ! isset( $this->providers[ $id ] ) ) {
			throw new \LogicException( 'Provider unavailable.' ); }
		return $this->providers[ $id ];
	}
	public static function sms_ir( \AniSms\Security\Secrets $secrets ): Provider {
		$registry = new self();
		$registry->register( 'sms-ir', new \AniSms\Provider\SmsIr( Settings::key( $secrets ) ) );
		return $registry->get( 'sms-ir' );
	}
}
