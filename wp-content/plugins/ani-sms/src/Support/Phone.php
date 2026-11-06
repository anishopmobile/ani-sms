<?php
/**
 * Iranian mobile phone normalization, independent of WordPress.
 *
 * @package AniSms
 */

namespace AniSms\Support;

final class Phone {
	public static function normalize( string $input ): string {
		if ( strlen( $input ) > 128 ) {
			throw new \InvalidArgumentException( 'Invalid Iranian mobile number.' );
		}
		$input = strtr(
			$input,
			array_combine(
				preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ),
				str_split( '01234567890123456789' )
			)
		);
		$input = preg_replace( '/[\s()\-]+/u', '', $input );
		if ( null === $input ) {
			throw new \InvalidArgumentException( 'Invalid Iranian mobile number.' );
		}
		if ( preg_match( '/^09[0-9]{9}$/D', $input ) ) {
			return '+98' . substr( $input, 1 );
		}
		if ( preg_match( '/^00989[0-9]{9}$/D', $input ) ) {
			return '+' . substr( $input, 2 );
		}
		if ( preg_match( '/^\+989[0-9]{9}$/D', $input ) ) {
			return $input;
		}
		throw new \InvalidArgumentException( 'Invalid Iranian mobile number.' );
	}
}
