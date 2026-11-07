<?php
require __DIR__.'/bootstrap.php';
$store=new AniSms\Infrastructure\Database($wpdb);$store->migrate();
$queue=new AniSms\Infrastructure\Queue($store);
$module=new AniSms\WooCommerce\Module($store,$queue);
ani_check(!class_exists('WooCommerce'),'fixture has no WooCommerce');
ani_check(''!==$module->unavailable_reason(),'dependency reason without fatal error');
update_option('ani_sms_wc_settings',array('customer_enabled'=>true));
update_option('ani_sms_modules',array('auth','woocommerce'));
$registry=new AniSms\Core\ModuleRegistry();$registry->register($module);$registry->boot(array('woocommerce'));
ani_check(AniSms\Core\Settings::enabled(),'core authentication independent');
$queue->enqueue(array('job_key'=>hash('sha256',random_bytes(32)),'module'=>'woocommerce','subject'=>'wc:999999','sequence'=>1,'route'=>'created_customer','mode'=>'live','user_id'=>0,'email_hash'=>'','phone_mask'=>'','available'=>0),array());
$queue->drain();
ani_check(1===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$store->table('jobs')} WHERE subject='wc:999999' AND state='queued'"),'missing dependency pauses jobs');
ani_check(true===get_option('ani_sms_wc_settings')['customer_enabled'],'missing WooCommerce preserves settings');
update_option('ani_sms_modules',array('auth'));$queue->drain();
ani_check(1===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$store->table('jobs')} WHERE subject='wc:999999' AND state='cancelled'"),'disabled module cancels even while dependency absent');
$store->query("DELETE FROM {$store->table('jobs')} WHERE subject='wc:999999'");
echo 'WooCommerce absent checks: '.$GLOBALS['ani_checks']." passed\n";
