<?php
declare(strict_types=1);
define('STUDIO_TESTING',true);
require __DIR__.'/../app/entry.php';
$dir=sys_get_temp_dir().'/studio-chat-tests-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$count=0;
function check(bool $ok,string $label):void{global$count;if(!$ok)throw new RuntimeException('FAIL: '.$label);$count++;}
function rejects(callable $fn,int $status=400):void{try{$fn();}catch(StudioError $e){check($e->http===$status,'correct rejection '.$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
$env=['APP_URL'=>'https://studio.eskalamos.com','HIGGSFIELD_API_KEY'=>'fixture:key','ELEVENLABS_API_KEY'=>'fixture-eleven','ELEVENLABS_DEFAULT_VOICE_ID'=>'pZv6Kbgq62dtlvkJTupr'];
function connect_fixture(string $scopes='studio:read studio:generate'):array{
    global$dir,$env;
    $client=studio_oauth_registration($dir,['redirect_uris'=>['https://chatgpt.com/connector_platform_oauth_redirect'],'client_name'=>'Fixture']);
    $verifier=str_repeat('v',43);$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $request=studio_oauth_request($dir,$env,['client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uris'][0],'response_type'=>'code','state'=>'fixture-state','code_challenge_method'=>'S256','code_challenge'=>$challenge,'resource'=>studio_chat_resource($env),'scope'=>$scopes]);
    $code=studio_oauth_approve($dir,$env,$request,'owner');
    $input=['grant_type'=>'authorization_code','client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uris'][0],'code'=>$code,'code_verifier'=>$verifier,'resource'=>studio_chat_resource($env)];
    return [$client,$input,studio_oauth_token($dir,$env,$input)];
}
try{
    studio_db($dir.'/account.json',static function(array &$v):void{$v=['id'=>'owner','password_hash'=>password_hash('fixture-password-only',PASSWORD_DEFAULT)];});
    check(studio_oauth_redirect_allowed('https://chatgpt.com/connector_platform_oauth_redirect'),'stable callback');
    foreach(['https://evil.test/connector_platform_oauth_redirect','https://chatgpt.com.evil.test/connector_platform_oauth_redirect','http://chatgpt.com/connector_platform_oauth_redirect','https://user@chatgpt.com/connector_platform_oauth_redirect','https://chatgpt.com/connector_platform_oauth_redirect?next=evil','https://chatgpt.com:443/connector_platform_oauth_redirect','https://chatgpt.com/unknown']as$uri)check(!studio_oauth_redirect_allowed($uri),'callback restricted');
    rejects(fn()=>studio_chat_base(['APP_URL'=>'https://studio.eskalamos.com:9443']),503);
    rejects(fn()=>studio_oauth_registration($dir,['redirect_uris'=>['https://evil.test/callback']]));
    rejects(fn()=>studio_oauth_scopes('studio:admin'));
    [$client,$input,$tokens]=connect_fixture();
    rejects(fn()=>studio_oauth_token($dir,$env,$input)); // authorization code can be used once
    check(!str_contains(file_get_contents($dir.'/oauth.json'),$tokens['access_token'])&&!str_contains(file_get_contents($dir.'/oauth.json'),$tokens['refresh_token']),'only hashes persisted');
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['access_token'];$principal=studio_chat_auth($dir,$env,'studio:generate');
    check($principal['owner']==='owner','token authorizes owner');
    $_SERVER['HTTP_ORIGIN']='https://evil.test';rejects(fn()=>studio_chat_origin($env),403);unset($_SERVER['HTTP_ORIGIN']);
    $request=['request_key'=>'11111111-1111-4111-8111-111111111111','kind'=>'image','prompt'=>'Una casa mediterránea','accept_cost'=>true];
    rejects(fn()=>studio_chat_create($dir,$env,$principal,$request),503);
    $calls=0;$keys=[];
    $GLOBALS['studio_test_transport']=function($provider,$path,$env,$method,$body,$key)use(&$calls,&$keys):array{
        $calls++;$keys[]=$key;
        return ['http'=>202,'error'=>0,'json'=>['request_id'=>'22222222-2222-4222-8222-222222222222'],'body'=>'','type'=>'application/json','correlation'=>'fixture'];
    };
    studio_chat_worker($dir,$env);check(studio_chat_worker_status($dir)['ready'],'cron heartbeat');
    rejects(fn()=>studio_chat_create($dir,$env,$principal,array_replace($request,['accept_cost'=>false])));
    $job=studio_chat_create($dir,$env,$principal,$request);
    check($calls===0&&$job['status']==='prepared','create queues without provider I/O');
    check(!isset($job['grant_id'],$job['owner'],$job['body'],$job['attempts']),'job private fields hidden');
    check(studio_chat_create($dir,$env,$principal,$request)['id']===$job['id'],'duplicate intent recovered');
    check(count(studio_read($dir.'/jobs.json'))===1,'one durable intent');
    rejects(fn()=>studio_chat_create($dir,$env,$principal,array_replace($request,['prompt'=>'Other'])),409);
    studio_chat_worker($dir,$env);studio_chat_worker($dir,$env);
    check($calls===1&&$keys[0]===$job['id'],'worker sends once with original idempotency');
    check(studio_read($dir.'/jobs.json')[$job['id']]['status']==='queued','provider acceptance persisted');
    $cancel=$request;$cancel['request_key']='33333333-3333-4333-8333-333333333333';
    studio_chat_create($dir,$env,$principal,$cancel);
    studio_chat_cancel($dir,$env,$principal,$cancel['request_key']);studio_chat_cancel($dir,$env,$principal,$cancel['request_key']);
    studio_chat_worker($dir,$env);check($calls===1,'canceled intent never submitted');
    rejects(fn()=>studio_chat_tool('studio_get_generation',['job_id'=>$job['id']],$dir,$env,['owner'=>'other']),404);
    $speech=$request;$speech['request_key']='44444444-4444-4444-8444-444444444444';$speech['kind']='speech';$speech['prompt']='Texto corto.';
    studio_chat_create($dir,$env,$principal,$speech);
    check(studio_read($dir.'/jobs.json')[$speech['request_key']]['voice_id']===$env['ELEVENLABS_DEFAULT_VOICE_ID'],'default voice used');
    $GLOBALS['studio_test_transport']=function()use(&$calls):array{$calls++;return ['http'=>0,'error'=>28,'json'=>null,'body'=>'','type'=>'','correlation'=>'fixture'];};
    studio_chat_worker($dir,$env);$before=$calls;studio_chat_worker($dir,$env);
    check($before===$calls&&studio_read($dir.'/jobs.json')[$speech['request_key']]['status']==='unknown','uncertain speech is not retried');
    $audio=$speech;$audio['request_key']='55555555-5555-4555-8555-555555555555';studio_chat_create($dir,$env,$principal,$audio);
    $GLOBALS['studio_test_transport']=fn()=>['http'=>200,'error'=>0,'json'=>null,'body'=>'ID3fixture-audio','type'=>'audio/mpeg','correlation'=>'fixture'];
    studio_chat_worker($dir,$env);
    $public=studio_chat_media($dir,studio_read($dir.'/jobs.json')[$audio['request_key']],$env);
    check(str_starts_with($public['media'][0]['url'],'https://studio.eskalamos.com/studio-api.php?op=download'),'temporary audio link');
    parse_str(parse_url($public['media'][0]['url'],PHP_URL_QUERY),$query);
    check(!str_contains(file_get_contents($dir.'/media-links.json'),$query['token']),'download tokens stored hashed');
    $pending=$request;$pending['request_key']='66666666-6666-4666-8666-666666666666';studio_chat_create($dir,$env,$principal,$pending);
    $refresh=['grant_type'=>'refresh_token','client_id'=>$client['client_id'],'resource'=>studio_chat_resource($env),'refresh_token'=>$tokens['refresh_token']];
    $rotated=studio_oauth_token($dir,$env,$refresh);check($rotated['refresh_token']!==$tokens['refresh_token'],'refresh rotates');
    rejects(fn()=>studio_oauth_token($dir,$env,$refresh));
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$rotated['access_token'];rejects(fn()=>studio_chat_auth($dir,$env),401);
    rejects(fn()=>studio_submit($dir.'/jobs.json',$pending['request_key'],$env,$dir),403);
    studio_chat_worker($dir,$env);check(studio_read($dir.'/jobs.json')[$pending['request_key']]['status']==='canceled','revocation cancels unsubmitted intents');
    [$readClient,$readInput,$readTokens]=connect_fixture('studio:read');$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$readTokens['access_token'];
    rejects(fn()=>studio_chat_auth($dir,$env,'studio:generate'),403);
    studio_oauth_revoke($dir,$readTokens['access_token'],$readClient['client_id']);rejects(fn()=>studio_chat_auth($dir,$env),401);
    [$badClient,$badInput,$badTokens]=connect_fixture();
    $verifier=str_repeat('w',43);$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $req=studio_oauth_request($dir,$env,['response_type'=>'code','client_id'=>$badClient['client_id'],'redirect_uri'=>$badClient['redirect_uris'][0],'state'=>'test','resource'=>studio_chat_resource($env),'code_challenge'=>$challenge,'code_challenge_method'=>'S256']);
    $code=studio_oauth_approve($dir,$env,$req,'owner');$badInput['code']=$code;
    rejects(fn()=>studio_oauth_token($dir,$env,$badInput));$badInput['code_verifier']=$verifier;
    rejects(fn()=>studio_oauth_token($dir,$env,array_replace($badInput,['resource'=>'https://evil.test/mcp'])));
    check(isset(studio_oauth_token($dir,$env,$badInput)['access_token']),'wrong PKCE cannot consume code');
    check(studio_oauth_disconnect($dir,'owner')>=1,'owner disconnect revokes all connections');
    $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$badTokens['access_token'];rejects(fn()=>studio_chat_auth($dir,$env),401);
    unset($_SERVER['HTTP_AUTHORIZATION']);$_GET['access_token']=$tokens['access_token'];rejects(fn()=>studio_chat_auth($dir,$env),401);
    check(count(studio_chat_tools())===6,'all MCP tools exposed');
    $metadata=studio_oauth_metadata($env);check($metadata['authorization_response_iss_parameter_supported']&&$metadata['code_challenge_methods_supported']===['S256'],'issuer and mandatory S256 metadata');
    echo "OK: $count chat/OAuth checks\n";
}finally{foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
