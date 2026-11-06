<?php
/**
 * A feature module owns its WordPress hooks and assets.
 *
 * @package AniSms
 */

namespace AniSms\Core;

interface Module {
	public function id(): string;

	/** Return an unavailable reason or an empty string when dependencies exist. */
	public function unavailable_reason(): string;

	/** Register hooks only; no SMS or persistent mutation during boot. */
	public function boot(): void;
}
