<?php
/** Queue privacy works even while WooCommerce is unavailable. @package AniSms */
namespace AniSms\Privacy;

use AniSms\Infrastructure\Database;
use AniSms\Security\Secrets;

final class Jobs {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_action(
			'admin_init',
			static function () {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content( 'ANI-SMS orders', wpautop( __( 'Optional order notifications process billing mobiles and order display values through SMS.ir transactional templates. Encrypted queued snapshots expire after 24 hours; live terminal payloads are cleared immediately. Local simulations send no SMS. Masked logs expire after 30 days. Billing mobiles are not linked to login identities. Queue data can be exported or erased through the billing email; backups require separate handling.', 'ani-sms' ) ) );
				}
			}
		);
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $items ) {
				$items['ani-sms-orders'] = array(
					'exporter_friendly_name' => 'ANI-SMS orders',
					'callback'               => array( $this, 'export' ),
				);
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $items ) {
				$items['ani-sms-orders'] = array(
					'eraser_friendly_name' => 'ANI-SMS orders',
					'callback'             => array( $this, 'erase' ),
				);
				return $items;
			}
		);
		add_action(
			'delete_user',
			function ( $id ) {
				$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'jobs' )} WHERE user_id=%d", $id ) );
			}
		);
	}
	private function hash( string $email ): string {
		return ( new Secrets() )->hash( 'order-email', strtolower( $email ) ); }
	public function export( string $email, int $page = 1 ): array {
		try {
			$hash = $this->hash( $email );
		} catch ( \Throwable $error ) {
			return array(
				'data' => array(),
				'done' => true,
			); }
		$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT id,subject,route,state,created FROM {$this->store->table( 'jobs' )} WHERE email_hash=%s ORDER BY id LIMIT 20 OFFSET %d", $hash, ( max( 1, $page ) - 1 ) * 20 ), ARRAY_A );
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'ani-sms-orders',
				'group_label' => 'ANI-SMS orders',
				'item_id'     => 'ani-sms-job-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Order', 'ani-sms' ),
						'value' => substr( $row['subject'], 3 ),
					),
					array(
						'name'  => __( 'Route', 'ani-sms' ),
						'value' => $row['route'],
					),
					array(
						'name'  => __( 'State', 'ani-sms' ),
						'value' => $row['state'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 20,
		);
	}
	public function erase( string $email, int $page = 1 ): array {
		try {
			$hash = $this->hash( $email ); } catch ( \Throwable $error ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( __( 'Queue data cannot be matched after server key rotation; it expires within 24 hours through scheduled cleanup.', 'ani-sms' ) ),
				'done'           => true,
			);
			}
			$removed = $this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'jobs' )} WHERE email_hash=%s LIMIT 1000", $hash ) );
			return array(
				'items_removed'  => $removed > 0,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => $removed < 1000,
			);
	}
}
