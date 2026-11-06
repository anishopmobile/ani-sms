<?php
/** @package AniSms */
namespace AniSms\Auth;

final class Frontend {
	public function __construct( private string $reason, private \AniSms\Infrastructure\Database $store ) {}
	public function boot(): void {
		add_shortcode( 'ani_sms_auth', array( $this, 'render' ) );
		add_action(
			'wp_enqueue_scripts',
			function () {
				if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'ani_sms_auth' ) ) {
					$this->assets(); }
			}
		);
	}
	private function assets(): void {
		$base = plugins_url( 'assets/', dirname( __DIR__, 2 ) . '/ani-sms.php' );
		wp_enqueue_style( 'ani-sms-front', $base . 'frontend.css', array(), '1.0.0-rc3' );
		wp_enqueue_script( 'ani-sms-front', $base . 'frontend.js', array(), '1.0.0-rc3', true );
		wp_localize_script(
			'ani-sms-front',
			'aniSmsText',
			array(
				'waiting'     => __( 'Sending…', 'ani-sms' ),
				'sent'        => __( 'If this number is eligible, a code has been sent.', 'ani-sms' ),
				'error'       => __( 'Connection failed. Try again.', 'ani-sms' ),
				'success'     => __( 'Verified successfully.', 'ani-sms' ),
				'expired'     => __( 'The code has expired. Request a new code.', 'ani-sms' ),
				'retry'       => __( 'Resend code', 'ani-sms' ),
				'wait'        => __( 'Wait', 'ani-sms' ),
				'unavailable' => __( 'Authentication is unavailable. Use the standard login.', 'ani-sms' ),
				'localCode'   => __( 'Local test code (no SMS sent):', 'ani-sms' ),
				'localReady'  => __( 'Test code created. No SMS was sent.', 'ani-sms' ),
			)
		);
	}
	public function render(): string {
		$this->assets();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); }
		if ( ! headers_sent() ) {
			nocache_headers(); }
		$uid      = wp_unique_id( 'ani-auth-' );
		$identity = is_user_logged_in() ? $this->store->row( $this->store->db->prepare( "SELECT phone FROM {$this->store->table( 'identities' )} WHERE user_id=%d", get_current_user_id() ) ) : null;
		ob_start();
		?>
		<section class="ani-auth" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php?action=ani_sms_auth' ) ); ?>" aria-labelledby="<?php echo esc_attr( $uid ); ?>title">
			<div class="ani-auth-brand" aria-hidden="true">ANI<span>SMS</span></div>
			<?php
			if ( \AniSms\Security\LocalTest::active() ) :
				?>
				<p class="ani-auth-test-banner" role="note"><?php esc_html_e( 'Local test mode: no SMS is sent. Test codes appear here. New registrations create real local WordPress accounts. Privileged accounts still use the standard login.', 'ani-sms' ); ?></p><?php endif; ?>
			<h2 id="<?php echo esc_attr( $uid ); ?>title"><?php echo esc_html( is_user_logged_in() ? __( 'Verify and link your mobile', 'ani-sms' ) : __( 'Login / Register', 'ani-sms' ) ); ?></h2>
			<?php if ( $identity ) : ?>
				<p class="ani-auth-linked"><?php esc_html_e( 'Your mobile is already verified. Changing a linked number is not offered in this release.', 'ani-sms' ); ?></p>
				<p dir="ltr"><?php echo esc_html( \AniSms\Infrastructure\Log::mask( $identity['phone'] ) ); ?></p>
			<?php elseif ( '' !== $this->reason ) : ?>
				<p><?php esc_html_e( 'Mobile authentication is currently unavailable.', 'ani-sms' ); ?></p>
				<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Standard WordPress login', 'ani-sms' ); ?></a>
			<?php else : ?>
				<p><?php esc_html_e( 'Your number is used to verify account ownership. Login does not subscribe you to marketing.', 'ani-sms' ); ?></p>
				<form class="ani-auth-phone" novalidate>
					<label for="<?php echo esc_attr( $uid ); ?>phone"><?php esc_html_e( 'Mobile number', 'ani-sms' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>phone" name="phone" type="tel" dir="ltr" inputmode="tel" autocomplete="tel" maxlength="40" required aria-describedby="<?php echo esc_attr( $uid ); ?>status">
					<?php if ( is_user_logged_in() ) : ?>
						<label for="<?php echo esc_attr( $uid ); ?>password"><?php esc_html_e( 'Current account password', 'ani-sms' ); ?></label>
						<input id="<?php echo esc_attr( $uid ); ?>password" type="password" name="password" autocomplete="current-password" required>
					<?php endif; ?>
					<button type="submit"><?php esc_html_e( 'Send verification code', 'ani-sms' ); ?></button>
				</form>
				<form class="ani-auth-code" hidden novalidate>
					<p class="ani-auth-number" dir="ltr"></p>
					<p class="ani-auth-test-code" hidden role="status" aria-live="polite"></p>
					<label for="<?php echo esc_attr( $uid ); ?>code"><?php esc_html_e( 'Six-digit verification code', 'ani-sms' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>code" name="code" type="text" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required aria-describedby="<?php echo esc_attr( $uid ); ?>status">
					<button type="submit"><?php esc_html_e( 'Verify', 'ani-sms' ); ?></button>
					<button type="button" class="ani-auth-resend secondary" disabled><?php esc_html_e( 'Resend code', 'ani-sms' ); ?></button>
					<span class="ani-auth-timer" aria-live="off"></span>
					<button type="button" class="ani-auth-edit secondary"><?php esc_html_e( 'Edit number', 'ani-sms' ); ?></button>
				</form>
				<p id="<?php echo esc_attr( $uid ); ?>status" class="ani-auth-status" role="status" aria-live="polite"></p>
				<noscript><p><?php esc_html_e( 'Enable JavaScript to use mobile authentication, or use the standard login.', 'ani-sms' ); ?></p></noscript>
				<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Standard WordPress login', 'ani-sms' ); ?></a>
			<?php endif; ?>
			<?php
			if ( get_privacy_policy_url() ) :
				?>
				<a class="ani-auth-privacy" href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php esc_html_e( 'Privacy policy', 'ani-sms' ); ?></a><?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
