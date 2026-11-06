<?php
/** @package AniSms */
namespace AniSms\Core;

use AniSms\Security\Secrets;

final class Settings {
	public static function defaults(): array {
		return array(
			'registration' => false,
			'expiry'       => 120,
			'cooldown'     => 60,
			'attempts'     => 5,
			'site_limit'   => 100,
			'template'     => 0,
			'parameter'    => 'Code',
			'purge'        => false,
		);
	}
	public static function get(): array {
		$saved = get_option( 'ani_sms_settings', array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}
	public static function enabled(): bool {
		$ids = get_option( 'ani_sms_modules', array() );
		return is_array( $ids ) && in_array( 'auth', $ids, true );
	}
	public static function key( Secrets $secrets ): string {
		$config = defined( 'ANI_SMS_API_KEY' ) ? ANI_SMS_API_KEY : getenv( 'ANI_SMS_API_KEY' );
		if ( is_string( $config ) && '' !== $config ) {
			return $config; }
		$cipher = get_option( 'ani_sms_credential', '' );
		return is_string( $cipher ) && '' !== $cipher ? $secrets->decrypt( $cipher ) : '';
	}
	public static function validate( array $input ): array {
		$values = self::get();
		foreach ( array(
			'expiry'     => array( 60, 300 ),
			'cooldown'   => array( 30, 180 ),
			'attempts'   => array( 3, 10 ),
			'site_limit' => array( 1, 10000 ),
			'template'   => array( 1, 2147483647 ),
		) as $name => $bounds ) {
			if ( isset( $input[ $name ] ) ) {
				$value = filter_var( $input[ $name ], FILTER_VALIDATE_INT );
				if ( false === $value || $value < $bounds[0] || $value > $bounds[1] ) {
					throw new \InvalidArgumentException( 'Invalid numeric setting: ' . $name ); }
				$values[ $name ] = $value;
			}
		}
		if ( isset( $input['parameter'] ) ) {
			if ( ! is_string( $input['parameter'] ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $input['parameter'] ) ) {
				throw new \InvalidArgumentException( 'Invalid template parameter.' ); }
			$values['parameter'] = $input['parameter'];
		}
		foreach ( array( 'registration', 'purge' ) as $name ) {
			if ( array_key_exists( $name, $input ) ) {
				if ( ! is_scalar( $input[ $name ] ) ) {
					throw new \InvalidArgumentException( 'Invalid boolean setting.' ); }
				$values[ $name ] = '1' === (string) $input[ $name ]; }
		}
		return $values;
	}
}
