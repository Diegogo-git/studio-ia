<?php
declare(strict_types=1);
define('STUDIO_TESTING',true);
require __DIR__.'/../app/entry.php';
$dir=sys_get_temp_dir().'/studio-tests-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$count=0;
function check(bool $ok,string $label):void{global$count;if(!$ok)throw new RuntimeException('FAIL: '.$label);$count++;}
function rejects(callable $fn,int $status=400):void{try{$fn();}catch(StudioError $e){check($e->http===$status,'correct rejection');return;}throw new RuntimeException('Expected rejection');}
function fixture(string $id,string $kind='image'):array{
    return array_merge(studio_input(['kind'=>$kind,'prompt'=>'Un paisaje natural','voice_id'=>'pZv6Kbgq62dtlvkJTupr']),['id'=>$id,'owner'=>'owner','fingerprint'=>'fingerprint','idempotency'=>$id,'status'=>'prepared','media'=>[]]);
}
try{
    check(studio_hf_key(['HIGGSFIELD_API_KEY_ID'=>'id','HIGGSFIELD_API_KEY_SECRET'=>'secret'])==='id:secret','split credential');
    check(studio_hf_key(['HIGGSFIELD_API_KEY'=>'complete:key'])==='complete:key','complete credential');
    rejects(fn()=>studio_hf_key(['HIGGSFIELD_API_KEY_ID'=>'id']),503);
    rejects(fn()=>studio_hf_key(['HIGGSFIELD_API_KEY'=>"id:secret\nInjected: value"]),503);
    rejects(fn()=>studio_input(['kind'=>'video','prompt'=>'scene','duration'=>16]));
    rejects(fn()=>studio_input(['kind'=>'image','prompt'=>'scene','aspect_ratio'=>'../../']));
    rejects(fn()=>studio_input(['kind'=>'speech','prompt'=>'hello','voice_id'=>'../../secrets']));
    rejects(fn()=>studio_input(['kind'=>'speech','prompt'=>str_repeat('ñ',2501),'voice_id'=>'pZv6Kbgq62dtlvkJTupr']));
    $video=studio_input(['kind'=>'video','prompt'=>'scene','generate_audio'=>false,'duration'=>4]);
    check($video['body']['generate_audio']===false && $video['body']['duration']===4,'video options');
    check(studio_url('javascript:alert(1)')===null && studio_url('https://a@evil.test/x')===null,'unsafe media blocked');
    check(studio_url('https://cdn.example.test/media.mp4')!==null,'https media accepted');
    $db=$dir.'/jobs.json';$id='11111111-1111-4111-8111-111111111111';
    studio_db($db,function(array &$v)use($id):void{$v[$id]=fixture($id);});
    rejects(fn()=>studio_owned($db,$id,'other-owner'),404);
    $calls=0;$idem=[];
    $GLOBALS['studio_test_transport']=function($provider,$path,$env,$method,$body,$key)use(&$calls,&$idem){$calls++;$idem[]=$key;return ['http'=>202,'error'=>0,'json'=>['request_id'=>'22222222-2222-4222-8222-222222222222'],'body'=>'','type'=>'application/json','correlation'=>'test'];};
    $first=studio_submit($db,$id,[],$dir);$second=studio_submit($db,$id,[],$dir);
    check($calls===1 && $first['status']==='queued' && $second['status']==='queued','no duplicate submission');
    check(!isset($first['body'],$first['owner'],$first['idempotency']),'private fields removed');
    $GLOBALS['studio_test_transport']=fn()=>['http'=>200,'error'=>0,'json'=>['status'=>'completed','images'=>[['url'=>'https://cdn.example.test/result.png']]],'body'=>'','type'=>'application/json','correlation'=>'test'];
    $done=studio_poll($db,studio_owned($db,$id,'owner'),[]);
    check($done['status']==='completed' && count($done['media'])===1,'completed image persisted');
    $id2='33333333-3333-4333-8333-333333333333';
    studio_db($db,function(array &$v)use($id2):void{$v[$id2]=fixture($id2);});
    $GLOBALS['studio_test_transport']=function($p,$path,$env,$m,$b,$key)use(&$idem){$idem[]=$key;return ['http'=>0,'error'=>28,'json'=>null,'body'=>'','type'=>'','correlation'=>''];};
    check(studio_submit($db,$id2,[],$dir)['status']==='unknown','ambiguous timeout preserved');
    $GLOBALS['studio_test_transport']=function($p,$path,$env,$m,$b,$key)use(&$idem){$idem[]=$key;return ['http'=>202,'error'=>0,'json'=>['request_id'=>'22222222-2222-4222-8222-222222222222'],'body'=>'','type'=>'application/json','correlation'=>''];};
    studio_submit($db,$id2,[],$dir);
    check($idem[count($idem)-1]===$idem[count($idem)-2],'recovery reuses idempotency key');
    $id3='44444444-4444-4444-8444-444444444444';
    studio_db($db,function(array &$v)use($id3):void{$v[$id3]=fixture($id3,'speech');});
    $GLOBALS['studio_test_transport']=fn()=>['http'=>0,'error'=>28,'json'=>null,'body'=>'','type'=>'','correlation'=>''];
    check(studio_submit($db,$id3,[],$dir)['status']==='unknown','speech timeout preserved');
    rejects(fn()=>studio_submit($db,$id3,[],$dir),409);
    $id4='55555555-5555-4555-8555-555555555555';
    studio_db($db,function(array &$v)use($id4):void{$v[$id4]=fixture($id4,'speech');});
    $GLOBALS['studio_test_transport']=fn()=>['http'=>200,'error'=>0,'json'=>null,'body'=>'ID3fake-audio','type'=>'audio/mpeg','correlation'=>''];
    $audio=studio_submit($db,$id4,[],$dir);
    check($audio['status']==='completed' && file_get_contents($dir.'/'.$id4.'.mp3')==='ID3fake-audio','audio private storage');
    $before=studio_read($db);
    try{studio_db($db,function(array &$v):void{$v=[];throw new RuntimeException('abort');});}catch(RuntimeException $e){}
    check(studio_read($db)===$before,'failed transaction retains previous data');
    echo "OK: $count application checks\n";
}finally{foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
