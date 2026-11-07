<?php
/** Explicit consent belongs to a verified identity, never to an order/import. @package AniSms */
namespace AniSms\Marketing;

use AniSms\Infrastructure\Database;
use AniSms\Security\Secrets;
use AniSms\Support\Phone;

final class Subscribers {
	public const VERSION = '2026-11-v1';
	public function __construct( private Database $store ) {
		$id = ( new Secrets() )->id();
		add_option( 'ani_sms_marketing_key_id', $id, '', false );
		if ( get_option( 'ani_sms_marketing_key_id' ) !== $id ) {
			throw new \RuntimeException( 'Marketing key migration required.' ); }
	}
	public function for_user( int $id ): ?array {
		$phone = $this->store->value( $this->store->db->prepare( "SELECT phone FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $id ) );
		return is_string( $phone ) ? $this->by_phone( $phone ) : null;
	}
	private function by_phone( string $phone ): ?array {
		return $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'subscribers' )} WHERE phone_hash=%s", ( new Secrets() )->hash( 'subscriber-phone', Phone::normalize( $phone ) ) ) );
	}
	/** Import is deliberately pending-only, including when a suppressed row already exists. */
	public function import( string $phone, string $tag ): int {
		$phone = Phone::normalize( $phone );
		if ( ! preg_match( '/^[a-z0-9_-]{0,40}$/D', $tag ) ) {
			throw new \InvalidArgumentException( 'Invalid tag.' ); }
		$secrets = new Secrets();
		return $this->store->lock(
			'subscriber:' . $secrets->hash( 'subscriber-phone', $phone ),
			function () use ( $phone, $tag, $secrets ) {
				$row = $this->by_phone( $phone );
				if ( $row ) {
					return (int) $row['id']; }
				if ( false === $this->store->insert(
					'subscribers',
					array(
						'phone_hash' => $secrets->hash( 'subscriber-phone', $phone ),
						'phone'      => $secrets->encrypt( $phone ),
						'state'      => 'pending',
						'source'     => 'admin_import',
						'version'    => '',
						'revision'   => bin2hex( random_bytes( 16 ) ),
						'tag'        => $tag,
						'token'      => '',
						'updated'    => time(),
					)
				) ) {
					throw new \RuntimeException( 'Subscriber storage failed.' ); }
				return (int) $this->store->db->insert_id;
			}
		);
	}
	public function consent( int $user_id, bool $explicit, string $version ): int {
		if ( ! $explicit || self::VERSION !== $version || ! \AniSms\Core\Settings::module_enabled( 'marketing' ) || '' === get_privacy_policy_url() ) {
			throw new \InvalidArgumentException( 'Explicit current consent required.' ); }
		$phone = $this->store->value( $this->store->db->prepare( "SELECT phone FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $user_id ) );
		if ( ! is_string( $phone ) || ! get_user_by( 'id', $user_id ) ) {
			throw new \InvalidArgumentException( 'Verified identity required.' ); }
		$local = \AniSms\Security\LocalTest::active();
		if ( \AniSms\Security\LocalTest::requested() && ! $local ) {
			throw new \RuntimeException( 'Local consent guard.' ); }
		$mode       = $local ? 'local' : 'live';
		$proof_mode = $this->store->value( $this->store->db->prepare( "SELECT verification_mode FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $user_id ) );
		if ( ! $local && ( 'live' !== $proof_mode || 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ) {
			throw new \RuntimeException( 'Live phone verification required.' ); }
		$id      = $this->import( $phone, '' );
		$secrets = new Secrets();
		return $this->store->lock(
			'subscriber:' . $secrets->hash( 'subscriber-phone', $phone ),
			function () use ( $id, $phone, $user_id, $secrets, $mode ) {
				return $this->store->transaction(
					function () use ( $id, $phone, $user_id, $secrets, $mode ) {
						$row   = $this->by_phone( $phone );
						$owner = $this->store->value( $this->store->db->prepare( "SELECT user_id FROM {$this->store->table( 'identities' )} WHERE phone=%s", $phone ) );
						if ( (int) $owner !== $user_id ) {
							throw new \RuntimeException( 'Identity changed.' ); }
						if ( 'consented' === $row['state'] && (int) $row['user_id'] === $user_id && self::VERSION === $row['version'] && $mode === $row['mode'] ) {
							return $id; }
						$token = bin2hex( random_bytes( 32 ) );
						$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'subscribers' )} SET phone=%s,user_id=%d,mode=%s,state='consented',source='account_form',version=%s,revision=%s,token=%s,token_hash=%s,updated=%d WHERE id=%d", $secrets->encrypt( $phone ), $user_id, $mode, self::VERSION, bin2hex( random_bytes( 16 ) ), $secrets->encrypt( $token ), $secrets->hash( 'unsubscribe', $token ), time(), $id ) );
						$this->evidence( $id, 'granted', 'account_form_' . $mode );
						return $id;
					}
				);
			}
		);
	}
	public function unsubscribe( string $token ): void {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			return; }
		$row = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'subscribers' )} WHERE token_hash=%s", ( new Secrets() )->hash( 'unsubscribe', $token ) ) );
		if ( $row ) {
			$this->store->lock(
				'subscriber:' . $row['phone_hash'],
				function () use ( $row, $token ) {
					$current = $this->store->row( $this->store->db->prepare( "SELECT token_hash FROM {$this->store->table( 'subscribers' )} WHERE id=%d", $row['id'] ) );
					if ( $current && hash_equals( $current['token_hash'], ( new Secrets() )->hash( 'unsubscribe', $token ) ) ) {
						$this->suppress( (int) $row['id'], false ); }
				}
			);
		}
	}
	public function suppress( int $id, bool $erase, string $source = 'unsubscribe' ): void {
		$row = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'subscribers' )} WHERE id=%d", $id ) );
		if ( ! $row ) {
			return; }
		$this->store->lock(
			'subscriber:' . $row['phone_hash'],
			function () use ( $id, $erase, $source ) {
				$this->store->transaction(
					function () use ( $id, $erase, $source ) {
						$db            = $this->store->db;
						$current_state = $this->store->value( $db->prepare( "SELECT state FROM {$this->store->table( 'subscribers' )} WHERE id=%d", $id ) );
						if ( ! $erase && 'suppressed' === $current_state ) {
							return; }
						$this->store->query( $db->prepare( "UPDATE {$this->store->table( 'subscribers' )} SET state='suppressed',revision=%s,updated=%d WHERE id=%d", bin2hex( random_bytes( 16 ) ), time(), $id ) );
						$this->store->query( $db->prepare( "UPDATE {$this->store->table( 'jobs' )} SET state='cancelled',error_code='suppressed',payload='' WHERE module='marketing' AND sequence=%d AND state IN ('queued','paused')", $id ) );
						$this->store->query( $db->prepare( "UPDATE {$this->store->table( 'campaign_recipients' )} SET state='cancelled' WHERE subscriber_id=%d AND state IN ('queued','paused')", $id ) );
						if ( $erase ) {
								$this->store->query( $db->prepare( "UPDATE {$this->store->table( 'subscribers' )} SET phone='',user_id=0,mode='',source='privacy',version='',tag='',token='',token_hash='' WHERE id=%d", $id ) );
							foreach ( array( 'consents', 'campaign_recipients' ) as $table ) {
								$this->store->query( $db->prepare( "DELETE FROM {$this->store->table( $table )} WHERE subscriber_id=%d", $id ) ); }
							$this->store->query( $db->prepare( "DELETE FROM {$this->store->table( 'jobs' )} WHERE module='marketing' AND sequence=%d", $id ) );
						} else {
							$this->evidence( $id, 'withdrawn', $source ); }
					}
				);
			}
		);
	}
	private function evidence( int $id, string $action, string $source ): void {
		if ( false === $this->store->insert(
			'consents',
			array(
				'subscriber_id' => $id,
				'action'        => $action,
				'source'        => $source,
				'version'       => self::VERSION,
				'created'       => time(),
			)
		) ) {
			throw new \RuntimeException( 'Consent storage failed.' ); }
	}
	public function tag( int $id, string $tag ): void {
		if ( ! preg_match( '/^[a-z0-9_-]{0,40}$/D', $tag ) ) {
			throw new \InvalidArgumentException( 'Invalid tag.' ); }
		$row = $this->store->row( $this->store->db->prepare( "SELECT phone_hash FROM {$this->store->table( 'subscribers' )} WHERE id=%d", $id ) );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Subscriber missing.' ); }
		$this->store->lock(
			'subscriber:' . $row['phone_hash'],
			function () use ( $id, $tag ) {
				$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'subscribers' )} SET tag=%s,revision=%s WHERE id=%d", $tag, bin2hex( random_bytes( 16 ) ), $id ) );
			}
		);
	}

	public function url( array $subscriber ): string {
		return add_query_arg( 'ani_sms_unsubscribe', ( new Secrets() )->decrypt( $subscriber['token'] ), home_url( '/' ) );
	}
}
