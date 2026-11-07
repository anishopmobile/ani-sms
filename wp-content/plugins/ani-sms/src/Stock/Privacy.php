<?php
/** Product requests retain no marketing permission or suppression. @package AniSms */
namespace AniSms\Stock;
use AniSms\Infrastructure\Database;
final class Privacy {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $items ) {
				$items['ani-sms-stock'] = array(
					'exporter_friendly_name' => 'ANI-SMS stock alerts',
					'callback'               => array( $this, 'export' ),
				);
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $items ) {
				$items['ani-sms-stock'] = array(
					'eraser_friendly_name' => 'ANI-SMS stock alerts',
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
					wp_add_privacy_policy_content( 'ANI-SMS stock alerts', wpautop( __( 'An explicit stock alert request uses your verified mobile for one product or exact variation only. It does not grant marketing consent or reserve stock. Requests expire after 30 days; queued snapshots expire within 24 hours and terminal phone snapshots are cleared. SMS.ir receives the destination and approved template parameters only in live mode. Local requests cannot become live notifications. Account cancellation, erasure and user deletion remove pending work.', 'ani-sms' ) ) );
				} }
		);
	}
	public function remove_user( int $id ): void {
		$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT id,product_id FROM {$this->store->table( 'stock_requests' )} WHERE user_id=%d", $id ), ARRAY_A );
		foreach ( $rows as $row ) {
			$this->store->lock(
				'stock:' . $row['product_id'],
				function () use ( $row ) {
					$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'jobs' )} WHERE module='stock' AND sequence=%d", $row['id'] ) );
					$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'stock_requests' )} WHERE id=%d", $row['id'] ) );
				}
			); }
	}
	public function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		$rows = $user ? $this->store->db->get_results( $this->store->db->prepare( "SELECT id,product_id,state,mode,created,expires FROM {$this->store->table( 'stock_requests' )} WHERE user_id=%d ORDER BY id LIMIT 50 OFFSET %d", $user->ID, ( max( 1, $page ) - 1 ) * 50 ), ARRAY_A ) : array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $row as $key => $value ) {
				$fields[] = array(
					'name'  => $key,
					'value' => (string) $value,
				);
			} $data[] = array(
				'group_id'    => 'ani-sms-stock',
				'group_label' => 'ANI-SMS stock alerts',
				'item_id'     => 'ani-sms-stock-' . $row['id'],
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
