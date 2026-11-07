<?php
/** @package AniSms */
namespace AniSms\Auth;

use AniSms\Core\Settings;
use AniSms\Infrastructure\Database;

final class Accounts {
	private array $created = array();
	public function __construct( private Database $store ) {}
	public function identity( string $phone ): int {
		return (int) $this->store->value( $this->store->db->prepare( "SELECT user_id FROM {$this->store->table( 'identities' )} WHERE phone=%s", $phone ) );
	}
	public static function allowed( \WP_User $user ): bool {
		foreach ( array( 'manage_options', 'edit_users', 'manage_woocommerce', 'promote_users', 'activate_plugins', 'edit_plugins' ) as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return false; }
		}
		return $user->exists() && 0 === (int) $user->user_status;
	}
	/** Unknown authentication handlers require an audited adapter; fail closed. */
	public static function compatible(): bool {
		global $wp_filter;
		$known = array( 'wp_authenticate_username_password', 'wp_authenticate_email_password', 'wp_authenticate_spam_check', 'wp_authenticate_application_password' );
		foreach ( $wp_filter['authenticate']->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! is_string( $callback['function'] ) || ! in_array( $callback['function'], $known, true ) ) {
					return false; }
			}
		}
		return defined( 'WPINC' ) && wp_normalize_path( ( new \ReflectionFunction( 'wp_authenticate' ) )->getFileName() ) === wp_normalize_path( ABSPATH . constant( 'WPINC' ) . '/pluggable.php' );
	}
	public function eligible( string $phone, string $purpose, int $actor ): bool {
		$id = $this->identity( $phone );
		if ( 'link' === $purpose ) {
			$user   = get_user_by( 'id', $actor );
			$mapped = (int) $this->store->value( $this->store->db->prepare( "SELECT COUNT(*) FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $actor ) );
			return $user && self::allowed( $user ) && 0 === $id && 0 === $mapped;
		}
		if ( $id ) {
			$user = get_user_by( 'id', $id );
			return $user && self::allowed( $user ); }
		return Settings::get()['registration'] && (bool) get_option( 'users_can_register' );
	}
	/** Called in the same InnoDB transaction as OTP consumption, under a phone lock. */
	public function complete( string $phone, string $purpose, int $actor ): int {
		if ( ! $this->eligible( $phone, $purpose, $actor ) || ! self::compatible() ) {
			throw new \RuntimeException( 'Account policy declined.' ); }
		$id = $this->identity( $phone );
		if ( $id ) {
			$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'identities' )} SET verified_at=%d,verification_mode=%s WHERE phone=%s AND user_id=%d", time(), \AniSms\Security\LocalTest::active() ? 'local' : 'live', $phone, $id ) );
			return $id; }
		if ( 'link' === $purpose ) {
			$id = $actor;
		} else {
			$id = wp_insert_user(
				array(
					'user_login'   => 'ani_' . bin2hex( random_bytes( 12 ) ),
					'user_pass'    => wp_generate_password( 48, true, true ),
					'role'         => 'subscriber',
					'display_name' => __( 'Member', 'ani-sms' ),
				)
			);
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( 'Account creation failed.' ); }
			$this->created[] = (int) $id;
		}
		try {
			$this->store->query( $this->store->db->prepare( "INSERT INTO {$this->store->table( 'identities' )} (phone,user_id,verified_at,verification_mode) VALUES (%s,%d,%d,%s)", $phone, $id, time(), \AniSms\Security\LocalTest::active() ? 'local' : 'live' ) );
		} catch ( \Throwable $error ) {
			clean_user_cache( $id );
			throw $error;
		}
		return (int) $id;
	}
	public function clear_created_cache(): void {
		foreach ( $this->created as $id ) {
			clean_user_cache( $id ); }
		$this->created = array();
	}
	public static function sign_in( int $id ): void {
		$user = get_user_by( 'id', $id );
		if ( ! $user || ! self::allowed( $user ) || ! self::compatible() ) {
			throw new \RuntimeException( 'Account policy declined.' ); }
		$checked = apply_filters( 'wp_authenticate_user', $user, '' );
		if ( ! self::matches( $checked, $id ) ) {
			throw new \RuntimeException( 'Account policy declined.' ); }
		$ticket  = bin2hex( random_bytes( 32 ) );
		$handler = static function ( $current, $login, $password ) use ( $user, $ticket ) {
			return null === $current && $login === $user->user_login && hash_equals( $ticket, $password ) ? $user : $current;
		};
		add_filter( 'authenticate', $handler, 1, 3 );
		try {
			$result = wp_authenticate( $user->user_login, $ticket );
		} finally {
			remove_filter( 'authenticate', $handler, 1 ); }
		if ( ! self::matches( $result, $id ) ) {
			throw new \RuntimeException( 'Account policy declined.' ); }
		wp_set_current_user( $id );
		wp_set_auth_cookie( $id, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
	}
	private static function matches( mixed $user, int $id ): bool {
		return $user instanceof \WP_User && $user->ID === $id; }
}
