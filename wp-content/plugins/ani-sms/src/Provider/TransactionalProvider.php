<?php
/** Multi-parameter transactional templates. @package AniSms */
namespace AniSms\Provider;

interface TransactionalProvider extends Provider {
	public function send_parameters( string $phone, int $template, array $parameters ): Result;
}
