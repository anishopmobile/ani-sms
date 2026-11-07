<?php
require __DIR__.'/bootstrap.php';
$store=new AniSms\Infrastructure\Database($wpdb);$store->migrate();add_filter('pre_wp_mail','__return_true');
$original_home=get_option('home');update_option('home','https://example.test');update_option('ani_sms_modules',array('auth','marketing'));
$user=wp_insert_user(array('user_login'=>'ani_boundary_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(30),'role'=>'subscriber'));
$phone='+989127779999';$store->query($wpdb->prepare("DELETE FROM {$store->table('identities')} WHERE phone=%s",$phone));$store->insert('identities',array('phone'=>$phone,'user_id'=>$user,'verified_at'=>time(),'verification_mode'=>'local'));
$sub=new AniSms\Marketing\Subscribers($store);
ani_rejects(fn()=>$sub->consent($user,true,AniSms\Marketing\Subscribers::VERSION),'local-only phone proof cannot grant live consent');
$store->query($wpdb->prepare("UPDATE {$store->table('identities')} SET verification_mode='unknown' WHERE user_id=%d",$user));
ani_rejects(fn()=>$sub->consent($user,true,AniSms\Marketing\Subscribers::VERSION),'legacy unclassified identity cannot grant live consent');
// Fixture models a successful live OTP proof; no actual SMS is sent.
$store->query($wpdb->prepare("UPDATE {$store->table('identities')} SET verification_mode='live' WHERE user_id=%d",$user));
$id=$sub->consent($user,true,AniSms\Marketing\Subscribers::VERSION);$row=$sub->for_user($user);
ani_check('live'===$row['mode'],'explicit consent after live phone proof is live-tagged');
$campaigns=new AniSms\Marketing\Campaigns($store,new AniSms\Infrastructure\Queue($store));
ani_check(0===count($campaigns->audience(array('mode'=>'local','tag'=>''))),'live consent excluded from local audience');
update_option('home','http://example.test');ani_rejects(fn()=>$sub->consent($user,true,AniSms\Marketing\Subscribers::VERSION),'insecure live consent rejected');
$sub->suppress($id,true);require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);update_option('home',$original_home);update_option('ani_sms_modules',array('auth'));
echo 'Marketing live boundary: '.$GLOBALS['ani_checks']." checks passed; no SMS\n";
