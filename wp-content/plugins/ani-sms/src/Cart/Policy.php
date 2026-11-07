<?php
/** Cancellation remains active without WooCommerce or this feature. @package AniSms */
namespace AniSms\Cart;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
final class Policy {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_action( 'ani_sms_marketing_withdrawn', array( $this, 'withdraw' ), 10, 2 );
		add_action(
			'update_option_ani_sms_modules',
			function ( $old, $next ) {
				foreach ( array( 'cart', 'marketing' )as $id ) {
					if ( is_array( $old ) && in_array( $id, $old, true ) && ( ! is_array( $next ) || ! in_array( $id, $next, true ) ) ) {
							$this->store->query( "UPDATE {$this->store->table('carts')} SET state='cancelled',optin_until=0,job_key=''" );
							$this->store->query( "UPDATE {$this->store->table('jobs')} SET state='cancelled',payload='',error_code='module_disabled' WHERE module='cart' AND state='queued'" );
							wp_clear_scheduled_hook( 'ani_sms_cart_scan' );
					}
				}
			},
			10,
			2
		);
		foreach ( array( 'woocommerce_new_order', 'woocommerce_checkout_order_processed', 'woocommerce_store_api_checkout_order_processed', 'woocommerce_payment_complete', 'woocommerce_order_status_changed' )as $hook ) {
			add_action(
				$hook,
				function ( $order ) {
					$order = $order instanceof \WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order ) : false );
					if ( $order && ! $order->has_status( array( 'auto-draft', 'draft', 'checkout-draft' ) ) ) {
						( new Service( $this->store, new Queue( $this->store ) ) )->purchased( (int) $order->get_customer_id() );}
				},
				40
			);
		}
	}
	public function withdraw( int $subscriber, bool $erase = false ): void {
		// Runs inside marketing's subscriber transaction: request rows precede jobs, as in capture.
		$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table('carts')} SET state='cancelled',optin_until=0,job_key='' WHERE subscriber_id=%d", $subscriber ) );
		$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table('jobs')} j INNER JOIN {$this->store->table('carts')} c ON j.module='cart' AND j.sequence=c.id SET j.state='cancelled',j.payload='',j.error_code='suppressed' WHERE c.subscriber_id=%d AND j.state='queued'", $subscriber ) );
		if ( $erase ) {
			$ids = $this->store->db->get_col( $this->store->db->prepare( "SELECT user_id FROM {$this->store->table('carts')} WHERE subscriber_id=%d", $subscriber ) );
			foreach ( $ids as $id ) {
				( new Privacy( $this->store ) )->remove_user( (int) $id );}
		}
	}
}
