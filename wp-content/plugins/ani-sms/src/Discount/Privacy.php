<?php
/** Product requests retain no marketing permission or suppression. @package AniSms */
namespace AniSms\Discount;
use AniSms\Infrastructure\Database;
final class Privacy {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $items ) {
				$items['ani-sms-discount'] = array(
					'exporter_friendly_name' => 'ANI-SMS discount alerts',
					'callback'               => array( $this, 'export' ),
				);
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $items ) {
				$items['ani-sms-discount'] = array(
					'eraser_friendly_name' => 'ANI-SMS discount alerts',
					'callback'             => array( $this, 'erase' ),
				);
				return $items;
			}
		);
		add_action( 'delete_user', array( $this, 'remove_user' ) );
		add_action(
			'admin_init',
			static function () {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content( 'ANI-SMS discount alerts', wpautop( __( 'Discount alerts require separate product requests and current marketing consent on a verified mobile. Requests last 30 days and allow one promotional message for the exact product or variation. Withdrawal cancels pending alerts. Catalog prices may change before purchase. Live SMS.ir marketing-line messages include a random unsubscribe link; local alerts send no SMS. Queue snapshots expire within 24 hours; terminal phones clear and request metadata expires after 30 days. Erasure and user deletion remove pending work.', 'ani-sms' ) ) );
				} }
		);
	}
	public function remove_user( int $id ): void {
		$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT id,product_id FROM {$this->store->table( 'discount_requests' )} WHERE user_id=%d", $id ), ARRAY_A );
		foreach ( $rows as $row ) {
			$this->store->lock(
				'discount:' . $row['product_id'],
				function () use ( $row ) {
					$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'jobs' )} WHERE module='discount' AND sequence=%d", $row['id'] ) );
					$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'discount_requests' )} WHERE id=%d", $row['id'] ) );
				}
			); }
	}
	public function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		$rows = $user ? $this->store->db->get_results( $this->store->db->prepare( "SELECT id,product_id,state,mode,created,expires FROM {$this->store->table( 'discount_requests' )} WHERE user_id=%d ORDER BY id LIMIT 50 OFFSET %d", $user->ID, ( max( 1, $page ) - 1 ) * 50 ), ARRAY_A ) : array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $row as $key => $value ) {
				$fields[] = array(
					'name'  => $key,
					'value' => (string) $value,
				);
			} $data[] = array(
				'group_id'    => 'ani-sms-discount',
				'group_label' => 'ANI-SMS discount alerts',
				'item_id'     => 'ani-sms-discount-' . $row['id'],
				'data'        => $fields,
			); }
		return array(
			'data' => $data,
			'done' => count( $rows ) < 50,
		);
	}
	public function erase( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$this->remove_user( $user->ID ); }
		return array(
			'items_removed'  => (bool) $user,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
