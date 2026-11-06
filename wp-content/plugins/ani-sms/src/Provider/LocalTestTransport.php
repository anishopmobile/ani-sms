<?php
/** No HTTP call, message delivery or stored plaintext code. @package AniSms */
namespace AniSms\Provider;
use AniSms\Security\LocalTest;
final class LocalTestTransport implements Provider {
	public function capabilities(): array {
		return array(
			'template'   => true,
			'connection' => false,
			'text'       => false,
			'delivery'   => false,
		); }
	public function connection(): Result {
		return new Result( 'failed', 'unsupported', provider: 'local-test' ); }
	public function send_template( string $phone, int $template, string $parameter, #[\SensitiveParameter] string $code ): Result {
		if ( ! LocalTest::active() ) {
			throw new \LogicException( 'Local OTP testing is unavailable.' ); }
		return new Result( 'accepted', operation: 'local_test', provider: 'local-test' );
	}
}
