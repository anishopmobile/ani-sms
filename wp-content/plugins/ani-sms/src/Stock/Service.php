<?php
/** One-shot product/variation requests, isolated from marketing consent. @package AniSms */
namespace AniSms\Stock;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
use AniSms\Security\LocalTest;
use AniSms\Security\Secrets;
use AniSms\Provider\Result;

final class Service {
	public const VERSION = 'stock-v1';
	public function __construct( private Database $store, private Queue $queue ) {}
	public static function settings(): array {
		$s = get_option( 'ani_sms_stock_settings', array() );
		return array_merge(
			array(
				'template' => 0,
				'mapping'  => array(
					'Product' => 'product_name',
					'Link'    => 'product_url',
				),
			),
			is_array( $s ) ? $s : array()
		);
	}
	public static function validate( array $input ): array {
		$id   = filter_var( $input['stock_template'] ?? 0, FILTER_VALIDATE_INT );
		$text = $input['stock_mapping'] ?? '';
		if ( false === $id || $id < 0 || $id > 2147483647 || ! is_string( $text ) || strlen( $text ) > 2000 ) {
			throw new \InvalidArgumentException( 'Invalid template.' ); }
		$mapping = array();
		foreach ( preg_split( '/\R/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
			$pair = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( count( $pair ) !== 2 || ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $pair[0] ) || isset( $mapping[ $pair[0] ] ) || ! in_array( $pair[1], array( 'product_name', 'product_url', 'site_name' ), true ) ) {
				throw new \InvalidArgumentException( 'Invalid mapping.' ); }
			$mapping[ $pair[0] ] = $pair[1];
		}
		if ( ! $mapping || count( $mapping ) > 10 ) {
			throw new \InvalidArgumentException( 'Empty mapping.' ); }
		return array(
			'template' => $id,
			'mapping'  => $mapping,
		);
	}
	public function product( int $id ): ?\WC_Product {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null; }
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->is_type( array( 'simple', 'variation' ) ) || 'publish' !== $p->get_status() ) {
			return null; }
		$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
		if ( ! $parent || 'publish' !== $parent->get_status() || 'hidden' === $parent->get_catalog_visibility() || post_password_required( $parent->get_id() ) ) {
			return null; }
		return $p;
	}
	public function available( \WC_Product $p ): bool {
		return 'instock' === $p->get_stock_status() && ! $p->is_on_backorder( 1 ) && $p->is_purchasable();
	}
	public function request( int $product_id, int $user, bool $confirmed, string $version = self::VERSION ): int {
		if ( self::VERSION !== $version || '' === get_privacy_policy_url() || ! $confirmed || ! \AniSms\Core\Settings::module_enabled( 'stock' ) ) {
			throw new \InvalidArgumentException( 'Explicit request required.' ); }
		$local = LocalTest::active();
		if ( LocalTest::requested() && ! $local ) {
			throw new \RuntimeException( 'Invalid local context.' ); }
		$identity = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $user ) );
		if ( ! get_user_by( 'id', $user ) || ! $identity || ( ! $local && ( 'live' !== $identity['verification_mode'] || 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ) ) {
			throw new \InvalidArgumentException( 'Verified phone required.' ); }
		return $this->store->lock(
			'stock:' . $product_id,
			function () use ( $product_id, $user, $local, $identity ) {
				$p = $this->product( $product_id );
				if ( ! $p || 'outofstock' !== $p->get_stock_status() ) {
					throw new \InvalidArgumentException( 'Choose an out-of-stock product or exact variation.' ); }
				$mode = $local ? 'local' : 'live';
				$row  = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'stock_requests' )} WHERE product_id=%d AND user_id=%d AND mode=%s", $product_id, $user, $mode ) );
				if ( $row && in_array( $row['state'], array( 'waiting', 'queued' ), true ) && (int) $row['expires'] > time() ) {
					return (int) $row['id']; }
				$secrets = new Secrets();
				$data    = array(
					'product_id'  => $product_id,
					'user_id'     => $user,
					'phone'       => $secrets->encrypt( $identity['phone'] ),
					'mode'        => $mode,
					'state'       => 'waiting',
					'revision'    => bin2hex( random_bytes( 16 ) ),
					'created'     => time(),
					'expires'     => time() + 30 * DAY_IN_SECONDS,
					'job_key'     => '',
					'job_expires' => 0,
				);
				if ( $row ) {
					$this->cancel_jobs( (int) $row['id'] );
					$ok = $this->store->quiet( fn() => $this->store->db->update( $this->store->table( 'stock_requests' ), $data, array( 'id' => $row['id'] ) ) );
					if ( false === $ok ) {
						throw new \RuntimeException( 'Request update failed.' ); }
					return (int) $row['id'];
				}
				if ( false === $this->store->insert( 'stock_requests', $data ) ) {
					throw new \RuntimeException( 'Request storage failed.' ); }
				return (int) $this->store->db->insert_id;
			}
		);
	}
	private function cancel_jobs( int $id ): void {
		$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'jobs' )} SET state='cancelled',error_code='request_cancelled',payload='' WHERE module='stock' AND sequence=%d AND state='queued'", $id ) );
	}
	public function cancel( int $id, int $user ): void {
		$row = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'stock_requests' )} WHERE id=%d AND user_id=%d", $id, $user ) );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Request not found.' ); }
		$this->store->lock(
			'stock:' . $row['product_id'],
			function () use ( $id ) {
				$this->cancel_jobs( $id );
				$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'stock_requests' )} SET state='cancelled',phone='',revision=%s WHERE id=%d", bin2hex( random_bytes( 16 ) ), $id ) );
			}
		);
	}
	private function parameters( \WC_Product $p ): array {
		$url = $p->get_permalink();
		if ( mb_strlen( $url ) > 200 ) {
			$base = add_query_arg( 'p', $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id(), home_url( '/' ) );
			$url  = $p instanceof \WC_Product_Variation ? add_query_arg( $p->get_variation_attributes(), $base ) : $base;
			if ( mb_strlen( $url ) > 200 ) {
				$url = $base; }
		}
		$values = array(
			'product_name' => $p->get_name(),
			'product_url'  => $p->get_permalink(),
			'site_name'    => get_bloginfo( 'name' ),
		);
		$out    = array();
		foreach ( self::settings()['mapping'] as $name => $variable ) {
			$value = trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', html_entity_decode( wp_strip_all_tags( $values[ $variable ] ), ENT_QUOTES, 'UTF-8' ) ) );
			if ( 'product_url' === $variable && mb_strlen( $value ) > 200 ) {
				throw new \InvalidArgumentException( 'Product URL exceeds template parameter limit.' ); }
			$out[ $name ] = mb_substr( '' === $value ? '-' : $value, 0, 200 );
		}
		return $out;
	}
	public function capture( int $id ): void {
		if ( ! function_exists( 'wc_get_product' ) || ! \AniSms\Core\Settings::module_enabled( 'stock' ) ) {
			return; }
		$this->store->lock(
			'stock:' . $id,
			function () use ( $id ) {
				$p = $this->product( $id );
				if ( ! $p ) {
					$rows = $this->store->db->get_col( $this->store->db->prepare( "SELECT id FROM {$this->store->table( 'stock_requests' )} WHERE product_id=%d AND state IN ('waiting','queued') LIMIT 50", $id ) );
					foreach ( $rows as $request ) {
						$this->cancel_jobs( (int) $request );
						$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'stock_requests' )} SET state='cancelled',phone='' WHERE id=%d", $request ) ); }
					if ( count( $rows ) === 50 ) {
						$this->schedule( $id ); }
					return;
				}
				if ( ! $this->available( $p ) ) {
					// A reversal re-arms only unsent requests. Terminal outcomes never re-arm automatically.
					$rows = $this->store->db->get_col( $this->store->db->prepare( "SELECT id FROM {$this->store->table( 'stock_requests' )} WHERE product_id=%d AND state='queued' LIMIT 50", $id ) );
					foreach ( $rows as $request ) {
						$this->cancel_jobs( (int) $request );
						$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'stock_requests' )} SET state='waiting',revision=%s WHERE id=%d AND state='queued'", bin2hex( random_bytes( 16 ) ), $request ) );
					}
					if ( count( $rows ) === 50 ) {
						$this->schedule( $id ); }
					return;
				}
				$mode    = LocalTest::requested() ? 'local' : 'live';
				$rows    = $this->store->db->get_results( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'stock_requests' )} WHERE product_id=%d AND state='waiting' AND mode=%s AND expires>%d ORDER BY id LIMIT 50", $id, $mode, time() ), ARRAY_A );
				$secrets = new Secrets();
				foreach ( $rows as $row ) {
					$this->store->transaction(
						function () use ( $row, $p, $id, $secrets ) {
							$key = $secrets->hash( 'stock-job', $row['id'] . ':' . $row['revision'] );
							$this->queue->enqueue(
								array(
									'job_key'    => $key,
									'module'     => 'stock',
									'subject'    => 'stock:' . $id,
									'sequence'   => $row['id'],
									'route'      => 'restocked',
									'mode'       => $row['mode'],
									'user_id'    => $row['user_id'],
									'email_hash' => '',
									'phone_mask' => \AniSms\Infrastructure\Log::mask( $secrets->decrypt( $row['phone'] ) ),
									'expires'    => min( (int) $row['expires'], time() + DAY_IN_SECONDS ),
								),
								array(
									'revision'   => $row['revision'],
									'config'     => hash( 'sha256', wp_json_encode( self::settings() ) ),
									'parameters' => $this->parameters( $p ),
								)
							);
							$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'stock_requests' )} SET state='queued',job_key=%s,job_expires=%d WHERE id=%d AND state='waiting'", $key, min( (int) $row['expires'], time() + DAY_IN_SECONDS ), $row['id'] ) );
						}
					);
				}
				if ( count( $rows ) === 50 ) {
					$this->schedule( $id ); }
			}
		);
	}
	private function schedule( int $id ): void {
		if ( ! wp_next_scheduled( 'ani_sms_stock_scan', array( $id ) ) ) {
			wp_schedule_single_event( time() + 5, 'ani_sms_stock_scan', array( $id ) ); }
	}
	public function dispatch( array $job, array $payload ): Result {
		$id = (int) substr( $job['subject'], 6 );
		return $this->store->lock(
			'stock:' . $id,
			function () use ( $id, $job, $payload ) {
				$row = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'stock_requests' )} WHERE id=%d", $job['sequence'] ) );
				$p   = $this->product( $id );
				if ( ! \AniSms\Core\Settings::module_enabled( 'stock' ) || ! $row || 'queued' !== $row['state'] || $row['revision'] !== $payload['revision'] || self::VERSION !== $row['version'] || $row['mode'] !== $job['mode'] || (int) $row['expires'] <= time() || ! $p || ! $this->available( $p ) ) {
					return new Result( 'cancelled', 'stock_request_changed' ); }
				if ( hash( 'sha256', wp_json_encode( self::settings() ) ) !== $payload['config'] || $this->parameters( $p ) !== $payload['parameters'] ) {
					return new Result( 'cancelled', 'snapshot_changed' ); }
				$identity = $this->store->row( $this->store->db->prepare( "SELECT phone,verification_mode FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $row['user_id'] ) );
				$secrets  = new Secrets();
				$phone    = $secrets->decrypt( $row['phone'] );
				if ( ! $identity || ! get_user_by( 'id', $row['user_id'] ) || $identity['phone'] !== $phone || ( 'live' === $job['mode'] && 'live' !== $identity['verification_mode'] ) ) {
					return new Result( 'cancelled', 'identity_changed' ); }
				if ( 'local' === $job['mode'] ) {
					return new Result( LocalTest::active() ? 'simulated' : 'cancelled', LocalTest::active() ? '' : 'local_guard' ); }
				if ( LocalTest::requested() || ! defined( 'ANI_SMS_LIVE_ENABLED' ) || true !== ANI_SMS_LIVE_ENABLED ) {
					return new Result( 'cancelled', 'live_disabled' ); }
				$provider = \AniSms\Core\ProviderRegistry::sms_ir( $secrets );
				return $provider instanceof \AniSms\Provider\TransactionalProvider ? $provider->send_parameters( $phone, self::settings()['template'], $payload['parameters'] ) : new Result( 'failed', 'unsupported' );
			}
		);
	}
	public function completed( array $job, Result $result ): void {
		$id = (int) substr( $job['subject'], 6 );
		$this->store->lock(
			'stock:' . $id,
			function () use ( $job, $result ) {
				$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'stock_requests' )} SET state=%s,phone='' WHERE job_key=%s AND state='queued'", $result->state, $job['job_key'] ) );
			}
		);
	}
	public function reconcile(): void {
		$db       = $this->store->db;
		$terminal = array( 'accepted', 'simulated', 'failed', 'unknown', 'cancelled' );
		$rows     = $db->get_results( $db->prepare( "SELECT r.id,r.product_id FROM {$this->store->table( 'stock_requests' )} r LEFT JOIN {$this->store->table( 'jobs' )} j ON j.job_key=r.job_key WHERE r.state IN ('waiting','queued') AND (r.expires<%d OR (r.state='queued' AND (r.job_expires<%d OR j.state IN ('accepted','simulated','failed','unknown','cancelled')))) LIMIT 100", time(), time() ), ARRAY_A );
		$deadline = microtime( true ) + 2;
		foreach ( $rows as $candidate ) {
			if ( microtime( true ) >= $deadline ) {
				break; }
			try {
				$this->store->lock(
					'stock:' . $candidate['product_id'],
					function () use ( $candidate, $terminal, $db ) {
						$row = $this->store->row( $db->prepare( "SELECT r.*,j.state AS job_state FROM {$this->store->table( 'stock_requests' )} r LEFT JOIN {$this->store->table( 'jobs' )} j ON j.job_key=r.job_key WHERE r.id=%d", $candidate['id'] ) );
						if ( ! $row || ! in_array( $row['state'], array( 'waiting', 'queued' ), true ) ) {
							return; }
						$state = 'queued' === $row['state'] && in_array( $row['job_state'], $terminal, true ) ? $row['job_state'] : '';
						if ( '' === $state && ( (int) $row['expires'] < time() || ( 'queued' === $row['state'] && (int) $row['job_expires'] < time() ) ) ) {
							$state = 'expired'; }
						if ( '' !== $state ) {
							$this->store->query( $db->prepare( "UPDATE {$this->store->table( 'stock_requests' )} SET state=%s,phone='' WHERE id=%d", $state, $row['id'] ) ); }
					}
				);
			} catch ( \RuntimeException $busy ) {
				/* A current capture/send owns the product lock; reconcile on the next pass. */ }
		}
	}
}
