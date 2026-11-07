<?php
/** Durable, bounded, at-most-once dispatch after an ambiguous provider result. @package AniSms */
namespace AniSms\Infrastructure;

use AniSms\Core\Settings;
use AniSms\Provider\Result;
use AniSms\Security\LocalTest;
use AniSms\Security\Secrets;

final class Queue {
	private array $handlers = array();
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_action( 'ani_sms_dispatch', array( $this, 'drain' ) );
		if ( ! wp_next_scheduled( 'ani_sms_dispatch' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'ani_sms_dispatch' );
		}
	}
	public function register( string $module, callable $handler ): void {
		$this->handlers[ $module ] = $handler;
	}
	public function enqueue( array $row, array $payload ): void {
		$row           += array(
			'state'     => 'queued',
			'created'   => time(),
			'available' => time() + 5,
			'expires'   => time() + DAY_IN_SECONDS,
		);
		$row['payload'] = ( new Secrets() )->encrypt( wp_json_encode( $payload, JSON_THROW_ON_ERROR ) );
		if ( false === $this->store->insert( 'jobs', $row ) ) {
			$existing = $this->store->value( $this->store->db->prepare( "SELECT id FROM {$this->store->table( 'jobs' )} WHERE job_key=%s", $row['job_key'] ) );
			if ( ! $existing ) {
				throw new \RuntimeException( 'Queue storage unavailable.' );
			}
		}
		if ( ! wp_next_scheduled( 'ani_sms_dispatch_soon' ) ) {
			wp_schedule_single_event( time() + 5, 'ani_sms_dispatch_soon' );
		}
	}
	public function drain(): void {
		$this->store->lock(
			'queue-runner',
			function () {
				$db    = $this->store->db;
				$table = $this->store->table( 'jobs' );
				// A worker may have died after the provider accepted its POST. Never retry its lease.
				$this->store->query( $db->prepare( "UPDATE $table SET state='unknown',error_code='worker_interrupted',payload='' WHERE state='processing' AND lease < %d", time() ) );
				$this->store->query( $db->prepare( "UPDATE $table SET state='cancelled',error_code='expired',payload='' WHERE state IN ('queued','paused') AND expires < %d", time() ) );
				$rows     = $db->get_results( $db->prepare( "SELECT * FROM $table WHERE state='queued' AND available <= %d ORDER BY id LIMIT 10", time() ), ARRAY_A );
				$deadline = microtime( true ) + 20;
				foreach ( $rows as $row ) {
					if ( microtime( true ) >= $deadline ) {
						break; }
					// Local jobs cannot become real sends after configuration or environment changes.
					if ( ! Settings::module_enabled( $row['module'] ) ) {
						$this->finish( $row, new Result( 'cancelled', 'module_disabled' ) );
						continue;
					}
					if ( ! isset( $this->handlers[ $row['module'] ] ) || ( 'local' === $row['mode'] && ! LocalTest::active() ) || ( 'live' === $row['mode'] && LocalTest::requested() ) ) {
						continue;
					}
					$claimed = $this->store->query( $db->prepare( "UPDATE $table SET state='processing',lease=%d WHERE id=%d AND state='queued'", time() + 60, $row['id'] ) );
					if ( 1 !== $claimed ) {
						continue; }
					try {
						$payload = json_decode( ( new Secrets() )->decrypt( $row['payload'] ), true, 32, JSON_THROW_ON_ERROR );
						$result  = ( $this->handlers[ $row['module'] ] )( $row, $payload );
					} catch ( \Throwable $error ) {
						$result = new Result( 'unknown', 'worker_error' );
					}
					$this->finish( $row, $result );
				}
				if ( (int) $this->store->value( "SELECT COUNT(*) FROM $table WHERE state='queued'" ) > 0 && ! wp_next_scheduled( 'ani_sms_dispatch_soon' ) ) {
					wp_schedule_single_event( time() + 60, 'ani_sms_dispatch_soon' );
				}
			}
		);
	}
	private function finish( array $row, Result $result ): void {
		if ( 'deferred' === $result->state ) {
			$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'jobs' )} SET state='queued',available=%d,lease=0 WHERE id=%d", time() + 60, $row['id'] ) );
			return;
		}
		$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'jobs' )} SET state=%s,error_code=%s,payload=%s,lease=0 WHERE id=%d", $result->state, $result->error, 'simulated' === $result->state ? $row['payload'] : '', $row['id'] ) );
		do_action( 'ani_sms_job_finished', $row, $result );
		$operation = array(
			'marketing' => 'campaign',
			'stock'     => 'stock_notification',
			'discount'  => 'discount_notification',
			'cart'      => 'cart_reminder',
		)[ $row['module'] ] ?? 'wc_notification';
		( new Log( $this->store ) )->record(
			'local' === $row['mode'] ? ( array(
				'marketing' => 'campaign_local_test',
				'stock'     => 'stock_local_test',
				'discount'  => 'discount_local_test',
				'cart'      => 'cart_local_test',
			)[ $row['module'] ] ?? 'wc_local_test' ) : $operation,
			$result->state,
			$result->error,
			provider_id: $result->message_id,
			correlation: $result->correlation
		);
	}
}
