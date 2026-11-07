<?php
/** Small complete campaign workflow: draft -> preview -> explicit approval -> controls. @package AniSms */
namespace AniSms\Marketing;
use AniSms\Infrastructure\Database;
use AniSms\Infrastructure\Queue;

final class Admin {
	public function __construct( private Database $store, private array $pending = array() ) {}
	public function action( string $operation, array $input ): void {
		if ( ! \AniSms\Core\Settings::module_enabled( 'marketing' ) ) {
			throw new \RuntimeException( 'Module disabled.' ); }
		$campaigns = new Campaigns( $this->store, new Queue( $this->store ) );
		$id        = absint( $input['campaign'] ?? 0 );
		switch ( $operation ) {
			case 'marketing_import':
				$text = $input['phones'] ?? '';
				$tag  = $input['tag'] ?? '';
				if ( ! is_string( $text ) || strlen( $text ) > 2000 || ! is_string( $tag ) ) {
					throw new \InvalidArgumentException( 'Invalid list.' ); }
				$phones = preg_split( '/[\s,]+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
				if ( count( $phones ) < 1 || count( $phones ) > 50 || ! preg_match( '/^[a-z0-9_-]{0,40}$/D', $tag ) ) {
					throw new \InvalidArgumentException( 'List limit.' ); }
				$phones = array_unique( array_map( array( \AniSms\Support\Phone::class, 'normalize' ), $phones ) );
				foreach ( $phones as $phone ) {
					( new Subscribers( $this->store ) )->import( $phone, $tag ); }
				break;
			case 'marketing_create':
				$campaigns->create( $input );
				break;
			case 'marketing_approve':
				$campaigns->approve( $id, is_string( $input['preview'] ?? null ) ? $input['preview'] : '', '1' === ( $input['confirm'] ?? '' ), get_current_user_id() );
				break;
			case 'marketing_control':
				$campaigns->control( $id, is_string( $input['control'] ?? null ) ? $input['control'] : '' );
				break;
			case 'marketing_process':
				do_action( 'ani_sms_dispatch' );
				break;
			case 'marketing_tag':
				( new Subscribers( $this->store ) )->tag( absint( $input['subscriber'] ?? 0 ), is_string( $input['tag'] ?? null ) ? $input['tag'] : '!' );
				break;
			case 'marketing_erase':
				if ( '1' !== ( $input['erase_confirm'] ?? '' ) ) {
					throw new \InvalidArgumentException( 'Erasure confirmation required.' ); }
				( new Subscribers( $this->store ) )->suppress( absint( $input['subscriber'] ?? 0 ), true, 'privacy' );
				break;
			case 'marketing_suppress':
				( new Subscribers( $this->store ) )->suppress( absint( $input['subscriber'] ?? 0 ), false, 'admin_action' );
				break;
			default:
				throw new \InvalidArgumentException( 'Unknown marketing action.' );
		}
	}
	private function form( string $operation, int $id = 0 ): void {
		echo '<form method="post" class="ani-settings ani-order-settings">';
		wp_nonce_field( 'ani_sms_admin' );
		echo '<input type="hidden" name="ani_op" value="' . esc_attr( $operation ) . '"><input type="hidden" name="campaign" value="' . esc_attr( (string) $id ) . '">';
	}
	private function field( string $name, string $label, string $type = 'text', string $value = '' ): void {
		if ( in_array( $this->pending['ani_op'] ?? '', array( 'marketing_create', 'marketing_import' ), true ) && is_string( $this->pending[ $name ] ?? null ) ) {
			$value = $this->pending[ $name ]; }
		echo '<label class="ani-field"><span>' . esc_html( $label ) . '</span>';
		if ( 'textarea' === $type ) {
			echo '<textarea name="' . esc_attr( $name ) . '" rows="4">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '">'; }
		echo '</label>';
	}
	private function button( string $label ): void {
		echo '<button class="ani-button">' . esc_html( $label ) . '</button></form>'; }
	public function render(): void {
		$module = new Module( $this->store, new Queue( $this->store ) );
		$module->reconcile();
		echo '<p>' . esc_html__( 'Marketing is independent of login and orders. Imports are pending only and never grant consent or remove suppression. Add [ani_sms_marketing] to an uncached account page for verified users to opt in explicitly.', 'ani-sms' ) . '</p>';
		if ( ! \AniSms\Core\Settings::module_enabled( 'marketing' ) ) {
			echo '<p>' . esc_html__( 'Enable the marketing module under Modules before creating campaigns.', 'ani-sms' ) . '</p>';
			return; }
		echo '<p>' . esc_html__( 'Local campaigns send no SMS and require no API or sending line. Live marketing uses a permitted SMS.ir sending line, never an OTP template. Delivery to blocked numbers is not promised.', 'ani-sms' ) . '</p>';
		$this->form( 'marketing_import' );
		echo '<h3>' . esc_html__( 'Import pending contacts (maximum 50)', 'ani-sms' ) . '</h3>';
		$this->field( 'phones', __( 'Mobiles, one per line', 'ani-sms' ), 'textarea' );
		$this->field( 'tag', __( 'Local group tag (letters, digits, dash or underscore)', 'ani-sms' ) );
		$this->button( __( 'Import without consent', 'ani-sms' ) );
		echo '<h3>' . esc_html__( 'Recent contacts', 'ani-sms' ) . '</h3>';
		$page = max( 1, min( 100000, absint( $_GET['contacts_page'] ?? 1 ) ) );
		$rows = $this->store->db->get_results( $this->store->db->prepare( "SELECT * FROM {$this->store->table( 'subscribers' )} ORDER BY id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
		echo '<div class="ani-table-scroll ani-marketing-contacts" tabindex="0"><table><thead><tr><th>' . esc_html__( 'Phone', 'ani-sms' ) . '</th><th>' . esc_html__( 'State', 'ani-sms' ) . '</th><th>' . esc_html__( 'Consent version', 'ani-sms' ) . '</th><th>' . esc_html__( 'Local group', 'ani-sms' ) . '</th><th>' . esc_html__( 'Action', 'ani-sms' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			try {
				$mask = '' === $row['phone'] ? '—' : \AniSms\Infrastructure\Log::mask( ( new \AniSms\Security\Secrets() )->decrypt( $row['phone'] ) );
			} catch ( \Throwable $error ) {
				$mask = '—'; }
			echo '<tr><td dir="ltr">' . esc_html( $mask ) . '</td><td>' . esc_html( $row['state'] ) . '</td><td>' . esc_html( $row['version'] ) . '</td><td>' . esc_html( $row['tag'] ) . '</td><td>';
			$this->form( 'marketing_tag' );
			echo '<input type="hidden" name="subscriber" value="' . esc_attr( (string) $row['id'] ) . '">';
			$this->field( 'tag', __( 'Local group', 'ani-sms' ), 'text', $row['tag'] );
			$this->button( __( 'Save group', 'ani-sms' ) );
			$this->form( 'marketing_suppress' );
			echo '<input type="hidden" name="subscriber" value="' . esc_attr( (string) $row['id'] ) . '">';
			$this->button( __( 'Suppress', 'ani-sms' ) );
			$this->form( 'marketing_erase' );
			echo '<input type="hidden" name="subscriber" value="' . esc_attr( (string) $row['id'] ) . '"><label><input type="checkbox" name="erase_confirm" value="1" required> ' . esc_html__( 'Identity verified for erasure', 'ani-sms' ) . '</label>';
			$this->button( __( 'Erase personal data; retain suppression', 'ani-sms' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table></div><p>';
		foreach ( array(
			-1 => __( 'Previous', 'ani-sms' ),
			1  => __( 'Next', 'ani-sms' ),
		) as $delta => $label ) {
			if ( $page + $delta > 0 && ( $delta < 0 || count( $rows ) === 20 ) ) {
				echo '<a href="' . esc_url( add_query_arg( 'contacts_page', $page + $delta ) ) . '">' . esc_html( $label ) . '</a> '; }
		}
		echo '</p>';
		$this->form( 'marketing_create' );
		echo '<h3>' . esc_html__( 'Create campaign draft', 'ani-sms' ) . '</h3>';
		$this->field( 'title', __( 'Campaign title', 'ani-sms' ) );
		$this->field( 'body', __( 'Message (up to 1000 characters; variables: {site_name}, {unsubscribe_url})', 'ani-sms' ), 'textarea' );
		$this->field( 'tag', __( 'Audience group (blank means all consented contacts)', 'ani-sms' ) );
		$this->field( 'line', __( 'Permitted sending line (blank for local simulation)', 'ani-sms' ) );
		$this->field( 'scheduled', __( 'Schedule in site timezone (within seven days; blank means now)', 'ani-sms' ), 'datetime-local' );
		$this->button( __( 'Save draft and preview', 'ani-sms' ) );
		$campaigns = new Campaigns( $this->store, new Queue( $this->store ) );
		$list      = $this->store->db->get_results( "SELECT * FROM {$this->store->table( 'campaigns' )} ORDER BY id DESC LIMIT 20", ARRAY_A );
		echo '<h3>' . esc_html__( 'Recent campaigns', 'ani-sms' ) . '</h3><ul>';
		foreach ( $list as $item ) {
			echo '<li><a href="' . esc_url( add_query_arg( 'campaign', $item['id'] ) ) . '">' . esc_html( $item['title'] ) . '</a> · ' . esc_html( $item['state'] ) . ' · ' . esc_html( $item['mode'] ) . '</li>'; }
		echo '</ul>';
		$id       = absint( $_GET['campaign'] ?? ( $list[0]['id'] ?? 0 ) );
		$campaign = $campaigns->get( $id );
		if ( $campaign ) {
			echo '<h3>' . esc_html( $campaign['title'] ) . '</h3>';
			if ( 'draft' === $campaign['state'] ) {
				try {
					$preview = $campaigns->preview( $id );
					echo '<p>' . esc_html__( 'Eligible recipients (deduplicated, maximum 200):', 'ani-sms' ) . ' <strong class="ani-audience-count">' . esc_html( (string) $preview['count'] ) . '</strong></p><pre class="ani-campaign-preview">' . esc_html( $preview['text'] ) . '</pre>';
					$this->form( 'marketing_approve', $id );
					echo '<input type="hidden" name="preview" value="' . esc_attr( $preview['fingerprint'] ) . '"><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I approve this audience and schedule. Live mode can incur SMS charges.', 'ani-sms' ) . '</label>';
					$this->button( __( 'Approve campaign', 'ani-sms' ) );
				} catch ( \Throwable $error ) {
					echo '<p>' . esc_html__( 'Audience exceeds the 200-recipient limit. Split contacts into smaller groups.', 'ani-sms' ) . '</p>'; }
			}
			$stats = $this->store->db->get_results( $this->store->db->prepare( "SELECT state,COUNT(*) AS total FROM {$this->store->table( 'campaign_recipients' )} WHERE campaign_id=%d GROUP BY state", $id ), ARRAY_A );
			foreach ( $stats as $stat ) {
				echo '<p class="ani-campaign-stat">' . esc_html( $stat['state'] . ': ' . $stat['total'] ) . '</p>'; }
			foreach ( array(
				'pause'  => __( 'Pause campaign', 'ani-sms' ),
				'resume' => __( 'Resume campaign', 'ani-sms' ),
				'cancel' => __( 'Cancel campaign', 'ani-sms' ),
			) as $action => $label ) {
				if ( ( 'pause' === $action && 'approved' !== $campaign['state'] ) || ( 'resume' === $action && 'paused' !== $campaign['state'] ) || ! in_array( $campaign['state'], array( 'draft', 'approved', 'paused' ), true ) ) {
					continue; }
				$this->form( 'marketing_control', $id );
				echo '<input type="hidden" name="control" value="' . esc_attr( $action ) . '">';
				$this->button( $label );
			}
			if ( \AniSms\Security\LocalTest::active() ) {
				$recipient = $this->store->row( $this->store->db->prepare( "SELECT s.* FROM {$this->store->table( 'campaign_recipients' )} r INNER JOIN {$this->store->table( 'subscribers' )} s ON s.id=r.subscriber_id WHERE r.campaign_id=%d AND s.state='consented' LIMIT 1", $id ) );
				if ( $recipient ) {
					try {
						echo '<details><summary>' . esc_html__( 'Local message preview · no SMS sent', 'ani-sms' ) . '</summary><pre class="ani-campaign-preview">' . esc_html( $campaigns->message( $campaign, $recipient ) ) . '</pre></details>';
					} catch ( \Throwable $error ) {
						echo esc_html__( 'Unavailable', 'ani-sms' ); }
				}
			}
		}
		$this->form( 'marketing_process' );
		$this->button( __( 'Process eligible jobs', 'ani-sms' ) );
	}
}
