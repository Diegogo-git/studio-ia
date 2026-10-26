<?php
declare(strict_types=1);

/** Narrow OAuth authorization server for the private single-owner Studio. */
function studio_oauth_scopes(string $raw):array {
    $scopes=array_values(array_unique(preg_split('/\s+/',trim($raw))?:[]));sort($scopes);
    if(!$scopes||array_diff($scopes,['studio:read','studio:generate']))throw new StudioError('invalid_scope',400);return $scopes;
}
function studio_oauth_redirect_allowed(string $uri):bool {
    $p=parse_url($uri);
    if(!$p||($p['scheme']??'')!=='https'||!in_array($p['host']??'',['chatgpt.com','chat.openai.com'],true)||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['query'])||isset($p['fragment']))return false;
    $path=$p['path']??'';
    return $path==='/connector_platform_oauth_redirect' || preg_match('~\A/connector/oauth/[a-zA-Z0-9_-]{5,120}\z~D',$path)===1 || preg_match('~\A/aip/g-[a-zA-Z0-9_-]{5,120}/oauth/callback\z~D',$path)===1;
}
function studio_oauth_registration(string $storage,array $in):array {
    $uris=$in['redirect_uris']??null;
    if(!is_array($uris)||!array_is_list($uris)||count($uris)<1||count($uris)>5)throw new StudioError('invalid_redirect_uri',400);
    foreach($uris as$uri)if(!is_string($uri)||!studio_oauth_redirect_allowed($uri))throw new StudioError('invalid_redirect_uri',400);
    if(($in['token_endpoint_auth_method']??'none')!=='none')throw new StudioError('invalid_client_metadata: use PKCE public client authentication',400);
    $grantTypes=$in['grant_types']??['authorization_code','refresh_token'];
    if(!is_array($grantTypes)||array_diff($grantTypes,['authorization_code','refresh_token'])||!in_array('authorization_code',$grantTypes,true))throw new StudioError('unsupported_grant_type',400);
    $responseTypes=$in['response_types']??['code'];if($responseTypes!==['code'])throw new StudioError('unsupported_response_type',400);
    $id='studio_'.bin2hex(random_bytes(16));$name=$in['client_name']??'ChatGPT';
    if(!is_string($name)||strlen($name)>100)throw new StudioError('invalid_client_metadata',400);
    $client=['client_id'=>$id,'client_name'=>$name,'redirect_uris'=>array_values(array_unique($uris)),'token_endpoint_auth_method'=>'none','grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code'],'created_at'=>time()];
    studio_db($storage.'/oauth.json',static function(array &$v)use($id,$client):void{
        if(count($v['clients']??[])>=200)throw new StudioError('registration_capacity_reached',429);$v['clients'][$id]=$client;
    });return $client;
}
function studio_oauth_request(string $storage,array $env,array $in):array {
    $state=$in['state']??'';$challenge=$in['code_challenge']??'';$clientId=$in['client_id']??'';$redirect=$in['redirect_uri']??'';
    foreach([$state,$challenge,$clientId,$redirect]as$value)if(!is_string($value))throw new StudioError('invalid_request',400);
    if(($in['response_type']??'')!=='code'||$state===''||strlen($state)>2048||($in['code_challenge_method']??'')!=='S256'||!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$challenge))throw new StudioError('invalid_request: state and S256 PKCE required',400);
    $all=studio_read($storage.'/oauth.json');$client=$all['clients'][$clientId]??null;
    if(!is_array($client)||!in_array($redirect,$client['redirect_uris'],true)||!studio_oauth_redirect_allowed($redirect))throw new StudioError('invalid_client or redirect_uri',400);
    if(($in['resource']??'')!==studio_chat_resource($env))throw new StudioError('invalid_target',400);
    $scopes=studio_oauth_scopes((string)($in['scope']??'studio:read studio:generate'));
    return ['client_id'=>$clientId,'client_name'=>$client['client_name'],'redirect_uri'=>$redirect,'state'=>$state,'challenge'=>$challenge,'scopes'=>$scopes,'resource'=>studio_chat_resource($env),'expires'=>time()+600];
}
function studio_oauth_approve(string $storage,array $env,array $request,string $owner):string {
    if(($request['expires']??0)<time())throw new StudioError('La autorización ha caducado. Vuelve a conectar el plugin.',400);
    $code=bin2hex(random_bytes(32));unset($request['state'],$request['client_name']);
    studio_db($storage.'/oauth.json',static function(array &$v)use($code,$request,$owner):void{
        foreach($v['codes']??[]as$key=>$c)if(($c['expires']??0)<time())unset($v['codes'][$key]);
        $v['codes'][hash('sha256',$code)]=array_merge($request,['owner'=>$owner,'expires'=>time()+300]);
    });return $code;
}
function studio_oauth_issue(array &$v,string $grantId,array $grant):array {
    $now=time();$access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(32));
    // Remove expired access records and used refresh tokens, while keeping token families revocable.
    foreach($v['access']??[]as$key=>$item)if(($item['expires']??0)<$now)unset($v['access'][$key]);
    foreach($v['refresh']??[]as$key=>$item)if(($item['expires']??0)<$now)unset($v['refresh'][$key]);
    $record=['grant_id'=>$grantId,'client_id'=>$grant['client_id'],'resource'=>$grant['resource'],'scopes'=>$grant['scopes']];
    $v['access'][hash('sha256',$access)]=$record+['expires'=>$now+3600];
    $v['refresh'][hash('sha256',$refresh)]=$record+['expires'=>$grant['expires'],'used'=>false];
    return ['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>3600,'refresh_token'=>$refresh,'scope'=>implode(' ',$grant['scopes'])];
}
function studio_oauth_token(string $storage,array $env,array $in):array {
    $clientId=$in['client_id']??'';$resource=$in['resource']??'';
    if(!is_string($clientId)||$resource!==studio_chat_resource($env))throw new StudioError('invalid_target',400);
    $owner=studio_chat_owner($storage,$env);
    $result=studio_db($storage.'/oauth.json',static function(array &$v)use($in,$clientId,$resource,$owner):array{
        if(!isset($v['clients'][$clientId]))return ['error'=>'invalid_client'];
        if(($in['grant_type']??'')==='authorization_code'){
            $code=$in['code']??'';$verifier=$in['code_verifier']??'';
            if(!is_string($code)||!is_string($verifier)||!preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D',$verifier))return ['error'=>'invalid_grant'];
            $hash=hash('sha256',$code);$c=$v['codes'][$hash]??null;
            $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
            if(!is_array($c)||($c['expires']??0)<time()||$c['client_id']!==$clientId||$c['redirect_uri']!==($in['redirect_uri']??'')||$c['resource']!==$resource||$c['owner']!==$owner||!hash_equals($c['challenge'],$challenge))return ['error'=>'invalid_grant'];
            unset($v['codes'][$hash]);$grantId=bin2hex(random_bytes(16));
            $grant=['owner'=>$owner,'client_id'=>$clientId,'resource'=>$resource,'scopes'=>$c['scopes'],'created_at'=>time(),'expires'=>time()+30*86400,'revoked'=>false];
            $v['grants'][$grantId]=$grant;return studio_oauth_issue($v,$grantId,$grant);
        }
        if(($in['grant_type']??'')==='refresh_token'){
            $refresh=$in['refresh_token']??'';if(!is_string($refresh))return ['error'=>'invalid_grant'];
            $hash=hash('sha256',$refresh);$r=$v['refresh'][$hash]??null;$g=is_array($r)?($v['grants'][$r['grant_id']]??null):null;
            if(!is_array($r)||!is_array($g)||$r['client_id']!==$clientId||$r['resource']!==$resource||$g['owner']!==$owner||($g['expires']??0)<time()||!empty($g['revoked']))return ['error'=>'invalid_grant'];
            if(!empty($r['used'])){$v['grants'][$r['grant_id']]['revoked']=true;return ['error'=>'invalid_grant'];}
            if(($r['expires']??0)<time())return ['error'=>'invalid_grant'];
            if(isset($in['scope']) && studio_oauth_scopes((string)$in['scope'])!==$r['scopes'])return ['error'=>'invalid_scope'];
            $v['refresh'][$hash]['used']=true;return studio_oauth_issue($v,$r['grant_id'],$g);
        }
        return ['error'=>'unsupported_grant_type'];
    });
    if(isset($result['error']))throw new StudioError($result['error'],400);return $result;
}
function studio_oauth_revoke(string $storage,string $token,string $clientId):void {
    studio_db($storage.'/oauth.json',static function(array &$v)use($token,$clientId):void{
        $hash=hash('sha256',$token);$r=$v['access'][$hash]??($v['refresh'][$hash]??null);
        if(is_array($r)&&($r['client_id']??'')===$clientId&&isset($v['grants'][$r['grant_id']]))$v['grants'][$r['grant_id']]['revoked']=true;
    });
}
function studio_oauth_disconnect(string $storage,string $owner):int {
    return studio_db($storage.'/oauth.json',static function(array &$v)use($owner):int{
        $count=0;foreach($v['grants']??[]as$id=>$g)if(($g['owner']??'')===$owner&&empty($g['revoked'])){$v['grants'][$id]['revoked']=true;$count++;}return $count;
    });
}
function studio_oauth_metadata(array $env):array {
    $base=studio_chat_base($env);
    return ['issuer'=>$base.'/oauth.php','authorization_response_iss_parameter_supported'=>true,'authorization_endpoint'=>$base.'/oauth.php?route=authorize',
        'token_endpoint'=>$base.'/oauth.php?route=token','registration_endpoint'=>$base.'/oauth.php?route=register','revocation_endpoint'=>$base.'/oauth.php?route=revoke',
        'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'token_endpoint_auth_methods_supported'=>['none'],
        'code_challenge_methods_supported'=>['S256'],'scopes_supported'=>['studio:read','studio:generate']];
}
function studio_oauth_session():void {
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');session_name('studio_oauth_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!defined('STUDIO_CHAT_TEST_HTTP'),'httponly'=>true,'samesite'=>'Lax']);session_start();
}
function studio_oauth_form(string $nonce,string $csrf,array $request,string $error=''):never {
    $escape=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    // no-referrer makes browsers send Origin: null for native form POSTs.
    // Keep the real origin for this same-origin consent form without leaking its URL externally.
    header('Referrer-Policy: same-origin');
    // Chromium applies form-action to the final OAuth redirect as well as the POST.
    header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; frame-ancestors 'none'; base-uri 'none'; form-action 'self' https://chatgpt.com https://chat.openai.com");
    $read=in_array('studio:read',$request['scopes'],true);$write=in_array('studio:generate',$request['scopes'],true);
    ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Conectar Studio IA con ChatGPT</title><style nonce="<?= $escape($nonce) ?>">:root{color-scheme:dark;font-family:system-ui;color:#eef2f3;background:#0b0e12}*{box-sizing:border-box}main{max-width:500px;margin:8vh auto;padding:32px;border:1px solid #29313b;border-radius:18px;background:#121820}h1{font-size:26px}p,li{font-size:14px;line-height:1.7;color:#aab6c2}label{display:block;margin:18px 0 8px;font-size:13px}input,button{font:inherit;width:100%;padding:13px;border-radius:8px}input{background:#0c1118;border:1px solid #34404d;color:white}button{cursor:pointer;margin-top:14px;border:0;background:#acffd1;color:#10261a;font-weight:bold}button[name=decision][value=deny]{background:transparent;color:#c4ccd6;border:1px solid #34404d}.error{color:#ffb8ad}</style></head><body><main><p>ESKALAMOS / STUDIO IA</p><h1>Conecta tu Studio con ChatGPT</h1><p>Aplicación solicitante: <strong><?= $escape((string)$request['client_name']) ?></strong></p><ul><?php if($read):?><li>Consultar tus trabajos, resultados y voces disponibles.</li><?php endif;if($write):?><li>Solicitar y cancelar generaciones en tus cuentas. Las generaciones consumen tus créditos de Higgsfield o ElevenLabs.</li><?php endif;?></ul><p>Las claves de los proveedores permanecen en tu servidor. Puedes revocar esta conexión desde el Studio.</p><form method="post" action="?route=authorize"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><label for="password">Contraseña del Studio</label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="200" required><p class="error" role="alert"><?= $escape($error) ?></p><button name="decision" value="allow">Conectar con estos permisos</button><button name="decision" value="deny" formnovalidate>Cancelar conexión</button></form></main></body></html>
<?php exit;
}
function studio_oauth_authorize(string $storage,array $env,string $nonce):never {
    $base=studio_chat_base($env);studio_oauth_session();
    $method=$_SERVER['REQUEST_METHOD']??'';
    if($method==='GET'){
        $request=studio_oauth_request($storage,$env,$_GET);studio_chat_owner($storage,$env);
        $_SESSION['authorization']=$request;$_SESSION['oauth_csrf']=bin2hex(random_bytes(24));
        $csrf=$_SESSION['oauth_csrf'];session_write_close();studio_oauth_form($nonce,$csrf,$request);
    }
    if($method!=='POST')throw new StudioError('Método no permitido.',405);
    if(($_SERVER['HTTP_ORIGIN']??$base)!==$base)throw new StudioError('Origen no autorizado.',403);
    $request=$_SESSION['authorization']??null;$csrf=$_SESSION['oauth_csrf']??'';
    if(!is_array($request)||$csrf===''||!hash_equals($csrf,(string)($_POST['csrf']??''))||($request['expires']??0)<time())throw new StudioError('La autorización ha caducado. Empieza la conexión de nuevo.',403);
    $redirect=$request['redirect_uri'];$query=['state'=>$request['state'],'iss'=>$base.'/oauth.php'];
    if(($_POST['decision']??'')==='deny'){
        unset($_SESSION['authorization'],$_SESSION['oauth_csrf']);session_write_close();$query['error']='access_denied';header('Location: '.$redirect.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),true,303);exit;
    }
    studio_chat_limit($storage,'oauth-login',8,900);
    $password=$_POST['password']??'';$account=studio_read($storage.'/account.json');$hash=$account['password_hash']??($env['STUDIO_PASSWORD_HASH']??'');
    if(!is_string($password)||strlen($password)>200||$hash===''||!password_verify($password,$hash)){
        session_write_close();http_response_code(401);studio_oauth_form($nonce,$csrf,$request,'Contraseña incorrecta.');
    }
    $query['code']=studio_oauth_approve($storage,$env,$request,studio_chat_owner($storage,$env));
    unset($_SESSION['authorization'],$_SESSION['oauth_csrf']);session_regenerate_id(true);session_write_close();
    header('Location: '.$redirect.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),true,303);exit;
}
function studio_oauth_dispatch(string $endpoint,string $storage,array $env,string $nonce):never {
    $route=$endpoint==='as-metadata'?'metadata':($endpoint==='resource-metadata'?'resource':($_GET['route']??'metadata'));
    $method=$_SERVER['REQUEST_METHOD']??'';
    if(in_array($route,['metadata','resource'],true)){
        if($method!=='GET')throw new StudioError('Método no permitido.',405);
        if($route==='metadata')studio_json(studio_oauth_metadata($env));
        studio_json(['resource'=>studio_chat_resource($env),'authorization_servers'=>[studio_chat_base($env).'/oauth.php'],'scopes_supported'=>['studio:read','studio:generate'],'bearer_methods_supported'=>['header'],'resource_name'=>'Studio IA · Eskalamos']);
    }
    if($route==='authorize')studio_oauth_authorize($storage,$env,$nonce);
    if($method!=='POST')throw new StudioError('Método no permitido.',405);
    studio_chat_origin($env);
    if($route==='register'){
        studio_chat_limit($storage,'oauth-register',10,3600);studio_json(studio_oauth_registration($storage,studio_chat_body()),201);
    }
    studio_chat_limit($storage,'oauth-token',120,300);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>16000)throw new StudioError('invalid_request',413);
    $in=str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')?studio_chat_body():$_POST;
    try{
        if($route==='token')studio_json(studio_oauth_token($storage,$env,$in));
        if($route==='revoke'){
            if(!is_string($in['token']??null)||!is_string($in['client_id']??null))throw new StudioError('invalid_request',400);
            studio_oauth_revoke($storage,$in['token'],$in['client_id']);studio_json(['revoked'=>true]);
        }
    }catch(StudioError $e){studio_json(['error'=>$e->getMessage()],$e->http);}
    throw new StudioError('No encontrado.',404);
}
