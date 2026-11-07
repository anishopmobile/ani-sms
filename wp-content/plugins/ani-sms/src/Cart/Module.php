<?php
/** Native cart foreground and order hooks. @package AniSms */
namespace AniSms\Cart;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
final class Module implements \AniSms\Core\Module {
	public function __construct( private Database $store, private Queue $queue ) {}
	public function id(): string {
		return 'cart';}
	public function unavailable_reason(): string {
		return ! \AniSms\Core\Settings::module_enabled( 'marketing' ) ? __( 'Enable marketing consent for cart reminders.', 'ani-sms' ) : ( function_exists( 'WC' ) ? '' : __( 'Activate WooCommerce for cart reminders.', 'ani-sms' ) );
	}
	public function boot(): void {
		$s = new Service( $this->store, $this->queue );
		$this->queue->register( 'cart', array( $s, 'dispatch' ) );
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				$schedules['ani_sms_cart_minute'] = array(
					'interval' => 60,
					'display'  => 'ANI-SMS cart minute',
				);
				return $schedules;
			}
		);
		add_action( 'ani_sms_cart_scan', array( $s, 'recover' ) );
		add_action( 'ani_sms_cleanup', array( $s, 'reconcile' ), 3 );
		if ( ! wp_next_scheduled( 'ani_sms_cart_scan' ) ) {
			wp_schedule_event( time() + 60, 'ani_sms_cart_minute', 'ani_sms_cart_scan' );}
		$foreground = static function () use ( $s ) {
			if ( is_admin() || wp_doing_cron() || ! is_user_logged_in() ) {
				return;}
			try {
				$s->activity( get_current_user_id(), WC()->cart->get_cart() );} catch ( \Throwable $e ) {
							/* Never interrupt the storefront. */}
		};
		add_action( 'wp', $foreground, 30 );
		add_action( 'woocommerce_cart_updated', $foreground, 30 );
		add_action(
			'ani_sms_job_finished',
			static function ( $job, $result ) use ( $s ) {
				if ( 'cart' === $job['module'] ) {
					$s->completed( $job, $result );
				}},
			10,
			2
		);
	}
}
