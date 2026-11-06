<?php
/** @package AniSms */
namespace AniSms\Privacy;
use AniSms\Infrastructure\Database;
final class Privacy {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $items ) {
				$items['ani-sms'] = array(
					'exporter_friendly_name' => 'ANI-SMS',
					'callback'               => array( $this, 'export' ),
				);
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $items ) {
				$items['ani-sms'] = array(
					'eraser_friendly_name' => 'ANI-SMS',
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
					wp_add_privacy_policy_content( 'ANI-SMS', wpautop( __( 'ANI-SMS processes mobile numbers to verify ownership. SMS.ir receives the destination and template parameters to send the requested message. OTP codes are never stored in plaintext. Expired challenges are removed after one hour, rate metadata within 24 hours after its window, and masked message metadata after 30 days. Identity links remain until removed. Login is not marketing consent. Data is preserved on uninstall unless the administrator explicitly enables plugin-data deletion. Server configuration and backups may retain credentials; handle them separately. Users without an email address can request identity-link removal through an administrator after identity verification.', 'ani-sms' ) ) ); }
			}
		);
	}
	private function identity_for_user( int $id ): ?array {
		$row = $this->store->row( $this->store->db->prepare( "SELECT phone,user_id,verified_at FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $id ) );
		return is_array( $row ) ? $row : null;
	}
	public function export( string $email, int $page = 1 ): array {
		$user     = get_user_by( 'email', $email );
		$identity = $user ? $this->identity_for_user( $user->ID ) : null;
		$data     = array();
		if ( 1 === $page && $identity ) {
			$data[] = array(
				'group_id'    => 'ani-sms',
				'group_label' => 'ANI-SMS',
				'item_id'     => 'ani-sms-identity-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Verified mobile', 'ani-sms' ),
						'value' => $identity['phone'],
					),
					array(
						'name'  => __( 'Verified at (UTC)', 'ani-sms' ),
						'value' => gmdate( 'c', $identity['verified_at'] ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}
	public function erase( string $email, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email );
		$removed = $user && $this->identity_for_user( $user->ID );
		if ( $user ) {
			$this->remove_user( $user->ID ); }
		return array(
			'items_removed'  => (bool) $removed,
			'items_retained' => false,
			'messages'       => array( __( 'ANI-SMS identity links and related challenges were removed. Masked aggregate message metadata expires under the retention policy.', 'ani-sms' ) ),
			'done'           => true,
		);
	}
	/** Also usable by a trusted administrative script after manual identity verification. */
	public function remove_user( int $id ): void {
		$row = $this->identity_for_user( $id );
		if ( ! $row ) {
			return; }
		$this->store->lock(
			'phone:' . $row['phone'],
			function () use ( $row, $id ) {
				$this->store->transaction(
					function () use ( $row, $id ) {
						$db = $this->store->db;
						$this->store->query( $db->prepare( "DELETE FROM {$this->store->table( 'challenges' )} WHERE phone=%s OR actor=%d", $row['phone'], $id ) );
						$this->store->query( $db->prepare( "DELETE FROM {$this->store->table( 'identities' )} WHERE user_id=%d", $id ) );
					}
				);
			}
		);
	}
}
