<?php
/** @package AniSms */
namespace AniSms\Auth;

use AniSms\Core\Settings;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Log;
use AniSms\Provider\Provider;
use AniSms\Security\Secrets;
use AniSms\Security\RateLimiter;
use AniSms\Security\Limited;

final class Otp {
	public function __construct( private Database $store, private Secrets $secrets, private Provider $provider, private Accounts $accounts ) {
		$this->provider = new \AniSms\Infrastructure\Sender( $provider, new Log( $store ) );
	}
	public function issue( string $phone, string $binding, string $ip, string $request, string $purpose = 'login', int $actor = 0 ): array {
		$phone = \AniSms\Support\Phone::normalize( $phone );
		if ( ! in_array( $purpose, array( 'login', 'link' ), true ) || ! preg_match( '/^[a-f0-9]{32}$/D', $request ) ) {
			throw new \InvalidArgumentException( 'Invalid request.' ); }
		return $this->store->lock(
			'phone:' . $phone,
			function () use ( $phone, $binding, $ip, $request, $purpose, $actor ) {
				$db          = $this->store->db;
				$table       = $this->store->table( 'challenges' );
				$hash        = $this->secrets->hash( 'binding', $binding );
				$request_key = $this->secrets->hash( 'request', $hash . $request );
				$existing    = $this->store->row( $db->prepare( "SELECT * FROM $table WHERE request_key=%s", $request_key ) );
				if ( $existing ) {
					if ( $existing['phone'] !== $phone || $existing['purpose'] !== $purpose || (int) $existing['actor'] !== $actor ) {
						throw new \InvalidArgumentException( 'Request ID reused.' ); }
					return $this->reply( $existing );
				}
				$settings = Settings::get();
				$last     = (int) $this->store->value( $db->prepare( "SELECT MAX(created) FROM $table WHERE phone=%s", $phone ) );
				if ( $last && time() - $last < $settings['cooldown'] ) {
					throw new Limited( $settings['cooldown'] - ( time() - $last ) ); }
				$rate = new RateLimiter( $this->store, $this->secrets );
				foreach ( array( array( 'issue-phone-15', $phone, 5, 900 ), array( 'issue-phone-hour', $phone, 10, 3600 ), array( 'issue-ip', $ip, 20, 900 ), array( 'issue-site', 'site', $settings['site_limit'], 3600 ) ) as $limit ) {
					$rate->hit( ...$limit ); }
				$id   = bin2hex( random_bytes( 32 ) );
				$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
				$row  = array(
					'id'           => $id,
					'phone'        => $phone,
					'binding'      => $hash,
					'purpose'      => $purpose,
					'actor'        => $actor,
					'request_key'  => $request_key,
					'digest'       => $this->secrets->hash( 'otp', $id . ':' . $purpose . ':' . $code ),
					'key_id'       => $this->secrets->id(),
					'state'        => 'pending_send',
					'created'      => time(),
					'expires'      => time() + $settings['expiry'],
					'attempts'     => 0,
					'max_attempts' => $settings['attempts'],
				);
				if ( false === $this->store->insert( 'challenges', $row ) ) {
					throw new \RuntimeException( 'Challenge storage failed.' ); }
				// All syntactically valid requests take the same provider path to avoid a timing oracle.
				// A sent code cannot authorize an account that fails the account policy.
				$result = $this->provider->send_template( $phone, $settings['template'], $settings['parameter'], $code );
				unset( $code );
				$row['state'] = $result->accepted() && Settings::enabled() && $this->accounts->eligible( $phone, $purpose, $actor ) ? 'active' : ( $result->accepted() ? 'failed' : $result->state );
				$this->store->transaction(
					function () use ( $db, $table, &$row, $phone, $purpose ) {
						if ( $row['expires'] <= time() ) {
							$row['state'] = 'expired'; }
						if ( 'active' === $row['state'] ) {
							$this->store->query( $db->prepare( "UPDATE $table SET state='expired',digest='' WHERE phone=%s AND purpose=%s AND state='active'", $phone, $purpose ) );
						}
						$this->store->query( $db->prepare( "UPDATE $table SET state=%s WHERE id=%s AND state='pending_send'", $row['state'], $row['id'] ) );
					}
				);
				return $this->reply( $row );
			}
		);
	}
	private function reply( array $row ): array {
		return array(
			'challenge'   => $row['id'],
			'expires_in'  => max( 0, (int) $row['expires'] - time() ),
			'retry_after' => max( 0, (int) $row['created'] + Settings::get()['cooldown'] - time() ),
			'phone'       => Log::mask( $row['phone'] ),
		);
	}
	public function verify( string $id, #[\SensitiveParameter] string $code, string $binding, string $ip, string $purpose = 'login', int $actor = 0 ): int {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $id ) || ! preg_match( '/^[0-9]{6}$/D', $code ) ) {
			throw new \InvalidArgumentException( 'Invalid code.' ); }
		$db    = $this->store->db;
		$table = $this->store->table( 'challenges' );
		$rate  = new RateLimiter( $this->store, $this->secrets );
		$rate->hit( 'verify-ip', $ip, 60, 900 );
		$row = $this->store->row( $db->prepare( "SELECT * FROM $table WHERE id=%s", $id ) );
		if ( ! $row || ! hash_equals( $row['binding'], $this->secrets->hash( 'binding', $binding ) ) || $row['purpose'] !== $purpose || (int) $row['actor'] !== $actor ) {
			throw new \InvalidArgumentException( 'Invalid code.' ); }
		$rate->hit( 'verify-phone', $row['phone'], 10, 900 );
		return $this->store->lock(
			'phone:' . $row['phone'],
			function () use ( $db, $table, $row, $code, $purpose, $actor ) {
				// Transaction commits unsuccessful attempt increments as well as successful consumption.
				try {
					$outcome = $this->store->transaction(
						function () use ( $db, $table, $row, $code, $purpose, $actor ) {
							$current = $this->store->row( $db->prepare( "SELECT * FROM $table WHERE id=%s FOR UPDATE", $row['id'] ) );
							if ( ! $current || 'active' !== $current['state'] || $current['expires'] <= time() || $current['key_id'] !== $this->secrets->id() ) {
								return 0; }
							if ( ! hash_equals( $current['digest'], $this->secrets->hash( 'otp', $row['id'] . ':' . $purpose . ':' . $code ) ) ) {
								$this->store->query( $db->prepare( "UPDATE $table SET attempts=attempts+1,state=IF(attempts>=max_attempts,'locked',state) WHERE id=%s", $row['id'] ) );
								return 0;
							}
							if ( ! Settings::enabled() || ! $this->accounts->eligible( $row['phone'], $purpose, $actor ) ) {
								return 0; }
							$id = $this->accounts->complete( $row['phone'], $purpose, $actor );
							$this->store->query( $db->prepare( "UPDATE $table SET state='consumed',digest='' WHERE id=%s", $row['id'] ) );
							return $id;
						}
					);
				} finally {
					$this->accounts->clear_created_cache(); }
				if ( ! $outcome ) {
					throw new \InvalidArgumentException( 'Invalid or expired code.' ); }
				return $outcome;
			}
		);
	}
}
