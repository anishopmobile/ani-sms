<?php
/** @package AniSms */
namespace AniSms\Provider;
interface Provider {
	public function send_template( string $phone, int $template, string $parameter, #[\SensitiveParameter] string $code ): Result;
	public function connection(): Result;
	public function capabilities(): array;
}
