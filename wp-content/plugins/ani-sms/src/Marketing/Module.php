<?php
/** Optional independent marketing module. @package AniSms */
namespace AniSms\Marketing;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;

final class Module implements \AniSms\Core\Module {
	public function __construct( private Database $store, private Queue $queue ) {}
	public function id(): string {
		return 'marketing'; }
	public function unavailable_reason(): string {
		try {
			new Subscribers( $this->store );
			return '';
		} catch ( \Throwable $error ) {
			return __( 'Configure the server secret key and sodium encryption.', 'ani-sms' ); }
	}
	public function boot(): void {
		$this->queue->register( 'marketing', array( new Campaigns( $this->store, $this->queue ), 'dispatch' ) );
		add_action(
			'ani_sms_job_finished',
			function ( $row, $result ) {
				if ( 'marketing' === $row['module'] ) {
					$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'campaign_recipients' )} SET state=%s WHERE job_key=%s", $result->state, $row['job_key'] ) );
					$this->reconcile();
				}
			},
			10,
			2
		);
		add_action( 'ani_sms_cleanup', array( $this, 'reconcile' ), 5 );
	}
	public function reconcile(): void {
		$recipients = $this->store->table( 'campaign_recipients' );
		$jobs       = $this->store->table( 'jobs' );
		$campaigns  = $this->store->table( 'campaigns' );
		$this->store->query( "UPDATE $recipients r INNER JOIN $jobs j ON r.job_key=j.job_key SET r.state=j.state WHERE j.state IN ('accepted','failed','unknown','simulated','cancelled') AND r.state='queued'" );
		$this->store->query( $this->store->db->prepare( "UPDATE $recipients r INNER JOIN $campaigns c ON r.campaign_id=c.id LEFT JOIN $jobs j ON r.job_key=j.job_key SET r.state='unknown' WHERE r.state='queued' AND j.id IS NULL AND c.scheduled < %d", time() - DAY_IN_SECONDS ) );
		$this->store->query( "UPDATE $campaigns c SET state='completed' WHERE c.state IN ('approved','paused') AND NOT EXISTS (SELECT 1 FROM $recipients r WHERE r.campaign_id=c.id AND r.state='queued')" );
	}
}
