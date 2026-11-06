<?php
/** @package AniSms */
namespace AniSms\Admin;

use AniSms\Core\Settings;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Log;
use AniSms\Security\Secrets;

final class Admin {
	private string $notice = '';
	private bool $error    = false;
	private array $pending = array();
	private const TABS     = array(
		'overview'   => 'Overview',
		'connection' => 'SMS.ir connection',
		'auth'       => 'Login and registration',
		'modules'    => 'Modules',
		'templates'  => 'Local templates',
		'logs'       => 'Logs',
		'help'       => 'Help',
	);
	public function __construct( private Database $store ) {}
	private function tabs(): array {
		return array(
			'overview'   => __( 'Overview', 'ani-sms' ),
			'connection' => __( 'SMS.ir connection', 'ani-sms' ),
			'auth'       => __( 'Login and registration', 'ani-sms' ),
			'modules'    => __( 'Modules', 'ani-sms' ),
			'templates'  => __( 'Local templates', 'ani-sms' ),
			'logs'       => __( 'Logs', 'ani-sms' ),
			'help'       => __( 'Help', 'ani-sms' ),
		);
	}
	public function boot(): void {
		add_action(
			'admin_notices',
			static function () {
				if ( 'ani-sms' === ( $_GET['page'] ?? '' ) && \AniSms\Security\LocalTest::active() ) {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'Local OTP test mode is active. No SMS is sent; codes are shown on the local form. Open the login page in a private window to test registration without changing your administrator session.', 'ani-sms' ) . '</p></div>';
				}
			}
		);
		add_action(
			'admin_menu',
			function () {
				add_menu_page( 'ANI-SMS', 'ANI-SMS', 'manage_options', 'ani-sms', array( $this, 'page' ), 'dashicons-email-alt' );
			}
		);
		add_action( 'admin_init', array( $this, 'actions' ) );
		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) {
				if ( 'toplevel_page_ani-sms' !== $hook ) {
					return; }
				$base = plugins_url( 'assets/', dirname( __DIR__, 2 ) . '/ani-sms.php' );
				wp_enqueue_style( 'ani-sms-admin', $base . 'admin.css', array(), '1.0.0-rc3' );
				wp_enqueue_script( 'ani-sms-admin', $base . 'admin.js', array(), '1.0.0-rc3', true );
				wp_localize_script(
					'ani-sms-admin',
					'aniSmsAdminText',
					array(
						'unsaved' => __( 'You have unsaved changes.', 'ani-sms' ),
						'pending' => __( 'Working…', 'ani-sms' ),
					)
				);
			}
		);
	}
	public function actions(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'ani-sms' !== ( $_GET['page'] ?? '' ) ) {
			return; }
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ani-sms' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'ani_sms_admin' );
		$input         = wp_unslash( $_POST );
		$this->pending = $input;
		$op            = is_string( $input['ani_op'] ?? null ) ? $input['ani_op'] : '';
		try {
			switch ( $op ) {
				case 'save_connection':
					$settings = Settings::validate( array_intersect_key( $input, array_flip( array( 'template', 'parameter' ) ) ) );
					$key      = $input['api_key'] ?? '';
					if ( ! is_string( $key ) || strlen( $key ) > 512 || preg_match( '/[\r\n]/', $key ) ) {
						throw new \InvalidArgumentException( 'Invalid API key.' ); }
					if ( '' !== $key ) {
						update_option( 'ani_sms_credential', ( new Secrets() )->encrypt( $key ), false ); }
					update_option( 'ani_sms_settings', $settings, false );
					delete_transient( 'ani_sms_account' );
					break;
				case 'delete_key':
					delete_option( 'ani_sms_credential' );
					delete_transient( 'ani_sms_account' );
					break;
				case 'test_connection':
					$cached = get_transient( 'ani_sms_account' );
					if ( is_array( $cached ) && $cached['checked'] > time() - 30 ) {
						$this->notice = __( 'Recent connection result is still current.', 'ani-sms' );
						return; }
					$secrets = new Secrets();
					$result  = \AniSms\Core\ProviderRegistry::sms_ir( $secrets )->connection();
					set_transient(
						'ani_sms_account',
						array(
							'state'   => $result->state,
							'credit'  => $result->credit,
							'checked' => time(),
						),
						300
					);
					if ( ! $result->accepted() ) {
						throw new \RuntimeException( 'Connection test failed.' ); }
					$this->notice = __( 'Account connection succeeded. This does not verify the OTP template or message delivery.', 'ani-sms' );
					return;
				case 'test_send':
					if ( ! defined( 'ANI_SMS_LIVE_ENABLED' ) || ! ANI_SMS_LIVE_ENABLED || '1' !== ( $input['send_consent'] ?? '' ) ) {
						throw new \RuntimeException( 'Explicit test configuration required.' ); }
					$secrets = new Secrets();
					( new \AniSms\Security\RateLimiter( $this->store, $secrets ) )->hit( 'admin-test', (string) get_current_user_id(), 3, 3600 );
					$phone    = \AniSms\Support\Phone::normalize( is_string( $input['test_phone'] ?? null ) ? $input['test_phone'] : '' );
					$settings = Settings::get();
					$code     = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
					$sender   = new \AniSms\Infrastructure\Sender( \AniSms\Core\ProviderRegistry::sms_ir( $secrets ), new Log( $this->store ), 'test_sms' );
					$result   = $sender->send_template( $phone, $settings['template'], $settings['parameter'], $code );
					unset( $code );
					$this->notice = $result->accepted() ? __( 'Test message accepted by provider; delivery is not confirmed. This code cannot log in.', 'ani-sms' ) : __( 'Test not confirmed. Review logs; do not resend an unknown result blindly.', 'ani-sms' );
					return;
				case 'save_auth':
					update_option( 'ani_sms_settings', Settings::validate( array_merge( array( 'registration' => '0' ), array_intersect_key( $input, array_flip( array( 'registration', 'expiry', 'cooldown', 'attempts', 'site_limit' ) ) ) ) ), false );
					break;
				case 'save_modules':
					update_option( 'ani_sms_modules', '1' === ( $input['auth_enabled'] ?? '' ) ? array( 'auth' ) : array(), false );
					if ( ! Settings::enabled() ) {
						$this->store->query( "UPDATE {$this->store->table( 'challenges' )} SET state='expired',digest='' WHERE state IN ('active','pending_send')" ); }
					break;
				case 'save_privacy':
					update_option( 'ani_sms_settings', Settings::validate( array( 'purge' => $input['purge'] ?? '0' ) ), false );
					break;
				default:
					throw new \InvalidArgumentException( 'Unknown action.' );
			}
			( new Log( $this->store ) )->record( 'admin_' . substr( $op, 0, 18 ), 'accepted' );
			$this->notice  = __( 'Changes saved.', 'ani-sms' );
			$this->pending = array();
		} catch ( \Throwable $error ) {
			$this->error  = true;
			$this->notice = __( 'The operation failed. Check the values and server configuration; secrets have not been displayed.', 'ani-sms' );
		}
		unset( $this->pending['api_key'] );
	}
	private function form( string $op ): void {
		echo '<form method="post" class="ani-settings">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="' . esc_attr( $op ) . '">';
	}
	private function button( string $text ): void {
		echo '<button class="ani-button" type="submit">' . esc_html( $text ) . '</button></form>'; }
	private function field( string $name, string $label, string $type = 'text', array $attrs = array() ): void {
		$value = $this->pending[ $name ] ?? Settings::get()[ $name ] ?? '';
		if ( ! is_scalar( $value ) ) {
			$value = ''; }
		echo '<label class="ani-field" for="ani-' . esc_attr( $name ) . '"><span>' . esc_html( $label ) . '</span><input id="ani-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( 'password' === $type ? '' : (string) $value ) . '"';
		foreach ( $attrs as $key => $val ) {
			echo ' ' . esc_attr( $key ) . '="' . esc_attr( (string) $val ) . '"'; }
		echo '></label>';
	}
	public function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ani-sms' ) ); }
		$tab = is_string( $_GET['tab'] ?? null ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'overview'; }
		$account    = get_transient( 'ani_sms_account' );
		$start      = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
		$db         = $this->store->db;
		$sent       = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$this->store->table( 'logs' )} WHERE created >= %d AND operation IN ('otp','test_sms') AND state='accepted'", $start ) );
		$failed     = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$this->store->table( 'logs' )} WHERE created >= %d AND operation IN ('otp','test_sms') AND state IN ('failed','unknown')", $start ) );
		$connection = ! is_array( $account ) ? __( 'Not checked', 'ani-sms' ) : ( 'accepted' === $account['state'] ? __( 'Connected', 'ani-sms' ) : __( 'Connection failed', 'ani-sms' ) );
		?>
		<div class="wrap ani-admin">
			<h1 class="screen-reader-text">ANI-SMS</h1>
			<div class="ani-shell">
				<aside class="ani-sidebar"><div class="ani-logo" dir="ltr">ANI<span>SMS</span></div><p><?php esc_html_e( 'Mobile access, thoughtfully simple.', 'ani-sms' ); ?></p>
				<details class="ani-navigation" open><summary><?php esc_html_e( 'Navigation', 'ani-sms' ); ?></summary><nav aria-label="ANI-SMS">
				<?php
				foreach ( $this->tabs() as $id => $label ) :
					?>
					<a 
					<?php
					if ( $id === $tab ) {
										echo 'aria-current="page"'; }
					?>
