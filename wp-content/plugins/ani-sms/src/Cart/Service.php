<?php
/** Explicit account cart intent and durable one-attempt episodes. @package AniSms */
namespace AniSms\Cart;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
use AniSms\Core\Settings;
use AniSms\Security\LocalTest;
use AniSms\Security\Secrets;
use AniSms\Provider\Result;
final class Service {
	public const VERSION = 'cart-v1';
	public function __construct( private Database $store, private Queue $queue, private $clock = null ) {
		if ( $clock && ( ! defined( 'ANI_SMS_TEST_ENV' ) || ! ANI_SMS_TEST_ENV ) ) {
			throw new \LogicException( 'Test clock only.' ); }
	}
	private function now(): int {
		return $this->clock ? (int) call_user_func( $this->clock ) : time(); }
	public static function settings(): array {
		$s = get_option( 'ani_sms_cart_settings', array() );
		return array_merge(
			array(
				'line'    => '',
				'delay'   => 3600,
				'message' => "{site_name}: {item_count} items remain in your cart.\n{cart_url}\n{unsubscribe_url}",
			),
			is_array( $s ) ? $s : array()
		);
	}
	public static function validate( array $input ): array {
		$line = $input['cart_line'] ?? '';
		if ( ! is_string( $line ) || ( '' !== $line && ! preg_match( '/^[1-9][0-9]{4,19}$/D', $line ) ) ) {
			throw new \InvalidArgumentException( 'Invalid sending line.' );}
		$text  = $input['cart_message'] ?? '';
		$delay = filter_var( $input['cart_delay'] ?? 60, FILTER_VALIDATE_INT );
		if ( ! is_string( $text ) || '' === trim( $text ) || mb_strlen( $text ) > 1000 || wp_strip_all_tags( $text ) !== $text || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $text ) || false === $delay || $delay < 15 || $delay > 8640 || strpbrk( str_replace( array( '{site_name}', '{item_count}', '{cart_url}', '{unsubscribe_url}' ), '', $text ), '{}' ) ) {
			throw new \InvalidArgumentException( 'Invalid cart settings.' ); }
		if ( ! str_contains( $text, '{unsubscribe_url}' ) ) {
			$text .= "\n{unsubscribe_url}";}
		return array(
			'line'    => $line,
			'delay'   => $delay * 60,
			'message' => trim( $text ),
		);
	}
	public function row( int $user, string $mode ): ?array {
		return $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table('carts')} WHERE user_id=%d AND mode=%s", $user, $mode ) ); }
	public static function mode(): string {
		return LocalTest::requested() ? 'local' : 'live'; }
	private function consent( int $user, string $mode ): array {
		$s        = ( new \AniSms\Marketing\Subscribers( $this->store ) )->for_user( $user );
		$identity = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table('identities')} WHERE user_id=%d", $user ) );
		if ( ! Settings::module_enabled( 'cart' ) || ! Settings::module_enabled( 'marketing' ) || ! $s || 'consented' !== $s['state'] || $mode !== $s['mode'] || \AniSms\Marketing\Subscribers::VERSION !== $s['version'] || ! $identity || ! get_user_by( 'id', $user ) || ( 'local' === $mode && ! LocalTest::active() ) || ( 'live' === $mode && ( 'live' !== $identity['verification_mode'] || 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ) ) {
			throw new \RuntimeException( 'Current consent and verified identity required.' ); }
		return $s;
	}
	public function preference( int $user, bool $enabled, string $version = self::VERSION ): void {
		$mode = self::mode();
		$s    = $enabled ? $this->consent( $user, $mode ) : null;
		if ( $enabled && ( self::VERSION !== $version || '' === get_privacy_policy_url() ) ) {
			throw new \InvalidArgumentException( 'Confirmation required.' );}
		$this->store->lock(
			'cart-user:' . $user,
			function () use ( $user, $mode, $enabled, $s ) {
				$r   = $this->row( $user, $mode );
				$now = $this->now();
				if ( ! $r && ! $enabled ) {
					return;}
				if ( ! $r ) {
					$this->store->insert(
						'carts',
						array(
							'user_id'          => $user,
							'mode'             => $mode,
							'subscriber_id'    => $s['id'],
							'consent_revision' => $s['revision'],
							'optin_until'      => $now + 30 * DAY_IN_SECONDS,
							'version'          => self::VERSION,
							'state'            => 'idle',
							'revision'         => bin2hex( random_bytes( 16 ) ),
							'fingerprint'      => '',
							'item_count'       => 0,
							'last_seen'        => $now,
							'started'          => $now,
							'last_attempt'     => 0,
							'job_key'          => '',
							'expires'          => $now + 30 * DAY_IN_SECONDS,
						)
					);
					return;}
				$this->cancel_row( $r, 'preference_changed' );
				$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table('carts')} SET optin_until=%d,subscriber_id=%d,consent_revision=%s,version=%s,expires=%d WHERE id=%d", $enabled ? $now + 30 * DAY_IN_SECONDS : 0, $s['id'] ?? $r['subscriber_id'], $s['revision'] ?? '', self::VERSION, $now + 30 * DAY_IN_SECONDS, $r['id'] ) );
			}
		);
	}
	public function snapshot( array $items ): array {
		if ( ! $items || count( $items ) > 100 ) {
			return array(
				'hash'  => '',
				'count' => 0,
			);}
		$out   = array();
		$count = 0;
		foreach ( $items as $item ) {
			$id     = absint( $item['variation_id'] ?? 0 ) ? absint( $item['variation_id'] ) : absint( $item['product_id'] ?? 0 );
			$q      = $item['quantity'] ?? 0;
			$p      = wc_get_product( $id );
			$parent = $p instanceof \WC_Product_Variation ? wc_get_product( $p->get_parent_id() ) : $p;
			if ( ! $p || ! $parent || ! $p->is_type( array( 'simple', 'variation' ) ) || 'publish' !== $p->get_status() || 'publish' !== $parent->get_status() || 'hidden' === $parent->get_catalog_visibility() || '' !== get_post_field( 'post_password', $parent->get_id() ) || ! is_numeric( $q ) || (float) $q <= 0 || (float) $q > 10000 || ! $p->is_purchasable() || ! $p->is_in_stock() || $p->is_on_backorder( (int) ceil( (float) $q ) ) || ! $p->has_enough_stock( $q ) ) {
				return array(
					'hash'  => '',
					'count' => 0,
				);}
			$attributes = $item['variation'] ?? array();
			if ( ! is_array( $attributes ) ) {
				return array(
					'hash'  => '',
					'count' => 0,
				);
			}ksort( $attributes );
			$out[]  = array( $id, (string) $q, $attributes );
			$count += (float) $q;
		}
		usort( $out, static fn( $a, $b )=>strcmp( wp_json_encode( $a ), wp_json_encode( $b ) ) );
		return array(
			'hash'  => hash( 'sha256', wp_json_encode( $out ) ),
			'count' => $count,
		);
	}
	public function persistent( int $user ): array {
		$data = get_user_meta( $user, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
		return $this->snapshot( is_array( $data ) && is_array( $data['cart'] ?? null ) ? $data['cart'] : array() );
	}
	public function activity( int $user, array $items ): void {
		if ( ! $user || ! Settings::module_enabled( 'cart' ) ) {
			return;}
		$mode = self::mode();
		$r    = $this->row( $user, $mode );
		if ( ! $r ) {
			return;}
		$this->store->lock(
			'cart-user:' . $user,
			function () use ( $user, $mode, $items ) {
				$r = $this->row( $user, $mode );
				if ( ! $r ) {
					return;
				}$now = $this->now();
				try {
					$s = $this->consent( $user, $mode );
				} catch ( \Throwable $e ) {
					$this->cancel_row( $r, 'consent_changed' );
						return;}
				if ( (int) $r['optin_until'] <= $now || $r['consent_revision'] !== $s['revision'] ) {
					$this->cancel_row( $r, 'consent_changed' );
					return;}
				$snap = $this->snapshot( $items );
				$this->cancel_row( $r, 'activity' );
				$state = $snap['hash'] ? 'waiting' : 'idle';
				$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table('carts')} SET state=%s,fingerprint=%s,item_count=%s,last_seen=%d,started=%d,revision=%s,job_key='',expires=%d WHERE id=%d", $state, $snap['hash'], (string) $snap['count'], $now, $now, bin2hex( random_bytes( 16 ) ), $now + 30 * DAY_IN_SECONDS, $r['id'] ) );
			}
		);
	}
	public function cancel_row( array $r, string $reason ): void {
		$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table('jobs')} SET state='cancelled',payload='',error_code=%s WHERE module='cart' AND sequence=%d AND state='queued'", $reason, $r['id'] ) );
		$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table('carts')} SET state='cancelled',revision=%s,job_key='' WHERE id=%d", bin2hex( random_bytes( 16 ) ), $r['id'] ) );
	}
	public function purchased( int $user ): void {
		if ( ! $user ) {
			return;
		}$this->store->lock(
			'cart-user:' . $user,
			function () use ( $user ) {
				foreach ( array( 'local', 'live' )as $mode ) {
					$r = $this->row( $user, $mode );
					if ( $r ) {
						$this->cancel_row( $r, 'purchase' );
					}
				}}
		);
	}
	private function eligible( array $r ): bool {
		$now = $this->now();
		if ( self::VERSION !== $r['version'] || (int) $r['optin_until'] <= $now || (int) $r['last_seen'] + 7 * DAY_IN_SECONDS <= $now || ( (int) $r['last_attempt'] > 0 && (int) $r['last_attempt'] + 7 * DAY_IN_SECONDS > $now ) ) {
			return false;}
		$s = $this->consent( (int) $r['user_id'], $r['mode'] );
		if ( $s['revision'] !== $r['consent_revision'] || (int) $s['id'] !== (int) $r['subscriber_id'] ) {
			return false;}
		$cart = $this->persistent( (int) $r['user_id'] );
		if ( ! $cart['hash'] || ! hash_equals( $r['fingerprint'], $cart['hash'] ) ) {
			return false;}
		$orders = wc_get_orders(
			array(
				'customer_id'  => (int) $r['user_id'],
				'type'         => 'shop_order',
				'status'       => array_keys( wc_get_order_statuses() ),
				'date_created' => '>' . ( (int) $r['started'] - 1 ),
				'limit'        => 1,
				'return'       => 'ids',
			)
		);
		return ! $orders;
	}
	private function message( array $r, array $s ): string {
		$settings = self::settings();
		$valid    = self::validate(
			array(
				'cart_line'    => $settings['line'],
				'cart_delay'   => (int) $settings['delay'] / 60,
				'cart_message' => $settings['message'],
			)
		);
		$site     = trim( html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' ) );
		$text     = strtr(
			$valid['message'],
			array(
				'{site_name}'       => $site,
				'{item_count}'      => (string) $r['item_count'],
				'{cart_url}'        => wc_get_cart_url(),
				'{unsubscribe_url}' => ( new \AniSms\Marketing\Subscribers( $this->store ) )->url( $s ),
			)
		);
		if ( mb_strlen( $text ) > 1500 ) {
			throw new \RuntimeException( 'Message too long.' );
		}return $text;
	}
	public function recover(): void {
		$this->reconcile();
		$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT * FROM {$this->store->table('carts')} WHERE state='waiting' AND last_seen<=%d ORDER BY last_seen,id LIMIT 20", $this->now() - (int) self::settings()['delay'] ), ARRAY_A );
		foreach ( $rows as $candidate ) {
			try {
				$this->store->lock(
					'cart-user:' . $candidate['user_id'],
					function () use ( $candidate ) {
						$r = $this->row( (int) $candidate['user_id'], $candidate['mode'] );
						if ( ! $r || 'waiting' !== $r['state'] || (int) $r['last_seen'] + (int) self::settings()['delay'] > $this->now() ) {
							return;}
						try {
							$ok = $this->eligible( $r );
						} catch ( \Throwable $e ) {
							$ok = false;
						}if ( ! $ok ) {
							$this->cancel_row( $r, 'ineligible' );
							return;}
						$s       = $this->consent( (int) $r['user_id'], $r['mode'] );
						$key     = ( new Secrets() )->hash( 'cart-job', $r['id'] . ':' . $r['revision'] );
						$message = $this->message( $r, $s );
						$this->store->transaction(
							function () use ( $r, $key, $message ) {
									$changed = $this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table('carts')} c SET state='queued',job_key=%s WHERE id=%d AND state='waiting' AND revision=%s AND EXISTS (SELECT 1 FROM {$this->store->table('subscribers')} s WHERE s.id=c.subscriber_id AND s.state='consented' AND s.revision=c.consent_revision AND s.mode=c.mode)", $key, $r['id'], $r['revision'] ) );
								if ( 1 !== $changed ) {
									return;}
								$this->queue->enqueue(
									array(
										'job_key'    => $key,
										'module'     => 'cart',
										'subject'    => 'cart:' . $r['user_id'],
										'sequence'   => $r['id'],
										'mode'       => $r['mode'],
										'route'      => 'abandoned',
										'user_id'    => $r['user_id'],
										'phone_mask' => '',
										'expires'    => min( time() + DAY_IN_SECONDS, (int) $r['optin_until'], (int) $r['last_seen'] + 7 * DAY_IN_SECONDS ),
									),
									array(
										'revision' => $r['revision'],
										'config'   => hash( 'sha256', wp_json_encode( self::settings() ) ),
										'message'  => $message,
									)
								);
							}
						);
					}
				);} catch ( \RuntimeException $busy ) {
				/* A later bounded scan retries storage/lock contention. */}
		}
	}
	public function dispatch( array $job, array $payload ): Result {
		$r          = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table('carts')} WHERE id=%d", $job['sequence'] ) );
		$subscriber = $r ? $this->store->row( $this->store->db->prepare( "SELECT phone_hash FROM {$this->store->table('subscribers')} WHERE id=%d", $r['subscriber_id'] ) ) : null;
		if ( ! $r || ! $subscriber ) {
			return new Result( 'cancelled', 'cart_missing' );}
		return $this->store->lock(
			'subscriber:' . $subscriber['phone_hash'],
			fn()=>$this->store->lock(
				'cart-user:' . $r['user_id'],
				function () use ( $r, $job, $payload ) {
					$current = $this->row( (int) $r['user_id'], $r['mode'] );
					if ( ! $current || 'queued' !== $current['state'] || $job['mode'] !== $current['mode'] || $job['job_key'] !== $current['job_key'] || $current['revision'] !== $payload['revision'] || (int) $current['last_seen'] + (int) self::settings()['delay'] > $this->now() ) {
					return new Result( 'cancelled', 'cart_changed' );}
					try {
							$ok = $this->eligible( $current );
							$s  = $this->consent( (int) $current['user_id'], $current['mode'] );
							} catch ( \Throwable $e ) {
					$ok = false;
					$s  = array();}
					if ( ! $ok || hash( 'sha256', wp_json_encode( self::settings() ) ) !== $payload['config'] || $this->message( $current, $s ) !== $payload['message'] ) {
					return new Result( 'cancelled', 'cart_ineligible' );}
					if ( 'live' === $job['mode'] && ( LocalTest::requested() || ! defined( 'ANI_SMS_LIVE_ENABLED' ) || true !== ANI_SMS_LIVE_ENABLED ) ) {
					return new Result( 'cancelled', 'live_disabled' );}
					$phone = ( new Secrets() )->decrypt( $s['phone'] );
					$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table('carts')} SET state='attempted',last_attempt=%d WHERE id=%d", $this->now(), $current['id'] ) );
					if ( 'local' === $job['mode'] ) {
					return new Result( LocalTest::active() ? 'simulated' : 'cancelled', LocalTest::active() ? '' : 'local_guard' );}
					$provider = \AniSms\Core\ProviderRegistry::sms_ir( new Secrets() );
					return $provider instanceof \AniSms\Provider\TextProvider ? $provider->send_text( $phone, self::settings()['line'], $payload['message'] ) : new Result( 'failed', 'unsupported' );
					}
			)
		);
	}
	public function reconcile(): void {
		$rows = $this->store->db->get_results( "SELECT c.*,j.state AS job_state,j.expires AS job_expires FROM {$this->store->table('carts')} c LEFT JOIN {$this->store->table('jobs')} j ON j.job_key=c.job_key WHERE c.state IN ('queued','attempted') LIMIT 100", ARRAY_A );
		foreach ( $rows as $r ) {
			$state = in_array( $r['job_state'], array( 'unknown', 'accepted', 'simulated', 'failed', 'cancelled' ), true ) ? $r['job_state'] : ( (int) $r['job_expires'] < time() ? 'cancelled' : '' );
			if ( $state ) {
				$this->completed(
					array(
						'sequence' => $r['id'],
						'job_key'  => $r['job_key'],
					),
					new Result( $state, '' )
				);}
		}
	}
	public function completed( array $job, Result $result ): void {
		$attempt = in_array( $result->state, array( 'unknown', 'accepted', 'simulated', 'failed' ), true ) ? $this->now() : 0;
		$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table('carts')} SET state=%s,last_attempt=GREATEST(last_attempt,%d) WHERE id=%d AND job_key=%s AND state IN ('queued','attempted')", $result->state, $attempt, $job['sequence'], $job['job_key'] ) );
	}
}
