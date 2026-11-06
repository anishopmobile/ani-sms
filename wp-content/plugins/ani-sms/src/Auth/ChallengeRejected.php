<?php
/** A bound challenge may expose its lifecycle state, never account existence. @package AniSms */
namespace AniSms\Auth;
final class ChallengeRejected extends \InvalidArgumentException {
	public function __construct( public readonly string $reason = 'invalid' ) {
		parent::__construct( 'OTP challenge rejected.' ); }
}
