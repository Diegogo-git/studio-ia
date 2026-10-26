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
    // Import a fictitious PNG, then animate its durable asset without live provider calls.
    foreach(['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','192.0.0.1','198.18.0.1','224.0.0.1','240.0.0.1','::1']as$ip)check(!studio_image_public_ip($ip),'non-public image target denied');
    check(studio_image_public_ip('8.8.8.8'),'public image address accepted');
    rejects(fn()=>studio_image_target('http://example.test/x.png'));
    rejects(fn()=>studio_image_target('https://user:pass@example.test/x.png'));
    rejects(fn()=>studio_image_target('https://127.0.0.1/x.png'));
    rejects(fn()=>studio_image_target('https://example.test:8443/x.png'));
    $chunk=static fn($type,$data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',320,568,8,2,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\x90\xa0\xb0",320),568))).$chunk('IEND','');
    check(studio_image_info($png)['height']===568,'real image bytes inspected');
    rejects(fn()=>studio_image_info('<svg></svg>'));
    rejects(fn()=>studio_image_info(str_repeat('x',10485761)),413);
    $transfers=[];$presigns=0;$sent=[];
    $GLOBALS['studio_test_image_transfer']=static function($url,$upload,$headers)use(&$transfers,$png):array{
        $transfers[]=[$upload===null?'GET':'PUT',$headers];return ['http'=>200,'body'=>$upload===null?$png:''];
    };
    $GLOBALS['studio_test_transport']=static function($provider,$path,$env,$method,$body,$key)use(&$presigns,&$sent):array{
        if($path==='files/generate-upload-url'){
            $presigns++;check($body['content_type']==='image/png','presign matches inspected MIME');
            return ['http'=>200,'error'=>0,'json'=>['upload_url'=>'https://storage.example.test/image?signature=private','public_url'=>'https://cdn.example.test/scene.png','upload_headers'=>['Content-Type'=>'image/png','x-amz-tagging'=>'retention=temporary']],'body'=>'','type'=>'application/json','correlation'=>'fixture'];
        }
        $sent[]=[$path,$body,$key];return ['http'=>202,'error'=>0,'json'=>['request_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],'body'=>'','type'=>'application/json','correlation'=>'fixture'];
    };
    $import=['request_key'=>'77777777-7777-4777-8777-777777777777','source_image'=>['download_url'=>'https://files.example.test/scene.png?temporary=secret','file_id'=>'file-fixture','mime_type'=>'image/jpeg']];
    $asset=studio_image_import($dir,$env,$principal,$import);
    check($asset['mime_type']==='image/png'&&$asset['width']===320,'MIME comes from bytes, not supplied file metadata');
    check(!isset($asset['url'],$asset['owner'],$asset['fingerprint'],$asset['sha256']),'private asset fields hidden');
    check(count($transfers)===2&&$presigns===1,'download, presign and PUT occur once');
    check(!str_contains(json_encode($transfers),'fixture:key')&&in_array('x-amz-tagging: retention=temporary',$transfers[1][1],true),'upload headers retained without provider credentials');
    $retry=$import;$retry['source_image']['download_url']='https://files.example.test/refreshed.png?temporary=renewed';
    check(studio_image_import($dir,$env,$principal,$retry)['image_id']===$asset['image_id']&&count($transfers)===2,'same file ID and import key recover without another transfer');
    $changed=$import;$changed['source_image']['file_id']='file-other';rejects(fn()=>studio_image_import($dir,$env,$principal,$changed),409);
    rejects(fn()=>studio_image_import($dir,$env,['owner'=>'other'],$import),404);
    rejects(fn()=>studio_image_import($dir,$env,$principal,$import+['image_url'=>'https://files.example.test/other.png']));
    $video=['kind'=>'video','prompt'=>'La cortina se mueve suavemente. Cámara fija.','image_url'=>'https://cdn.example.test/scene.png','duration'=>5,'generate_audio'=>false];
    $normalized=studio_input($video);
    check($normalized['model']==='bytedance/seedance-2.0/image-to-video'&&!isset($normalized['body']['aspect_ratio']),'image-to-video uses the source aspect ratio and correct model');
    $textVideo=$video;unset($textVideo['image_url']);check(studio_input($textVideo)['model']==='bytedance/seedance-2.0/text-to-video','text-to-video remains available');
    rejects(fn()=>studio_input(array_replace($video,['kind'=>'image'])));
    $animate=['request_key'=>'88888888-8888-4888-8888-888888888888','kind'=>'video','image_id'=>$asset['image_id'],'prompt'=>$video['prompt'],'duration'=>5,'accept_cost'=>true];
    rejects(fn()=>studio_chat_create($dir,$env,$principal,array_replace($animate,['accept_cost'=>false])));
    rejects(fn()=>studio_chat_create($dir,$env,['owner'=>'other'],$animate),404);
    rejects(fn()=>studio_chat_create($dir,$env,$principal,$animate+['image_url'=>'https://files.example.test/bypass.png']));
    $animation=studio_chat_create($dir,$env,$principal,$animate);
    check($animation['status']==='prepared'&&!$sent,'animating an imported image queues without a generation call');
    check(studio_chat_create($dir,$env,$principal,$animate)['id']===$animation['id'],'animation intent recovered');
    check(!str_contains(file_get_contents($dir.'/jobs.json'),'temporary=secret'),'ChatGPT signed download URL is not persisted in a generation');
    studio_chat_worker($dir,$env);studio_chat_worker($dir,$env);
    check(count($sent)===1&&$sent[0][0]==='bytedance/seedance-2.0/image-to-video'&&$sent[0][1]['image_url']==='https://cdn.example.test/scene.png'&&!$sent[0][1]['generate_audio']&&$sent[0][2]===$animation['id'],'worker sends the uploaded reference once with audio disabled and stable idempotency');
    $GLOBALS['studio_test_image_transfer']=static function(){throw new StudioError('Fixture upload failure',502);};
    $failed=$import;$failed['request_key']='99999999-9999-4999-8999-999999999999';
    rejects(fn()=>studio_image_import($dir,$env,$principal,$failed),502);
    check(!isset(studio_read($dir.'/images.json')[$failed['request_key']]),'failed transfer does not register an image');
    unset($GLOBALS['studio_test_image_transfer']);
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
    check(count(studio_chat_tools())===7,'all MCP tools exposed');
    $metadata=studio_oauth_metadata($env);check($metadata['authorization_response_iss_parameter_supported']&&$metadata['code_challenge_methods_supported']===['S256'],'issuer and mandatory S256 metadata');
    echo "OK: $count chat/OAuth checks\n";
}finally{foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
