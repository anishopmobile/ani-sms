<?php
/** @package AniSms */
namespace AniSms\Security;

use AniSms\Infrastructure\Database;

final class RateLimiter {
	public function __construct( private Database $store, private Secrets $secrets ) {}
	public function hit( string $scope, string $subject, int $limit, int $window ): void {
		$db     = $this->store->db;
		$table  = $this->store->table( 'rates' );
		$bucket = $this->secrets->hash( 'rate', $scope . ':' . $subject );
		$now    = time();
		// One atomic UPSERT provides a rolling fixed window starting with first use.
		$this->store->query( $db->prepare( "INSERT INTO $table (bucket,hits,expires) VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE hits=IF(expires<=%d,1,hits+1), expires=IF(expires<=%d,%d,expires)", $bucket, $now + $window, $now, $now, $now + $window ) );
		$row = $this->store->row( $db->prepare( "SELECT hits,expires FROM $table WHERE bucket=%s", $bucket ) );
		if ( ! $row || (int) $row['hits'] > $limit ) {
			throw new Limited( max( 1, (int) ( $row['expires'] ?? $now + $window ) - $now ) ); }
	}
}
