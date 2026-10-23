<?php
declare(strict_types=1);

/* Studio IA: private, single-owner production workspace. PHP 8.1+, cURL. */
final class StudioError extends RuntimeException {
    public function __construct(string $message, public int $http = 400) { parent::__construct($message); }
}
function studio_env(string $path): array {
    if (!is_file($path)) return [];
    $env = @parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($env)) throw new StudioError('No se puede leer la configuración del servidor.', 503);
    return $env;
}
function studio_json(array $data, int $http = 200): never {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
}
function studio_db(string $path, callable $fn): mixed {
    $lock = fopen($path.'.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new StudioError('Almacenamiento ocupado. Inténtalo de nuevo.', 503);
    @chmod($path.'.lock', 0600); $tmp=null;
    try {
        $raw = is_file($path) ? file_get_contents($path) : '';
        if ($raw === false) throw new RuntimeException('Read failed');
        $data = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Invalid data');
        $before=$data; $result=$fn($data);
        if ($data !== $before || !is_file($path)) {
            $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
            $f=fopen($tmp,'x'); if(!$f)throw new RuntimeException('Write failed'); @chmod($tmp,0600);
            try { if(fwrite($f,$json)!==strlen($json))throw new RuntimeException('Write failed');fflush($f); } finally {fclose($f);}
            if(!rename($tmp,$path))throw new RuntimeException('Activation failed');$tmp=null;
        }
        return $result;
    } finally {if($tmp!==null && is_file($tmp))unlink($tmp);flock($lock,LOCK_UN);fclose($lock);}
}
function studio_read(string $path): array { return studio_db($path, static fn(array &$v): array => $v); }
function studio_uuid(string $id): bool { return preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $id) === 1; }
function studio_url(mixed $url): ?string {
    if (!is_string($url) || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) return null;
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return null;
    return $url;
}
function studio_hf_key(array $env): string {
    $combined = trim((string)($env['HIGGSFIELD_API_KEY'] ?? ''));
    $id=trim((string)($env['HIGGSFIELD_API_KEY_ID']??''));$secret=trim((string)($env['HIGGSFIELD_API_KEY_SECRET']??''));
    if($combined==='' && ($id==='' || $secret===''))throw new StudioError('Faltan las credenciales de Higgsfield en el servidor.',503);
    $key=$combined!==''?$combined:$id.':'.$secret;
    if ($key === ':' || str_contains($key, "\r") || str_contains($key, "\n") || str_contains($key, ' ')) throw new StudioError('Faltan las credenciales de Higgsfield en el servidor.', 503);
    return $key;
}
function studio_input(array $in): array {
    $kind = $in['kind'] ?? '';
    if (!in_array($kind, ['image', 'video', 'speech'], true)) throw new StudioError('Selecciona imagen, vídeo o voz.');
    $text = trim(is_string($in['prompt'] ?? null) ? $in['prompt'] : '');
    $limit = $kind === 'speech' ? 2500 : 6000;
    $len = preg_match_all('/./us', $text);
    if ($len === false || $len < 1 || $len > $limit) throw new StudioError('El texto está vacío o supera el límite permitido.');
    if ($kind === 'speech') {
        $voice = $in['voice_id'] ?? '';
        if (!is_string($voice) || !preg_match('/\A[a-zA-Z0-9_-]{10,100}\z/D', $voice)) throw new StudioError('Introduce un ID de voz válido.');
        $speed = $in['speed'] ?? 1;
        if (!is_numeric($speed) || (float)$speed < 0.7 || (float)$speed > 1.2) throw new StudioError('La velocidad debe estar entre 0,7 y 1,2.');
        return ['kind'=>$kind, 'provider'=>'elevenlabs', 'model'=>'eleven_multilingual_v2', 'body'=>['text'=>$text, 'model_id'=>'eleven_multilingual_v2', 'voice_settings'=>['stability'=>0.5, 'similarity_boost'=>0.75, 'speed'=>(float)$speed]], 'voice_id'=>$voice];
    }
    $ratio = $in['aspect_ratio'] ?? '9:16'; $resolution = $in['resolution'] ?? '720p';
    $ratios = $kind === 'image' ? ['9:16','16:9','4:3','3:4','1:1','2:3','3:2'] : ['9:16','16:9','4:3','3:4','1:1','21:9'];
    if (!in_array($ratio, $ratios, true) || !in_array($resolution, ['720p','1080p'], true)) throw new StudioError('Formato o resolución no válidos.');
    $body = ['prompt'=>$text, 'aspect_ratio'=>$ratio, 'resolution'=>$resolution];
    if ($kind === 'image') { $body['batch_size']=1; $body['enhance_prompt']=true; $model='higgsfield-ai/soul/standard'; }
    else {
        $duration = filter_var($in['duration'] ?? 5, FILTER_VALIDATE_INT);
        if ($duration === false || $duration < 4 || $duration > 15) throw new StudioError('El vídeo debe durar entre 4 y 15 segundos.');
        if (!is_bool($in['generate_audio'] ?? false)) throw new StudioError('El ajuste de audio no es válido.');
        $body['duration']=$duration; $body['generate_audio']=$in['generate_audio'] ?? false;
        $model='bytedance/seedance-2.0/text-to-video';
    }
    return ['kind'=>$kind, 'provider'=>'higgsfield', 'model'=>$model, 'body'=>$body];
}
function studio_http(string $provider, string $path, array $env, string $method='GET', ?array $body=null, ?string $idempotency=null): array {
    if (defined('STUDIO_TESTING') && isset($GLOBALS['studio_test_transport'])) return ($GLOBALS['studio_test_transport'])($provider,$path,$env,$method,$body,$idempotency);
    $base = $provider === 'higgsfield' ? 'https://api.higgsfield.ai/' : 'https://api.elevenlabs.io/';
    if ($provider === 'higgsfield') $auth = 'Authorization: Key '.studio_hf_key($env);
    else {
        $key = trim((string)($env['ELEVENLABS_API_KEY'] ?? ''));
        if ($key === '' || preg_match('/[\r\n]/', $key)) throw new StudioError('Falta la clave de ElevenLabs en el servidor.', 503);
        $auth = 'xi-api-key: '.$key;
    }
    if (str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '://')) throw new StudioError('Ruta no válida.', 500);
    $headers = [$auth, 'Accept: */*'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($idempotency !== null) $headers[] = 'Idempotency-Key: '.$idempotency;
    $c = curl_init($base.$path); $response = ''; $correlation = ''; $tooLarge = false;
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>$provider==='elevenlabs' && $method==='POST' ? 90 : 25,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_HEADERFUNCTION=>static function($c, string $line) use (&$correlation): int {
            if (stripos($line, 'X-Correlation-ID:') === 0) $correlation = substr(trim(substr($line, 17)), 0, 100);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>static function($c, string $chunk) use (&$response, &$tooLarge): int {
            if (strlen($response)+strlen($chunk)>12*1024*1024) { $tooLarge=true; return 0; }
            $response.=$chunk; return strlen($chunk);
        }]);
    if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    curl_exec($c); $error=curl_errno($c); $http=(int)curl_getinfo($c,CURLINFO_HTTP_CODE); $type=(string)curl_getinfo($c,CURLINFO_CONTENT_TYPE); curl_close($c);
    return ['http'=>$http, 'error'=>$error, 'body'=>$response, 'json'=>json_decode($response, true), 'type'=>$type, 'correlation'=>$correlation, 'large'=>$tooLarge];
}
function studio_provider_error(array $r): string {
    if ($r['error']) return 'La respuesta no se ha confirmado. No vuelvas a generar automáticamente.';
    return match($r['http']) {401=>'El proveedor ha rechazado la clave API.',403=>'El proveedor ha rechazado el acceso. Revisa saldo y permisos.',404=>'El modelo, voz o solicitud no está disponible para esta cuenta.',400,422=>'El proveedor ha rechazado los parámetros de la solicitud.',423,503=>'El modelo no está disponible temporalmente.',429=>'Límite del proveedor alcanzado. Espera antes de consultar de nuevo.',default=>'El proveedor no ha confirmado la operación. Consulta el historial antes de reenviar.'};
}
function studio_public_job(array $job): array {
    unset($job['body'], $job['fingerprint'], $job['owner'], $job['idempotency'], $job['voice_id']);
    return $job;
}
function studio_update(string $db, string $id, array $changes): array {
    return studio_db($db, static function(array &$all) use($id,$changes): array {
        if (!isset($all[$id])) throw new StudioError('Generación no encontrada.',404);
        $all[$id]=array_replace($all[$id],$changes); return $all[$id];
    });
}
function studio_owned(string $db, string $id, string $owner): array {
    if (!studio_uuid($id)) throw new StudioError('Identificador no válido.');
    $all=studio_read($db); $job=$all[$id]??null;
    if (!is_array($job) || !hash_equals($owner,$job['owner']??'')) throw new StudioError('Generación no encontrada.',404);
    return $job;
}
function studio_submit(string $db, string $id, array $env, string $storage): array {
    // Per-intent lock prevents simultaneous/replayed requests from spending twice.
    $lock=fopen($storage.'/intent-'.$id.'.lock','c'); @chmod($storage.'/intent-'.$id.'.lock',0600);
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if($lock) fclose($lock); throw new StudioError('Este envío ya está en curso.',409); }
    try {
        $all=studio_read($db); $job=$all[$id];
        if (!in_array($job['status'],['prepared','unknown'],true)) return studio_public_job($job);
        if ($job['status']==='unknown' && $job['provider']==='elevenlabs') throw new StudioError('ElevenLabs no permite recuperar este envío con seguridad. Revisa su historial antes de crear otro.',409);
        // Persist unknown BEFORE network I/O: a worker termination cannot cause blind retries.
        $job=studio_update($db,$id,['status'=>'unknown','message'=>'Envío pendiente de confirmar.']);
        if ($job['provider']==='higgsfield') $r=studio_http('higgsfield',$job['model'],$env,'POST',$job['body'],$job['idempotency']);
        else $r=studio_http('elevenlabs','v1/text-to-speech/'.rawurlencode($job['voice_id']).'?output_format=mp3_44100_128',$env,'POST',$job['body']);
        $ok=!$r['error'] && $r['http']>=200 && $r['http']<300;
        if ($ok && $job['provider']==='elevenlabs' && str_starts_with($r['type'],'audio/')) {
            $file=$storage.'/'.$id.'.mp3';
            if (file_put_contents($file,$r['body'],LOCK_EX)===false) throw new StudioError('El audio se generó, pero no pudo guardarse. Revísalo en ElevenLabs antes de reenviar.',500);
            @chmod($file,0600);
            $change=['status'=>'completed','message'=>'Locución lista.','media'=>[['type'=>'audio','url'=>'?action=audio&id='.$id]]];
        } elseif ($ok && studio_uuid((string)($r['json']['request_id']??''))) {
            $change=['status'=>'queued','message'=>'Solicitud aceptada.','request_id'=>$r['json']['request_id']];
        } else {
            $uncertain=$r['error'] || $r['http']>=500 || ($ok && !isset($r['json']['request_id']));
            $change=['status'=>$uncertain?'unknown':'failed','message'=>studio_provider_error($r)];
        }
        $change['http']=$r['http']; $change['correlation']=$r['correlation'];
        return studio_public_job(studio_update($db,$id,$change));
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function studio_poll(string $db, array $job, array $env): array {
    if ($job['provider']!=='higgsfield' || !in_array($job['status'],['queued','in_progress'],true)) return studio_public_job($job);
    $now=time();
    $claim=studio_db($db,static function(array &$all) use($job,$now): bool {
        if (($all[$job['id']]['last_poll']??0)>$now-8) return false;
        $all[$job['id']]['last_poll']=$now;return true;
    });
    if (!$claim) return studio_public_job(studio_read($db)[$job['id']]);
    $r=studio_http('higgsfield','requests/'.$job['request_id'].'/status',$env);
    if ($r['error'] || $r['http']!==200 || !is_array($r['json'])) return studio_public_job(studio_update($db,$job['id'],['message'=>studio_provider_error($r),'http'=>$r['http']]));
    $j=$r['json'];$status=$j['status']??'';
    if (!in_array($status,['queued','in_progress','completed','failed','nsfw','canceled'],true)) throw new StudioError('Estado desconocido del proveedor.',502);
    $media=[];
    foreach(($j['images']??[]) as $image) if($url=studio_url($image['url']??null)) $media[]=['type'=>'image','url'=>$url];
    if($url=studio_url($j['video']['url']??null)) $media[]=['type'=>'video','url'=>$url];
    $message=match($status){'completed'=>'Contenido listo. Descárgalo: los enlaces del proveedor pueden caducar.','failed'=>'La generación ha fallado en el proveedor.','nsfw'=>'El proveedor ha rechazado el contenido.','canceled'=>'Solicitud cancelada.','in_progress'=>'Generando contenido.',default=>'En cola.'};
    if ($status==='completed' && !$media) $message='El proveedor indica que terminó, pero todavía no devuelve un archivo. Revisa Requests en Higgsfield.';
    return studio_public_job(studio_update($db,$job['id'],['status'=>$status,'media'=>$media,'message'=>$message,'http'=>200,'correlation'=>$r['correlation']]));
}
if (defined('STUDIO_TESTING')) return;
if (!defined('STUDIO_RELEASE')) {http_response_code(404);exit;}
if (isset($_GET['health'])) studio_json(['status'=>'ok','app'=>'studio-ia','release'=>STUDIO_RELEASE]);
$root=dirname(__DIR__,3); $storage=$root.'/storage';
$nonce=base64_encode(random_bytes(20));
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; img-src https: data:; media-src https: 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Frame-Options: DENY'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, max-age=0');
ini_set('display_errors','0');
set_exception_handler(static function(Throwable $e): never {studio_json(['error'=>$e instanceof StudioError?$e->getMessage():'No se pudo completar la operación. Revisa el servidor.'],$e instanceof StudioError?$e->http:500);});
if (!is_dir($storage) && !mkdir($storage,0700,true) && !is_dir($storage)) throw new StudioError('No se puede crear el almacenamiento privado.',503);
$env=studio_env($root.'/.env');
ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
session_name('studio_session');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start(); $_SESSION['csrf']??=bin2hex(random_bytes(24));
$accountFile=$storage.'/account.json';$account=studio_read($accountFile);
$owner=$account['id']??'owner';
$authenticated=isset($_SESSION['owner'],$_SESSION['expires']) && $_SESSION['owner']===$owner && $_SESSION['expires']>time();
$hash=$account['password_hash']??($env['STUDIO_PASSWORD_HASH']??'');$needsSetup=$hash==='';
$action=is_string($_GET['action']??null)?$_GET['action']:'';
$in=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>40000) throw new StudioError('Solicitud demasiado grande.',413);
    $raw=file_get_contents('php://input');$in=json_decode($raw?:'{}',true);
    if (!is_array($in)) throw new StudioError('Solicitud no válida.');
    if (!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) throw new StudioError('Sesión caducada. Recarga la página.',403);
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    $expected=rtrim($env['APP_URL']??'https://studio.eskalamos.com','/');
    if ($origin!=='' && $origin!==$expected) throw new StudioError('Origen no autorizado.',403);
    if ($action==='setup' || $action==='login') {
        $limKey=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');
        studio_db($storage.'/login-limits.json',static function(array &$v)use($limKey): void {
            foreach($v as $k=>$entry)if(($entry['time']??0)<time()-900)unset($v[$k]);
            if(($v[$limKey]['count']??0)>=8)throw new StudioError('Demasiados intentos. Espera 15 minutos.',429);
            $v[$limKey]=['count'=>($v[$limKey]['count']??0)+1,'time'=>time()];
        });
        $password=$in['password']??'';
        if (!is_string($password) || strlen($password)>200) throw new StudioError('Contraseña no válida.');
        if ($action==='setup') {
            if (!$needsSetup) throw new StudioError('El acceso ya está configurado.',409);
            $token=$in['token']??'';
            if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D',$token)) throw new StudioError('Código de configuración no válido.',403);
            if (strlen($password)<12) throw new StudioError('Usa al menos 12 caracteres para la contraseña.');
            $setup=studio_read($storage.'/setup.json');
            if (($setup['expires']??0)<time() || !hash_equals($setup['token_hash']??'',hash('sha256',$token))) throw new StudioError('El enlace de configuración ha caducado o no es válido.',403);
            $newHash=password_hash($password,PASSWORD_DEFAULT);$newOwner=bin2hex(random_bytes(16));
            studio_db($accountFile,static function(array &$v)use($newHash,$newOwner): void {
                if(!empty($v['password_hash']))throw new StudioError('El acceso ya está configurado.',409);
                $v=['id'=>$newOwner,'password_hash'=>$newHash,'created_at'=>gmdate('c')];
            });
            studio_db($storage.'/setup.json',static function(array &$v):void{$v=[];});$owner=$newOwner;
        } elseif ($needsSetup || !password_verify($password,$hash)) throw new StudioError('Contraseña incorrecta.',401);
        session_regenerate_id(true);$_SESSION['owner']=$owner;$_SESSION['expires']=time()+8*3600;
        studio_json(['ok'=>true]);
    }
    if (!$authenticated) throw new StudioError('Inicia sesión para continuar.',401);
    if ($action==='logout') {$_SESSION=[];session_destroy();studio_json(['ok'=>true]);}
}
if ($action!=='' && !in_array($action,['setup','login','logout'],true)) {
    if (!$authenticated) throw new StudioError('Inicia sesión para continuar.',401);
    $csrf=$_SESSION['csrf'];session_write_close();$db=$storage.'/jobs.json';
    if ($action==='config' && $_SERVER['REQUEST_METHOD']==='GET') {
        $hfReady=false;try{studio_hf_key($env);$hfReady=true;}catch(StudioError $e){}
        studio_json(['higgsfield'=>$hfReady,'elevenlabs'=>trim($env['ELEVENLABS_API_KEY']??'')!=='','default_voice'=>$env['ELEVENLABS_DEFAULT_VOICE_ID']??'', 'release'=>substr(STUDIO_RELEASE,0,12)]);
    }
    if ($action==='jobs' && $_SERVER['REQUEST_METHOD']==='GET') {
        $all=array_filter(studio_read($db),static fn($j)=>($j['owner']??'')===$owner);
        uasort($all,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));
        studio_json(['jobs'=>array_map('studio_public_job',array_values(array_slice($all,0,100,true)))]);
    }
    if ($action==='voices' && $_SERVER['REQUEST_METHOD']==='GET') {
        $search=is_string($_GET['search']??null)?substr($_GET['search'],0,80):'';
        $r=studio_http('elevenlabs','v2/voices?page_size=100&include_total_count=false&search='.rawurlencode($search),$env);
        if($r['error']||$r['http']!==200)throw new StudioError(studio_provider_error($r),502);
        $voices=[];foreach(($r['json']['voices']??[])as$v)$voices[]=['id'=>$v['voice_id'],'name'=>$v['name']];
        studio_json(['voices'=>$voices,'has_more'=>$r['json']['has_more']??false]);
    }
    if ($action==='generate' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (($in['accept_cost']??false)!==true) throw new StudioError('Confirma el consumo de créditos antes de generar.');
        $id=$in['client_id']??'';if(!is_string($id)||!studio_uuid($id))throw new StudioError('Identificador de envío no válido.');
        $input=studio_input($in);$fingerprint=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));
        studio_db($db,static function(array &$all)use($id,$input,$fingerprint,$owner): void {
            if(isset($all[$id])) {if(($all[$id]['owner']??'')!==$owner || ($all[$id]['fingerprint']??'')!==$fingerprint)throw new StudioError('Este envío ya existe con otros parámetros.',409);return;}
            $recent=0;foreach($all as$j)if(($j['created_at']??'')>gmdate('c',time()-3600))$recent++;
            if($recent>=30)throw new StudioError('Límite del Studio: 30 generaciones por hora.',429);
            if(count($all)>=5000)throw new StudioError('Historial lleno. Descarga los resultados y consulta al administrador.',503);
            $all[$id]=array_merge($input,['id'=>$id,'owner'=>$owner,'fingerprint'=>$fingerprint,'idempotency'=>$id,'status'=>'prepared','prompt'=>($input['body']['prompt']??$input['body']['text']),'created_at'=>gmdate('c'),'media'=>[],'message'=>'Preparando envío.']);
        });
        studio_json(['job'=>studio_submit($db,$id,$env,$storage)]);
    }
    if (in_array($action,['status','resume','cancel','audio'],true)) {
        $id=$in['id']??($_GET['id']??'');if(!is_string($id))throw new StudioError('Identificador no válido.');
        $job=studio_owned($db,$id,$owner);
        if ($action==='status' && $_SERVER['REQUEST_METHOD']==='GET') studio_json(['job'=>studio_poll($db,$job,$env)]);
        if ($action==='resume' && $_SERVER['REQUEST_METHOD']==='POST') studio_json(['job'=>studio_submit($db,$id,$env,$storage)]);
        if ($action==='cancel' && $_SERVER['REQUEST_METHOD']==='POST') {
            if($job['provider']!=='higgsfield' || !isset($job['request_id']))throw new StudioError('No se puede cancelar este envío.');
            if(!in_array($job['status'],['queued','in_progress'],true))throw new StudioError('La solicitud ya ha terminado.',409);
            $r=studio_http('higgsfield','requests/'.$job['request_id'].'/cancel',$env,'POST');
            if($r['error']||$r['http']<200||$r['http']>=300)throw new StudioError('El proveedor no ha confirmado la cancelación. El trabajo puede continuar.',502);
            studio_json(['job'=>studio_public_job(studio_update($db,$id,['message'=>'Cancelación solicitada. Pendiente de confirmación del proveedor.','last_poll'=>0]))]);
        }
        if($action==='audio' && $_SERVER['REQUEST_METHOD']==='GET') {
            if($job['provider']!=='elevenlabs'||$job['status']!=='completed'||!is_file($storage.'/'.$id.'.mp3'))throw new StudioError('Audio no disponible.',404);
            header('Content-Type: audio/mpeg');header('Content-Disposition: '.(isset($_GET['download'])?'attachment':'inline').'; filename="studio-'.$id.'.mp3"');
            header('Content-Length: '.filesize($storage.'/'.$id.'.mp3')); readfile($storage.'/'.$id.'.mp3');exit;
        }
    }
    throw new StudioError('Función no encontrada.',404);
}
$csrf=$_SESSION['csrf'];session_write_close();
header('Content-Type: text/html; charset=utf-8');
$bootstrap=json_encode(['authenticated'=>$authenticated,'setup'=>$needsSetup,'csrf'=>$csrf],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Studio IA · Eskalamos</title>
<style nonce="<?= $nonce ?>">
:root{color-scheme:dark;font-family:Inter,system-ui,-apple-system,sans-serif;color:#eef2f3;background:#0b0e12;--muted:#98a3af;--line:#29313b;--accent:#acffd1}*{box-sizing:border-box}body{margin:0}button,input,select,textarea{font:inherit}button,a,input,select,textarea{outline-offset:4px}button{cursor:pointer}button:disabled{cursor:wait;opacity:.55}a{color:var(--accent)}.shell{display:grid;grid-template-columns:230px 1fr;min-height:100vh}.sidebar{padding:32px 22px;border-right:1px solid var(--line);background:#10151b}.brand{font-size:17px;font-weight:800;letter-spacing:-.6px}.brand span{color:var(--accent)}.subbrand{font-size:10px;letter-spacing:3px;color:var(--muted);margin-top:8px}.nav{display:grid;gap:8px;margin-top:54px}.nav button{border:0;background:transparent;color:#a2aab4;text-align:left;border-radius:10px;padding:13px 15px}.nav button[aria-pressed=true]{background:#21332d;color:var(--accent)}.side-note{font-size:12px;line-height:1.7;color:var(--muted);margin-top:90px}.workspace{padding:32px 42px 60px;max-width:1600px;width:100%;margin:auto}.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;border-bottom:1px solid var(--line);padding-bottom:24px}.eyebrow{text-transform:uppercase;font-size:11px;letter-spacing:2px;color:var(--muted)}.badge{display:inline-block;border:1px solid #36433c;border-radius:20px;padding:7px 11px;font-size:11px;color:var(--accent)}.hero{padding:42px 0 30px}.hero h1{font-size:clamp(30px,4vw,48px);letter-spacing:-2px;margin:10px 0 13px}.hero p{color:var(--muted);line-height:1.6;margin:0;max-width:680px}.studio-grid{display:grid;grid-template-columns:minmax(300px,1.05fr) minmax(280px,1fr);gap:24px}.panel{background:#121820;border:1px solid var(--line);border-radius:16px;padding:26px}.panel h2{font-size:17px;margin:0 0 22px}.label,label{display:block;font-size:12px;color:#c4ccd6;margin:19px 0 9px}input,textarea,select{width:100%;border:1px solid #34404d;background:#0c1118;color:#eef2f3;border-radius:8px;padding:12px}textarea{min-height:180px;resize:vertical;line-height:1.6}textarea::placeholder,input::placeholder{color:#707c89}.fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}.hint{color:var(--muted);font-size:12px;line-height:1.6;margin:10px 0}.cost{background:#1a2523;border:1px solid #35483e;padding:14px;border-radius:9px;margin:22px 0 16px;font-size:12px;line-height:1.7}.check{display:flex;align-items:flex-start;gap:10px;line-height:1.5;color:#c3cdd4}.check input{width:17px;flex:none;margin-top:2px}.primary{background:var(--accent);color:#10261a;border:0;font-weight:750;border-radius:9px;padding:14px 20px;width:100%}.secondary{background:transparent;color:#d2dae1;border:1px solid #35414e;border-radius:8px;padding:9px 13px;font-size:12px}.header-actions{display:flex;gap:12px;align-items:center}.error{color:#ffb8ad;font-size:13px;line-height:1.6;white-space:pre-line}.success{color:var(--accent)}.preview{min-height:320px;display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center;color:var(--muted);border:1px dashed #36414e;border-radius:12px;padding:24px}.preview svg{color:#658676;margin-bottom:18px}.preview p{font-size:13px;line-height:1.7;max-width:280px}.preview img,.preview video{max-height:420px;max-width:100%;border-radius:10px}.preview audio{width:100%}.preview a{display:block;margin-top:15px;font-size:12px}.history{margin-top:30px}.history-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.history-top h2{margin:0;font-size:18px}.jobs{display:grid;gap:12px}.job{border:1px solid var(--line);background:#11171e;border-radius:12px;padding:18px}.job header{display:flex;justify-content:space-between;gap:12px}.job h3{font-size:13px;margin:0;line-height:1.6}.job p{font-size:12px;color:var(--muted);line-height:1.6;overflow-wrap:anywhere}.job .actions{display:flex;gap:8px;flex-wrap:wrap}.status{font-size:10px;color:var(--accent);text-transform:uppercase;white-space:nowrap}.auth{width:min(460px,calc(100% - 40px));margin:12vh auto;background:#121820;border:1px solid var(--line);padding:34px;border-radius:18px}.auth h1{font-size:27px;letter-spacing:-1px;margin:30px 0 12px}.auth p{font-size:13px;color:var(--muted);line-height:1.7}.auth .primary{margin-top:22px}.hidden,[hidden]{display:none!important}#message{margin:15px 0}.footer{font-size:11px;color:#697787;margin-top:32px}.empty{color:var(--muted);font-size:13px;padding:20px 0}@media(max-width:1000px){.shell{grid-template-columns:175px 1fr}.workspace{padding:26px}.studio-grid{grid-template-columns:1fr}}@media(max-width:650px){.shell{display:block}.sidebar{padding:20px;border-right:0;border-bottom:1px solid var(--line)}.nav{display:flex;margin-top:22px}.nav button{padding:10px}.side-note{display:none}.workspace{padding:22px 18px}.topbar{align-items:flex-start}.header-actions{flex-wrap:wrap;justify-content:flex-end}.hero{padding-top:28px}.fields{gap:10px}.panel{padding:20px}}
</style></head><body>
<div id="auth" class="auth" hidden><div class="brand">ESKALAMOS <span>/ STUDIO IA</span></div><h1 id="auth-title">Tu espacio de producción</h1><p id="auth-copy">Accede para crear imágenes, vídeos y locuciones.</p><form id="auth-form"><div id="setup-field" hidden><label for="setup-token">Código de configuración</label><input id="setup-token" type="password" autocomplete="off"></div><label for="password">Contraseña</label><input id="password" type="password" autocomplete="current-password" required maxlength="200"><button class="primary" id="auth-submit">Entrar al Studio</button><p id="auth-error" class="error" role="alert"></p></form></div>
<div id="app" class="shell" hidden><aside class="sidebar"><div class="brand">ESKALAMOS <span>↗</span></div><div class="subbrand">STUDIO IA</div><nav class="nav" aria-label="Tipo de contenido"><button data-kind="image" aria-pressed="true">◈ &nbsp; Imágenes</button><button data-kind="video" aria-pressed="false">▷ &nbsp; Vídeos</button><button data-kind="speech" aria-pressed="false">≋ &nbsp; Locuciones</button></nav><div class="side-note">TU ESPACIO CREATIVO<br>De una idea a contenido.<br><br>Higgsfield + ElevenLabs<br>Créditos de tus cuentas.</div></aside><main class="workspace"><div class="topbar"><div><div class="eyebrow">Espacio de producción</div><div class="hint">Studio IA / Eskalamos</div></div><div class="header-actions"><span class="badge">Acceso privado</span><button class="secondary" id="logout">Cerrar sesión</button></div></div><section class="hero"><div class="eyebrow">CREA ALGO NUEVO</div><h1 id="page-title">Dale forma a tu próxima idea.</h1><p id="page-copy">Describe la imagen que necesitas. Elige el formato y genera una pieza para tu próximo contenido.</p></section><div class="studio-grid"><section class="panel"><h2 id="form-title">Nueva imagen</h2><form id="generate-form"><label for="prompt" id="prompt-label">Describe tu imagen</label><textarea id="prompt" maxlength="6000" required placeholder="Una calle de Alicante al atardecer, fotografía natural, luz cálida, sin texto…"></textarea><div class="hint" id="counter">0 / 6000 caracteres</div><div id="visual-fields"><div class="fields"><div><label for="ratio">Formato</label><select id="ratio"><option value="9:16">9:16 · Vertical</option><option value="16:9">16:9 · Horizontal</option><option value="1:1">1:1 · Cuadrado</option><option value="4:3">4:3</option><option value="3:4">3:4</option></select></div><div><label for="resolution">Resolución</label><select id="resolution"><option>720p</option><option>1080p</option></select></div></div><div id="video-fields" hidden><label for="duration">Duración del vídeo</label><select id="duration"><option value="4">4 segundos</option><option value="5" selected>5 segundos</option><option value="8">8 segundos</option><option value="10">10 segundos</option><option value="15">15 segundos</option></select><label class="check"><input id="native-audio" type="checkbox">Generar audio nativo del vídeo</label><p class="hint">Para añadir tu locución después, deja el audio desactivado. Esta función crea escenas desde texto; no sincroniza un avatar.</p></div></div><div id="speech-fields" hidden><label for="voice">ID de voz de ElevenLabs</label><input id="voice" placeholder="Pega el ID de la voz" autocomplete="off"><div class="fields"><div><label for="voice-search">Buscar una voz</label><input id="voice-search" placeholder="Nombre o acento"></div><div><label>&nbsp;</label><button type="button" class="secondary" id="load-voices">Buscar en mi cuenta</button></div></div><select id="voice-list" aria-label="Voces disponibles" hidden></select><p class="hint" id="voice-hint">Puedes usar el ID de cualquier voz a la que tu cuenta tenga acceso.</p><label for="speed">Velocidad</label><select id="speed"><option value="0.9">0,9 · Pausada</option><option value="1" selected>1 · Normal</option><option value="1.1">1,1 · Ágil</option><option value="1.2">1,2 · Rápida</option></select></div><div class="cost"><strong id="model-label">Higgsfield · Soul Standard</strong><div id="cost-note">Una imagen por solicitud. El precio depende de la resolución y de tu cuenta.</div><a id="pricing-link" href="https://open.higgsfield.ai/pricing" target="_blank" rel="noopener noreferrer">Consultar tarifas del proveedor ↗</a></div><label class="check"><input id="accept-cost" type="checkbox" required>Confirmo que esta generación consumirá créditos de mi cuenta según la tarifa del proveedor.</label><button class="primary" id="generate" type="submit">Generar imagen ↗</button><p id="message" role="status" aria-live="polite"></p></form></section><section class="panel"><h2>Vista previa</h2><div class="preview" id="preview"><svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="6" y="6" width="36" height="36" rx="9" stroke="currentColor" stroke-width="1.5"/><path d="m9 34 11-12 8 7 5-5 8 10" stroke="currentColor" stroke-width="1.5"/><circle cx="31" cy="17" r="3" stroke="currentColor" stroke-width="1.5"/></svg><strong>Aquí aparecerá tu contenido</strong><p>El historial conserva cada envío, aunque cierres esta pestaña. Los vídeos pueden tardar varios minutos.</p></div><p class="hint">Las imágenes y vídeos se entregan mediante enlaces del proveedor. Descárgalos al terminar. Las locuciones se guardan en tu hosting.</p></section></div><section class="history"><div class="history-top"><h2>Tus generaciones</h2><button class="secondary" id="refresh">Actualizar historial</button></div><div id="jobs" class="jobs"><p class="empty">Cargando historial…</p></div></section><div class="footer">Producción con tus cuentas de Higgsfield y ElevenLabs · Sin API de OpenAI</div></main></div>
<script nonce="<?= $nonce ?>">
'use strict';
const boot=<?= $bootstrap ?>;
const $=id=>document.getElementById(id);
let kind='image',busy=false,jobs=[],intent=null,pollTimer=null,pollCount=0;
const terminal=new Set(['completed','failed','nsfw','canceled','unknown','prepared']);
const labels={queued:'En cola',in_progress:'Generando',completed:'Listo',failed:'Fallido',nsfw:'Rechazado',canceled:'Cancelado',unknown:'Por confirmar',prepared:'Pendiente de envío'};
async function api(action,data=null){const response=await fetch('?action='+action,{method:data?'POST':'GET',credentials:'same-origin',headers:data?{'Content-Type':'application/json','X-CSRF-Token':boot.csrf}:{},body:data?JSON.stringify(data):null});let j;try{j=await response.json()}catch{throw Error('Respuesta interrumpida. Revisa el historial antes de generar otra vez.')}if(!response.ok)throw Error(j.error||'No se pudo completar la operación.');return j}
function message(text,error=false){$('message').textContent=text;$('message').className=error?'error':'hint'}
function el(tag,text,cls){const n=document.createElement(tag);if(text)n.textContent=text;if(cls)n.className=cls;return n}
function setKind(next){kind=next;intent=null;$('accept-cost').checked=false;document.querySelectorAll('[data-kind]').forEach(n=>n.setAttribute('aria-pressed',String(n.dataset.kind===kind)));$('visual-fields').hidden=kind==='speech';$('video-fields').hidden=kind!=='video';$('speech-fields').hidden=kind!=='speech';const isSpeech=kind==='speech',isVideo=kind==='video';$('page-title').textContent=isSpeech?'Ponle voz a tu contenido.':isVideo?'Convierte una idea en movimiento.':'Dale forma a tu próxima idea.';$('page-copy').textContent=isSpeech?'Escribe tu guion y usa la voz que elijas de tu cuenta de ElevenLabs.':isVideo?'Describe una escena y genera un clip para tu próximo vídeo.':'Describe la imagen que necesitas. Elige el formato y genera una pieza para tu próximo contenido.';$('form-title').textContent=isSpeech?'Nueva locución':isVideo?'Nuevo vídeo':'Nueva imagen';$('prompt-label').textContent=isSpeech?'Texto de la locución':isVideo?'Describe la escena':'Describe tu imagen';$('prompt').maxLength=isSpeech?2500:6000;$('prompt').placeholder=isSpeech?'Pega aquí el texto que quieres narrar…':isVideo?'Un paseo por Alicante al amanecer, cámara avanzando suavemente, sin texto…':'Una calle de Alicante al atardecer, fotografía natural, luz cálida, sin texto…';$('model-label').textContent=isSpeech?'ElevenLabs · Multilingual v2':isVideo?'Higgsfield · Seedance 2.0':'Higgsfield · Soul Standard';$('cost-note').textContent=isSpeech?'Consumo según la longitud del texto y la tarifa de tu voz y plan.':isVideo?'Precio según duración, resolución, dimensiones y tarifa de tu cuenta.':'Una imagen por solicitud. El precio depende de la resolución y de tu cuenta.';$('pricing-link').href=isSpeech?'https://elevenlabs.io/app/subscription':'https://open.higgsfield.ai/pricing';$('generate').textContent=isSpeech?'Generar locución ↗':isVideo?'Generar vídeo ↗':'Generar imagen ↗';count();message('')}
function count(){$('counter').textContent=Array.from($('prompt').value).length+' / '+$('prompt').maxLength+' caracteres'}
function preview(job){$('preview').replaceChildren();if(!job.media?.length){$('preview').append(el('strong',labels[job.status]||job.status),el('p',job.message||'Consulta el historial para seguir el estado.'));return}for(const m of job.media){const n=el(m.type==='image'?'img':m.type==='audio'?'audio':'video');n.src=m.url;if(m.type==='image')n.alt='Imagen generada';else{n.controls=true;n.preload='metadata'}$('preview').append(n);const a=el('a','Abrir / descargar '+(m.type==='image'?'imagen':m.type==='video'?'vídeo':'audio')+' ↗');a.href=m.url+(m.type==='audio'?'&download=1':'');a.target='_blank';a.rel='noopener noreferrer';$('preview').append(a)}}
function updateJob(job){const i=jobs.findIndex(j=>j.id===job.id);if(i>=0)jobs[i]=job;else jobs.unshift(job);renderJobs()}
function renderJobs(){$('jobs').replaceChildren();if(!jobs.length){$('jobs').append(el('p','Todavía no has creado contenido. Tu primera generación aparecerá aquí.','empty'));return}for(const j of jobs){const card=el('article',null,'job'),head=el('header');head.append(el('h3',(j.kind==='speech'?'Locución':j.kind==='video'?'Vídeo':'Imagen')+' · '+new Date(j.created_at).toLocaleString('es-ES')),el('span',labels[j.status]||j.status,'status'));card.append(head,el('p',j.prompt),el('p',j.message||''));const actions=el('div',null,'actions');function button(title,fn){const b=el('button',title,'secondary');b.type='button';b.addEventListener('click',async()=>{b.disabled=true;try{await fn()}catch(e){message(e.message,true)}finally{b.disabled=false}});actions.append(b)}if(j.media?.length)button('Ver resultado',()=>preview(j));if(['queued','in_progress'].includes(j.status)){button('Consultar estado',async()=>{const r=await api('status&id='+encodeURIComponent(j.id));updateJob(r.job);preview(r.job)});button('Cancelar solicitud',async()=>{const r=await api('cancel',{id:j.id});updateJob(r.job)})}if(j.provider==='higgsfield'&&['unknown','prepared'].includes(j.status))button('Recuperar el mismo envío',async()=>{const r=await api('resume',{id:j.id});updateJob(r.job);preview(r.job);startPolling()});if(j.request_id)card.append(el('p','Solicitud: '+j.request_id));card.append(actions);$('jobs').append(card)}}
async function loadJobs(){const r=await api('jobs');jobs=r.jobs;renderJobs();startPolling()}
function startPolling(){clearTimeout(pollTimer);if(!jobs.some(j=>j.provider==='higgsfield'&&!terminal.has(j.status)))return;pollTimer=setTimeout(pollPending,Math.min(30000,8000+pollCount*1500))}
async function pollPending(){if(document.hidden){startPolling();return}try{for(const j of jobs.filter(j=>j.provider==='higgsfield'&&!terminal.has(j.status)).slice(0,4)){const r=await api('status&id='+encodeURIComponent(j.id));updateJob(r.job);if(r.job.status==='completed')preview(r.job)}}catch(e){message(e.message,true)}pollCount++;if(pollCount<180)startPolling();else message('Se ha pausado la consulta automática. Puedes actualizar el historial para continuar.')}
$('auth-form').addEventListener('submit',async e=>{e.preventDefault();$('auth-submit').disabled=true;$('auth-error').textContent='';try{await api(boot.setup?'setup':'login',{password:$('password').value,token:$('setup-token').value});location.replace(location.pathname)}catch(err){$('auth-error').textContent=err.message}finally{$('auth-submit').disabled=false}});
$('generate-form').addEventListener('submit',async e=>{e.preventDefault();if(busy)return;busy=true;$('generate').disabled=true;message('Enviando… No cierres esta pestaña hasta confirmar el envío.');const data={kind,prompt:$('prompt').value,aspect_ratio:$('ratio').value,resolution:$('resolution').value,duration:Number($('duration').value),generate_audio:$('native-audio').checked,voice_id:$('voice').value,speed:Number($('speed').value),accept_cost:$('accept-cost').checked};const signature=JSON.stringify(data);if(!intent||intent.signature!==signature)intent={signature,id:crypto.randomUUID()};data.client_id=intent.id;try{const r=await api('generate',data);updateJob(r.job);preview(r.job);message(r.job.message,['unknown','failed'].includes(r.job.status));$('accept-cost').checked=false;intent=null;startPolling()}catch(err){message(err.message+' El identificador de este envío se conserva para evitar duplicados.',true);try{await loadJobs()}catch{}}finally{busy=false;$('generate').disabled=false}});
$('prompt').addEventListener('input',count);document.querySelectorAll('[data-kind]').forEach(b=>b.addEventListener('click',()=>{if(!busy)setKind(b.dataset.kind)}));$('refresh').addEventListener('click',async()=>{pollCount=0;try{await loadJobs()}catch(e){message(e.message,true)}});$('logout').addEventListener('click',async()=>{await api('logout',{});location.reload()});$('load-voices').addEventListener('click',async()=>{$('load-voices').disabled=true;try{const r=await api('voices&search='+encodeURIComponent($('voice-search').value));$('voice-list').replaceChildren(el('option','Selecciona una voz'));for(const v of r.voices){const o=el('option',v.name+' · '+v.id);o.value=v.id;$('voice-list').append(o)}$('voice-list').hidden=false;$('voice-hint').textContent=r.has_more?'Hay más voces: concreta la búsqueda.':'Voces disponibles en tu cuenta.'}catch(e){message(e.message,true)}finally{$('load-voices').disabled=false}});$('voice-list').addEventListener('change',()=>{if($('voice-list').selectedIndex>0)$('voice').value=$('voice-list').value});
if(boot.authenticated){$('app').hidden=false;(async()=>{try{const c=await api('config');$('voice').value=c.default_voice;await loadJobs();if(!c.higgsfield||!c.elevenlabs)message('Hay un proveedor sin configurar en el servidor.',true)}catch(e){message(e.message,true)}})()}else{$('auth').hidden=false;if(boot.setup){$('auth-title').textContent='Activa tu acceso privado.';$('auth-copy').textContent='Elige una contraseña de al menos 12 caracteres. El código de configuración se obtiene desde tu hosting y solo sirve una vez.';$('setup-field').hidden=false;$('password').minLength=12;$('password').autocomplete='new-password';$('auth-submit').textContent='Crear mi acceso';const token=new URLSearchParams(location.hash.slice(1)).get('setup');if(token){$('setup-token').value=token;history.replaceState(null,'',location.pathname)}}}
</script></body></html>
