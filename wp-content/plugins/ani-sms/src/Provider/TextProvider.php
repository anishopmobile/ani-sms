<?php
/** Free-form campaigns must use a sending line, never OTP templates. @package AniSms */
namespace AniSms\Provider;
interface TextProvider extends Provider {
	public function send_text( string $phone, string $line, string $text ): Result;
}
