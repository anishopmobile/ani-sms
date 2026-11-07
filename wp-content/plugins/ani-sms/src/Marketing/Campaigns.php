<?php
/** Snapshot audiences, explicit approval and per-recipient cancellation checks. @package AniSms */
namespace AniSms\Marketing;

use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;
use AniSms\Provider\Result;
use AniSms\Security\LocalTest;
use AniSms\Security\Secrets;

final class Campaigns {
	public const LIMIT = 200;
	public function __construct( private Database $store, private Queue $queue ) {}
	public function get( int $id ): ?array {
		return $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'campaigns' )} WHERE id=%d", $id ) );
	}
	public function create( array $input ): int {
		foreach ( array( 'title', 'body', 'tag', 'line', 'scheduled' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Invalid campaign.' ); }
		}
		if ( ! LocalTest::active() && ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) || in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) ) {
			throw new \InvalidArgumentException( 'Live campaigns require a public HTTPS unsubscribe URL.' ); }
		$title = sanitize_text_field( $input['title'] ?? '' );
		$text  = trim( $input['body'] ?? '' );
		$tag   = $input['tag'] ?? '';
		$line  = $input['line'] ?? '';
		if ( '' === $title || mb_strlen( $title ) > 120 || '' === $text || mb_strlen( $text ) > 1000 || wp_strip_all_tags( $text ) !== $text || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $text ) || ! preg_match( '/^[a-z0-9_-]{0,40}$/D', $tag ) ) {
			throw new \InvalidArgumentException( 'Invalid campaign text.' ); }
		if ( strpbrk( str_replace( array( '{site_name}', '{unsubscribe_url}' ), '', $text ), '{}' ) ) {
			throw new \InvalidArgumentException( 'Unknown variable.' ); }
		if ( ! str_contains( $text, '{unsubscribe_url}' ) ) {
			$text .= "\n" . __( 'Unsubscribe:', 'ani-sms' ) . ' {unsubscribe_url}'; }
		if ( ! LocalTest::active() && ! preg_match( '/^[1-9][0-9]{4,19}$/D', $line ) ) {
			throw new \InvalidArgumentException( 'Sending line required.' ); }
		if ( strlen( $line ) > 20 || ( '' !== $line && ! ctype_digit( $line ) ) ) {
			throw new \InvalidArgumentException( 'Invalid line.' ); }
		$scheduled = time() + 5;
		if ( '' !== ( $input['scheduled'] ?? '' ) ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $input['scheduled'], wp_timezone() );
			if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $input['scheduled'] ) {
				throw new \InvalidArgumentException( 'Invalid date.' ); }
			$scheduled = $date->getTimestamp();
		}
		if ( $scheduled < time() - 60 || $scheduled > time() + 7 * DAY_IN_SECONDS ) {
			throw new \InvalidArgumentException( 'Schedule within seven days.' ); }
		if ( false === $this->store->insert(
			'campaigns',
			array(
				'title'     => $title,
				'body'      => ( new Secrets() )->encrypt( $text ),
				'line'      => $line,
				'tag'       => $tag,
				'state'     => 'draft',
				'mode'      => LocalTest::requested() ? 'local' : 'live',
				'created'   => time(),
				'scheduled' => max( time() + 5, $scheduled ),
			)
		) ) {
			throw new \RuntimeException( 'Campaign storage failed.' ); }
		return (int) $this->store->db->insert_id;
	}
	public function audience( array $campaign ): array {
		$where = '' !== $campaign['tag'] ? $this->store->db->prepare( ' AND tag=%s', $campaign['tag'] ) : '';
		$rows  = $this->store->db->get_results( "SELECT * FROM {$this->store->table( 'subscribers' )} WHERE state='consented'$where ORDER BY id LIMIT 201", ARRAY_A );
		if ( count( $rows ) > self::LIMIT ) {
			throw new \InvalidArgumentException( 'Split audience into smaller tags.' ); }
		return $rows;
	}
	public function preview( int $id ): array {
		$campaign = $this->get( $id );
		if ( ! $campaign || 'draft' !== $campaign['state'] ) {
			throw new \InvalidArgumentException( 'Draft required.' ); }
		$rows = $this->audience( $campaign );
		return array(
			'count'       => count( $rows ),
			'fingerprint' => ( new Secrets() )->hash( 'campaign-preview', wp_json_encode( array( $campaign, array_map( static fn( $row ) => array( $row['id'], $row['revision'] ), $rows ) ) ) ),
			'rows'        => $rows,
			'text'        => ( new Secrets() )->decrypt( $campaign['body'] ),
		);
	}
	public function approve( int $id, string $fingerprint, bool $confirmed, int $actor ): void {
		if ( ! $confirmed || ! user_can( $actor, 'manage_options' ) || ! \AniSms\Core\Settings::module_enabled( 'marketing' ) ) {
			throw new \InvalidArgumentException( 'Administrator confirmation required.' ); }
		$this->store->lock(
			'campaign:' . $id,
			function () use ( $id, $fingerprint, $actor ) {
				$preview = $this->preview( $id );
				if ( ! hash_equals( $preview['fingerprint'], $fingerprint ) || $preview['count'] < 1 ) {
					throw new \InvalidArgumentException( 'Audience changed. Preview again.' ); }
				$campaign = $this->get( $id );
				if ( ( 'local' === $campaign['mode'] ) !== LocalTest::active() || ( 'live' === $campaign['mode'] && LocalTest::requested() ) ) {
					throw new \InvalidArgumentException( 'Campaign mode mismatch.' ); }
				$this->store->transaction(
					function () use ( $id, $actor, $campaign, $preview ) {
						$secrets = new Secrets();
						foreach ( $preview['rows'] as $row ) {
								$key = $secrets->hash( 'campaign-job', $id . ':' . $row['id'] );
							if ( false === $this->store->insert(
								'campaign_recipients',
								array(
									'campaign_id'   => $id,
									'subscriber_id' => $row['id'],
									'revision'      => $row['revision'],
									'job_key'       => $key,
								)
							) ) {
									throw new \RuntimeException( 'Recipient snapshot failed.' ); }
							$this->queue->enqueue(
								array(
									'job_key'    => $key,
									'module'     => 'marketing',
									'subject'    => 'campaign:' . $id,
									'sequence'   => $row['id'],
									'route'      => 'campaign',
									'mode'       => $campaign['mode'],
									'user_id'    => $row['user_id'],
									'email_hash' => '',
									'phone_mask' => \AniSms\Infrastructure\Log::mask( $secrets->decrypt( $row['phone'] ) ),
									'available'  => max( time() + 5, (int) $campaign['scheduled'] ),
									'expires'    => max( time(), (int) $campaign['scheduled'] ) + DAY_IN_SECONDS,
								),
								array( 'revision' => $row['revision'] )
							);
						}
						$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'campaigns' )} SET state='approved',approved_by=%d WHERE id=%d", $actor, $id ) );
					}
				);
			}
		);
	}
	public function control( int $id, string $action ): void {
		if ( ! in_array( $action, array( 'pause', 'resume', 'cancel' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid control.' ); }
		$this->store->lock(
			'campaign:' . $id,
			function () use ( $id, $action ) {
				$campaign = $this->get( $id );
				if ( ! $campaign || ! in_array( $campaign['state'], array( 'draft', 'approved', 'paused' ), true ) || ( 'resume' === $action && 'paused' !== $campaign['state'] ) || ( 'pause' === $action && 'approved' !== $campaign['state'] ) ) {
					throw new \InvalidArgumentException( 'Invalid campaign state.' ); }
				$state = array(
					'pause'  => 'paused',
					'resume' => 'approved',
					'cancel' => 'cancelled',
				)[ $action ];
				$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'campaigns' )} SET state=%s WHERE id=%d", $state, $id ) );
				if ( 'pause' === $action ) {
					$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'jobs' )} SET state='paused' WHERE module='marketing' AND subject=%s AND state IN ('queued','paused')", 'campaign:' . $id ) );
				}
				if ( 'resume' === $action ) {
					$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'jobs' )} SET state='queued' WHERE module='marketing' AND subject=%s AND state='paused'", 'campaign:' . $id ) );
					if ( ! wp_next_scheduled( 'ani_sms_dispatch_soon' ) ) {
						wp_schedule_single_event( time() + 5, 'ani_sms_dispatch_soon' ); }
				}
				if ( 'cancel' === $action ) {
					$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'jobs' )} SET state='cancelled',error_code='campaign_cancelled',payload='' WHERE module='marketing' AND subject=%s AND state IN ('queued','paused')", 'campaign:' . $id ) );
					$this->store->query( $this->store->db->prepare( "UPDATE {$this->store->table( 'campaign_recipients' )} SET state='cancelled' WHERE campaign_id=%d AND state='queued'", $id ) );
				}
			}
		);
	}
	public function dispatch( array $job, array $payload ): Result {
		$id = (int) substr( $job['subject'], 9 );
		return $this->store->lock(
			'campaign:' . $id,
			function () use ( $id, $job, $payload ) {
				$campaign = $this->get( $id );
				if ( ! \AniSms\Core\Settings::module_enabled( 'marketing' ) || ! $campaign || ! in_array( $campaign['state'], array( 'approved', 'paused' ), true ) || $campaign['mode'] !== $job['mode'] ) {
					return new Result( 'cancelled', 'campaign_stopped' ); }
				if ( 'paused' === $campaign['state'] ) {
					return new Result( 'deferred' ); }
				$row = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'subscribers' )} WHERE id=%d", $job['sequence'] ) );
				if ( ! $row ) {
					return new Result( 'cancelled', 'subscriber_missing' ); }
				return $this->store->lock(
					'subscriber:' . $row['phone_hash'],
					function () use ( $campaign, $job, $payload, $row ) {
						$current = $this->store->row( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'subscribers' )} WHERE id=%d", $row['id'] ) );
						if ( ! $current || 'consented' !== $current['state'] || $current['revision'] !== $payload['revision'] || Subscribers::VERSION !== $current['version'] ) {
							return new Result( 'cancelled', 'consent_changed' ); }
						if ( 'local' === $job['mode'] ) {
							return new Result( LocalTest::active() ? 'simulated' : 'cancelled', LocalTest::active() ? '' : 'local_guard' ); }
						if ( LocalTest::requested() || ! defined( 'ANI_SMS_LIVE_ENABLED' ) || true !== ANI_SMS_LIVE_ENABLED ) {
							return new Result( 'cancelled', 'live_disabled' ); }
						$secrets  = new Secrets();
						$provider = \AniSms\Core\ProviderRegistry::sms_ir( $secrets );
						return $provider instanceof \AniSms\Provider\TextProvider ? $provider->send_text( $secrets->decrypt( $current['phone'] ), $campaign['line'], $this->message( $campaign, $current ) ) : new Result( 'failed', 'unsupported' );
					}
				);
			}
		);
	}
	public function message( array $campaign, array $subscriber ): string {
		$body = ( new Secrets() )->decrypt( $campaign['body'] );
		return str_replace( array( '{site_name}', '{unsubscribe_url}' ), array( mb_substr( wp_strip_all_tags( get_bloginfo( 'name' ) ), 0, 100 ), ( new Subscribers( $this->store ) )->url( $subscriber ) ), $body );
	}
}
