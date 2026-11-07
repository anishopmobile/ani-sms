<?php
/** @package AniSms */
namespace AniSms\Infrastructure;

final class Database {
	public const VERSION = '2';
	public function __construct( public readonly \wpdb $db ) {}
	public function quiet( callable $work ): mixed {
		$previous = $this->db->suppress_errors( true );
		try {
			return $work();
		} finally {
			$this->db->suppress_errors( $previous ); }
	}
	public function value( string $sql ): mixed {
		return $this->quiet( fn() => $this->db->get_var( $sql ) ); }
	public function row( string $sql ): ?array {
		return $this->quiet( fn() => $this->db->get_row( $sql, ARRAY_A ) ); }
	public function insert( string $table, array $data ): int|false {
		return $this->quiet( fn() => $this->db->insert( $this->table( $table ), $data ) ); }
	public function table( string $name ): string {
		if ( ! in_array( $name, array( 'identities', 'challenges', 'rates', 'logs', 'jobs', 'event_states' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown table.' );
		}
		return $this->db->prefix . 'ani_sms_' . $name;
	}
	public function query( string $sql ): int {
		$result = $this->quiet( fn() => $this->db->query( $sql ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'ANI-SMS storage operation failed.' );
		}
		return (int) $result;
	}
	public function lock( string $key, callable $work ): mixed {
		$name = 'ani_sms_' . substr( hash( 'sha256', $this->db->get_var( 'SELECT DATABASE()' ) . $this->db->prefix . $key ), 0, 48 );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 2)', $name ) ) ) {
			throw new \RuntimeException( 'ANI-SMS operation busy.' );
		}
		try {
			return $work(); } finally {
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
	}
	public function transaction( callable $work ): mixed {
		$this->query( 'START TRANSACTION' );
		try {
			$result = $work();
			$this->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			throw $error;
		}
	}
	public function migrate(): void {
		$this->lock(
			'schema',
			function () {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				$charset = $this->db->get_charset_collate();
				$schemas = array(
					'event_states' => 'subject varchar(96) NOT NULL, generation char(32) NOT NULL, sequence bigint(20) unsigned NOT NULL, status varchar(32) NOT NULL, created bigint(20) unsigned NOT NULL, PRIMARY KEY  (subject)',
					'jobs'         => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT, job_key char(64) NOT NULL, module varchar(24) NOT NULL, subject varchar(96) NOT NULL, sequence bigint(20) unsigned NOT NULL, route varchar(32) NOT NULL, mode varchar(8) NOT NULL, payload longtext NOT NULL, user_id bigint(20) unsigned NOT NULL DEFAULT 0, email_hash char(64) NOT NULL DEFAULT '', phone_mask varchar(24) NOT NULL, state varchar(16) NOT NULL, error_code varchar(32) NOT NULL DEFAULT '', created bigint(20) unsigned NOT NULL, available bigint(20) unsigned NOT NULL, expires bigint(20) unsigned NOT NULL, lease bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (id), UNIQUE KEY job_key (job_key), KEY dispatch (state,available), KEY expires (expires), KEY subject (subject), KEY user_id (user_id), KEY email_hash (email_hash)",
					'identities'   => 'phone varchar(16) NOT NULL, user_id bigint(20) unsigned NOT NULL, verified_at bigint(20) unsigned NOT NULL, PRIMARY KEY  (phone), UNIQUE KEY user_id (user_id)',
					'challenges'   => 'id char(64) NOT NULL, phone varchar(16) NOT NULL, binding char(64) NOT NULL, purpose varchar(16) NOT NULL, actor bigint(20) unsigned NOT NULL DEFAULT 0, request_key char(64) NOT NULL, digest char(64) NOT NULL, key_id char(16) NOT NULL, state varchar(16) NOT NULL, created bigint(20) unsigned NOT NULL, expires bigint(20) unsigned NOT NULL, attempts int unsigned NOT NULL DEFAULT 0, max_attempts int unsigned NOT NULL, PRIMARY KEY  (id), UNIQUE KEY request_key (request_key), KEY phone_created (phone,created), KEY expires (expires)',
					'rates'        => 'bucket char(64) NOT NULL, hits int unsigned NOT NULL, expires bigint(20) unsigned NOT NULL, PRIMARY KEY  (bucket), KEY expires (expires)',
					'logs'         => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT, correlation char(32) NOT NULL, operation varchar(24) NOT NULL, state varchar(16) NOT NULL, error_code varchar(32) NOT NULL DEFAULT '', phone_mask varchar(24) NOT NULL DEFAULT '', provider_id varchar(64) NOT NULL DEFAULT '', created bigint(20) unsigned NOT NULL, PRIMARY KEY  (id), KEY created (created), KEY state_created (state,created)",
				);
				foreach ( $schemas as $name => $columns ) {
					$sql = "CREATE TABLE {$this->table( $name )} (\n" . str_replace( ', ', ",\n", $columns ) . "\n) ENGINE=InnoDB $charset;";
					dbDelta( $sql );
				}
				foreach ( array_merge( array_map( fn( $n ) => $this->table( $n ), array_keys( $schemas ) ), array( $this->db->users, $this->db->usermeta ) ) as $table ) {
					$engine = $this->db->get_var( $this->db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
					if ( 'InnoDB' !== $engine ) {
						throw new \RuntimeException( 'ANI-SMS requires InnoDB tables.' );
					}
				}
				update_option( 'ani_sms_schema', self::VERSION, false );
			}
		);
	}
	public function cleanup(): void {
		$now      = time();
		$deadline = microtime( true ) + 2;
		foreach ( array(
			'challenges' => array( 'expires', $now ),
			'jobs'       => array( 'expires', $now ),
			'rates'      => array( 'expires', $now - 23 * HOUR_IN_SECONDS ),
			'logs'       => array( 'created', $now - 30 * DAY_IN_SECONDS + HOUR_IN_SECONDS ),
		) as $name => $rule ) {
			do {
				$deleted = $this->query( $this->db->prepare( "DELETE FROM {$this->table( $name )} WHERE {$rule[0]} < %d LIMIT 1000", $rule[1] ) );
			} while ( 1000 === $deleted && microtime( true ) < $deadline );
		}
		update_option( 'ani_sms_last_cleanup', $now, false );
	}
}
