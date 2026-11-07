<?php
/** Cart settings and protected local queue previews. @package AniSms */
namespace AniSms\Cart;
use AniSms\Infrastructure\Database;
final class Admin {
	public function __construct( private Database $store, private array $pending = array() ) {}
	public function render(): void {
		$s = Service::settings();
		echo '<p>' . esc_html__( 'Verified accounts only, explicit cart opt-in and current marketing consent. Guests are excluded. Local mode needs no API or sending line.', 'ani-sms' ) . '</p><p><code>[ani_sms_cart]</code></p><form method="post" class="ani-settings ani-order-settings">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="save_cart">';
		foreach ( array(
			'line'    => __( 'Permitted sending line (blank for local simulation)', 'ani-sms' ),
			'delay'   => __( 'Inactivity delay in minutes (15 to 8640)', 'ani-sms' ),
			'message' => __( 'Cart message: site_name, item_count, cart_url, unsubscribe_url', 'ani-sms' ),
		)as $key => $label ) {
			$name  = 'cart_' . $key;
			$value = 'save_cart' === ( $this->pending['ani_op'] ?? '' ) && is_string( $this->pending[ $name ] ?? null ) ? $this->pending[ $name ] : ( 'delay' === $key ? $s[ $key ] / 60 : $s[ $key ] );
			echo '<label class="ani-field"><span>' . esc_html( $label ) . '</span>';
			if ( 'message' === $key ) {
				echo '<textarea rows="6" required name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
			} else {
				echo '<input dir="ltr" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			}echo '</label>';
		}
		echo '<button class="ani-button">' . esc_html__( 'Save changes', 'ani-sms' ) . '</button></form><form method="post">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="cart_process"><button class="ani-button">' . esc_html__( 'Process eligible jobs', 'ani-sms' ) . '</button></form>';
		$page = max( 1, min( 100000, absint( $_GET['cart_page'] ?? 1 ) ) );
		$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT c.*,j.payload FROM {$this->store->table('carts')} c LEFT JOIN {$this->store->table('jobs')} j ON j.job_key=c.job_key ORDER BY c.id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
		echo '<div class="ani-table-scroll"><table><thead><tr><th>' . esc_html__( 'Request', 'ani-sms' ) . '</th><th>' . esc_html__( 'State', 'ani-sms' ) . '</th><th>' . esc_html__( 'Local preview', 'ani-sms' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( (string) $r['id'] ) . '</td><td>' . esc_html( $r['state'] ) . '</td><td>';
			if ( \AniSms\Security\LocalTest::active() && 'local' === $r['mode'] && ! empty( $r['payload'] ) ) {
				try {
							$p = json_decode( ( new \AniSms\Security\Secrets() )->decrypt( $r['payload'] ), true, 32, JSON_THROW_ON_ERROR );
							echo '<details><summary>' . esc_html__( 'Local message preview · no SMS sent', 'ani-sms' ) . '</summary><pre class="ani-campaign-preview">' . esc_html( $p['message'] ) . '</pre></details>';} catch ( \Throwable $e ) {
						/* Invalid preview is omitted. */}
			}
			echo '</td></tr>';
		}echo '</tbody></table></div>';
		foreach ( array(
			-1 => __( 'Previous', 'ani-sms' ),
			1  => __( 'Next', 'ani-sms' ),
		)as $delta => $label ) {
			if ( $page + $delta > 0 && ( $delta < 0 || count( $rows ) === 20 ) ) {
				echo '<a href="' . esc_url( add_query_arg( 'cart_page', $page + $delta ) ) . '">' . esc_html( $label ) . '</a> ';}
		}
	}
}
