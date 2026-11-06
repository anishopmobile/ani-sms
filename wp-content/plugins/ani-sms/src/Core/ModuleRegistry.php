<?php
/**
 * Deterministic module loading. Optional modules do not own the core.
 *
 * @package AniSms
 */

namespace AniSms\Core;

final class ModuleRegistry {
	/** @var array<string, Module> */
	private array $modules = array();
	/** @var array<string, array{status: string, reason: string}> */
	private array $states = array();
	private bool $started = false;

	public function register( Module $module ): void {
		$id = $module->id();
		if ( $this->started || ! preg_match( '/^[a-z][a-z0-9_-]*$/D', $id ) || isset( $this->modules[ $id ] ) ) {
			throw new \LogicException( 'Invalid, duplicate or late ANI-SMS module registration.' );
		}
		$this->modules[ $id ] = $module;
	}

	/** @param string[] $enabled IDs persisted by a privileged settings action. */
	public function boot( array $enabled ): void {
		if ( $this->started ) {
			return;
		}
		$this->started = true;
		foreach ( $this->modules as $id => $module ) {
			if ( ! in_array( $id, $enabled, true ) ) {
				$this->states[ $id ] = array(
					'status' => 'disabled',
					'reason' => '',
				);
				continue;
			}
			$reason = $module->unavailable_reason();
			if ( '' !== $reason ) {
				$this->states[ $id ] = array(
					'status' => 'unavailable',
					'reason' => $reason,
				);
				continue;
			}
			$module->boot();
			$this->states[ $id ] = array(
				'status' => 'enabled',
				'reason' => '',
			);
		}
	}

	public function states(): array {
		return $this->states;
	}
}
