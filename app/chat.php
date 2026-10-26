<?php
declare(strict_types=1);

/** MCP and REST transport for the existing single-owner Studio. No provider secrets are returned. */
function studio_chat_base(array $env): string {
    $base=rtrim((string)($env['APP_URL']??'https://studio.eskalamos.com'),'/');$p=parse_url($base);
    $local=defined('STUDIO_CHAT_TEST_HTTP') && ($p['scheme']??'')==='http' && ($p['host']??'')==='127.0.0.1';
    if(!$p || (!$local && (($p['scheme']??'')!=='https' || ($p['host']??'')!=='studio.eskalamos.com' || !in_array($p['port']??443,[443],true))) || (isset($p['user']) || isset($p['pass'])) || isset($p['query']) || isset($p['fragment']) || !in_array($p['path']??'',['','/'],true)) throw new StudioError('APP_URL no está configurada para el Studio.',503);
    return $base;
}
function studio_chat_resource(array $env): string {return studio_chat_base($env).'/mcp.php';}
function studio_chat_owner(string $storage,array $env): string {
    $account=studio_read($storage.'/account.json');
    if(empty($account['password_hash']) && empty($env['STUDIO_PASSWORD_HASH']))throw new StudioError('Configura primero la contraseña privada del Studio.',503);
    return $account['id']??'owner';
}
function studio_chat_limit(string $storage,string $group,int $max,int $window):void {
    $key=hash('sha256',$group.'|'.($_SERVER['REMOTE_ADDR']??'local'));
    studio_db($storage.'/chat-limits.json',static function(array &$all)use($key,$max,$window):void{
        $now=time();foreach($all as$k=>$v)if(($v['expires']??0)<$now)unset($all[$k]);
        if(($all[$key]['count']??0)>=$max)throw new StudioError('Demasiadas solicitudes. Espera antes de reintentar.',429);
        $all[$key]=['count'=>($all[$key]['count']??0)+1,'expires'=>$all[$key]['expires']??($now+$window)];
    });
}
function studio_chat_origin(array $env):void {
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if($origin!=='' && !in_array($origin,[studio_chat_base($env),'https://chatgpt.com','https://chat.openai.com'],true))throw new StudioError('Origen no autorizado.',403);
}
function studio_chat_body():array {
    if((int)($_SERVER['CONTENT_LENGTH']??0)>40000)throw new StudioError('Solicitud demasiado grande.',413);
    $raw=file_get_contents('php://input',false,null,0,40001);
    if(!is_string($raw)||strlen($raw)>40000)throw new StudioError('Solicitud demasiado grande.',413);
    try{$j=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new StudioError('JSON no válido.');}
    if(!is_array($j)||array_is_list($j))throw new StudioError('Se requiere un objeto JSON.');return $j;
}
function studio_chat_auth(string $storage,array $env,string $scope='studio:read'):array {
    $auth=$_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if(!is_string($auth)||!preg_match('/\ABearer ([a-f0-9]{64})\z/Di',$auth,$m))throw new StudioError('Inicia sesión con tu cuenta del Studio.',401);
    $all=studio_read($storage.'/oauth.json');$token=$all['access'][hash('sha256',$m[1])]??null;
    $grant=is_array($token)?($all['grants'][$token['grant_id']]??null):null;
    $owner=studio_chat_owner($storage,$env);
    if(!is_array($token)||!is_array($grant)||!empty($grant['revoked'])||($token['expires']??0)<=time()||($grant['expires']??0)<=time()||($token['resource']??'')!==studio_chat_resource($env)||($grant['owner']??'')!==$owner)throw new StudioError('La conexión ha caducado o fue revocada. Conecta de nuevo el Studio.',401);
    if(!in_array($scope,$token['scopes']??[],true))throw new StudioError('Esta conexión no tiene el permiso solicitado.',403);
    return ['owner'=>$owner,'grant_id'=>$token['grant_id'],'scopes'=>$token['scopes']];
}
function studio_chat_auth_error(StudioError $e,array $env):never {
    $challenge='Bearer resource_metadata="'.studio_chat_base($env).'/oauth.php?route=resource", scope="studio:read studio:generate"';
    if($e->http===403)$challenge.=', error="insufficient_scope"';
    if(in_array($e->http,[401,403],true))header('WWW-Authenticate: '.$challenge);
    studio_json(['error'=>$e->getMessage()],$e->http);
}
function studio_chat_worker_status(string $storage):array {
    $last=studio_read($storage.'/worker-status.json');$lastSeen=(int)($last['last_seen']??0);
    return ['ready'=>$lastSeen>time()-180 && $lastSeen<=time()+30,'last_seen'=>$lastSeen?gmdate('c',$lastSeen):null];
}
function studio_chat_capabilities(string $storage,array $env):array {
    $hf=true;try{studio_hf_key($env);}catch(StudioError $e){$hf=false;}
    return ['app'=>'Studio IA','connection'=>'authenticated','higgsfield_configured'=>$hf,'elevenlabs_configured'=>trim($env['ELEVENLABS_API_KEY']??'')!=='',
        'default_voice_id'=>$env['ELEVENLABS_DEFAULT_VOICE_ID']??'','worker'=>studio_chat_worker_status($storage),
        'models'=>['image'=>'higgsfield-ai/soul/standard','video'=>'bytedance/seedance-2.0/text-to-video','video_from_image'=>'bytedance/seedance-2.0/image-to-video','speech'=>'eleven_multilingual_v2'],
        'image_inputs'=>['chatgpt_file'=>true,'https_url'=>true,'studio_image_job'=>true,'max_bytes'=>10485760,'formats'=>['image/jpeg','image/png','image/webp'],'aspect_ratio'=>'from_source_image'],
        'limits'=>['generations_per_hour'=>30,'speech_characters'=>2500,'video_seconds'=>[4,15]],'avatar_lipsync'=>false,'openai_api_used'=>false];
}
function studio_chat_media(string $storage,array $job,array $env):array {
    $public=studio_public_job($job);$public['panel_url']=studio_chat_base($env).'/';
    if($job['provider']==='elevenlabs' && $job['status']==='completed' && is_file($storage.'/'.$job['id'].'.mp3')){
        $token=bin2hex(random_bytes(32));$expires=time()+600;
        studio_db($storage.'/media-links.json',static function(array &$v)use($token,$expires,$job):void{
            foreach($v as$k=>$item)if(($item['expires']??0)<time())unset($v[$k]);
            if(count($v)>=1000)throw new StudioError('Demasiados enlaces temporales activos.',429);
            $v[hash('sha256',$token)]=['id'=>$job['id'],'owner'=>$job['owner'],'expires'=>$expires];
        });
        $public['media']=[['type'=>'audio','url'=>studio_chat_base($env).'/studio-api.php?op=download&id='.$job['id'].'&token='.$token,'expires_at'=>gmdate('c',$expires)]];
    }
    return $public;
}
function studio_chat_create(string $storage,array $env,array $principal,array $in):array {
    if(($in['accept_cost']??false)!==true)throw new StudioError('La generación requiere autorización para consumir créditos.');
    $id=$in['request_key']??'';
    if(!is_string($id)||!studio_uuid($id))throw new StudioError('request_key debe ser un UUID que identifique este envío.');
    if(array_key_exists('image_url',$in))throw new StudioError('Importa primero la imagen y usa su image_id.');
    if(isset($in['image_id'])){
        if(($in['kind']??'')!=='video'||!is_string($in['image_id']))throw new StudioError('image_id solo se admite para vídeo.');
        $asset=studio_image_owned($storage,$in['image_id'],$principal['owner']);$in['image_url']=$asset['url'];
    }
    if(($in['kind']??'')==='speech' && trim((string)($in['voice_id']??''))==='')$in['voice_id']=$env['ELEVENLABS_DEFAULT_VOICE_ID']??'';
    $input=studio_input($in);$fingerprint=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));
    // Validate configuration before creating an intent; missing credentials cannot leave an uncertain charge.
    if($input['provider']==='higgsfield')studio_hf_key($env);
    elseif(trim($env['ELEVENLABS_API_KEY']??'')==='')throw new StudioError('Falta la clave de ElevenLabs en el servidor.',503);
    $db=$storage.'/jobs.json';$all=studio_read($db);
    if(isset($all[$id])){
        $existing=studio_owned($db,$id,$principal['owner']);
        if(!hash_equals($existing['fingerprint']??'',$fingerprint))throw new StudioError('Ese request_key ya se usó con otros parámetros.',409);
        return studio_chat_media($storage,$existing,$env);
    }
    if(!studio_chat_worker_status($storage)['ready'])throw new StudioError('El procesador del Studio no está activo. Configura su tarea cron antes de enviar generaciones desde el chat.',503);
    $job=studio_db($db,static function(array &$all)use($id,$input,$fingerprint,$principal):array{
        if(isset($all[$id])){
            $existing=$all[$id];if(($existing['owner']??'')!==$principal['owner']||($existing['fingerprint']??'')!==$fingerprint)throw new StudioError('Conflicto de identificador de envío.',409);return $existing;
        }
        $recent=0;foreach($all as$j)if(($j['created_at']??'')>gmdate('c',time()-3600))$recent++;
        if($recent>=30)throw new StudioError('Límite del Studio: 30 generaciones por hora.',429);
        if(count($all)>=5000)throw new StudioError('El historial del Studio está lleno.',503);
        return $all[$id]=array_merge($input,['id'=>$id,'owner'=>$principal['owner'],'grant_id'=>$principal['grant_id'],'fingerprint'=>$fingerprint,'idempotency'=>$id,
            'status'=>'prepared','prompt'=>$input['body']['prompt']??$input['body']['text'],'created_at'=>gmdate('c'),'media'=>[], 'attempts'=>0,
            'message'=>'Solicitud guardada. El procesador la enviará al proveedor en aproximadamente un minuto.']);
    });
    return studio_chat_media($storage,$job,$env);
}
function studio_chat_cancel(string $storage,array $env,array $principal,string $id):array {
    $db=$storage.'/jobs.json';studio_owned($db,$id,$principal['owner']);
    $lock=fopen($storage.'/intent-'.$id.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new StudioError('El envío está en curso. Consulta su estado antes de cancelar.',409);}
    try{
        $job=studio_owned($db,$id,$principal['owner']);
        if($job['status']==='canceled')return studio_chat_media($storage,$job,$env);
        if($job['status']==='prepared')$job=studio_update($db,$id,['status'=>'canceled','message'=>'Cancelado antes de enviarlo al proveedor.']);
        elseif($job['provider']==='higgsfield' && in_array($job['status'],['queued','in_progress'],true) && isset($job['request_id'])){
            $r=studio_http('higgsfield','requests/'.$job['request_id'].'/cancel',$env,'POST');
            if($r['error']||$r['http']<200||$r['http']>=300)throw new StudioError('El proveedor no ha confirmado la cancelación. Consulta el estado.',502);
            $job=studio_update($db,$id,['message'=>'Cancelación solicitada; pendiente de confirmar con el proveedor.','last_poll'=>0]);
        }else throw new StudioError('No se puede cancelar este trabajo en su estado actual.',409);
        return studio_chat_media($storage,$job,$env);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function studio_chat_tool(string $name,array $args,string $storage,array $env,array $principal):array {
    $db=$storage.'/jobs.json';
    if($name==='studio_connection_status')return studio_chat_capabilities($storage,$env);
    if($name==='studio_import_image')return ['image'=>studio_image_import($storage,$env,$principal,$args)];
    if($name==='studio_create_generation')return ['job'=>studio_chat_create($storage,$env,$principal,$args)];
    if($name==='studio_get_generation'){
        $job=studio_owned($db,(string)($args['job_id']??''),$principal['owner']);
        studio_poll($db,$job,$env);return ['job'=>studio_chat_media($storage,studio_owned($db,$job['id'],$principal['owner']),$env)];
    }
    if($name==='studio_list_generations'){
        $limit=$args['limit']??10;if(!is_int($limit)||$limit<1||$limit>20)throw new StudioError('limit debe estar entre 1 y 20.');
        $all=array_filter(studio_read($db),static fn($j)=>($j['owner']??'')===$principal['owner']);
        uasort($all,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));$jobs=[];
        foreach(array_slice($all,0,$limit)as$j)$jobs[]=studio_chat_media($storage,$j,$env);return ['jobs'=>$jobs];
    }
    if($name==='studio_list_voices'){
        $search=$args['search']??'';if(!is_string($search)||strlen($search)>100)throw new StudioError('Búsqueda no válida.');
        $r=studio_http('elevenlabs','v2/voices?page_size=100&include_total_count=false&search='.rawurlencode($search),$env);
        if($r['error']||$r['http']!==200)throw new StudioError(studio_provider_error($r),502);
        $voices=[];foreach(($r['json']['voices']??[])as$v)$voices[]=['voice_id'=>$v['voice_id'],'name'=>$v['name']];return ['voices'=>$voices,'has_more'=>$r['json']['has_more']??false];
    }
    if($name==='studio_cancel_generation')return ['job'=>studio_chat_cancel($storage,$env,$principal,(string)($args['job_id']??''))];
    throw new StudioError('Herramienta no encontrada.',404);
}
function studio_chat_tools():array {
    $object=static fn(array $p,array $required=[]):array=>['type'=>'object','properties'=>$p?:new stdClass(),'required'=>$required,'additionalProperties'=>false];
    $s=static fn(string $description):array=>['type'=>'string','description'=>$description];
    $file=$object(['download_url'=>$s('URL temporal del archivo proporcionada por ChatGPT; no inventarla.'),'file_id'=>$s('Identificador del archivo proporcionado por ChatGPT.'),'mime_type'=>$s('Tipo MIME opcional.'),'file_name'=>$s('Nombre opcional del archivo.')],['download_url','file_id']);
    $definitions=[
        ['studio_connection_status','Comprobar la conexión','Comprueba proveedores configurados, modelos disponibles y si el procesador puede recibir trabajos. No genera contenido.',$object([]),false],
        ['studio_import_image','Preparar una imagen para animarla','Importa una escena aprobada desde un archivo de ChatGPT, una URL HTTPS o un trabajo de imagen del Studio. Sube la imagen a Higgsfield y devuelve image_id. No genera vídeo. Reutiliza request_key al recuperar el envío.',
            $object(['request_key'=>['type'=>'string','format'=>'uuid'],'source_image'=>$file,'image_url'=>$s('URL HTTPS de una imagen accesible sin iniciar sesión; alternativa al archivo.'),'image_job_id'=>['type'=>'string','format'=>'uuid','description'=>'Trabajo propio de imagen terminado; alternativa al archivo y a la URL.']],['request_key']),true],
        ['studio_create_generation','Generar contenido','Guarda un único trabajo de imagen, vídeo o locución autorizado por el usuario. Consume créditos al procesarse. Reutiliza request_key al recuperar un envío; nunca crees otro por un tiempo de espera.',
            $object(['request_key'=>['type'=>'string','format'=>'uuid','description'=>'UUID único por intención de generación; reutiliza exactamente el mismo después de una respuesta incierta.'],
                'kind'=>['type'=>'string','enum'=>['image','video','speech']], 'prompt'=>$s('Descripción visual o texto exacto de la locución. Hasta 6000 caracteres visuales o 2500 de voz.'),
                'image_id'=>['type'=>'string','format'=>'uuid','description'=>'Para animar una imagen: ID devuelto por studio_import_image. Con image_id el vídeo conserva el formato de la imagen de entrada.'],
                'accept_cost'=>['type'=>'boolean','description'=>'true únicamente si el usuario ha autorizado esta generación y su consumo de créditos.'],
                'aspect_ratio'=>['type'=>'string','enum'=>['9:16','16:9','1:1','4:3','3:4'],'default'=>'9:16'],
                'resolution'=>['type'=>'string','enum'=>['720p','1080p'],'default'=>'720p'],
                'duration'=>['type'=>'integer','minimum'=>4,'maximum'=>15,'default'=>5],
                'generate_audio'=>['type'=>'boolean','default'=>false], 'voice_id'=>$s('ID de ElevenLabs; omitir para usar la voz predeterminada del servidor.'),
                'speed'=>['type'=>'number','minimum'=>0.7,'maximum'=>1.2,'default'=>1]],['request_key','kind','prompt','accept_cost']),true],
        ['studio_get_generation','Consultar una generación','Devuelve el estado y los enlaces del trabajo propio. No envía otra generación. Los enlaces de audio caducan a los 10 minutos; consulta de nuevo para renovarlos.',$object(['job_id'=>['type'=>'string','format'=>'uuid']],['job_id']),false],
        ['studio_list_generations','Consultar historial','Lista los trabajos del propietario, incluidos pendientes, fallidos y resultados.',$object(['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>20,'default'=>10]]),false],
        ['studio_list_voices','Consultar voces','Busca voces de la cuenta de ElevenLabs por nombre o acento. No genera audio.',$object(['search'=>$s('Nombre, acento o término de búsqueda opcional.')]),false],
        ['studio_cancel_generation','Cancelar una generación','Cancela un trabajo pendiente o solicita cancelación en Higgsfield. No garantiza detener un trabajo ya procesado.',$object(['job_id'=>['type'=>'string','format'=>'uuid']],['job_id']),true],
    ];
    $tools=[];foreach($definitions as[$name,$title,$description,$schema,$write]){
        $scopes=[$write?'studio:generate':'studio:read'];
        $tools[]=['name'=>$name,'title'=>$title,'description'=>$description,'inputSchema'=>$schema,
            'annotations'=>['readOnlyHint'=>!$write,'destructiveHint'=>$write&&$name!=='studio_import_image','idempotentHint'=>true,'openWorldHint'=>$name==='studio_import_image'],
            'securitySchemes'=>[['type'=>'oauth2','scopes'=>$scopes]],'_meta'=>['securitySchemes'=>[['type'=>'oauth2','scopes'=>$scopes]]]];
        if($name==='studio_import_image')$tools[array_key_last($tools)]['_meta']['openai/fileParams']=['source_image'];
    }return $tools;
}
function studio_chat_mcp(string $storage,array $env):never {
    studio_chat_origin($env);
    try{$principal=studio_chat_auth($storage,$env);}catch(StudioError $e){studio_chat_auth_error($e,$env);}
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');studio_json(['error'=>'El transporte MCP utiliza POST; no ofrece un flujo SSE permanente.'],405);}
    studio_chat_limit($storage,'mcp',600,300);$in=studio_chat_body();$id=$in['id']??null;
    if(($in['jsonrpc']??'')!=='2.0'||!is_string($in['method']??null)||($id!==null&&!is_int($id)&&!is_string($id)))studio_json(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid Request']],400);
    if(isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION'])&&!in_array($_SERVER['HTTP_MCP_PROTOCOL_VERSION'],['2025-11-25','2025-06-18','2025-03-26'],true))studio_json(['error'=>'Versión MCP no compatible.'],400);
    if(!array_key_exists('id',$in)){
        if(!str_starts_with($in['method'],'notifications/'))studio_json(['error'=>'Notificación no válida.'],400);
        http_response_code(202);exit;
    }
    $params=$in['params']??[];if(!is_array($params))studio_json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>-32602,'message'=>'Invalid params']],400);
    if($in['method']==='initialize'){
        $version=$params['protocolVersion']??'2025-11-25';if(!in_array($version,['2025-11-25','2025-06-18','2025-03-26'],true))$version='2025-11-25';
        $result=['protocolVersion'=>$version,'capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'studio-ia-eskalamos','version'=>'1.1.0'],
            'instructions'=>'Studio IA genera con las cuentas del propietario. Comprueba studio_connection_status. Para animar una escena aprobada usa studio_import_image con el archivo real de ChatGPT, una URL accesible o un trabajo de imagen propio terminado; después usa su image_id en studio_create_generation con kind=video. No inventes URLs ni archivos. Envía solo trabajos solicitados y con consumo autorizado. Conserva request_key y job_id; ante una respuesta incierta reutiliza el mismo request_key. Consulta estado antes de repetir. No uses los plugins comerciales de Higgsfield o ElevenLabs como sustituto. Los trabajos no son avatares sincronizados. No solicites claves de proveedores.'];
    }elseif($in['method']==='ping')$result=new stdClass();
    elseif($in['method']==='tools/list')$result=['tools'=>studio_chat_tools()];
    elseif($in['method']==='tools/call'){
        $name=$params['name']??'';$args=$params['arguments']??[];$known=array_column(studio_chat_tools(),null,'name');
        if(!is_string($name)||!isset($known[$name])||!is_array($args))studio_json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>-32602,'message'=>'Invalid tool or arguments']],400);
        $scope=$known[$name]['securitySchemes'][0]['scopes'][0];
        try{$principal=studio_chat_auth($storage,$env,$scope);}catch(StudioError $e){studio_chat_auth_error($e,$env);}
        try{$data=studio_chat_tool($name,$args,$storage,$env,$principal);$result=['content'=>[['type'=>'text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]],'structuredContent'=>$data,'isError'=>false];}
        catch(StudioError $e){$result=['content'=>[['type'=>'text','text'=>json_encode(['error'=>$e->getMessage(),'status'=>$e->http],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]],'isError'=>true];}
    }else studio_json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>-32601,'message'=>'Method not found']],400);
    studio_json(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);
}
function studio_chat_download(string $storage,array $env):never {
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')throw new StudioError('Método no permitido.',405);
    $token=$_GET['token']??'';$id=$_GET['id']??'';
    if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/D',$token)||!is_string($id)||!studio_uuid($id))throw new StudioError('Enlace no válido.',404);
    $links=studio_read($storage.'/media-links.json');$link=$links[hash('sha256',$token)]??null;
    if(!is_array($link)||($link['expires']??0)<time()||($link['id']??'')!==$id||($link['owner']??'')!==studio_chat_owner($storage,$env))throw new StudioError('El enlace ha caducado. Consulta el trabajo de nuevo.',404);
    $job=studio_owned($storage.'/jobs.json',$id,$link['owner']);$file=$storage.'/'.$id.'.mp3';
    if($job['provider']!=='elevenlabs'||$job['status']!=='completed'||!is_file($file))throw new StudioError('Audio no disponible.',404);
    header('Content-Type: audio/mpeg');header('Content-Disposition: attachment; filename="studio-'.$id.'.mp3"');header('Content-Length: '.filesize($file));readfile($file);exit;
}
function studio_chat_rest(string $storage,array $env):never {
    studio_chat_origin($env);$op=$_GET['op']??'';
    if($op==='download')studio_chat_download($storage,$env);
    $names=['status'=>'studio_connection_status','create'=>'studio_create_generation','import-image'=>'studio_import_image','job'=>'studio_get_generation','jobs'=>'studio_list_generations','voices'=>'studio_list_voices','cancel'=>'studio_cancel_generation'];
    if(!is_string($op)||!isset($names[$op]))throw new StudioError('Función no encontrada.',404);
    $write=in_array($op,['create','cancel','import-image'],true);
    try{$principal=studio_chat_auth($storage,$env,$write?'studio:generate':'studio:read');}catch(StudioError $e){studio_chat_auth_error($e,$env);}
    if(($_SERVER['REQUEST_METHOD']??'')!==($write?'POST':'GET'))throw new StudioError('Método no permitido.',405);
    studio_chat_limit($storage,'rest',600,300);$args=$write?studio_chat_body():$_GET;
    if($op==='jobs'&&isset($args['limit']))$args['limit']=filter_var($args['limit'],FILTER_VALIDATE_INT);
    studio_json(studio_chat_tool($names[$op],$args,$storage,$env,$principal),$op==='create'?202:200);
}
function studio_chat_worker(string $storage,array $env):void {
    $lock=fopen($storage.'/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return;}@chmod($storage.'/worker.lock',0600);
    try{
        studio_db($storage.'/worker-status.json',static function(array &$v):void{$v=['last_seen'=>time(),'status'=>'running'];});
        $all=studio_read($storage.'/jobs.json');$processed=0;
        foreach($all as$id=>$job){
            if($processed>=3)break;
            if(!isset($job['grant_id'])||!studio_uuid((string)$id)||!in_array($job['status'],['prepared','unknown'],true))continue;
            if($job['status']==='unknown'&&($job['provider']!=='higgsfield'||($job['attempts']??0)>=3))continue;
            $oauth=studio_read($storage.'/oauth.json');$grant=$oauth['grants'][$job['grant_id']]??null;
            if(!is_array($grant)||!empty($grant['revoked'])||($grant['expires']??0)<time()||($grant['owner']??'')!==studio_chat_owner($storage,$env)||!in_array('studio:generate',$grant['scopes']??[],true)){
                studio_db($storage.'/jobs.json',static function(array &$all)use($id):void{
                    if(($all[$id]['status']??'')==='prepared')$all[$id]=array_replace($all[$id],['status'=>'canceled','message'=>'La conexión que autorizó este trabajo ya no está activa.']);
                });continue;
            }
            $processed++;studio_update($storage.'/jobs.json',$id,['attempts'=>($job['attempts']??0)+1]);
            try{studio_submit($storage.'/jobs.json',$id,$env,$storage);}catch(StudioError $e){studio_update($storage.'/jobs.json',$id,['message'=>$e->getMessage()]);}
            studio_db($storage.'/worker-status.json',static function(array &$v):void{$v=['last_seen'=>time(),'status'=>'running'];});
        }
        studio_db($storage.'/worker-status.json',static function(array &$v):void{$v=['last_seen'=>time(),'status'=>'idle'];});
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function studio_chat_dispatch(string $endpoint,string $storage,array $env,string $nonce):never {
    if($endpoint==='worker'){
        if(PHP_SAPI!=='cli')throw new StudioError('No encontrado.',404);
        studio_chat_worker($storage,$env);echo "Studio worker: completed\n";exit;
    }
    try{
        if($endpoint==='mcp')studio_chat_mcp($storage,$env);
        if($endpoint==='rest')studio_chat_rest($storage,$env);
        if($endpoint==='oauth'||$endpoint==='as-metadata'||$endpoint==='resource-metadata')studio_oauth_dispatch($endpoint,$storage,$env,$nonce);
    }catch(StudioError $e){studio_chat_auth_error($e,$env);}
    throw new StudioError('No encontrado.',404);
}
