<?php
/** Explicit verified-account requests and cancellation, with no phone field. @package AniSms */
namespace AniSms\Discount;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
final class Frontend {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_shortcode( 'ani_sms_discount', array( $this, 'render' ) );
		add_action( 'admin_post_ani_sms_discount', array( $this, 'save' ) );
		add_action(
			'woocommerce_single_product_summary',
			function () {
				if ( \AniSms\Core\Settings::module_enabled( 'discount' ) ) {
					echo $this->render();
				} },
			35
		); // Render escapes all dynamic output.
		add_action(
			'wp_enqueue_scripts',
			static function () {
				if ( \AniSms\Core\Settings::module_enabled( 'discount' ) && ( ( function_exists( 'is_product' ) && is_product() ) || ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'ani_sms_discount' ) ) ) ) {
					wp_enqueue_style( 'ani-sms-discount', plugins_url( 'assets/discount.css', dirname( __DIR__, 2 ) . '/ani-sms.php' ), array(), '1.4.0-rc1' ); }
			}
		);
	}
	public function render( array $attributes = array() ): string {
		if ( ! \AniSms\Core\Settings::module_enabled( 'discount' ) || ! function_exists( 'wc_get_product' ) ) {
			return '<p>' . esc_html__( 'Discount alerts are unavailable.', 'ani-sms' ) . '</p>'; }

		$service = new Service( $this->store, new Queue( $this->store ) );
		$id      = absint( $attributes['product_id'] ?? ( function_exists( 'is_product' ) && is_product() ? get_queried_object_id() : 0 ) );
		$options = array();
		$p       = $id ? wc_get_product( $id ) : false;
		if ( $p && $p->is_type( 'variable' ) ) {
			foreach ( array_slice( $p->get_children(), 0, 100 ) as $child ) {
				$v = $service->product( (int) $child );
				if ( $v instanceof \WC_Product_Variation && ! $v->is_on_sale( 'edit' ) ) {
					$options[ $child ] = $v->get_name() . ' — ' . wc_get_formatted_variation( $v, true, true, false ); }
			}
		} elseif ( $p && $service->product( $id ) && ! $p->is_on_sale( 'edit' ) ) {
			$options[ $id ] = $p->get_name(); }
		if ( ! is_user_logged_in() || ! $this->store->value( $this->store->db->prepare( "SELECT user_id FROM {$this->store->table( 'identities' )} WHERE user_id=%d", get_current_user_id() ) ) ) {
			if ( $id && ! $options ) {
				return ''; }
			return '<p>' . esc_html__( 'Sign in with a verified mobile to request a discount alert.', 'ani-sms' ) . '</p>'; }
		try {
			$subscriber = ( new \AniSms\Marketing\Subscribers( $this->store ) )->for_user( get_current_user_id() );
			$mode       = \AniSms\Security\LocalTest::active() ? 'local' : 'live';
			if ( ! \AniSms\Core\Settings::module_enabled( 'marketing' ) || ! $subscriber || 'consented' !== $subscriber['state'] || $mode !== $subscriber['mode'] || \AniSms\Marketing\Subscribers::VERSION !== $subscriber['version'] ) {
				throw new \RuntimeException( 'Consent required.' ); }
		} catch ( \Throwable $error ) {
			$url = Service::settings()['consent_url'];
			return '<p>' . esc_html__( 'Current marketing consent is required for discount alerts.', 'ani-sms' ) . ( '' !== $url ? ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage SMS subscription', 'ani-sms' ) . '</a>' : '' ) . '</p>';
		}
		ob_start();
		echo '<section class="ani-discount"><h2>' . esc_html__( 'Notify me about a discount', 'ani-sms' ) . '</h2><p>' . esc_html__( 'One discount alert for this exact product or variation, valid for 30 days. Current marketing consent is also required. Catalog prices can change before purchase.', 'ani-sms' ) . '</p>';
		if ( isset( $_GET['ani_discount_saved'] ) ) {
			echo '<p role="status">' . esc_html__( 'Discount alert preferences updated.', 'ani-sms' ) . '</p>'; }
		if ( $options ) {
			$this->form( 'request' );
			echo '<label>' . esc_html__( 'Exact not currently discounted product or variation', 'ani-sms' ) . '<select name="product_id" required>';
			foreach ( $options as $target => $label ) {
				echo '<option value="' . esc_attr( (string) $target ) . '">' . esc_html( wp_strip_all_tags( $label ) ) . '</option>'; }
			echo '</select></label><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Send one promotional discount SMS for this selection to my verified mobile.', 'ani-sms' ) . '</label><p><a href="' . esc_url( get_privacy_policy_url() ) . '">' . esc_html__( 'Privacy policy', 'ani-sms' ) . '</a></p><button>' . esc_html__( 'Request discount alert', 'ani-sms' ) . '</button></form>';
		}
		$where = $id ? $this->store->db->prepare( ' AND product_id IN (%d,%d)', $id, $id ) : '';
		// On a variable product include requests for its exact children, without querying posts/meta.
		if ( $p && $p->is_type( 'variable' ) ) {
			$ids   = array_map( 'absint', array_slice( $p->get_children(), 0, 100 ) );
			$where = $ids ? ' AND product_id IN (' . implode( ',', $ids ) . ')' : ' AND 1=0'; }
		$page = max( 1, absint( $_GET['discount_page'] ?? 1 ) );
		$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'discount_requests' )} WHERE user_id=%d$where ORDER BY id DESC LIMIT 20 OFFSET %d", get_current_user_id(), ( min( $page, 100000 ) - 1 ) * 20 ), ARRAY_A );
		foreach ( $rows as $row ) {
			$target = $service->product( (int) $row['product_id'] );
			echo '<div class="ani-discount-request"><p>' . esc_html( $target ? $target->get_name() : __( 'Unavailable', 'ani-sms' ) ) . ' · <span class="ani-discount-state" data-state="' . esc_attr( $row['state'] ) . '">' . esc_html( self::states()[ $row['state'] ] ?? __( 'Unavailable', 'ani-sms' ) ) . '</span></p>';
			if ( in_array( $row['state'], array( 'waiting', 'queued' ), true ) ) {
				$this->form( 'cancel' );
				echo '<input type="hidden" name="request_id" value="' . esc_attr( (string) $row['id'] ) . '"><button>' . esc_html__( 'Cancel discount alert', 'ani-sms' ) . '</button></form>'; }
			echo '</div>';
		}
		foreach ( array(
			-1 => __( 'Previous', 'ani-sms' ),
			1  => __( 'Next', 'ani-sms' ),
		) as $delta => $label ) {
			if ( $page + $delta > 0 && ( $delta < 0 || count( $rows ) === 20 ) ) {
				echo '<a href="' . esc_url( add_query_arg( 'discount_page', $page + $delta ) ) . '">' . esc_html( $label ) . '</a> '; }
		}
		echo '</section>';
		return ob_get_clean();
	}
	private static function states(): array {
		return array(
			'waiting'   => __( 'Waiting for a discount', 'ani-sms' ),
			'queued'    => __( 'Alert queued', 'ani-sms' ),
			'simulated' => __( 'Simulated; no SMS sent', 'ani-sms' ),
			'accepted'  => __( 'Alert accepted; delivery unconfirmed', 'ani-sms' ),
			'failed'    => __( 'Alert failed', 'ani-sms' ),
			'unknown'   => __( 'Result unknown; no automatic resend', 'ani-sms' ),
			'cancelled' => __( 'Request cancelled', 'ani-sms' ),
			'expired'   => __( 'Request expired', 'ani-sms' ),
		);
	}

	private function form( string $op ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ani_sms_discount"><input type="hidden" name="operation" value="' . esc_attr( $op ) . '">';
		echo '<input type="hidden" name="discount_version" value="' . esc_attr( Service::VERSION ) . '">';
		wp_nonce_field( 'ani_sms_discount' );
	}
	public function save(): void {
		try {
			\AniSms\Auth\Browser::origin();
			check_admin_referer( 'ani_sms_discount' );
			if ( ! is_user_logged_in() ) {
				throw new \RuntimeException( 'Login required.' ); }
			( new \AniSms\Security\RateLimiter( $this->store, new \AniSms\Security\Secrets() ) )->hit( 'discount-pref', (string) get_current_user_id(), 30, HOUR_IN_SECONDS );
			$service = new Service( $this->store, new Queue( $this->store ) );
			if ( 'cancel' === ( $_POST['operation'] ?? '' ) ) {
				$service->cancel( absint( $_POST['request_id'] ?? 0 ), get_current_user_id() );
			} else {
				$service->request( absint( $_POST['product_id'] ?? 0 ), get_current_user_id(), '1' === ( $_POST['confirm'] ?? '' ), is_string( $_POST['discount_version'] ?? null ) ? $_POST['discount_version'] : '' ); }
			wp_safe_redirect( add_query_arg( 'ani_discount_saved', '1', wp_get_referer() ? wp_get_referer() : home_url( '/' ) ), 303 );
			exit;
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'Discount alert could not be changed. Verify your mobile and product selection, then try again.', 'ani-sms' ), '', array( 'response' => 400 ) ); }
	}
}
