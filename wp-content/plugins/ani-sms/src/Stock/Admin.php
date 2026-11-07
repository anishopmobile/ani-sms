<?php
/** Configuration and masked queue diagnostics. @package AniSms */
namespace AniSms\Stock;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
final class Admin {
	public function __construct( private Database $store, private array $pending = array() ) {}
	public function render(): void {
		( new Service( $this->store, new Queue( $this->store ) ) )->reconcile();
		echo '<p>' . esc_html__( 'Optional one-shot stock alerts require WooCommerce and an explicit request on a verified mobile. Marketing subscriptions are separate. Requests expire after 30 days.', 'ani-sms' ) . '</p>';
		if ( ! function_exists( 'wc_get_product' ) ) {
			echo '<p>' . esc_html__( 'Activate WooCommerce to use stock alerts. Saved settings are preserved.', 'ani-sms' ) . '</p>'; }
		echo '<p>' . esc_html__( 'Local mode needs no API; template ID 0 is allowed. Live stock alerts require an approved transactional SMS.ir template, not a marketing campaign.', 'ani-sms' ) . '</p>';
		$s     = Service::settings();
		$lines = array();
		foreach ( $s['mapping'] as $key => $value ) {
			$lines[] = $key . '=' . $value; }
		$template = 'save_stock' === ( $this->pending['ani_op'] ?? '' ) && is_scalar( $this->pending['stock_template'] ?? null ) ? (string) $this->pending['stock_template'] : (string) $s['template'];
		$text     = 'save_stock' === ( $this->pending['ani_op'] ?? '' ) && is_string( $this->pending['stock_mapping'] ?? null ) ? $this->pending['stock_mapping'] : implode( "\n", $lines );
		echo '<form method="post" class="ani-settings ani-order-settings">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="save_stock"><label class="ani-field"><span>' . esc_html__( 'Approved template ID', 'ani-sms' ) . '</span><input type="number" min="0" max="2147483647" name="stock_template" value="' . esc_attr( $template ) . '" required></label><label class="ani-field"><span>' . esc_html__( 'Stock mapping: product_name, product_url, site_name', 'ani-sms' ) . '</span><textarea dir="ltr" name="stock_mapping" rows="4" required>' . esc_textarea( $text ) . '</textarea></label><button class="ani-button">' . esc_html__( 'Save changes', 'ani-sms' ) . '</button></form>';
		echo '<p>' . esc_html__( 'Forms appear on classic product pages. Use [ani_sms_stock product_id="123"] for an exact product/variation or [ani_sms_stock] for account requests. Block templates can use the shortcode block.', 'ani-sms' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="stock_process"><button class="ani-button">' . esc_html__( 'Process eligible jobs', 'ani-sms' ) . '</button></form>';
		$page = max( 1, min( 100000, absint( $_GET['stock_admin_page'] ?? 1 ) ) );
		$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT r.*,j.state AS job_state,j.error_code,j.payload FROM {$this->store->table( 'stock_requests' )} r LEFT JOIN {$this->store->table( 'jobs' )} j ON j.job_key=r.job_key ORDER BY r.id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
		echo '<div class="ani-table-scroll ani-order-jobs" tabindex="0"><table><thead><tr>';
		foreach ( array( __( 'Product', 'ani-sms' ), __( 'Request', 'ani-sms' ), __( 'State', 'ani-sms' ), __( 'Phone', 'ani-sms' ), __( 'Error category', 'ani-sms' ), __( 'Local preview', 'ani-sms' ) ) as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$mask = '—';
			try {
				if ( '' !== $row['phone'] ) {
					$mask = \AniSms\Infrastructure\Log::mask( ( new \AniSms\Security\Secrets() )->decrypt( $row['phone'] ) ); }
			} catch ( \Throwable $error ) {
				/* Never expose raw data. */ }
			echo '<tr>';
			foreach ( array( $row['product_id'], $row['id'], $row['state'], $mask, $row['error_code'] ?? '' ) as $cell ) {
				echo '<td>' . esc_html( (string) $cell ) . '</td>'; }
			echo '<td>';
			if ( \AniSms\Security\LocalTest::active() && 'local' === $row['mode'] && ! empty( $row['payload'] ) ) {
				try {
					$payload = json_decode( ( new \AniSms\Security\Secrets() )->decrypt( $row['payload'] ), true, 32, JSON_THROW_ON_ERROR );
					echo '<details><summary>' . esc_html__( 'Parameters · no SMS sent', 'ani-sms' ) . '</summary><pre>' . esc_html( wp_json_encode( $payload['parameters'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre></details>';
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
				echo '<a href="' . esc_url( add_query_arg( 'stock_admin_page', $page + $delta ) ) . '">' . esc_html( $label ) . '</a> '; }
		}
	}
}
