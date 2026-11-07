<?php
/** Private configuration and loopback-only payload previews. @package AniSms */
namespace AniSms\WooCommerce;

use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
use AniSms\Security\LocalTest;
use AniSms\Security\Secrets;

final class Admin {
	public function __construct( private Database $store ) {}
	public function render(): void {
		$settings = Settings::get();
		$queue    = new Queue( $this->store );
		$reason   = ( new Module( $this->store, $queue ) )->unavailable_reason();
		if ( $reason ) {
			echo '<p class="ani-feedback">' . esc_html( $reason ) . '</p>'; }
		echo '<p>' . esc_html__( 'Order notifications are optional. Enable the module and each recipient group separately. Billing phones are used only for that order, never for account linking.', 'ani-sms' ) . '</p>';
		echo '<p>' . esc_html__( 'Local test: template ID 0 is allowed; no API or SMS is used. Simulated results are not deliveries. Live sending requires approved SMS.ir templates and the server live switch.', 'ani-sms' ) . '</p>';
		echo '<form method="post" class="ani-settings">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="save_woocommerce">';
		foreach ( array(
			'customer_enabled' => __( 'Notify customer', 'ani-sms' ),
			'admin_enabled'    => __( 'Notify administrator recipients', 'ani-sms' ),
			'created_enabled'  => __( 'Notify on order creation', 'ani-sms' ),
		) as $key => $label ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $settings[ $key ], true, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '<label class="ani-field"><span>' . esc_html__( 'Administrator mobiles (up to five, comma separated)', 'ani-sms' ) . '</span><input name="admin_phones" dir="ltr" maxlength="200" value="' . esc_attr( implode( ', ', $settings['admin_phones'] ) ) . '"></label>';
		echo '<fieldset><legend>' . esc_html__( 'Notify when status changes to:', 'ani-sms' ) . '</legend>';
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		foreach ( $statuses as $status => $label ) {
			$status = substr( $status, 3 );
			echo '<label><input type="checkbox" name="statuses[]" value="' . esc_attr( $status ) . '" ' . checked( in_array( $status, $settings['statuses'], true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
		}
		echo '</fieldset><p>' . esc_html__( 'Mapping format: ExactPanelParameter=variable, one per line. Allowed variables:', 'ani-sms' ) . ' <code>' . esc_html( implode( ', ', Settings::VARIABLES ) ) . '</code></p>';
		$labels = self::labels();
		foreach ( Settings::ROUTES as $route ) {
			$lines = array();
			foreach ( $settings[ $route ]['mapping'] as $key => $variable ) {
				$lines[] = $key . '=' . $variable; }
			echo '<fieldset><legend>' . esc_html( $labels[ $route ] ) . '</legend><label class="ani-field"><span>' . esc_html__( 'Approved template ID', 'ani-sms' ) . '</span><input type="number" min="0" max="2147483647" required name="' . esc_attr( $route ) . '[template]" value="' . esc_attr( (string) $settings[ $route ]['template'] ) . '"></label><label class="ani-field"><span>' . esc_html__( 'Parameter mapping', 'ani-sms' ) . '</span><textarea rows="5" dir="ltr" required name="' . esc_attr( $route ) . '[mapping]">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea></label></fieldset>';
		}
		echo '<button class="ani-button">' . esc_html__( 'Save changes', 'ani-sms' ) . '</button></form>';
		echo '<h3>' . esc_html__( 'Recent notification jobs', 'ani-sms' ) . '</h3><p>' . esc_html__( 'Jobs expire after 24 hours. Unknown results are never retried automatically. WP-Cron depends on traffic; use system cron every minute for timely notifications. Local jobs run only through a loopback request.', 'ani-sms' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="run_order_queue"><button class="ani-button">' . esc_html__( 'Process eligible jobs', 'ani-sms' ) . '</button></form>';
		$rows = $this->store->db->get_results( "SELECT * FROM {$this->store->table( 'jobs' )} WHERE module='woocommerce' ORDER BY id DESC LIMIT 20", ARRAY_A );
		echo '<div class="ani-table-scroll"><table><thead><tr>';
		foreach ( array( __( 'Order', 'ani-sms' ), __( 'Route', 'ani-sms' ), __( 'Phone', 'ani-sms' ), __( 'State', 'ani-sms' ), __( 'Error category', 'ani-sms' ), __( 'Local preview', 'ani-sms' ) ) as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( substr( $row['subject'], 3 ), $labels[ $row['route'] ] ?? $row['route'], $row['phone_mask'], $row['state'], $row['error_code'] ) as $cell ) {
				echo '<td>' . esc_html( (string) $cell ) . '</td>'; }
			echo '<td>';
			if ( LocalTest::active() && 'local' === $row['mode'] && '' !== $row['payload'] && (int) $row['expires'] >= time() ) {
				try {
					$payload = json_decode( ( new Secrets() )->decrypt( $row['payload'] ), true, 32, JSON_THROW_ON_ERROR );
					echo '<details><summary>' . esc_html__( 'Parameters · no SMS sent', 'ani-sms' ) . '</summary><pre dir="auto">' . esc_html( wp_json_encode( $payload['parameters'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
				} catch ( \Throwable $error ) {
					echo esc_html__( 'Unavailable', 'ani-sms' ); }
			}
			echo '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No events match this filter.', 'ani-sms' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
	public static function labels(): array {
		return array(
			'created_customer' => __( 'Order created · customer', 'ani-sms' ),
			'created_admin'    => __( 'Order created · administrator', 'ani-sms' ),
			'status_customer'  => __( 'Status changed · customer', 'ani-sms' ),
			'status_admin'     => __( 'Status changed · administrator', 'ani-sms' ),
		);
	}
}
