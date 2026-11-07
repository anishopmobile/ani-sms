<?php
/** Marketing-line settings and one-shot discount diagnostics. @package AniSms */
namespace AniSms\Discount;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
final class Admin {
	public function __construct( private Database $store, private array $pending = array() ) {}
	public function render(): void {
		( new Service( $this->store, new Queue( $this->store ) ) )->reconcile();
		echo '<p>' . esc_html__( 'Discount alerts need current marketing consent and a separate request for the exact product or variation. One alert per request, valid for 30 days. No price or stock is reserved.', 'ani-sms' ) . '</p><p>' . esc_html__( 'Live discount messages use a permitted SMS.ir marketing line, never an OTP template. Local simulation needs no API or sending line.', 'ani-sms' ) . '</p>';
		$reason = ( new Module( $this->store, new Queue( $this->store ) ) )->unavailable_reason();
		if ( '' !== $reason ) {
			echo '<p class="ani-feedback">' . esc_html( $reason ) . '</p>'; }
		$s = Service::settings();
		echo '<form method="post" class="ani-settings ani-order-settings">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="save_discount">';
		foreach ( array(
			'line'        => __( 'Permitted sending line (blank for local simulation)', 'ani-sms' ),
			'consent_url' => __( 'Subscription preferences page URL (same site)', 'ani-sms' ),
		) as $key => $label ) {
			$name  = 'discount_' . $key;
			$value = 'save_discount' === ( $this->pending['ani_op'] ?? '' ) && is_string( $this->pending[ $name ] ?? null ) ? $this->pending[ $name ] : $s[ $key ];
			echo '<label class="ani-field"><span>' . esc_html( $label ) . '</span><input name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" dir="ltr"></label>';
		}
		$text = 'save_discount' === ( $this->pending['ani_op'] ?? '' ) && is_string( $this->pending['discount_message'] ?? null ) ? $this->pending['discount_message'] : $s['message'];
		echo '<label class="ani-field"><span>' . esc_html__( 'Discount message variables: product_name, product_url, site_name, regular_price, sale_price, discount_percent, unsubscribe_url', 'ani-sms' ) . '</span><textarea name="discount_message" rows="6" required>' . esc_textarea( $text ) . '</textarea></label><button class="ani-button">' . esc_html__( 'Save changes', 'ani-sms' ) . '</button></form>';
		echo '<p>' . esc_html__( 'Classic product pages show the form automatically. Block templates can use [ani_sms_discount product_id="123"]. Use [ani_sms_discount] for account requests. Prices are catalog values, not personalized tax or coupon quotes.', 'ani-sms' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="discount_process"><button class="ani-button">' . esc_html__( 'Process eligible jobs', 'ani-sms' ) . '</button></form>';
		$page = max( 1, min( 100000, absint( $_GET['discount_admin_page'] ?? 1 ) ) );
		$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT r.*,j.error_code,j.payload FROM {$this->store->table( 'discount_requests' )} r LEFT JOIN {$this->store->table( 'jobs' )} j ON j.job_key=r.job_key ORDER BY r.id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
		echo '<div class="ani-table-scroll ani-order-jobs" tabindex="0"><table><thead><tr>';
		foreach ( array( __( 'Product', 'ani-sms' ), __( 'Request', 'ani-sms' ), __( 'State', 'ani-sms' ), __( 'Error category', 'ani-sms' ), __( 'Local preview', 'ani-sms' ) ) as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( $row['product_id'], $row['id'], $row['state'], $row['error_code'] ?? '' ) as $value ) {
				echo '<td>' . esc_html( (string) $value ) . '</td>';
			} echo '<td>';
			if ( \AniSms\Security\LocalTest::active() && 'local' === $row['mode'] && ! empty( $row['payload'] ) ) {
				try {
					$p = json_decode( ( new \AniSms\Security\Secrets() )->decrypt( $row['payload'] ), true, 32, JSON_THROW_ON_ERROR );
					echo '<details><summary>' . esc_html__( 'Local message preview · no SMS sent', 'ani-sms' ) . '</summary><pre class="ani-campaign-preview">' . esc_html( $p['message'] ) . '</pre></details>';
				} catch ( \Throwable $error ) {
					echo esc_html__( 'Unavailable', 'ani-sms' ); }
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		foreach ( array(
			-1 => __( 'Previous', 'ani-sms' ),
			1  => __( 'Next', 'ani-sms' ),
		) as $delta => $label ) {
			if ( $page + $delta > 0 && ( $delta < 0 || count( $rows ) === 20 ) ) {
				echo '<a href="' . esc_url( add_query_arg( 'discount_admin_page', $page + $delta ) ) . '">' . esc_html( $label ) . '</a> '; }
		}
	}
}
