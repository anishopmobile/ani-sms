<?php
/** Explicit opt-in routing and safe variable mappings. @package AniSms */
namespace AniSms\WooCommerce;

final class Settings {
	public const VARIABLES = array( 'order_number', 'status', 'customer_name', 'total', 'site_name' );
	public const ROUTES    = array( 'created_customer', 'created_admin', 'status_customer', 'status_admin' );
	public static function defaults(): array {
		$values = array(
			'customer_enabled' => false,
			'admin_enabled'    => false,
			'created_enabled'  => true,
			'statuses'         => array(),
			'admin_phones'     => array(),
		);
		foreach ( self::ROUTES as $route ) {
			$values[ $route ] = array(
				'template' => 0,
				'mapping'  => array(
					'OrderNumber' => 'order_number',
					'Status'      => 'status',
				),
			);
		}
		return $values;
	}
	public static function get(): array {
		$value = get_option( 'ani_sms_wc_settings', array() );
		return array_merge( self::defaults(), is_array( $value ) ? $value : array() );
	}
	public static function fingerprint(): string {
		return hash( 'sha256', wp_json_encode( self::get() ) );
	}
	public static function generation(): string {
		$value = get_option( 'ani_sms_wc_generation', '' );
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value ) ) {
			$value = bin2hex( random_bytes( 16 ) );
			add_option( 'ani_sms_wc_generation', $value, '', false );
			$value = get_option( 'ani_sms_wc_generation' );
		}
		return $value;
	}
	public static function validate( array $input ): array {
		$out = self::defaults();
		foreach ( array( 'customer_enabled', 'admin_enabled', 'created_enabled' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! in_array( $input[ $key ], array( '0', '1' ), true ) ) {
				throw new \InvalidArgumentException( 'Invalid switch.' ); }
			$out[ $key ] = '1' === ( $input[ $key ] ?? '0' );
		}
		$statuses = $input['statuses'] ?? array();
		$allowed  = function_exists( 'wc_get_order_statuses' ) ? array_map( static fn( $key ) => substr( $key, 3 ), array_keys( wc_get_order_statuses() ) ) : array();
		if ( ! is_array( $statuses ) || count( array_filter( $statuses, 'is_string' ) ) !== count( $statuses ) || array_diff( $statuses, $allowed ) ) {
			throw new \InvalidArgumentException( 'Invalid statuses.' ); }
		$out['statuses'] = array_values( array_unique( $statuses ) );
		$phones          = $input['admin_phones'] ?? '';
		if ( ! is_string( $phones ) || strlen( $phones ) > 200 ) {
			throw new \InvalidArgumentException( 'Invalid recipients.' ); }
		foreach ( preg_split( '/[\s,]+/u', trim( $phones ), -1, PREG_SPLIT_NO_EMPTY ) as $phone ) {
			$out['admin_phones'][] = \AniSms\Support\Phone::normalize( $phone ); }
		$out['admin_phones'] = array_values( array_unique( $out['admin_phones'] ) );
		if ( count( $out['admin_phones'] ) > 5 ) {
			throw new \InvalidArgumentException( 'Too many recipients.' ); }
		foreach ( self::ROUTES as $route ) {
			$item = $input[ $route ] ?? array();
			if ( ! is_array( $item ) ) {
				throw new \InvalidArgumentException( 'Invalid route.' ); }
			$id   = filter_var( $item['template'] ?? 0, FILTER_VALIDATE_INT );
			$text = $item['mapping'] ?? '';
			if ( false === $id || $id < 0 || $id > 2147483647 || ! is_string( $text ) || strlen( $text ) > 2000 ) {
				throw new \InvalidArgumentException( 'Invalid template.' ); }
			$mapping = array();
			foreach ( preg_split( '/\R/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
				$pair = array_map( 'trim', explode( '=', $line, 2 ) );
				if ( 2 !== count( $pair ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $pair[0] ) || ! in_array( $pair[1], self::VARIABLES, true ) || isset( $mapping[ $pair[0] ] ) ) {
					throw new \InvalidArgumentException( 'Invalid mapping.' ); }
				$mapping[ $pair[0] ] = $pair[1];
			}
			if ( ! $mapping || count( $mapping ) > 10 ) {
				throw new \InvalidArgumentException( 'Empty mapping.' ); }
			$out[ $route ] = array(
				'template' => $id,
				'mapping'  => $mapping,
			);
		}
		return $out;
	}
}
