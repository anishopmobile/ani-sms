<?php
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
$plugin_source = dirname( __DIR__, 2 ) . '/wp-content/plugins/ani-sms';
if ( ! is_dir( WP_PLUGIN_DIR . '/ani-sms' ) ) { mkdir( WP_PLUGIN_DIR . '/ani-sms', 0777, true ); }
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
foreach ( $iterator as $file ) {
	$relative = substr( $file->getPathname(), strlen( $plugin_source ) + 1 );
	$target = WP_PLUGIN_DIR . '/ani-sms/' . $relative;
	if ( $file->isDir() ) { if ( ! is_dir( $target ) ) { mkdir( $target, 0777, true ); } } else { copy( $file->getPathname(), $target ); }
}
$result = activate_plugin( 'ani-sms/ani-sms.php' );
if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Test activation failed.' ); }
$theme = WP_CONTENT_DIR . '/themes/ani-fixture';
if ( ! is_dir( $theme ) ) { mkdir( $theme, 0777, true ); }
file_put_contents( $theme . '/style.css', "/*\nTheme Name: ANI-SMS test fixture\nVersion: 1.0\n*/\n" );
file_put_contents( $theme . '/index.php', '<?php ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head><body style="margin:0;padding:16px;background:#f3f4f6;font-family:system-ui,Tahoma,sans-serif"><?php echo do_shortcode("[ani_sms_auth]"); wp_footer(); ?></body></html>' );
switch_theme( 'ani-fixture' );
update_option( 'WPLANG', 'fa_IR' );
$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
if ( $privacy_id ) { wp_update_post( array( 'ID' => $privacy_id, 'post_status' => 'publish' ) ); }
if ( ! $privacy_id ) { $privacy_id = wp_insert_post( array( 'post_title' => 'Test Privacy Policy', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'Disposable test privacy fixture.' ) ); update_option( 'wp_page_for_privacy_policy', $privacy_id ); }
update_option( 'users_can_register', 1 );
update_option( 'ani_sms_modules', array( 'auth' ) );
update_option( 'ani_sms_settings', array_merge( \AniSms\Core\Settings::defaults(), array( 'registration' => true, 'template' => 123 ) ) );
global $wpdb;
$store = new \AniSms\Infrastructure\Database( $wpdb );
foreach ( array( 'challenges', 'rates' ) as $table ) { $store->query( "DELETE FROM {$store->table( $table )}" ); }
$admin = get_user_by( 'login', 'ani_test_admin' );
$password = wp_generate_password( 32, true, true );
wp_set_password( $password, $admin->ID );
file_put_contents( dirname( rtrim( $ani_test_root, '/\\' ) ) . '/test-auth.json', wp_json_encode( array( 'login' => $admin->user_login, 'password' => $password ) ) );
if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { mkdir( WPMU_PLUGIN_DIR, 0777, true ); }
file_put_contents( WPMU_PLUGIN_DIR . '/ani-test-transport.php', <<<'PHP'
<?php
if (!defined('ANI_SMS_TEST_ENV') || !ANI_SMS_TEST_ENV) { exit; }
add_action('init', function() { if (get_locale() === 'fa_IR') { $GLOBALS['wp_locale']->text_direction = 'rtl'; } });
add_filter('pre_http_request', function($pre,$args,$url) {
    if (str_starts_with($url,'https://api.sms.ir/')) {
        if (($args['method'] ?? '') === 'POST') {
            file_put_contents(dirname(rtrim(ABSPATH,'/\\')).'/test-otp.json', $args['body'], LOCK_EX);
            return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"status":1,"data":{"messageId":555,"cost":1}}'];
        }
        return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"status":1,"data":125.5}'];
    }
    return new WP_Error('test_network_disabled','External requests are blocked in tests.');
},10,3);
PHP
);
echo "PASS: plugin activated; disposable browser fixtures prepared.\n";
