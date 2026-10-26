<?php
declare(strict_types=1);

/** Bounded image imports. Provider credentials never go to download or storage hosts. */
function studio_image_public_ip(string $ip):bool {
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
    $n=ip2long($ip);
    foreach([['100.64.0.0',10],['192.0.0.0',24],['198.18.0.0',15],['224.0.0.0',4],['240.0.0.0',4]]as[$base,$bits]){
        $mask=-1<<(32-$bits);if(($n&$mask)===(ip2long($base)&$mask))return false;
    }return true;
}
function studio_image_target(string $url):array {
    $p=parse_url($url);
    if(!studio_url($url)||!$p||($p['port']??443)!==443||isset($p['fragment'])||strlen($url)>8192||!preg_match('/\A[a-zA-Z0-9.-]+\z/D',$p['host']))throw new StudioError('La imagen necesita una URL HTTPS válida.');
    $ips=gethostbynamel($p['host']);
    if(!$ips)throw new StudioError('No se puede resolver el servidor de la imagen.',502);
    foreach($ips as$ip)if(!studio_image_public_ip($ip))throw new StudioError('No se permiten direcciones de red privadas o reservadas.');
    return [$p['host'],$ips[0]];
}
function studio_image_transfer(string $url,?string $upload=null,array $headers=[]):array {
    if(defined('STUDIO_TESTING')&&isset($GLOBALS['studio_test_image_transfer']))return ($GLOBALS['studio_test_image_transfer'])($url,$upload,$headers);
    $deadline=microtime(true)+25;
    for($redirect=0;$redirect<=3;$redirect++){
        [$host,$ip]=studio_image_target($url);$body='';$location='';$tooLarge=false;$cap=$upload===null?10*1024*1024:65536;
        $c=curl_init($url);$remaining=(int)ceil($deadline-microtime(true));if($remaining<1)throw new StudioError('La transferencia de la imagen ha tardado demasiado.',504);
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>min(8,$remaining),CURLOPT_TIMEOUT=>$remaining,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_USERAGENT=>'StudioIA-ImageTransfer/1.0',CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$location):int{if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body,&$tooLarge,$cap):int{if(strlen($body)+strlen($chunk)>$cap){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
        if($upload!==null)curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_POSTFIELDS=>$upload]);
        curl_exec($c);$error=curl_errno($c);$http=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
        if($tooLarge)throw new StudioError('La imagen supera el límite de 10 MiB o la respuesta no es válida.',413);
        if($error)throw new StudioError('No se pudo transferir la imagen. Vuelve a adjuntarla si su enlace ha caducado.',502);
        if($upload===null&&in_array($http,[301,302,303,307,308],true)&&$location!==''&&$redirect<3){
            if(str_starts_with($location,'/')&&!str_starts_with($location,'//'))$location='https://'.$host.$location;
            $url=$location;continue;
        }
        if($http<200||$http>=300)throw new StudioError('El servidor no entregó o no aceptó la imagen. Vuelve a adjuntar el archivo.',502);
        return ['body'=>$body,'http'=>$http];
    }throw new StudioError('Demasiadas redirecciones al obtener la imagen.',502);
}
function studio_image_info(string $bytes):array {
    if(strlen($bytes)>10*1024*1024)throw new StudioError('La imagen supera el límite de 10 MiB.',413);
    $info=@getimagesizefromstring($bytes);
    if(!$info||!in_array($info['mime']??'',['image/jpeg','image/png','image/webp'],true))throw new StudioError('Usa una imagen PNG, JPEG o WebP válida.');
    if(min($info[0],$info[1])<256||max($info[0],$info[1])>8192||$info[0]*$info[1]>36000000)throw new StudioError('La imagen debe tener al menos 256 píxeles por lado y como máximo 8192 por lado y 36 megapíxeles.');
    return ['width'=>$info[0],'height'=>$info[1],'mime_type'=>$info['mime'],'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
}
function studio_image_public(array $asset):array {
    return array_intersect_key($asset,array_flip(['image_id','width','height','mime_type','bytes','created_at']));
}
function studio_image_owned(string $storage,string $id,string $owner):array {
    if(!studio_uuid($id))throw new StudioError('image_id no es válido.');
    $asset=studio_read($storage.'/images.json')[$id]??null;
    if(!is_array($asset)||($asset['owner']??'')!==$owner)throw new StudioError('Imagen no encontrada.',404);
    return $asset;
}
function studio_image_import(string $storage,array $env,array $principal,array $in):array {
    $id=$in['request_key']??'';if(!is_string($id)||!studio_uuid($id))throw new StudioError('request_key debe ser un UUID para esta importación.');
    $sources=array_filter(['file'=>!empty($in['source_image']),'url'=>!empty($in['image_url']),'job'=>!empty($in['image_job_id'])]);
    if(count($sources)!==1)throw new StudioError('Selecciona exactamente un archivo, una URL de imagen o un trabajo de imagen.');
    $source=array_key_first($sources);$url='';
    if($source==='file'){
        $file=$in['source_image'];
        if(!is_array($file)||!is_string($file['download_url']??null)||!is_string($file['file_id']??null)||$file['file_id']===''||strlen($file['file_id'])>200)throw new StudioError('ChatGPT no ha entregado el archivo. Vuelve a adjuntar la imagen o usa una URL HTTPS accesible.');
        $url=$file['download_url'];$sourceKey='file:'.$file['file_id'];
    }elseif($source==='url'){
        if(!is_string($in['image_url']))throw new StudioError('URL de imagen no válida.');$url=$in['image_url'];$sourceKey='url:'.$url;
    }else{
        $jobId=$in['image_job_id'];if(!is_string($jobId)||!studio_uuid($jobId))throw new StudioError('image_job_id no es válido.');
        $job=studio_owned($storage.'/jobs.json',$jobId,$principal['owner']);
        if($job['kind']!=='image'||$job['status']!=='completed'||empty($job['media'][0]['url']))throw new StudioError('El trabajo de imagen todavía no tiene un resultado terminado.',409);
        $url=$job['media'][0]['url'];$sourceKey='job:'.$jobId;
    }
    if(!studio_url($url)||strlen($url)>8192)throw new StudioError('Se necesita una URL HTTPS accesible para transferir la imagen.');
    $fingerprint=hash('sha256',$sourceKey);$lock=fopen($storage.'/image-import-'.$id.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new StudioError('La imagen se está importando. Reutiliza el mismo request_key al consultar de nuevo.',409);}@chmod($storage.'/image-import-'.$id.'.lock',0600);
    try{
        $all=studio_read($storage.'/images.json');
        if(isset($all[$id])){
            $old=studio_image_owned($storage,$id,$principal['owner']);if(!hash_equals($old['fingerprint'],$fingerprint))throw new StudioError('Ese request_key ya corresponde a otra imagen.',409);return studio_image_public($old);
        }
        if(count($all)>=1000)throw new StudioError('El registro de imágenes está lleno.',503);
        studio_chat_limit($storage,'image-import',30,3600);
        $bytes=studio_image_transfer($url)['body'];$info=studio_image_info($bytes);
        $r=studio_http('higgsfield','files/generate-upload-url',$env,'POST',['content_type'=>$info['mime_type']]);
        $signed=$r['json']??null;
        if($r['error']||$r['http']<200||$r['http']>=300||!is_array($signed)||!studio_url($signed['upload_url']??null)||!studio_url($signed['public_url']??null)||!is_array($signed['upload_headers']??null))throw new StudioError('Higgsfield no pudo preparar la subida de la imagen.',502);
        $headers=[];
        foreach($signed['upload_headers']as$name=>$value){
            if(!is_string($name)||!preg_match('/\A(?:Content-Type|x-amz-[a-z0-9-]+)\z/Di',$name)||!is_string($value)||strlen($value)>4096||preg_match('/[\r\n]/',$value))throw new StudioError('La respuesta de subida de Higgsfield no es válida.',502);
            if(strcasecmp($name,'Content-Type')===0&&$value!==$info['mime_type'])throw new StudioError('El tipo de imagen de la subida no coincide.',502);
            $headers[]=$name.': '.$value;
        }
        if(count($headers)>16||!array_filter($headers,static fn($h)=>stripos($h,'Content-Type: ')===0))throw new StudioError('Faltan las cabeceras de subida de la imagen.',502);
        studio_image_transfer($signed['upload_url'],$bytes,$headers);unset($bytes);
        $asset=$info+['image_id'=>$id,'owner'=>$principal['owner'],'fingerprint'=>$fingerprint,'url'=>$signed['public_url'],'created_at'=>gmdate('c')];
        studio_db($storage.'/images.json',static function(array &$v)use($id,$asset):void{$v[$id]=$asset;});
        return studio_image_public($asset);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
