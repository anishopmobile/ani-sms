<?php
/** One-shot exact-product promotional alerts with current marketing consent. @package AniSms */
namespace AniSms\Discount;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
use AniSms\Security\LocalTest;
use AniSms\Security\Secrets;
use AniSms\Provider\Result;

final class Service {
	public const VERSION = 'discount-v1';
	public function __construct( private Database $store, private Queue $queue ) {}
	public static function settings(): array {
		$s = get_option( 'ani_sms_discount_settings', array() );
		return array_merge(
			array(
				'line'        => '',
				'consent_url' => '',
				'message'     => "{product_name}: {regular_price} → {sale_price} ({discount_percent}%)\n{product_url}\n{unsubscribe_url}",
			),
			is_array( $s ) ? $s : array()
		);
	}
	public static function validate( array $input ): array {
		$url = $input['discount_consent_url'] ?? '';
		if ( ! is_string( $url ) || strlen( $url ) > 2000 || ( '' !== $url && ( ! str_starts_with( $url, home_url( '/' ) ) || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) || wp_parse_url( $url, PHP_URL_SCHEME ) !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ) ) {
			throw new \InvalidArgumentException( 'Same-site subscription URL required.' ); }
		$line = $input['discount_line'] ?? '';
		$text = $input['discount_message'] ?? '';
		if ( ! is_string( $line ) || strlen( $line ) > 20 || ( '' !== $line && ! preg_match( '/^[1-9][0-9]{4,19}$/D', $line ) ) || ! is_string( $text ) || '' === trim( $text ) || mb_strlen( $text ) > 1000 || wp_strip_all_tags( $text ) !== $text || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $text ) ) {
			throw new \InvalidArgumentException( 'Invalid discount text or line.' ); }
		$allowed = array( '{product_name}', '{product_url}', '{site_name}', '{regular_price}', '{sale_price}', '{discount_percent}', '{unsubscribe_url}' );
		if ( strpbrk( str_replace( $allowed, '', $text ), '{}' ) ) {
			throw new \InvalidArgumentException( 'Unknown discount variable.' ); }
		if ( ! str_contains( $text, '{unsubscribe_url}' ) ) {
			$text .= "\n" . __( 'Unsubscribe:', 'ani-sms' ) . ' {unsubscribe_url}'; }
		return array(
			'line'        => $line,
			'message'     => $text,
			'consent_url' => $url,
		);
	}
	private function consent( int $user, string $mode ): array {
		if ( ! \AniSms\Core\Settings::module_enabled( 'marketing' ) ) {
			throw new \RuntimeException( 'Marketing disabled.' ); }
		$row = ( new \AniSms\Marketing\Subscribers( $this->store ) )->for_user( $user );
		if ( ! $row || (int) $row['user_id'] !== $user || 'consented' !== $row['state'] || $mode !== $row['mode'] || \AniSms\Marketing\Subscribers::VERSION !== $row['version'] ) {
			throw new \RuntimeException( 'Current explicit marketing consent required.' ); }
		return $row;
	}
	public function offer( \WC_Product $p ): array {
		return array(
			'regular'  => (string) $p->get_regular_price( 'edit' ),
			'sale'     => (string) $p->get_sale_price( 'edit' ),
			'from'     => $p->get_date_on_sale_from( 'edit' )?->getTimestamp(),
			'to'       => $p->get_date_on_sale_to( 'edit' )?->getTimestamp(),
			'currency' => get_woocommerce_currency(),
		);
	}
	public function boundaries( \WC_Product $p ): void {
		foreach ( array( $p->get_date_on_sale_from( 'edit' ), $p->get_date_on_sale_to( 'edit' ) ) as $date ) {
			$when = $date ? $date->getTimestamp() + 1 : 0;
			if ( $when > time() && $when <= time() + 30 * DAY_IN_SECONDS && ! wp_next_scheduled( 'ani_sms_discount_boundary', array( $p->get_id(), $when ) ) ) {
				wp_schedule_single_event( $when, 'ani_sms_discount_boundary', array( $p->get_id(), $when ) ); }
		}
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
		return is_finite( (float) $p->get_regular_price( 'edit' ) ) && is_finite( (float) $p->get_sale_price( 'edit' ) ) && $p->is_on_sale( 'edit' ) && (float) $p->get_regular_price( 'edit' ) > 0 && (float) $p->get_sale_price( 'edit' ) >= 0 && (float) $p->get_price( 'edit' ) === (float) $p->get_sale_price( 'edit' ) && 'instock' === $p->get_stock_status() && ! $p->is_on_backorder( 1 ) && $p->is_purchasable();
	}
	public function request( int $product_id, int $user, bool $confirmed, string $version = self::VERSION ): int {
		if ( self::VERSION !== $version || '' === get_privacy_policy_url() || ! $confirmed || ! \AniSms\Core\Settings::module_enabled( 'discount' ) ) {
			throw new \InvalidArgumentException( 'Explicit request required.' ); }
		$local = LocalTest::active();
		if ( LocalTest::requested() && ! $local ) {
			throw new \RuntimeException( 'Invalid local context.' ); }
		$identity = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $user ) );
		if ( ! get_user_by( 'id', $user ) || ! $identity || ( ! $local && ( 'live' !== $identity['verification_mode'] || 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ) ) {
			throw new \InvalidArgumentException( 'Verified phone required.' ); }
		$subscriber = $this->consent( $user, $local ? 'local' : 'live' );
		return $this->store->lock(
			'subscriber:' . $subscriber['phone_hash'],
			fn() => $this->store->lock(
				'discount:' . $product_id,
				function () use ( $product_id, $user, $local, $identity ) {
					$p = $this->product( $product_id );
					if ( ! $p || $p->is_on_sale( 'edit' ) ) {
						throw new \InvalidArgumentException( 'Choose an not currently discounted product or exact variation.' ); }
					$mode       = $local ? 'local' : 'live';
					$subscriber = $this->consent( $user, $mode );
					$this->boundaries( $p );
					$row = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'discount_requests' )} WHERE product_id=%d AND user_id=%d AND mode=%s", $product_id, $user, $mode ) );
					if ( $row && in_array( $row['state'], array( 'waiting', 'queued' ), true ) && (int) $row['expires'] > time() ) {
						return (int) $row['id']; }
					$secrets = new Secrets();
					$data    = array(
						'product_id'       => $product_id,
						'subscriber_id'    => $subscriber['id'],
						'consent_revision' => $subscriber['revision'],
						'version'          => self::VERSION,
						'source'           => 'account_form',
						'user_id'          => $user,
						'phone'            => $secrets->encrypt( $identity['phone'] ),
						'mode'             => $mode,
						'state'            => 'waiting',
						'revision'         => bin2hex( random_bytes( 16 ) ),
						'created'          => time(),
						'expires'          => time() + 30 * DAY_IN_SECONDS,
						'job_key'          => '',
						'job_expires'      => 0,
					);
					if ( $row ) {
						$this->cancel_jobs( (int) $row['id'] );
						$ok = $this->store->quiet( fn() => $this->store->db->update( $this->store->table( 'discount_requests' ), $data, array( 'id' => $row['id'] ) ) );
						if ( false === $ok ) {
							throw new \RuntimeException( 'Request update failed.' ); }
						return (int) $row['id'];
					}
					if ( false === $this->store->insert( 'discount_requests', $data ) ) {
						throw new \RuntimeException( 'Request storage failed.' ); }
					return (int) $this->store->db->insert_id;
					}
			)
		);
	}
	private function cancel_jobs( int $id ): void {
		$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'jobs' )} SET state='cancelled',error_code='request_cancelled',payload='' WHERE module='discount' AND sequence=%d AND state='queued'", $id ) );
	}
	public function cancel( int $id, int $user ): void {
		$row = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'discount_requests' )} WHERE id=%d AND user_id=%d", $id, $user ) );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Request not found.' ); }
		$this->store->lock(
			'discount:' . $row['product_id'],
			function () use ( $id ) {
				$this->cancel_jobs( $id );
				$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'discount_requests' )} SET state='cancelled',phone='',revision=%s WHERE id=%d", bin2hex( random_bytes( 16 ) ), $id ) );
			}
		);
	}
	private function parameters( \WC_Product $p ): array {
		$regular = (float) $p->get_regular_price( 'edit' );
		$price   = (float) $p->get_sale_price( 'edit' );
		$values  = array(
			'product_name'     => $p->get_name(),
			'product_url'      => $p->get_permalink(),
			'site_name'        => get_bloginfo( 'name' ),
			'regular_price'    => wc_price( $regular ),
			'sale_price'       => wc_price( $price ),
			'discount_percent' => number_format_i18n( $regular > 0 ? round( 100 * ( $regular - $price ) / $regular, 2 ) : 0, 2 ),
		);
		foreach ( $values as $key => $value ) {
			$values[ $key ] = trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) ) ); }
		return $values;
	}
	private function message( \WC_Product $p, array $subscriber ): string {
		$values                    = $this->parameters( $p );
		$values['unsubscribe_url'] = ( new \AniSms\Marketing\Subscribers( $this->store ) )->url( $subscriber );
		$settings                  = self::settings();
		$validated                 = self::validate(
			array(
				'discount_line'        => $settings['line'],
				'discount_message'     => $settings['message'],
				'discount_consent_url' => $settings['consent_url'],
			)
		);
		$replace                   = array();
		foreach ( $values as $key => $value ) {
			$replace[ '{' . $key . '}' ] = $value; }
		$text = strtr( $validated['message'], $replace );
		if ( mb_strlen( $text ) > 1500 ) {
			throw new \RuntimeException( 'Rendered message exceeds allowed size.' ); }
		return $text;
	}
	private function rearm( array $row ): void {
		$this->cancel_jobs( (int) $row['id'] );
		$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'discount_requests' )} SET state='waiting',revision=%s,job_key='',job_expires=0 WHERE id=%d AND state='queued'", bin2hex( random_bytes( 16 ) ), $row['id'] ) );
	}
	public function capture( int $id ): void {
		if ( ! function_exists( 'wc_get_product' ) || ! \AniSms\Core\Settings::module_enabled( 'discount' ) ) {
			return; }
		$this->store->lock(
			'discount:' . $id,
			function () use ( $id ) {
				$p = $this->product( $id );
				if ( ! $p ) {
					$rows = $this->store->db->get_col( $this->store->db->prepare( "SELECT id FROM {$this->store->table( 'discount_requests' )} WHERE product_id=%d AND state IN ('waiting','queued') LIMIT 50", $id ) );
					foreach ( $rows as $request ) {
						$this->cancel_jobs( (int) $request );
						$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'discount_requests' )} SET state='cancelled',phone='' WHERE id=%d", $request ) ); }
					if ( count( $rows ) === 50 ) {
						$this->schedule( $id ); }
					return;
				}
				$this->boundaries( $p );
				$offer_key = $this->available( $p ) ? substr( hash( 'sha256', wp_json_encode( $this->offer( $p ) ) ), 0, 32 ) : str_repeat( '0', 32 );
				$previous  = $this->store->value( $this->store->db->prepare( "SELECT generation FROM {$this->store->table( 'event_states' )} WHERE subject=%s", 'discount:' . $id ) );
				$queued    = $this->store->db->get_results( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'discount_requests' )} WHERE product_id=%d AND state='queued' AND offer_key<>%s LIMIT 50", $id, $offer_key ), ARRAY_A );
				foreach ( $queued as $old ) {
					$this->rearm( $old ); }
				if ( $previous !== $offer_key ) {
					$this->store->query( $this->store->db->prepare( "INSERT INTO {$this->store->table( 'event_states' )} (subject,generation,sequence,status,created) VALUES (%s,%s,1,'sale',%d) ON DUPLICATE KEY UPDATE generation=VALUES(generation),sequence=sequence+1", 'discount:' . $id, $offer_key, time() ) ); }
				if ( count( $queued ) === 50 ) {
					$this->schedule( $id );
					return; }
				if ( ! $this->available( $p ) ) {
					// A reversal re-arms only unsent requests. Terminal outcomes never re-arm automatically.
					$rows = $this->store->db->get_col( $this->store->db->prepare( "SELECT id FROM {$this->store->table( 'discount_requests' )} WHERE product_id=%d AND state='queued' LIMIT 50", $id ) );
					foreach ( $rows as $request ) {
						$this->cancel_jobs( (int) $request );
						$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'discount_requests' )} SET state='waiting',revision=%s WHERE id=%d AND state='queued'", bin2hex( random_bytes( 16 ) ), $request ) );
					}
					if ( count( $rows ) === 50 ) {
						$this->schedule( $id ); }
					return;
				}
				$mode    = LocalTest::requested() ? 'local' : 'live';
				$rows    = $this->store->db->get_results( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'discount_requests' )} WHERE product_id=%d AND state='waiting' AND mode=%s AND expires>%d ORDER BY id LIMIT 50", $id, $mode, time() ), ARRAY_A );
				$secrets = new Secrets();
				foreach ( $rows as $row ) {
					try {
						$subscriber = $this->consent( (int) $row['user_id'], $row['mode'] );
					} catch ( \Throwable $error ) {
						$this->cancel( (int) $row['id'], (int) $row['user_id'] );
						continue; }
					if ( $subscriber['revision'] !== $row['consent_revision'] || (int) $subscriber['id'] !== (int) $row['subscriber_id'] ) {
						$this->cancel( (int) $row['id'], (int) $row['user_id'] );
						continue; }
					$this->store->transaction(
						function () use ( $row, $p, $id, $secrets, $subscriber, $offer_key ) {
							$key     = $secrets->hash( 'discount-job', $row['id'] . ':' . $row['revision'] );
							$expires = min( (int) $row['expires'], time() + DAY_IN_SECONDS );
							$changed = $this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'discount_requests' )} r SET state='queued',job_key=%s,job_expires=%d,offer_key=%s WHERE id=%d AND state='waiting' AND EXISTS (SELECT 1 FROM {$this->store->table( 'subscribers' )} s WHERE s.id=r.subscriber_id AND s.state='consented' AND s.revision=r.consent_revision AND s.mode=r.mode)", $key, $expires, $offer_key, $row['id'] ) );
							if ( 1 !== $changed ) {
								return; }
							$this->queue->enqueue(
								array(
									'job_key'    => $key,
									'module'     => 'discount',
									'subject'    => 'discount:' . $id,
									'sequence'   => $row['id'],
									'route'      => 'sale_started',
									'mode'       => $row['mode'],
									'user_id'    => $row['user_id'],
									'email_hash' => '',
									'phone_mask' => \AniSms\Infrastructure\Log::mask( $secrets->decrypt( $row['phone'] ) ),
									'expires'    => $expires,
								),
								array(
									'revision' => $row['revision'],
									'config'   => hash( 'sha256', wp_json_encode( self::settings() ) ),
									'offer'    => $this->offer( $p ),
									'message'  => $this->message( $p, $subscriber ),
								)
							);
						}
					);
				}
				if ( count( $rows ) === 50 ) {
					$this->schedule( $id ); }
			}
		);
	}
	private function schedule( int $id ): void {
		if ( ! wp_next_scheduled( 'ani_sms_discount_scan', array( $id ) ) ) {
			wp_schedule_single_event( time() + 5, 'ani_sms_discount_scan', array( $id ) ); }
	}
	public function dispatch( array $job, array $payload ): Result {
		$id     = (int) substr( $job['subject'], 9 );
		$lookup = $this->store->row( $this->store->db->prepare( "SELECT s.phone_hash FROM {$this->store->table( 'discount_requests' )} r INNER JOIN {$this->store->table( 'subscribers' )} s ON s.id=r.subscriber_id WHERE r.id=%d", $job['sequence'] ) );
		if ( ! $lookup ) {
			return new Result( 'cancelled', 'consent_missing' ); }
		return $this->store->lock(
			'subscriber:' . $lookup['phone_hash'],
			fn() => $this->store->lock(
				'discount:' . $id,
				function () use ( $id, $job, $payload ) {
					$row = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'discount_requests' )} WHERE id=%d", $job['sequence'] ) );
				if ( ! \AniSms\Core\Settings::module_enabled( 'discount' ) || ! $row || 'queued' !== $row['state'] || $row['revision'] !== $payload['revision'] || self::VERSION !== $row['version'] || $row['mode'] !== $job['mode'] || (int) $row['expires'] <= time() ) {
				return new Result( 'cancelled', 'discount_request_changed' ); }
				try {
				$subscriber = $this->consent( (int) $row['user_id'], $row['mode'] );
				} catch ( \Throwable $error ) {
				return new Result( 'cancelled', 'consent_changed' ); }
				if ( (int) $subscriber['id'] !== (int) $row['subscriber_id'] || $subscriber['revision'] !== $row['consent_revision'] ) {
				return new Result( 'cancelled', 'consent_changed' ); }
					$p = $this->product( $id );
				if ( ! $p ) {
				return new Result( 'cancelled', 'product_unavailable' ); }
				if ( ! $this->available( $p ) || $this->offer( $p ) !== $payload['offer'] ) {
				$this->rearm( $row );
				$this->schedule( $id );
				return new Result( 'cancelled', 'discount_changed' ); }
				if ( hash( 'sha256', wp_json_encode( self::settings() ) ) !== $payload['config'] || $this->message( $p, $subscriber ) !== $payload['message'] ) {
				return new Result( 'cancelled', 'snapshot_changed' ); }
					$identity = $this->store->row( $this->store->db->prepare( "SELECT phone,verification_mode FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $row['user_id'] ) );
					$secrets  = new Secrets();
				$phone        = $secrets->decrypt( $row['phone'] );
				if ( ! $identity || ! get_user_by( 'id', $row['user_id'] ) || $identity['phone'] !== $phone || ( 'live' === $job['mode'] && 'live' !== $identity['verification_mode'] ) ) {
				return new Result( 'cancelled', 'identity_changed' ); }
				if ( 'local' === $job['mode'] ) {
				return new Result( LocalTest::active() ? 'simulated' : 'cancelled', LocalTest::active() ? '' : 'local_guard' ); }
				if ( LocalTest::requested() || ! defined( 'ANI_SMS_LIVE_ENABLED' ) || true !== ANI_SMS_LIVE_ENABLED ) {
				return new Result( 'cancelled', 'live_disabled' ); }
					$provider = \AniSms\Core\ProviderRegistry::sms_ir( $secrets );
					return $provider instanceof \AniSms\Provider\TextProvider ? $provider->send_text( $phone, self::settings()['line'], $payload['message'] ) : new Result( 'failed', 'unsupported' );
				}
			)
		);
	}
	public function completed( array $job, Result $result ): void {
		$id = (int) substr( $job['subject'], 9 );
		$this->store->lock(
			'discount:' . $id,
			function () use ( $job, $result ) {
				$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'discount_requests' )} SET state=%s,phone='' WHERE job_key=%s AND state='queued'", $result->state, $job['job_key'] ) );
			}
		);
	}
	public function reconcile(): void {
		$db       = $this->store->db;
		$terminal = array( 'accepted', 'simulated', 'failed', 'unknown', 'cancelled' );
		$rows     = $db->get_results( $db->prepare( "SELECT r.id,r.product_id FROM {$this->store->table( 'discount_requests' )} r LEFT JOIN {$this->store->table( 'jobs' )} j ON j.job_key=r.job_key WHERE r.state IN ('waiting','queued') AND (r.expires<%d OR (r.state='queued' AND (r.job_expires<%d OR j.state IN ('accepted','simulated','failed','unknown','cancelled')))) LIMIT 100", time(), time() ), ARRAY_A );
		$deadline = microtime( true ) + 2;
		foreach ( $rows as $candidate ) {
			if ( microtime( true ) >= $deadline ) {
				break; }
			try {
				$this->store->lock(
					'discount:' . $candidate['product_id'],
					function () use ( $candidate, $terminal, $db ) {
						$row = $this->store->row( $db->prepare( "SELECT r.*,j.state AS job_state FROM {$this->store->table( 'discount_requests' )} r LEFT JOIN {$this->store->table( 'jobs' )} j ON j.job_key=r.job_key WHERE r.id=%d", $candidate['id'] ) );
						if ( ! $row || ! in_array( $row['state'], array( 'waiting', 'queued' ), true ) ) {
							return; }
						$state = 'queued' === $row['state'] && in_array( $row['job_state'], $terminal, true ) ? $row['job_state'] : '';
						if ( '' === $state && ( (int) $row['expires'] < time() || ( 'queued' === $row['state'] && (int) $row['job_expires'] < time() ) ) ) {
							$state = 'expired'; }
						if ( '' !== $state ) {
							$this->store->query( $db->prepare( "UPDATE {$this->store->table( 'discount_requests' )} SET state=%s,phone='' WHERE id=%d", $state, $row['id'] ) ); }
					}
				);
			} catch ( \RuntimeException $busy ) {
				/* A current capture/send owns the product lock; reconcile on the next pass. */ }
		}
	}
}
