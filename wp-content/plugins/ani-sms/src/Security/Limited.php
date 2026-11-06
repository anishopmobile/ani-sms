<?php
/** @package AniSms */
namespace AniSms\Security;
final class Limited extends \RuntimeException {
	public function __construct( public readonly int $retry_after ) {
		parent::__construct( 'Request limit reached.' ); }
}
