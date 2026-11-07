<?php
/** Withdrawal remains effective with the feature or WooCommerce disabled. @package AniSms */
namespace AniSms\Discount;
use AniSms\Infrastructure\Database;
final class Policy {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_action( 'ani_sms_marketing_withdrawn', array( $this, 'withdraw' ), 10, 2 );
		add_action(
			'update_option_ani_sms_modules',
			function ( $old, $next ) {
				foreach ( array( 'discount', 'marketing' ) as $module ) {
					if ( is_array( $old ) && in_array( $module, $old, true ) && ( ! is_array( $next ) || ! in_array( $module, $next, true ) ) ) {
						$this->store->query( "UPDATE {$this->store->table( 'discount_requests' )} SET state='cancelled',phone='' WHERE state IN ('waiting','queued')" );
						$this->store->query( "UPDATE {$this->store->table( 'jobs' )} SET state='cancelled',error_code='module_disabled',payload='' WHERE module='discount' AND state='queued'" );
						foreach ( array( 'scan', 'children', 'boundary' ) as $suffix ) {
							wp_unschedule_hook( 'ani_sms_discount_' . $suffix );
						} wp_clear_scheduled_hook( 'ani_sms_discount_recovery' );
					}
				}
			},
			10,
			2
		);
	}
	public function withdraw( int $subscriber, bool $erase = false ): void {
		$requests = $this->store->table( 'discount_requests' );
		$jobs     = $this->store->table( 'jobs' );
		$this->store->query( $this->store->db->prepare( "UPDATE $requests SET state='cancelled',phone='' WHERE subscriber_id=%d AND state IN ('waiting','queued')", $subscriber ) );
		$this->store->query( $this->store->db->prepare( "UPDATE $jobs j INNER JOIN $requests r ON j.module='discount' AND j.sequence=r.id SET j.state='cancelled',j.error_code='suppressed',j.payload='' WHERE r.subscriber_id=%d AND j.state='queued'", $subscriber ) );
		if ( $erase ) {
			$this->store->query( $this->store->db->prepare( "DELETE j FROM $jobs j INNER JOIN $requests r ON j.module='discount' AND j.sequence=r.id WHERE r.subscriber_id=%d", $subscriber ) );
			$this->store->query( $this->store->db->prepare( "DELETE FROM $requests WHERE subscriber_id=%d", $subscriber ) );
		}
	}
}
