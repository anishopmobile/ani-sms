<?php
/** Optional WooCommerce CRUD integration; billing phones never become identities. @package AniSms */
namespace AniSms\WooCommerce;

use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
use AniSms\Provider\Result;
use AniSms\Security\LocalTest;
use AniSms\Security\Secrets;

final class Module implements \AniSms\Core\Module {
	public function __construct( private Database $store, private Queue $queue ) {}
	public function id(): string {
		return 'woocommerce'; }
	public function unavailable_reason(): string {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' ) ? '' : __( 'Activate WooCommerce to use order notifications. Saved settings are preserved.', 'ani-sms' );
	}
	public function boot(): void {
		$this->queue->register( $this->id(), array( $this, 'dispatch' ) );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'baseline' ) );
		add_action(
			'woocommerce_new_order',
			function ( $id ) {
				$this->capture( (int) $id, true );
			},
			20
		);
		add_action(
			'woocommerce_after_order_object_save',
			function ( $order ) {
				$this->capture( (int) $order->get_id() );
			},
			20
		);
		add_action(
			'woocommerce_order_status_changed',
			function ( $id ) {
				$this->capture( (int) $id );
			},
			20
		);
		add_action( 'woocommerce_delete_order', array( $this, 'deleted' ) );
	}
	public function baseline( $order ): void {
		if ( ! $order instanceof \WC_Order || ! $order->get_id() ) {
			return; }
		$fresh = wc_get_order( $order->get_id() );
		if ( ! $fresh instanceof \WC_Order ) {
			return; }
		try {
			$this->store->lock(
				'wc:' . $order->get_id(),
				function () use ( $fresh ) {
					$state = $this->state( $fresh->get_id() );
					if ( ! $state || Settings::generation() !== $state['generation'] ) {
						$this->save_state( $fresh->get_id(), $fresh->get_status(), 0, time() ); }
				}
			);
		} catch ( \Throwable $error ) {
			// Notification infrastructure must not abort an order save.
		}
	}

	private function state( int $id ): ?array {
		return $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'event_states' )} WHERE subject=%s", 'wc:' . $id ) );
	}
	private function save_state( int $id, string $status, int $sequence, int $created ): void {
		$this->store->query( $this->store->db->prepare( "INSERT INTO {$this->store->table( 'event_states' )} (subject,generation,sequence,status,created) VALUES (%s,%s,%d,%s,%d) ON DUPLICATE KEY UPDATE generation=VALUES(generation),sequence=VALUES(sequence),status=VALUES(status),created=VALUES(created)", 'wc:' . $id, Settings::generation(), $sequence, $status, $created ) );
	}
	public function capture( int $id, bool $created = false ): void {
		if ( ! \AniSms\Core\Settings::module_enabled( $this->id() ) || '' !== $this->unavailable_reason() ) {
			return; }
		try {
			$this->store->lock(
				'wc:' . $id,
				function () use ( $id, $created ) {
					$order = wc_get_order( $id );
					if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), array( 'auto-draft', 'checkout-draft', 'trash' ), true ) ) {
						return; }
					$this->store->transaction(
						function () use ( $id, $order, $created ) {
							$state = $this->state( $id );
							$fresh = ! $state || Settings::generation() !== $state['generation'];
							if ( $fresh && ! $created ) {
								$this->save_state( $id, $order->get_status(), 0, time() );
								return; }
							if ( ! $fresh && $state['status'] === $order->get_status() ) {
								return; }
							$is_created = $created && ( $fresh || in_array( $state['status'], array( 'checkout-draft', 'auto-draft' ), true ) );
							$sequence   = $fresh ? 1 : (int) $state['sequence'] + 1;
							$started    = $is_created ? time() : (int) $state['created'];
							$this->save_state( $id, $order->get_status(), $sequence, $started );
							$settings = Settings::get();
							$event    = $is_created ? 'created' : 'status';
							$eligible = $is_created ? $settings['created_enabled'] : in_array( $order->get_status(), $settings['statuses'], true );
							$secrets  = new Secrets();
							foreach ( array( 'customer', 'admin' ) as $audience ) {
								if ( ! $settings[ $audience . '_enabled' ] ) {
									continue; }
								$phones = $settings['admin_phones'];
								if ( 'customer' === $audience ) {
									try {
										$phones = array( \AniSms\Support\Phone::normalize( $order->get_billing_phone() ) );
									} catch ( \InvalidArgumentException $error ) {
															continue; }
								}
								foreach ( $phones as $phone ) {
									$route = $event . '_' . $audience;
									// Coalesce the first immediate transition into an unsent creation message.
									$pending = null;
									if ( ! $is_created && 2 === $sequence && time() - $started <= 30 ) {
										$pending = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'jobs' )} WHERE subject=%s AND route=%s AND sequence=1 AND state='queued' AND job_key=%s", 'wc:' . $id, 'created_' . $audience, $this->job_key( $id, 1, 'created_' . $audience, $phone, $secrets ) ) );
									}
									if ( $pending ) {
										$route = 'created_' . $audience; }
									if ( ! $pending && ! $eligible ) {
										continue; }
									$payload = $this->snapshot( $order, $route, $phone, $sequence );
									if ( $pending ) {
										$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'jobs' )} SET sequence=%d,payload=%s WHERE id=%d AND state='queued'", $sequence, $secrets->encrypt( wp_json_encode( $payload, JSON_THROW_ON_ERROR ) ), $pending['id'] ) );
									} else {
										$this->queue->enqueue(
											array(
												'job_key'  => $this->job_key( $id, $sequence, $route, $phone, $secrets ),
												'module'   => $this->id(),
												'subject'  => 'wc:' . $id,
												'sequence' => $sequence,
												'route'    => $route,
												'mode'     => LocalTest::requested() ? 'local' : 'live',
												'user_id'  => $order->get_customer_id(),
												'email_hash' => $secrets->hash( 'order-email', strtolower( $order->get_billing_email() ) ),
												'phone_mask' => \AniSms\Infrastructure\Log::mask( $phone ),
											),
											$payload
										);
									}
								}
							}
						}
					);
				}
			);
		} catch ( \Throwable $error ) {
			// Notification failure must not break checkout or expose customer data.
			try {
				( new \AniSms\Infrastructure\Log( $this->store ) )->record( 'wc_capture', 'failed', 'capture_error' ); } catch ( \Throwable $log_error ) {
				/* Checkout must remain available. */ }
		}
	}
	private function job_key( int $id, int $sequence, string $route, string $phone, Secrets $secrets ): string {
		return $secrets->hash( 'order-job', Settings::generation() . ':' . $id . ':' . $sequence . ':' . $route . ':' . $phone );
	}
	private function snapshot( \WC_Order $order, string $route, string $phone, int $sequence ): array {
		$variables  = array(
			'order_number'  => $order->get_order_number(),
			'status'        => wc_get_order_status_name( $order->get_status() ),
			'customer_name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'total'         => $order->get_formatted_order_total(),
			'site_name'     => get_bloginfo( 'name' ),
		);
		$settings   = Settings::get();
		$parameters = array();
		foreach ( $settings[ $route ]['mapping'] as $name => $variable ) {
			$value               = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $variables[ $variable ] ), ENT_QUOTES, 'UTF-8' ) );
			$parameters[ $name ] = mb_substr( '' !== trim( $value ) ? trim( $value ) : '-', 0, 200 );
		}
		return array(
			'phone'      => $phone,
			'template'   => $settings[ $route ]['template'],
			'parameters' => $parameters,
			'status'     => $order->get_status(),
			'sequence'   => $sequence,
			'generation' => Settings::generation(),
			'config'     => Settings::fingerprint(),
		);
	}
	public function dispatch( array $job, array $payload ): Result {
		$id = (int) substr( $job['subject'], 3 );
		return $this->store->lock(
			'wc:' . $id,
			function () use ( $id, $job, $payload ) {
				$order = wc_get_order( $id );
				$state = $this->state( $id );
				if ( ! \AniSms\Core\Settings::module_enabled( $this->id() ) || ! $order instanceof \WC_Order || ! $state || (int) $state['sequence'] !== $payload['sequence'] || $order->get_status() !== $payload['status'] || Settings::generation() !== $payload['generation'] || Settings::fingerprint() !== $payload['config'] ) {
					return new Result( 'cancelled', 'snapshot_changed' ); }
				if ( str_ends_with( $job['route'], '_customer' ) ) {
					try {
						$current = \AniSms\Support\Phone::normalize( $order->get_billing_phone() );
					} catch ( \InvalidArgumentException $error ) {
								return new Result( 'cancelled', 'recipient_changed' ); }
					if ( $current !== $payload['phone'] ) {
						return new Result( 'cancelled', 'recipient_changed' ); }
				}
				if ( 'local' === $job['mode'] ) {
					return new Result( LocalTest::active() ? 'simulated' : 'cancelled', LocalTest::active() ? '' : 'local_guard' ); }
				if ( LocalTest::requested() || ! defined( 'ANI_SMS_LIVE_ENABLED' ) || true !== ANI_SMS_LIVE_ENABLED ) {
					return new Result( 'cancelled', 'live_disabled' ); }
				$provider = \AniSms\Core\ProviderRegistry::sms_ir( new Secrets() );
				return $provider instanceof \AniSms\Provider\TransactionalProvider ? $provider->send_parameters( $payload['phone'], $payload['template'], $payload['parameters'] ) : new Result( 'failed', 'unsupported' );
			}
		);
	}
	public function deleted( int $id ): void {
		$this->store->lock(
			'wc:' . $id,
			function () use ( $id ) {
				foreach ( array( 'jobs', 'event_states' ) as $table ) {
					$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( $table )} WHERE subject=%s", 'wc:' . $id ) ); }
			}
		);
	}
}
