<?php
/** Explicit cart reminder intent and withdrawal. @package AniSms */
namespace AniSms\Cart;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
final class Frontend {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_shortcode( 'ani_sms_cart', array( $this, 'render' ) );
		add_action(
			'wp_enqueue_scripts',
			static function () {
				if ( \AniSms\Core\Settings::module_enabled( 'cart' ) && ( ( function_exists( 'is_cart' ) && is_cart() ) || ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'ani_sms_cart' ) ) ) ) {
					wp_enqueue_style( 'ani-sms-cart', plugins_url( 'assets/cart.css', dirname( __DIR__, 2 ) . '/ani-sms.php' ), array(), '1.5.0-rc1' );
				}}
		);
		add_action( 'admin_post_ani_sms_cart', array( $this, 'save' ) );
		add_action(
			'woocommerce_after_cart',
			function () {
				echo $this->render();
			}
		);
	}
	public function render(): string {
		if ( ! \AniSms\Core\Settings::module_enabled( 'cart' ) || ! function_exists( 'WC' ) ) {
			return '';}
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Sign in with a verified mobile for cart reminders.', 'ani-sms' ) . '</p>';}
		$r  = ( new Service( $this->store, new Queue( $this->store ) ) )->row( get_current_user_id(), Service::mode() );
		$on = $r && (int) $r['optin_until'] > time();
		ob_start();
		echo '<section class="ani-cart"><h2>' . esc_html__( 'Abandoned cart reminder', 'ani-sms' ) . '</h2><p>' . esc_html__( 'One reminder after inactivity, no more than once in seven days. Returning or ordering cancels pending reminders. Current marketing consent is required.', 'ani-sms' ) . '</p>';
		if ( isset( $_GET['ani_cart_saved'] ) ) {
			echo '<p role="status">' . esc_html__( 'Cart reminder preference updated.', 'ani-sms' ) . '</p>';}
		if ( $r ) {
			echo '<p class="ani-cart-state" data-state="' . esc_attr( $r['state'] ) . '">' . esc_html( self::states()[ $r['state'] ] ?? __( 'Unavailable', 'ani-sms' ) ) . '</p>';}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ani_sms_cart"><input type="hidden" name="cart_version" value="' . esc_attr( Service::VERSION ) . '">';
		wp_nonce_field( 'ani_sms_cart' );
		echo '<input type="hidden" name="operation" value="' . ( $on ? 'withdraw' : 'enable' ) . '">';
		if ( ! $on ) {
			echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Remind me once about my abandoned cart on my verified mobile.', 'ani-sms' ) . '</label>';}
		echo '<p><a href="' . esc_url( get_privacy_policy_url() ) . '">' . esc_html__( 'Privacy policy', 'ani-sms' ) . '</a></p><button>' . esc_html( $on ? __( 'Disable cart reminders', 'ani-sms' ) : __( 'Enable cart reminders', 'ani-sms' ) ) . '</button></form></section>';
		return ob_get_clean();
	}
	private static function states(): array {
		return array(
			'idle'      => __( 'No eligible cart', 'ani-sms' ),
			'waiting'   => __( 'Waiting for inactivity', 'ani-sms' ),
			'queued'    => __( 'Alert queued', 'ani-sms' ),
			'simulated' => __( 'Simulated; no SMS sent', 'ani-sms' ),
			'accepted'  => __( 'Alert accepted; delivery unconfirmed', 'ani-sms' ),
			'attempted' => __( 'Result unknown; no automatic resend', 'ani-sms' ),
			'unknown'   => __( 'Result unknown; no automatic resend', 'ani-sms' ),
			'cancelled' => __( 'Request cancelled', 'ani-sms' ),
			'failed'    => __( 'Alert failed', 'ani-sms' ),
		);}
	public function save(): void {
		try {
			\AniSms\Auth\Browser::origin();
			check_admin_referer( 'ani_sms_cart' );
			if ( ! is_user_logged_in() ) {
				throw new \RuntimeException( 'Login required.' );}
			( new \AniSms\Security\RateLimiter( $this->store, new \AniSms\Security\Secrets() ) )->hit( 'cart-pref', (string) get_current_user_id(), 30, HOUR_IN_SECONDS );
			$on = 'withdraw' !== ( $_POST['operation'] ?? '' );
			if ( $on && '1' !== ( $_POST['confirm'] ?? '' ) ) {
				throw new \InvalidArgumentException( 'Confirmation required.' );}
			( new Service( $this->store, new Queue( $this->store ) ) )->preference( get_current_user_id(), $on, is_string( $_POST['cart_version'] ?? null ) ? $_POST['cart_version'] : '' );
			wp_safe_redirect( add_query_arg( 'ani_cart_saved', '1', wp_get_referer() ? wp_get_referer() : home_url( '/' ) ), 303 );
			exit;
		} catch ( \Throwable $e ) {
			wp_die( esc_html__( 'Cart reminder could not be changed. Verify your mobile and marketing consent.', 'ani-sms' ), '', array( 'response' => 400 ) );}
	}
}
