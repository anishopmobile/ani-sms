<?php
define('ANI_SMS_LOCAL_TEST',true);$_SERVER['REMOTE_ADDR']='127.0.0.1';require __DIR__.'/bootstrap.php';
$store=new AniSms\Infrastructure\Database($wpdb);$store->migrate();
if(($argv[1]??'')==='--reversal'){
 $id=(int)$argv[2];$pid=(int)$argv[3];$signal=$argv[4];
 $store->lock('discount:'.$pid,function()use($store,$wpdb,$id,$signal){
  $store->query($wpdb->prepare("UPDATE {$store->table('jobs')} SET state='cancelled' WHERE module='discount' AND sequence=%d AND state='queued'",$id));file_put_contents($signal,'ready');sleep(4);
  $store->query($wpdb->prepare("UPDATE {$store->table('discount_requests')} SET state='waiting',revision=%s WHERE id=%d AND state='queued'",bin2hex(random_bytes(16)),$id));
 });exit(0);
}
add_filter('pre_wp_mail','__return_true');update_option('ani_sms_modules',array('auth','marketing','discount'));
$user=wp_insert_user(array('user_login'=>'ani_discount_race_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(30),'role'=>'subscriber'));
$phone='+989127776666';$store->query($wpdb->prepare("DELETE FROM {$store->table('identities')} WHERE phone=%s",$phone));$store->insert('identities',array('phone'=>$phone,'user_id'=>$user,'verified_at'=>time(),'verification_mode'=>'local'));
(new AniSms\Marketing\Subscribers($store))->consent($user,true,AniSms\Marketing\Subscribers::VERSION);
$queue=new AniSms\Infrastructure\Queue($store);$service=new AniSms\Discount\Service($store,$queue);$p=new WC_Product_Simple();$p->set_name('Discount race fixture');$p->set_status('publish');$p->set_regular_price('100');$p->set_stock_status('outofstock');$p->save();$id=$service->request($p->get_id(),$user,true);$p->set_stock_status('instock');$p->set_sale_price('80');$p->set_price('80');$p->save();$service->capture($p->get_id());
$signal=tempnam(sys_get_temp_dir(),'ani_discount_');$process=proc_open(array(PHP_BINARY,__FILE__,'--reversal',(string)$id,(string)$p->get_id(),$signal),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));if(!is_resource($process))throw new RuntimeException('Race client did not start.');
$deadline=microtime(true)+10;while(trim(file_get_contents($signal))!=='ready'&&microtime(true)<$deadline){usleep(100000);clearstatcache(true,$signal);}ani_check(trim(file_get_contents($signal))==='ready','second PHP client owns reversal product lock');
$service->reconcile();$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);ani_check(0===proc_close($process),'independent reversal client completed');unlink($signal);
$row=$store->row($wpdb->prepare("SELECT * FROM {$store->table('discount_requests')} WHERE id=%d",$id));ani_check('waiting'===$row['state']&&''!==$row['phone'],'concurrent reconciliation cannot cancel or erase a re-armed request');
$service->reconcile();$row=$store->row($wpdb->prepare("SELECT * FROM {$store->table('discount_requests')} WHERE id=%d",$id));ani_check('waiting'===$row['state'],'old cancelled job cannot overwrite fresh revision');
$p->delete(true);(new AniSms\Discount\Privacy($store))->remove_user($user);require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);
echo 'Discount concurrency: '.$GLOBALS['ani_checks']." checks passed; two PHP/MySQL clients\n";
