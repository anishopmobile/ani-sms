<?php
/** Product CRUD hooks and bounded recovery; no order-storage dependency. @package AniSms */
namespace AniSms\Stock;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
final class Module implements \AniSms\Core\Module {
	public function __construct( private Database $store, private Queue $queue ) {}
	public function id(): string {
		return 'stock'; }
	public function unavailable_reason(): string {
		return function_exists( 'wc_get_product' ) ? '' : __( 'Activate WooCommerce to use stock alerts. Saved settings are preserved.', 'ani-sms' ); }
	public function boot(): void {
		$service = new Service( $this->store, $this->queue );
		$this->queue->register( 'stock', array( $service, 'dispatch' ) );
		$safe = function ( $id ) use ( $service ) {
			try {
				$service->capture( (int) $id );
				$p = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : false;
				if ( $p && $p->is_type( 'variable' ) && ! wp_next_scheduled( 'ani_sms_stock_children', array( (int) $id, 0 ) ) ) {
					wp_schedule_single_event( time() + 5, 'ani_sms_stock_children', array( (int) $id, 0 ) ); }
			} catch ( \Throwable $error ) {
				try {
					( new \AniSms\Infrastructure\Log( $this->store ) )->record( 'stock_capture', 'failed', 'capture_error' ); } catch ( \Throwable $log_error ) {
					/* Never abort a product save. */ }
			}
		};
		foreach ( array( 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ) as $hook ) {
			add_action( $hook, $safe, 30 ); }
		foreach ( array( 'woocommerce_after_product_object_save', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
			add_action(
				$hook,
				static function ( $p ) use ( $safe ) {
					$safe( $p->get_id() );
				},
				30
			); }
		add_action( 'ani_sms_stock_scan', $safe );
		add_action( 'ani_sms_stock_children', array( $this, 'children' ), 10, 2 );
		add_action( 'ani_sms_stock_recovery', array( $this, 'recover' ) );
		if ( ! wp_next_scheduled( 'ani_sms_stock_recovery' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'ani_sms_stock_recovery' ); }
		add_action(
			'ani_sms_job_finished',
			function ( $job, $result ) use ( $service ) {
				if ( 'stock' === $job['module'] ) {
					$service->reconcile();
				} },
			10,
			2
		);
		add_action( 'ani_sms_cleanup', array( $service, 'reconcile' ), 3 );
		foreach ( array( 'woocommerce_delete_product', 'woocommerce_delete_product_variation' ) as $hook ) {
			add_action( $hook, array( $this, 'deleted' ) ); }
	}
	public function children( int $id, int $offset ): void {
		if ( ! \AniSms\Core\Settings::module_enabled( 'stock' ) || ! function_exists( 'wc_get_product' ) ) {
			return; }
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->is_type( 'variable' ) ) {
			return; }
		$children = $p->get_children();
		foreach ( array_slice( $children, $offset, 50 ) as $child ) {
			if ( ! wp_next_scheduled( 'ani_sms_stock_scan', array( (int) $child ) ) ) {
				wp_schedule_single_event( time() + 5, 'ani_sms_stock_scan', array( (int) $child ) ); }
		}
		if ( count( $children ) > $offset + 50 && ! wp_next_scheduled( 'ani_sms_stock_children', array( $id, $offset + 50 ) ) ) {
			wp_schedule_single_event( time() + 5, 'ani_sms_stock_children', array( $id, $offset + 50 ) ); }
	}

	public function recover(): void {
		$cursor  = (int) get_option( 'ani_sms_stock_cursor', 0 );
		$ids     = $this->store->db->get_col( $this->store->db->prepare( "SELECT DISTINCT product_id FROM {$this->store->table( 'stock_requests' )} WHERE product_id>%d AND state IN ('waiting','queued') ORDER BY product_id LIMIT 20", $cursor ) );
		$service = new Service( $this->store, $this->queue );
		foreach ( $ids as $id ) {
			$service->capture( (int) $id ); }
		update_option( 'ani_sms_stock_cursor', count( $ids ) === 20 ? (int) end( $ids ) : 0, false );
		$service->reconcile();
	}
	public function deleted( int $id ): void {
		$this->store->lock(
			'stock:' . $id,
			function () use ( $id ) {
				$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'jobs' )} WHERE module='stock' AND subject=%s", 'stock:' . $id ) );
				$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'stock_requests' )} WHERE product_id=%d", $id ) );
			}
		);
	}
}
