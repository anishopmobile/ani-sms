<?php
/** @package AniSms */
namespace AniSms\Provider;
final readonly class Result {
	public string $correlation;
	public function __construct( public string $state, public string $error = '', public string $message_id = '', public ?float $credit = null, public string $operation = 'send_template', public string $provider = 'sms-ir', public bool $retryable = false ) {
		$this->correlation = bin2hex( random_bytes( 16 ) );
	}
	public function accepted(): bool {
		return 'accepted' === $this->state; }
}
