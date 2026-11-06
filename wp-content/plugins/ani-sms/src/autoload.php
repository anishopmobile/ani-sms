<?php
/**
 * Small dependency-free namespace loader.
 *
 * @package AniSms
 */

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'AniSms\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $relative ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);