href="<?php echo esc_url( admin_url( 'admin.php?page=ani-sms&tab=' . $id ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
				</nav></details><small>SMS.ir · 1.0.0-rc3</small></aside>
				<main class="ani-main">
				<div class="ani-stats">
				<?php
				foreach ( array(
					__( 'Connection', 'ani-sms' )   => $connection,
					__( 'Panel credit', 'ani-sms' ) => is_array( $account ) && null !== $account['credit'] ? number_format_i18n( $account['credit'], 2 ) : __( 'Unavailable', 'ani-sms' ),
					__( 'Accepted today · this plugin', 'ani-sms' ) => number_format_i18n( $sent ),
					__( 'Errors today · this plugin', 'ani-sms' ) => number_format_i18n( $failed ),
				) as $label => $value ) :
					?>
	<div class="ani-stat"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong></div><?php endforeach; ?>
				</div>
				<?php
				if ( $this->notice ) :
					?>
					<div role="status" class="ani-feedback <?php echo $this->error ? 'ani-error' : ''; ?>"><?php echo esc_html( $this->notice ); ?></div><?php endif; ?>
				<section class="ani-card"><h2><?php echo esc_html( $this->tabs()[ $tab ] ); ?></h2>
				<?php $this->content( $tab ); ?>
				</section></main>
			</div>
		</div>
		<?php
	}
	private function content( string $tab ): void {
		$s = Settings::get();
		switch ( $tab ) {
			case 'overview':
				echo '<p>' . esc_html__( 'SMS.ir is the only provider. Accepted messages are not confirmed deliveries. Counters cover this plugin in the site timezone.', 'ani-sms' ) . '</p>';
				echo '<p>' . esc_html__( 'Credit is the balance returned by your panel, not a promised number of SMS. Its billing unit depends on your SMS.ir account.', 'ani-sms' ) . '</p>';
				$account = get_transient( 'ani_sms_account' );
				if ( is_array( $account ) ) {
					echo '<p>' . esc_html__( 'Last checked:', 'ani-sms' ) . ' ' . esc_html( wp_date( 'Y-m-d H:i:s', $account['checked'] ) ) . '</p>'; }
				echo '<p><code>[ani_sms_auth]</code></p>';
				$cleanup = (int) get_option( 'ani_sms_last_cleanup', 0 );
				echo '<p>' . esc_html__( 'Last privacy cleanup:', 'ani-sms' ) . ' ' . esc_html( $cleanup ? wp_date( 'Y-m-d H:i:s', $cleanup ) : __( 'Not run yet', 'ani-sms' ) ) . '</p>';
				if ( $cleanup && time() - $cleanup > 2 * HOUR_IN_SECONDS ) {
					echo '<p class="ani-feedback ani-error">' . esc_html__( 'Privacy cleanup is delayed. Check the site cron before relying on retention limits.', 'ani-sms' ) . '</p>'; }
				break;
			case 'connection':
				try {
					$secrets = new Secrets();
					$exists  = '' !== Settings::key( $secrets );
				} catch ( \Throwable $e ) {
					$exists = false; }
				echo '<p>' . esc_html( $exists ? __( 'API key configured. Stored values are never returned to the browser.', 'ani-sms' ) : __( 'No usable API key. Configure the server secret before saving credentials.', 'ani-sms' ) ) . '</p>';
				$this->form( 'save_connection' );
				$this->field(
					'api_key',
					__( 'Replace API key (leave blank to keep)', 'ani-sms' ),
					'password',
					array(
						'autocomplete' => 'new-password',
						'maxlength'    => 512,
					)
				);
				$this->field(
					'template',
					__( 'Approved OTP template ID', 'ani-sms' ),
					'number',
					array(
						'min'      => 1,
						'max'      => 2147483647,
						'required' => 'required',
					)
				);
				$this->field(
					'parameter',
					__( 'Exact OTP parameter name', 'ani-sms' ),
					'text',
					array(
						'required'  => 'required',
						'maxlength' => 40,
						'dir'       => 'ltr',
					)
				);
				$this->button( __( 'Save changes', 'ani-sms' ) );
				$this->form( 'test_connection' );
				$this->button( __( 'Check connection · no SMS', 'ani-sms' ) );
				$this->form( 'delete_key' );
				$this->button( __( 'Delete database API key', 'ani-sms' ) );
				echo '<p>' . esc_html__( 'A server-configured API key takes priority and cannot be deleted here.', 'ani-sms' ) . '</p>';
				$this->form( 'test_send' );
				$this->field(
					'test_phone',
					__( 'Test recipient mobile', 'ani-sms' ),
					'tel',
					array(
						'required' => 'required',
						'dir'      => 'ltr',
					)
				);
				echo '<label><input type="checkbox" name="send_consent" value="1" required> ' . esc_html__( 'Send one paid test SMS to this number. This code grants no account access.', 'ani-sms' ) . '</label>';
				$this->button( __( 'Send paid test SMS', 'ani-sms' ) );
				break;
			case 'auth':
				$this->form( 'save_auth' );
				echo '<label><input type="checkbox" name="registration" value="1" ' . checked( $s['registration'], true, false ) . '> ' . esc_html__( 'Allow new subscriber accounts when WordPress registration is also enabled', 'ani-sms' ) . '</label>';
				foreach ( array(
					'expiry'     => array( __( 'Code lifetime (seconds)', 'ani-sms' ), 60, 300 ),
					'cooldown'   => array( __( 'Resend cooldown (seconds)', 'ani-sms' ), 30, 180 ),
					'attempts'   => array( __( 'Maximum attempts per challenge', 'ani-sms' ), 3, 10 ),
					'site_limit' => array( __( 'Site issuance limit per hour', 'ani-sms' ), 1, 10000 ),
				) as $name => $rule ) {
					$this->field(
						$name,
						$rule[0],
						'number',
						array(
							'min'      => $rule[1],
							'max'      => $rule[2],
							'required' => 'required',
						)
					); }
				$this->button( __( 'Save changes', 'ani-sms' ) );
				echo '<p>' . esc_html__( 'Codes have six digits. Phone/IP limits cannot be reset by resending. Privileged accounts must use the standard login. Already logged-in subscribers may link an unassigned number after password reauthentication and OTP verification; changing an existing link is not offered.', 'ani-sms' ) . '</p>';
				break;
			case 'modules':
				$reason = ( new \AniSms\Auth\Module( $this->store ) )->unavailable_reason();
				echo '<p>' . esc_html( '' === $reason ? __( 'Authentication dependencies are available.', 'ani-sms' ) : $reason ) . '</p>';
				$this->form( 'save_modules' );
				echo '<label><input type="checkbox" name="auth_enabled" value="1" ' . checked( Settings::enabled(), true, false ) . '> ' . esc_html__( 'Enable mobile authentication', 'ani-sms' ) . '</label>';
				$this->button( __( 'Save module state', 'ani-sms' ) );
				break;
			case 'templates':
				echo '<p>' . esc_html__( 'Local template: account verification. Create and approve the template in SMS.ir first. Configure its ID and exact code parameter under Connection. This plugin never creates, edits or deletes panel templates.', 'ani-sms' ) . '</p>';
				echo '<dl><dt>' . esc_html__( 'Template ID', 'ani-sms' ) . '</dt><dd>' . esc_html( (string) $s['template'] ) . '</dd><dt>' . esc_html__( 'OTP parameter', 'ani-sms' ) . '</dt><dd><code>' . esc_html( $s['parameter'] ) . '</code></dd></dl>';
				break;
			case 'logs':
				$this->logs();
				break;
			case 'help':
				echo '<p>' . esc_html__( 'Add [ani_sms_auth] to a WordPress page. HTTPS is required. Exclude that page and admin-ajax authentication responses from page/CDN caching. Browser tokens are fetched separately and never embedded in cached HTML.', 'ani-sms' ) . '</p>';
				echo '<p>' . esc_html__( 'Server setup: configure ANI_SMS_SECRET_KEY as base64 of 32 random bytes outside the database, then enable ANI_SMS_LIVE_ENABLED only after reviewing the API contract and test account. API keys may also be supplied through ANI_SMS_API_KEY. Never commit these values.', 'ani-sms' ) . '</p>';
				echo '<p>' . esc_html__( 'Logs are kept for 30 days without message bodies or OTP values. Expired challenges are cleaned after one hour and rate metadata after 24 hours beyond its window. Deactivation stops authentication and preserves permanent data. Configure a system cron at least every five minutes on low-traffic sites.', 'ani-sms' ) . '</p>';
				$this->form( 'save_privacy' );
				echo '<label><input type="checkbox" name="purge" value="1" ' . checked( $s['purge'], true, false ) . '> ' . esc_html__( 'On uninstall, permanently delete ANI-SMS settings, credentials, identity links and logs. WordPress users and orders will remain.', 'ani-sms' ) . '</label>';
				$this->button( __( 'Save uninstall preference', 'ani-sms' ) );
				break;
		}
	}
	private function logs(): void {
		$db    = $this->store->db;
		$state = is_string( $_GET['state'] ?? null ) && in_array( $_GET['state'], array( 'accepted', 'failed', 'unknown' ), true ) ? $_GET['state'] : '';
		$page  = max( 1, min( 100000, absint( $_GET['logpage'] ?? 1 ) ) );
		$table = $this->store->table( 'logs' );
		$where = '' !== $state ? $db->prepare( ' WHERE state=%s', $state ) : '';
		$count = (int) $db->get_var( "SELECT COUNT(*) FROM $table$where" );
		$rows  = $db->get_results( $db->prepare( "SELECT * FROM $table$where ORDER BY id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
		echo '<form method="get"><input type="hidden" name="page" value="ani-sms"><input type="hidden" name="tab" value="logs"><label>' . esc_html__( 'Status', 'ani-sms' ) . ' <select name="state"><option value="">' . esc_html__( 'All', 'ani-sms' ) . '</option>';
		foreach ( array( 'accepted', 'failed', 'unknown' ) as $value ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $state, $value, false ) . '>' . esc_html( $value ) . '</option>'; }
		echo '</select></label> <button class="ani-button">' . esc_html__( 'Filter', 'ani-sms' ) . '</button></form><div class="ani-table-scroll"><table><thead><tr>';
		foreach ( array( __( 'Time', 'ani-sms' ), __( 'Operation', 'ani-sms' ), __( 'State', 'ani-sms' ), __( 'Phone', 'ani-sms' ), __( 'Error category', 'ani-sms' ) ) as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( wp_date( 'Y-m-d H:i:s', $row['created'] ), $row['operation'], $row['state'], $row['phone_mask'], $row['error_code'] ) as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			} echo '</tr>'; }
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No events match this filter.', 'ani-sms' ) . '</td></tr>'; }
		echo '</tbody></table></div><div class="ani-pages">';
		foreach ( array(
			-1 => __( 'Previous', 'ani-sms' ),
			1  => __( 'Next', 'ani-sms' ),
		) as $delta => $label ) {
			$next = $page + $delta;
			if ( $next > 0 && $next <= max( 1, (int) ceil( $count / 20 ) ) ) {
				echo '<a href="' . esc_url(
					add_query_arg(
						array(
							'logpage' => $next,
							'state'   => $state,
						)
					)
				) . '">' . esc_html( $label ) . '</a> '; }
		}
		echo '</div>';
	}
}
