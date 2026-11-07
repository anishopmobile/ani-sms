<?php
require __DIR__.'/bootstrap.php';
$calls=0;$reply='accepted';
add_filter('pre_http_request',function($pre,$args,$url)use(&$calls,&$reply){
 ++$calls;$body=json_decode($args['body'],true);
 ani_check('https://api.sms.ir/v1/send/bulk'===$url&&'POST'===$args['method'],'marketing uses bulk endpoint, never verify');
 ani_check('300012345'===$body['lineNumber']&&array('09127770001')===$body['mobiles']&&null===$body['sendDateTime'],'official line/mobiles/immediate contract');
 if('timeout'===$reply){return new WP_Error('timeout');}
 $json='accepted'===$reply?'[12345]':('multiple'===$reply?'[1,2]':'["12345"]');
 return array('response'=>array('code'=>200),'body'=>'{"status":1,"data":{"messageIds":'.$json.'}}','headers'=>array());
},10,3);
$provider=new AniSms\Provider\SmsIr('dummy-key');
ani_check($provider->send_text('09127770001','300012345','Local test')->accepted(),'one message ID accepted');
foreach(array('multiple','malformed','timeout')as $reply){ani_check('unknown'===$provider->send_text('09127770001','300012345','Local test')->state,'ambiguous response terminal unknown: '.$reply);}
$before=$calls;ani_check('failed'===$provider->send_text('09127770001','','x')->state,'missing line rejected');ani_check('failed'===$provider->send_text('09127770001','300012345',str_repeat('x',1501))->state,'oversized rendered body rejected');ani_check($before===$calls,'invalid campaigns never reach network');
echo 'Marketing transport: '.$GLOBALS['ani_checks']." checks passed; all HTTP mocked\n";
