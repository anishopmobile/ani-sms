<?php
require __DIR__.'/bootstrap.php';$store=new AniSms\Infrastructure\Database($wpdb);$store->migrate();$queue=new AniSms\Infrastructure\Queue($store);$module=new AniSms\Stock\Module($store,$queue);
ani_check(!function_exists('wc_get_product'),'fixture has no WooCommerce');ani_check(''!==$module->unavailable_reason(),'dependency reason without fatal error');
update_option('ani_sms_modules',array('auth','stock'));update_option('ani_sms_stock_settings',array('template'=>777));
$registry=new AniSms\Core\ModuleRegistry();$registry->register($module);$registry->boot(array('stock'));
$queue->enqueue(array('job_key'=>hash('sha256',random_bytes(32)),'module'=>'stock','subject'=>'stock:999999','sequence'=>999999,'route'=>'restocked','mode'=>'live','phone_mask'=>'','available'=>0),array());$queue->drain();
ani_check(1===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$store->table('jobs')} WHERE module='stock' AND subject='stock:999999' AND state='queued'"),'missing dependency pauses jobs');
ani_check(777===AniSms\Stock\Service::settings()['template'],'settings preserved without WooCommerce');
ani_check(AniSms\Core\Settings::enabled(),'authentication remains independent');update_option('ani_sms_modules',array('auth'));$queue->drain();
ani_check(1===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$store->table('jobs')} WHERE module='stock' AND subject='stock:999999' AND state='cancelled'"),'disabled module cancels even without WooCommerce');$store->query("DELETE FROM {$store->table('jobs')} WHERE module='stock'");
echo 'Stock absent: '.$GLOBALS['ani_checks']." checks passed\n";
