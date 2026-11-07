<?php
/** Separate explicit opt-in and bearer-token opt-out, available even after module disable. @package AniSms */
namespace AniSms\Marketing;
use AniSms\Infrastructure\Database;

final class Frontend {
	public function __construct( private Database $store ) {}
	public function boot(): void {
		add_action(
			'wp_enqueue_scripts',
			static function () {
				if ( \AniSms\Core\Settings::module_enabled( 'marketing' ) && is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'ani_sms_marketing' ) ) {
					wp_enqueue_style( 'ani-sms-marketing', plugins_url( 'assets/marketing.css', dirname( __DIR__, 2 ) . '/ani-sms.php' ), array(), '1.2.0-rc1' ); }
			}
		);
		add_shortcode( 'ani_sms_marketing', array( $this, 'render' ) );
		add_action( 'admin_post_ani_sms_marketing', array( $this, 'save' ) );
		add_action( 'template_redirect', array( $this, 'unsubscribe' ), 1 );
	}
	public function render(): string {
		if ( ! \AniSms\Core\Settings::module_enabled( 'marketing' ) ) {
			return '<p>' . esc_html__( 'Marketing subscriptions are currently disabled.', 'ani-sms' ) . '</p>'; }
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Sign in with a verified mobile to manage optional SMS subscriptions.', 'ani-sms' ) . '</p>'; }
		try {
			$row = ( new Subscribers( $this->store ) )->for_user( get_current_user_id() );
		} catch ( \Throwable $error ) {
			return '<p>' . esc_html__( 'Unavailable', 'ani-sms' ) . '</p>'; }
		ob_start();
		echo '<section class="ani-marketing"><h2>' . esc_html__( 'Optional SMS subscription', 'ani-sms' ) . '</h2><p>' . esc_html__( 'Login and purchases do not grant marketing consent. You can unsubscribe at any time.', 'ani-sms' ) . '</p>';
		if ( isset( $_GET['ani_marketing_saved'] ) ) {
			echo '<p role="status">' . esc_html__( 'Subscription preferences updated.', 'ani-sms' ) . '</p>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ani_sms_marketing"><input type="hidden" name="consent_version" value="' . esc_attr( Subscribers::VERSION ) . '">';
		wp_nonce_field( 'ani_sms_marketing' );
		if ( $row && 'consented' === $row['state'] && ( \AniSms\Security\LocalTest::active() ? 'local' : 'live' ) === $row['mode'] ) {
			echo '<input type="hidden" name="preference" value="withdraw"><button type="submit">' . esc_html__( 'Unsubscribe from marketing SMS', 'ani-sms' ) . '</button>';
		} else {
			echo '<input type="hidden" name="preference" value="subscribe"><label><input type="checkbox" name="explicit_consent" value="1" required> ' . esc_html__( 'I explicitly agree to receive marketing SMS from this site on my verified mobile.', 'ani-sms' ) . '</label><p><a href="' . esc_url( get_privacy_policy_url() ) . '">' . esc_html__( 'Privacy policy', 'ani-sms' ) . '</a></p><button type="submit">' . esc_html__( 'Confirm optional subscription', 'ani-sms' ) . '</button>';
		}
		echo '</form></section>';
		return ob_get_clean();
	}
	public function save(): void {
		try {
			\AniSms\Auth\Browser::origin();
			check_admin_referer( 'ani_sms_marketing' );
			if ( ! is_user_logged_in() ) {
				throw new \RuntimeException( 'Authentication required.' ); }
			( new \AniSms\Security\RateLimiter( $this->store, new \AniSms\Security\Secrets() ) )->hit( 'marketing-pref', (string) get_current_user_id(), 20, HOUR_IN_SECONDS );
			$subscribers = new Subscribers( $this->store );
			if ( 'withdraw' === ( $_POST['preference'] ?? '' ) ) {
				$row = $subscribers->for_user( get_current_user_id() );
				if ( $row ) {
					$subscribers->suppress( (int) $row['id'], false ); }
			} else {
				$subscribers->consent( get_current_user_id(), '1' === ( $_POST['explicit_consent'] ?? '' ), is_string( $_POST['consent_version'] ?? null ) ? $_POST['consent_version'] : '' );
			}
			wp_safe_redirect( add_query_arg( 'ani_marketing_saved', '1', wp_get_referer() ? wp_get_referer() : home_url( '/' ) ) );
			exit;
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'Subscription could not be changed. Verify your mobile and try again.', 'ani-sms' ), '', array( 'response' => 400 ) ); }
	}
	public function unsubscribe(): void {
		if ( ! isset( $_GET['ani_sms_unsubscribe'] ) ) {
			return; }
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$token = is_string( $_GET['ani_sms_unsubscribe'] ) ? $_GET['ani_sms_unsubscribe'] : '';
		$done  = false;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && '1' === ( $_POST['confirm'] ?? '' ) ) {
			try {
				( new Subscribers( $this->store ) )->unsubscribe( $token );
				$done = true;
			} catch ( \Throwable $error ) {
				wp_die( esc_html__( 'Please try again shortly.', 'ani-sms' ), '', array( 'response' => 503 ) ); }
		}
		$text = $done ? __( 'Unsubscribe request processed. No account login is required.', 'ani-sms' ) : __( 'Confirm that you want to stop marketing SMS from this site.', 'ani-sms' );
		$body = '<p>' . esc_html( $text ) . '</p>';
		if ( ! $done ) {
			$body .= '<form method="post"><button name="confirm" value="1">' . esc_html__( 'Confirm unsubscribe', 'ani-sms' ) . '</button></form>'; }
		wp_die( $body, esc_html__( 'SMS subscription', 'ani-sms' ), array( 'response' => 200 ) ); // Escaped text plus fixed form only.
	}
}
