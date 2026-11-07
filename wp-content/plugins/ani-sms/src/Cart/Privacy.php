<?php
/** Cart intent export, erasure and account deletion. @package AniSms */
namespace AniSms\Cart;
use AniSms\Infrastructure\Database;
final class Privacy {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_action( 'delete_user', array( $this, 'remove_user' ) );
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $items ) {
				$items['ani-sms-cart'] = array(
					'exporter_friendly_name' => 'ANI-SMS cart reminders',
					'callback'               => array( $this, 'export' ),
				);
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $items ) {
				$items['ani-sms-cart'] = array(
					'eraser_friendly_name' => 'ANI-SMS cart reminders',
					'callback'             => array( $this, 'erase' ),
				);
				return $items;
			}
		);
		add_action(
			'admin_init',
			static function () {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content( 'ANI-SMS cart reminders', wpautop( __( 'Cart reminders require separate account opt-in and current marketing consent. Cart fingerprints, activity times and reminder outcomes are retained for up to 30 days. No guest, payment data or session cookies are collected. One attempt per seven days; return, order or withdrawal cancels pending work. Local mode sends no SMS.', 'ani-sms' ) ) );
				}}
		);
	}
	public function remove_user( int $user ): void {
		$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table('jobs')} WHERE module='cart' AND user_id=%d", $user ) );
		$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table('carts')} WHERE user_id=%d", $user ) );
	}
	public function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT id,mode,state,last_seen,last_attempt,optin_until FROM {$this->store->table('carts')} WHERE user_id=%d", $user->ID ), ARRAY_A );
			foreach ( $rows as $row ) {
				$fields = array();
				foreach ( $row as $k => $v ) {
					$fields[] = array(
						'name'  => $k,
						'value' => (string) $v,
					);
				}$data[] = array(
					'group_id'    => 'ani-sms-cart',
					'group_label' => 'ANI-SMS cart reminders',
					'item_id'     => 'cart-' . $row['id'],
					'data'        => $fields,
				);}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}
	public function erase( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$this->remove_user( (int) $user->ID );}
		return array(
			'items_removed'  => (bool) $user,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
