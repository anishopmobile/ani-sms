<?php
/** Independent smoke checks. No WordPress, database, network or SMS access. */
require dirname( __DIR__, 2 ) . '/wp-content/plugins/ani-sms/src/autoload.php';

use AniSms\Core\Module;
use AniSms\Core\ModuleRegistry;
use AniSms\Support\Phone;

$checks = 0;
function check( bool $condition, string $message ): void {
	global $checks;
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

foreach ( array( '09123456789', '+989123456789', '00989123456789', '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', ' (0912) 345-6789 ' ) as $number ) {
	check( '+989123456789' === Phone::normalize( $number ), 'Normalization failed.' );
}
foreach ( array( '', '9123456789', '+19012345678', '02112345678', '091234567890', '09123abc789', '09۱۲۳۴۵۶۷۸۹x', "09123456789\0", str_repeat( ' ', 129 ), "\xff" ) as $number ) {
	$rejected = false;
	try {
		Phone::normalize( $number );
	} catch ( InvalidArgumentException $error ) {
		$rejected = true;
	}
	check( $rejected, 'Invalid phone accepted.' );
}

final class TestModule implements Module {
	public int $boots = 0;
	public function __construct( private string $name, private string $reason = '' ) {}
	public function id(): string { return $this->name; }
	public function unavailable_reason(): string { return $this->reason; }
	public function boot(): void { ++$this->boots; }
}

$registry = new ModuleRegistry();
$ready = new TestModule( 'ready' );
$missing = new TestModule( 'missing', 'Dependency absent.' );
$disabled = new TestModule( 'disabled' );
foreach ( array( $ready, $missing, $disabled ) as $module ) {
	$registry->register( $module );
}
try {
	$registry->register( new TestModule( 'ready' ) );
	throw new RuntimeException( 'Duplicate registration accepted.' );
} catch ( LogicException $error ) {
	check( true, 'Duplicate rejected.' );
}
$registry->boot( array( 'ready', 'missing', 'unknown' ) );
$registry->boot( array( 'ready' ) );
check( 1 === $ready->boots, 'Enabled module boot must be idempotent.' );
check( 0 === $missing->boots && 0 === $disabled->boots, 'Unavailable/disabled module booted.' );
check( 'enabled' === $registry->states()['ready']['status'], 'Enabled state missing.' );
check( 'unavailable' === $registry->states()['missing']['status'], 'Unavailable state missing.' );
check( 'disabled' === $registry->states()['disabled']['status'], 'Disabled state missing.' );
try {
	$registry->register( new TestModule( 'late' ) );
	throw new RuntimeException( 'Late registration accepted.' );
} catch ( LogicException $error ) {
	check( true, 'Late registration rejected.' );
}
echo "PASS: {$checks} checks; no SMS sent.\n";
