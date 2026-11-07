<?php
/** Erasure retains only keyed suppression, to prevent accidental reimport/resend. @package AniSms */
namespace AniSms\Marketing;
use AniSms\Infrastructure\Database;

final class Privacy {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $items ) {
				$items['ani-sms-marketing'] = array(
					'exporter_friendly_name' => 'ANI-SMS marketing',
					'callback'               => array( $this, 'export' ),
				);
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $items ) {
				$items['ani-sms-marketing'] = array(
					'eraser_friendly_name' => 'ANI-SMS marketing',
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
					wp_add_privacy_policy_content( 'ANI-SMS marketing', wpautop( __( 'Optional marketing requires separate explicit consent on a verified mobile. Encrypted mobiles, consent source/version/time and withdrawal records support subscription management. SMS.ir receives the mobile, message and a random unsubscribe link only when a live campaign is approved. No SMS is sent in local mode. Campaign data expires after 30 days; queued jobs expire 24 hours after their scheduled time. Erasure removes personal data but retains a keyed phone suppression fingerprint to prevent reimport from restoring consent. This fingerprint remains until a verified fresh opt-in or an explicit complete plugin purge. Server logs and backups need separate retention; redact unsubscribe tokens from access logs.', 'ani-sms' ) ) ); }
			}
		);
		add_action( 'ani_sms_cleanup', array( $this, 'cleanup' ), 4 );
	}
	public function remove_user( int $user_id ): void {
		$ids = $this->store->db->get_col( $this->store->db->prepare( "SELECT id FROM {$this->store->table( 'subscribers' )} WHERE user_id=%d", $user_id ) );
		foreach ( $ids as $id ) {
			( new Subscribers( $this->store ) )->suppress( (int) $id, true ); }
	}
	public function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user && 1 === $page ) {
			$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'subscribers' )} WHERE user_id=%d", $user->ID ), ARRAY_A );
			foreach ( $rows as $row ) {
				try {
					$phone = ( new \AniSms\Security\Secrets() )->decrypt( $row['phone'] );
				} catch ( \Throwable $error ) {
					$phone = ''; }
				$data[] = array(
					'group_id'    => 'ani-sms-marketing',
					'group_label' => 'ANI-SMS marketing',
					'item_id'     => 'ani-sms-subscriber-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Phone', 'ani-sms' ),
							'value' => $phone,
						),
						array(
							'name'  => __( 'State', 'ani-sms' ),
							'value' => $row['state'],
						),
						array(
							'name'  => __( 'Consent version', 'ani-sms' ),
							'value' => $row['version'],
						),
						array(
							'name'  => __( 'Consent source', 'ani-sms' ),
							'value' => $row['source'],
						),
						array(
							'name'  => __( 'Updated at (UTC)', 'ani-sms' ),
							'value' => gmdate( 'c', (int) $row['updated'] ),
						),
					),
				);
			}
		}
		$history = $user ? $this->store->db->get_results( $this->store->db->prepare( "SELECT c.* FROM {$this->store->table( 'consents' )} c INNER JOIN {$this->store->table( 'subscribers' )} s ON c.subscriber_id=s.id WHERE s.user_id=%d ORDER BY c.id LIMIT 50 OFFSET %d", $user->ID, ( max( 1, $page ) - 1 ) * 50 ), ARRAY_A ) : array();
		foreach ( $history as $item ) {
			$data[] = array(
				'group_id'    => 'ani-sms-marketing',
				'group_label' => 'ANI-SMS marketing',
				'item_id'     => 'ani-sms-consent-' . $item['id'],
				'data'        => array(
					array(
						'name'  => __( 'Action', 'ani-sms' ),
						'value' => $item['action'],
					),
					array(
						'name'  => __( 'Consent source', 'ani-sms' ),
						'value' => $item['source'],
					),
					array(
						'name'  => __( 'Consent version', 'ani-sms' ),
						'value' => $item['version'],
					),
					array(
						'name'  => __( 'Updated at (UTC)', 'ani-sms' ),
						'value' => gmdate( 'c', (int) $item['created'] ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $history ) < 50,
		);
	}
	public function erase( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$this->remove_user( $user->ID ); }
		return array(
			'items_removed'  => (bool) $user,
			'items_retained' => true,
			'messages'       => array( __( 'Personal marketing data was removed. A keyed suppression fingerprint remains to prevent accidental resubscription by import.', 'ani-sms' ) ),
			'done'           => true,
		);
	}
	public function cleanup(): void {
		$ids = $this->store->db->get_col( $this->store->db->prepare( "SELECT id FROM {$this->store->table( 'campaigns' )} WHERE created < %d LIMIT 100", time() - 30 * DAY_IN_SECONDS ) );
		foreach ( $ids as $id ) {
			$this->store->lock(
				'campaign:' . $id,
				function () use ( $id ) {
					$this->store->transaction(
						function () use ( $id ) {
							$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'jobs' )} WHERE module='marketing' AND subject=%s", 'campaign:' . $id ) );
							$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'campaign_recipients' )} WHERE campaign_id=%d", $id ) );
							$this->store->query( $this->store->db->prepare( "DELETE FROM {$this->store->table( 'campaigns' )} WHERE id=%d", $id ) );
						}
					);
				}
			);
		}
	}
}
