<?php
declare(strict_types=1);
require __DIR__.'/../src/WaixError.php';
require __DIR__.'/../src/Client.php';
require __DIR__.'/../src/Webhook.php';
use Waix\Client;
use Waix\WaixError;
use Waix\Webhook;
function check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$key='f5bf0474-d4b6-4ca5-bd1b-92e44f3ad0fb'; $calls=[];
$api=new Client('fixture',transport:static function($method,$url,$headers,$body,$timeout) use (&$calls): array {
    $calls[]=compact('method','url','headers','body','timeout');
    if (str_ends_with($url,'/connections')) return ['status'=>429,'headers'=>['Retry-After'=>'60'],'body'=>'{"error":"Wait","code":"RATE_LIMITED","request_id":"req-fixture"}'];
    return ['status'=>202,'headers'=>[],'body'=>'{"data":{"id":"result"},"pagination":{"next_before":"next"}}'];
});
$result=$api->messages->send(['connection_id'=>$key,'to'=>'+77000000000','type'=>'text','text'=>['body'=>'Сәлем']],$key);
check($result['data']['id']==='result','Envelope');check($calls[0]['url']==='https://waix.kz/api/v1/messages','Endpoint');
check(in_array('Authorization: Bearer fixture',$calls[0]['headers'],true),'Authorization');check(in_array('Idempotency-Key: '.$key,$calls[0]['headers'],true),'Idempotency');
$api->otp->send(['to'=>'+77000000000'],$key);$api->otp->verify($key,'123456');$api->otp->status($key);
$api->connections->updateProfile($key,['about'=>'WAIX']);check($calls[4]['method']==='PUT','Profile method');
$api->templates->update($key,'order_ready',['components'=>[]]);check($calls[5]['method']==='PATCH','Template method');
$before=count($calls);try{$api->connections->list();throw new RuntimeException('Must reject 429');}catch(WaixError $e){check($e->status===429&&$e->requestId==='req-fixture'&&$e->retryAfter==='60','Error metadata');}check(count($calls)===$before+1,'No retries');
foreach ([fn()=>new Client('fixture','http://example.com/api/v1'),fn()=>$api->messages->send([],'bad'),fn()=>$api->request('GET','//attacker.test')] as $action){try{$action();throw new RuntimeException('Validation was skipped');}catch(InvalidArgumentException $e){}}
$raw='{"message":"Сәлем"}';$ts='1770000000';$sig='v1='.hash_hmac('sha256',$ts.'.'.$raw,'secret');
check(Webhook::verify($raw,$ts,$sig,'secret',now:(int)$ts),'Valid webhook');check(!Webhook::verify('{}',$ts,$sig,'secret',now:(int)$ts),'Tampered body');check(!Webhook::verify($raw,$ts,$sig,'secret',now:(int)$ts+301),'Expired webhook');check(!Webhook::verify($raw,$ts,$sig,'wrong',now:(int)$ts),'Wrong secret');
if (getenv('WAIX_TEST_URL')) {
    $live=new Client('fixture',getenv('WAIX_TEST_URL'));
    $response=$live->messages->send(['connection_id'=>$key,'to'=>'+77000000000','type'=>'text','text'=>['body'=>'Test']],$key);
    check($response['data']['id']==='http-fixture','Real cURL transport');
    try{$live->connections->list();throw new RuntimeException('Redirect should fail');}catch(WaixError $e){check($e->status===302,'Do not follow redirects');}
}
echo "PHP SDK: all contract, error and signature checks passed\n";
