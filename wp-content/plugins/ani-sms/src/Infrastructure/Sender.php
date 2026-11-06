<?php
/** Shared sending boundary. No blind retries or payload logging. @package AniSms */
namespace AniSms\Infrastructure;
use AniSms\Provider\Provider;
use AniSms\Provider\Result;
final class Sender implements Provider {
	public function __construct( private Provider $provider, private Log $log, private string $operation = 'otp' ) {}
	public function capabilities(): array {
		return $this->provider->capabilities(); }
	public function connection(): Result {
		return $this->provider->connection(); }
	public function send_template( string $phone, int $template, string $parameter, #[\SensitiveParameter] string $code ): Result {
		$result = $this->provider->send_template( $phone, $template, $parameter, $code );
		$this->log->record( $this->operation, $result->state, $result->error, $phone, $result->message_id, $result->correlation );
		return $result;
	}
}
