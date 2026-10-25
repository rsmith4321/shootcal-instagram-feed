<?php
declare(strict_types=1);
use ShootCalInstagramFeed\{Image_Cache,Shortcode};
use const ShootCalInstagramFeed\{OPTION_KEY,CACHE_KEY};
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','shootcal-plugin-dev.local'],true)) throw new RuntimeException('Disposable local WordPress only');
require __DIR__.'/restore-options.php';
function image_check(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label);echo "PASS $label\n"; }
$keys=[OPTION_KEY,CACHE_KEY,Image_Cache::OPTION,'shootcal_instagram_feed_images_lock','cron'];$before=[];foreach($keys as $key)$before[$key]=get_option($key,false);
$fixture=wp_tempnam('image-cache-fixture');$g=imagecreatetruecolor(1600,1000);for($y=0;$y<1000;$y+=5){imagefilledrectangle($g,0,$y,1599,$y+4,imagecolorallocate($g,$y%255,($y*7)%255,($y*11)%255));}imagejpeg($g,$fixture,95);
// Exercise the real WordPress decoder with a load counter and controlled save failures.
require_once ABSPATH.'wp-includes/class-wp-image-editor.php';
require_once ABSPATH.'wp-includes/class-wp-image-editor-gd.php';
require_once ABSPATH.'wp-includes/class-wp-image-editor-imagick.php';
class ShootCal_Image_Cache_Test_Imagick extends WP_Image_Editor_Imagick {
 public static int $loads=0;
 public function load(){++self::$loads;return parent::load();}
}
class ShootCal_Image_Cache_Test_Editor extends WP_Image_Editor_GD {
 public static int $loads=0;
 public static bool $oversize=false;
 public static string $failure='';
 public static array $written=[];
 public function load(){++self::$loads;return parent::load();}
 public function save($destfilename=null,$mime_type=null){
  $saved=parent::save($destfilename,$mime_type);
  if(self::$oversize&&!is_wp_error($saved)){$handle=fopen($saved['path'],'ab');ftruncate($handle,8*1024*1024+1);fclose($handle);}
  if(self::$failure!==''&&!is_wp_error($saved)){
   self::$written[]=$saved['path'];file_put_contents($saved['path'].'.unrelated','preserve unrelated bytes');
   if(self::$failure==='throw')throw new RuntimeException('Fixture encoder failed after output');
   return new WP_Error('fixture_encoder_failure','Fixture encoder failed after output');
  }
  return $saved;
 }
}
$editor_choices=0;
$editors=static function()use(&$editor_choices):array{++$editor_choices;return [ShootCal_Image_Cache_Test_Editor::class];};
add_filter('wp_image_editors',$editors);
$account='17841499990000001';$calls=0;$bad_redirect=false;$files=[];$extra_fixtures=[];$download_fixture=$fixture;$request_delay_us=0;$last_request_args=[];
$mock=static function($pre,array $args,string $url)use(&$calls,&$bad_redirect,&$download_fixture,&$request_delay_us,&$last_request_args){
 ++$calls;$last_request_args=$args;if($request_delay_us)usleep($request_delay_us);
 if($bad_redirect)return ['headers'=>['location'=>'https://127.0.0.1/private'],'response'=>['code'=>302],'body'=>'','cookies'=>[]];
 copy($download_fixture,$args['filename']);return ['headers'=>['content-type'=>'image/jpeg'],'response'=>['code'=>200],'body'=>'','cookies'=>[]];
};
add_filter('pre_http_request',$mock,10,3);
try {
 foreach(['https://scontent.cdninstagram.com/a.jpg','https://scontent.fbcdn.net/a.jpg'] as $u)image_check(Image_Cache::allowed_url($u),'allows expected image CDN');
 foreach(['http://scontent.cdninstagram.com/a','https://cdninstagram.com.evil.test/a','https://cdninstagram.com@127.0.0.1/a','https://scontent.cdninstagram.com:444/a','file:///etc/passwd'] as $u)image_check(!Image_Cache::allowed_url($u),'rejects non-CDN or unsafe URL');
 $item=['id'=>'thumbnail-regression','image_url'=>'https://scontent.cdninstagram.com/fixture.jpg','permalink'=>'https://www.instagram.com/p/fixture/','caption'=>'A portrait','media_type'=>'IMAGE','timestamp'=>'2026-10-09T12:00:00Z'];
 $option=get_option(OPTION_KEY,[]);$option['instagram_account_id']=$account;update_option(OPTION_KEY,$option,false);
 update_option(CACHE_KEY,['account'=>['id'=>$account,'username'=>'fixture'],'items'=>[$item],'fetched_at'=>time()],false);delete_option(Image_Cache::OPTION);delete_option('shootcal_instagram_feed_images_lock');
 $manifest=new ReflectionProperty(Image_Cache::class,'manifest');$manifest->setValue(null,null);
 image_check(Image_Cache::attributes($account,$item,5)===[]&&$calls===0,'cold rendering omits unavailable image with zero image requests');
 (new Image_Cache)->run();$a=Image_Cache::attributes($account,$item,5);
 image_check($calls===1&&!empty($a['srcset']),'one background download creates responsive sources');
 foreach ([1=>[100,100,100],2=>[50,50,50],3=>[50,34,34],4=>[50,34,25],5=>[50,34,20],6=>[50,34,17]] as $columns=>$widths) {
  [$phone,$tablet,$desktop]=$widths;
  image_check(Image_Cache::attributes($account,$item,$columns)['sizes']==="auto, (max-width: 640px) {$phone}vw, (max-width: 1024px) {$tablet}vw, {$desktop}vw",'responsive sizes match phone and tablet boundaries for '.$columns.' columns');
 }

 $m=get_option(Image_Cache::OPTION);$variants=reset($m['items'])['variants'];$u=wp_upload_dir();
 foreach($variants as $v){$path=$u['basedir'].'/shootcal-social-feed/'.$v['file'];$files[]=$path;$size=getimagesize($path);image_check($size[0]===$v['width']&&$size[1]===$v['height']&&abs($size[0]/$size[1]-1.6)<.01,'derived dimensions preserve original aspect ratio');}
 image_check(count($variants)===3&&$a['width']===640,'320/640/1280 sizes and useful default source');
 image_check(ShootCal_Image_Cache_Test_Editor::$loads===1,'all three renditions share one decoded source');
 $html=(new Shortcode)->render(['limit'=>1,'columns'=>5]);image_check(str_contains($html,'srcset=')&&str_contains($html,'width="640"'),'shortcode emits responsive sources and dimensions');
 image_check($calls===1,'rendering performs no download');
 image_check(Image_Cache::attributes('another-account',$item,5)===[],'another account cannot reuse the saved thumbnails');
 (new Image_Cache)->run();image_check($calls===1,'ready images are reused without network');
 $bad_redirect=true;$build=new ReflectionMethod(Image_Cache::class,'build');$result=$build->invoke(new Image_Cache,hash('sha256','rejected-redirect'),$item['image_url']);image_check($result===[]&&$calls===2,'unsafe redirect rejected before a second request');
 unlink($files[0]);image_check(Image_Cache::attributes($account,$item,5)===[],'missing local derivative is omitted without provider fallback');
 (new Image_Cache)->run();image_check($calls===2,'same feed signature does not retry a missing file in a tight loop');
 $cold_html=(new Shortcode)->render(['limit'=>1,'more'=>'true']);image_check(!str_contains($cold_html,'<img')&&!str_contains($cold_html,'<template')&&!str_contains($cold_html,'__item'),'missing file leaves no initial or deferred empty tile');
 // A changed feed signature permits a bounded background repair of missing files.
 $cache=get_option(CACHE_KEY);$cache['fetched_at']++;update_option(CACHE_KEY,$cache,false);$bad_redirect=false;
 (new Image_Cache)->run();image_check($calls===3&&!empty(Image_Cache::attributes($account,$item,5)['srcset']),'next background refresh repairs missing completed files');
 $old=$u['basedir'].'/shootcal-social-feed/'.hash('sha256','fix2-old-orphan').'-320.jpg';
 $recent=$u['basedir'].'/shootcal-social-feed/'.hash('sha256','fix2-recent-orphan').'-320.jpg';
 $unrelated=$u['basedir'].'/shootcal-social-feed/fix2-unrelated-original.jpg';
 foreach([$old,$recent,$unrelated] as $path){$files[]=$path;copy($fixture,$path);}touch($old,time()-20*DAY_IN_SECONDS);touch($unrelated,time()-20*DAY_IN_SECONDS);
 touch($files[0],time()-20*DAY_IN_SECONDS);$active_mtime=filemtime($files[0]);$active_hash=hash_file('sha256',$files[0]);
 (new Image_Cache)->run();$m=get_option(Image_Cache::OPTION);
 image_check(is_file($old)&&is_file($recent)&&is_file($unrelated),'first observed orphan gets full retirement grace regardless of old file age');
 image_check(($m['retired'][basename($old)]??0)>=time()-2&&($m['retired'][basename($recent)]??0)>=time()-2,'retirement markers start when owned files become unreferenced');
 image_check(!isset($m['retired'][basename($files[0])])&&filemtime($files[0])===$active_mtime&&hash_file('sha256',$files[0])===$active_hash,'old active image has no retirement marker and bytes or mtime never change');
 $m['retired'][basename($old)]=time()-15*DAY_IN_SECONDS;$m['retired']['unrelated-state-key']=1;$m['retired'][hash('sha256','absent-file').'-320.jpg']=1;update_option(Image_Cache::OPTION,$m,false);$manifest->setValue(null,null);
 (new Image_Cache)->run();$m=get_option(Image_Cache::OPTION);
 image_check(!is_file($old)&&is_file($recent)&&is_file($unrelated),'cleanup removes only owned files whose retirement grace has elapsed');
 image_check(!isset($m['retired']['unrelated-state-key'])&&!isset($m['retired'][hash('sha256','absent-file').'-320.jpg']),'retirement map contains only actual owned filenames still present');
 image_check(!empty(Image_Cache::attributes($account,$item,5)['srcset']),'retirement cleanup preserves every active completed image');
 // An old active post must receive fresh grace when it leaves the current feed.
 $retired_key=hash('sha256',$account.':retired-post');$retired=$u['basedir'].'/shootcal-social-feed/'.$retired_key.'-320.jpg';$files[]=$retired;copy($fixture,$retired);touch($retired,time()-20*DAY_IN_SECONDS);
 $retired_item=array_replace($item,['id'=>'retired-post']);$cache=get_option(CACHE_KEY);$cache['items'][]=$retired_item;$cache['fetched_at']++;update_option(CACHE_KEY,$cache,false);
 $m=get_option(Image_Cache::OPTION);$m['items'][$retired_key]=['variants'=>[['file'=>basename($retired),'width'=>320,'height'=>200]]];update_option(Image_Cache::OPTION,$m,false);$manifest->setValue(null,null);(new Image_Cache)->run();
 $cache['items']=[$item];$cache['fetched_at']++;update_option(CACHE_KEY,$cache,false);(new Image_Cache)->run();$m=get_option(Image_Cache::OPTION);
 image_check(!isset($m['items'][$retired_key])&&is_file($retired)&&($m['retired'][basename($retired)]??0)>=time()-2,'removed old active post drops reference but gets a new14-day grace');
 image_check(!empty(Image_Cache::attributes($account,$item,5)['srcset'])&&$calls===3,'removed post cleanup preserves active cache without network');
 // Reusing a completed file cancels retirement; retiring again starts a fresh clock.
 $cache['items'][]=$retired_item;$cache['fetched_at']++;update_option(CACHE_KEY,$cache,false);$m['items'][$retired_key]=['variants'=>[['file'=>basename($retired),'width'=>320,'height'=>200]]];$m['retired'][basename($retired)]=time()-15*DAY_IN_SECONDS;update_option(Image_Cache::OPTION,$m,false);$manifest->setValue(null,null);(new Image_Cache)->run();
 image_check(is_file($retired)&&!isset(get_option(Image_Cache::OPTION)['retired'][basename($retired)]),'reused active owned variant cancels its prior retirement marker');
 $cache['items']=[$item];$cache['fetched_at']++;update_option(CACHE_KEY,$cache,false);(new Image_Cache)->run();
 image_check((get_option(Image_Cache::OPTION)['retired'][basename($retired)]??0)>=time()-2,'retiring a reused variant starts a fresh grace clock');
 // Disconnect follows the real trusted Config boundary and cache deletion hooks.
 wp_clear_scheduled_hook(Image_Cache::HOOK);
 \ShootCalInstagramFeed\Config::clear_token();delete_option(CACHE_KEY);
 image_check(wp_next_scheduled(Image_Cache::HOOK)!==false,'disconnect schedules background image cleanup without a future feed refresh');
 $events=0;foreach(_get_cron_array() as $hooks){$events+=count($hooks[Image_Cache::HOOK]??[]);}image_check($events===1&&$calls===3,'connection and cache hooks schedule exactly one cleanup with no network');
 wp_clear_scheduled_hook(Image_Cache::HOOK);(new Image_Cache)->run();$m=get_option(Image_Cache::OPTION);
 image_check($m['items']===[]&&is_file($files[0])&&($m['retired'][basename($files[0])]??0)>=time()-2,'disconnect clears references but gives even old active images full retirement grace');
 image_check(is_file($files[1])&&is_file($recent)&&is_file($unrelated),'disconnect retains recent owned images for grace and unrelated original');
 image_check((int)wp_next_scheduled(Image_Cache::HOOK)>=time()+DAY_IN_SECONDS-2,'grace cleanup continues daily without an active feed');
 image_check(get_option('shootcal_instagram_feed_images_lock',false)===false&&$calls===3,'empty feed releases image lock and makes no HTTP calls');
 wp_clear_scheduled_hook(Image_Cache::HOOK);Image_Cache::schedule();image_check(wp_next_scheduled(Image_Cache::HOOK)!==false,'persisted retirement markers can restore a missing cleanup event');
 // Advancing retirement markers simulates elapsed grace without altering file bytes.
 foreach($m['retired'] as $name=>$when)$m['retired'][$name]=time()-15*DAY_IN_SECONDS;update_option(Image_Cache::OPTION,$m,false);$manifest->setValue(null,null);
 wp_clear_scheduled_hook(Image_Cache::HOOK);(new Image_Cache)->run();
 image_check(!is_file($files[0])&&!is_file($files[1])&&!is_file($recent)&&!is_file($retired)&&is_file($unrelated),'later empty-feed cleanup retires only owned images after retirement grace');
 image_check(empty(get_option(Image_Cache::OPTION)['retired'])&&wp_next_scheduled(Image_Cache::HOOK)===false,'empty-feed cleanup drops vanished markers and stops when no owned orphans remain');
 (new Shortcode)->render();image_check(wp_next_scheduled(Image_Cache::HOOK)===false&&$calls===3,'read-only disconnected shortcode does not schedule or fetch');
 // Header-only rejection must happen before the image editor loads any frame.
 $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);
 $small=imagecreatetruecolor(2,2);$png=wp_tempnam('feed-still-png');imagepng($small,$png);$webp=wp_tempnam('feed-still-webp');imagewebp($small,$webp);$gif=wp_tempnam('feed-still-gif');imagegif($small,$gif);unset($small);$extra_fixtures=[$png,$webp,$gif];
 $png_bytes=file_get_contents($png);$webp_bytes=file_get_contents($webp);$gif_bytes=file_get_contents($gif);
 $idat='';for($offset=8;$offset<strlen($png_bytes);){$length=unpack('N',substr($png_bytes,$offset,4))[1];if(substr($png_bytes,$offset+4,4)==='IDAT')$idat.=substr($png_bytes,$offset+8,$length);$offset+=12+$length;}
 $apng=substr($png_bytes,0,33).$chunk('acTL',pack('NN',65,0));$seq=0;
 for($frame=0;$frame<65;$frame++){$apng.=$chunk('fcTL',pack('NNNNNnnCC',$seq++,2,2,0,0,1,10,0,0));$apng.=$frame===0?$chunk('IDAT',$idat):$chunk('fdAT',pack('N',$seq++).$idat);}$apng.=$chunk('IEND','');
 $webp_chunk=static fn(string $type,string $data):string=>$type.pack('V',strlen($data)).$data.(strlen($data)%2?"\0":'');
 $animation=$webp_chunk('VP8X',"\x02\0\0\0\x01\0\0\x01\0\0").$webp_chunk('ANIM',str_repeat("\0",6));
 for($frame=0;$frame<65;$frame++)$animation.=$webp_chunk('ANMF',str_repeat("\0",6)."\x01\0\0\x01\0\0\x64\0\0\0".substr($webp_bytes,12));
 $animated_webp='RIFF'.pack('V',strlen($animation)+4).'WEBP'.$animation;
 // GIF global color table is shared by all65 frames.
 $gif_start=13+(ord($gif_bytes[10])&128?3*(2**((ord($gif_bytes[10])&7)+1)):0);
 $animated_gif=substr($gif_bytes,0,$gif_start).str_repeat(substr($gif_bytes,$gif_start,-1),65)."\x3b";
 $large_png=substr($png_bytes,0,8).$chunk('IHDR',pack('NN',10000,10000).substr($png_bytes,24,5)).substr($png_bytes,33);
 $rejects=['65-frame APNG'=>$apng,'65-frame WebP'=>$animated_webp,'65-frame GIF'=>$animated_gif,'over-budget source pixels'=>$large_png,'truncated PNG'=>substr($png_bytes,0,-4),'truncated WebP'=>substr($webp_bytes,0,-1)];
 $rejects['unsupported HEIC container']=pack('N',24).'ftypheic'.pack('N',0).'heicmif1';
 $controls=$webp_chunk('ANMF','');$rejects['hidden animation chunk']='RIFF'.pack('V',strlen($webp_bytes)-8+strlen($controls)).substr($webp_bytes,8).$controls;
 $rejects['PNG chunk scan limit']=substr($png_bytes,0,33).str_repeat($chunk('tEXt',''),4096).substr($png_bytes,33);
 $duplicate=substr($webp_bytes,12).substr($webp_bytes,12);$rejects['multiple WebP codestreams']='RIFF'.pack('V',strlen($duplicate)+4).'WEBP'.$duplicate;
 $control=$chunk('acTL',pack('NN',65,0));$rejects['misplaced PNG animation control']=substr($png_bytes,0,-12).$control.substr($png_bytes,-12);
 foreach($rejects as $label=>$bytes){
  $path=wp_tempnam('feed-rejected');$extra_fixtures[]=$path;file_put_contents($path,$bytes);$download_fixture=$path;$loads=ShootCal_Image_Cache_Test_Editor::$loads;$choices=$editor_choices;$key=hash('sha256','fix3-'.$label);
  if($label==='unsupported HEIC container')image_check(wp_is_heic_image_mime_type(wp_get_image_mime($path)),'HEIC fixture is recognized by WordPress MIME detection');
  $result=$build->invoke(new Image_Cache,$key,$item['image_url']);
  image_check($result===[]&&ShootCal_Image_Cache_Test_Editor::$loads===$loads&&$editor_choices===$choices,$label.' rejected before any editor selection or frame decode');
  image_check((glob($u['basedir'].'/shootcal-social-feed/'.$key.'-*')?:[])===[],$label.' creates no derivative files');
 }
 foreach(['JPEG'=>$fixture,'PNG'=>$png,'WebP'=>$webp] as $label=>$path){
  $download_fixture=$path;$loads=ShootCal_Image_Cache_Test_Editor::$loads;$key=hash('sha256','fix3-still-'.$label);$result=$build->invoke(new Image_Cache,$key,$item['image_url']);
  foreach($result as $v)$files[]=$u['basedir'].'/shootcal-social-feed/'.$v['file'];
  image_check(count($result)===($label==='JPEG'?3:1)&&ShootCal_Image_Cache_Test_Editor::$loads===$loads+1,'valid still '.$label.' uses one decoder for its whole ladder');
 }
 if(extension_loaded('imagick')){
  $imagick_editors=static fn()=>[ShootCal_Image_Cache_Test_Imagick::class];remove_filter('wp_image_editors',$editors);add_filter('wp_image_editors',$imagick_editors);
  try{foreach(['JPEG'=>$fixture,'PNG'=>$png,'WebP'=>$webp] as $label=>$path){
   $download_fixture=$path;$loads=ShootCal_Image_Cache_Test_Imagick::$loads;$key=hash('sha256','fix3-imagick-'.$label);$result=$build->invoke(new Image_Cache,$key,$item['image_url']);
   foreach($result as $v)$files[]=$u['basedir'].'/shootcal-social-feed/'.$v['file'];
   image_check(count($result)===($label==='JPEG'?3:1)&&ShootCal_Image_Cache_Test_Imagick::$loads===$loads+1,'Imagick still '.$label.' uses one decoder and saves its whole ladder');
  }}finally{remove_filter('wp_image_editors',$imagick_editors);add_filter('wp_image_editors',$editors);}
 }
 // Exercise WordPress's real output-format mapping, which rewrites save paths.
 $format_webp=static function(array $formats):array{$formats['image/jpeg']='image/webp';return $formats;};
 $format_manifest=get_option(Image_Cache::OPTION);$format_temps=glob(get_temp_dir().'shootcal-feed-image*')?:[];add_filter('image_editor_output_format',$format_webp);
 try{
  foreach(['GD'=>$editors,'Imagick'=>$imagick_editors??null] as $engine=>$engine_filter){
   if($engine_filter===null)continue;remove_filter('wp_image_editors',$editors);add_filter('wp_image_editors',$engine_filter);
   try{
    $download_fixture=$fixture;$format_item=array_replace($item,['id'=>'fix3-format-webp-'.$engine]);$key=hash('sha256',$account.':'.$format_item['id']);$result=$build->invoke(new Image_Cache,$key,$item['image_url']);
    foreach($result as $v)$files[]=$u['basedir'].'/shootcal-social-feed/'.$v['file'];
    image_check(count($result)===3&&array_column($result,'width')===[320,640,1280],$engine.' JPEG-to-WebP output filter completes ascending responsive rungs');
    foreach($result as $v){$path=$u['basedir'].'/shootcal-social-feed/'.$v['file'];image_check(str_ends_with($v['file'],'.webp')&&(getimagesize($path)['mime']??'')==='image/webp',$engine.' filtered file extension and encoded media agree');}
    $format_state=is_array($format_manifest)?$format_manifest:[];$format_state['items'][$key]=['variants'=>$result];update_option(Image_Cache::OPTION,$format_state,false);$manifest->setValue(null,null);
    $attrs=Image_Cache::attributes($account,$format_item,5);image_check(!empty($attrs['srcset'])&&$attrs['width']===640,$engine.' filtered derivatives are completed and usable by renderer');
   }finally{remove_filter('wp_image_editors',$engine_filter);add_filter('wp_image_editors',$editors);}
  }
  image_check((glob(get_temp_dir().'shootcal-feed-image*')?:[])===$format_temps,'output-format conversion leaves no streamed or rewritten temporary paths');
  $key=hash('sha256','fix3-filtered-output-failure');$preserved_webp=$u['basedir'].'/shootcal-social-feed/'.$key.'-1280.webp';$files[]=$preserved_webp;copy($webp,$preserved_webp);$preserved_webp_hash=hash_file('sha256',$preserved_webp);ShootCal_Image_Cache_Test_Editor::$oversize=true;
  try{$result=$build->invoke(new Image_Cache,$key,$item['image_url']);}finally{ShootCal_Image_Cache_Test_Editor::$oversize=false;}
  image_check($result===[]&&hash_file('sha256',$preserved_webp)===$preserved_webp_hash,'failed filtered conversion preserves previously completed WebP bytes');
  image_check((glob(get_temp_dir().'shootcal-feed-image*')?:[])===$format_temps,'failed filtered conversion removes actual rewritten output temporary');
 }finally{remove_filter('image_editor_output_format',$format_webp);shootcal_instagram_restore_test_options([Image_Cache::OPTION=>$format_manifest]);$manifest->setValue(null,null);}
 $download_fixture=$fixture;$calls_before=$calls;$loads=ShootCal_Image_Cache_Test_Editor::$loads;
 // Core can remap JPEG to PNG/GIF as well as accepted WebP outputs. Failures
 // after a write must remove the exact owned stem files, including on throw.
 foreach(['image/webp','image/png','image/gif']as$mapped)foreach(['error','throw']as$failure){
  $mapping=static function(array $formats)use($mapped):array{$formats['image/jpeg']=$mapped;return $formats;};
  $beforeTemps=glob(get_temp_dir().'shootcal-feed-image*')?:[];$key=hash('sha256','mapped-failure-'.$mapped.'-'.$failure);
  $preserved=$u['basedir'].'/shootcal-social-feed/'.$key.'-1280.webp';$files[]=$preserved;copy($webp,$preserved);$preservedHash=hash_file('sha256',$preserved);
  ShootCal_Image_Cache_Test_Editor::$failure=$failure;ShootCal_Image_Cache_Test_Editor::$written=[];add_filter('image_editor_output_format',$mapping);
  $threw=false;$result=[];
  try{$result=$build->invoke(new Image_Cache,$key,$item['image_url']);}catch(RuntimeException $error){$threw=$error->getMessage()==='Fixture encoder failed after output';}
  finally{remove_filter('image_editor_output_format',$mapping);ShootCal_Image_Cache_Test_Editor::$failure='';}
  $written=ShootCal_Image_Cache_Test_Editor::$written;
  image_check(count($written)===1&&($failure==='throw'?$threw:$result===[]),'remapped '.$mapped.' '.$failure.' exercises a real written encoder output');
  image_check(!is_file($written[0])&&hash_file('sha256',$preserved)===$preservedHash,'remapped '.$mapped.' '.$failure.' removes partial temporary and retains completed destination');
  image_check(is_file($written[0].'.unrelated')&&file_get_contents($written[0].'.unrelated')==='preserve unrelated bytes','remapped '.$mapped.' '.$failure.' preserves unrelated same-prefix files');
  unlink($written[0].'.unrelated');
  image_check((glob(get_temp_dir().'shootcal-feed-image*')?:[])===$beforeTemps,'remapped '.$mapped.' '.$failure.' leaves no owned source or derivative temporary');
 }
 $calls_before=$calls;$loads=ShootCal_Image_Cache_Test_Editor::$loads;
 $expired=$build->invoke(new Image_Cache,hash('sha256','fix3-expired-deadline'),$item['image_url'],hrtime(true)/1e9-1);
 image_check($expired===[]&&$calls===$calls_before&&ShootCal_Image_Cache_Test_Editor::$loads===$loads,'expired job deadline admits no HTTP request or decoder');
 $request_delay_us=20000;
 try{$expired=$build->invoke(new Image_Cache,hash('sha256','fix3-download-deadline'),$item['image_url'],hrtime(true)/1e9+0.005);}finally{$request_delay_us=0;}
 image_check($expired===[]&&ShootCal_Image_Cache_Test_Editor::$loads===$loads,'deadline exhausted by download prevents any image decode');
 image_check($last_request_args['timeout']<=0.01&&$last_request_args['limit_response_size']===8*1024*1024&&!empty($last_request_args['stream']),'download carries remaining-time timeout and streamed8MiB cap');
 $key=hash('sha256','fix3-output-budget');$preserved=$u['basedir'].'/shootcal-social-feed/'.$key.'-1280.jpg';$files[]=$preserved;copy($fixture,$preserved);$preserved_hash=hash_file('sha256',$preserved);$temps_before=glob(get_temp_dir().'shootcal-feed-image*')?:[];
 ShootCal_Image_Cache_Test_Editor::$oversize=true;
 try{$result=$build->invoke(new Image_Cache,$key,$item['image_url']);}finally{ShootCal_Image_Cache_Test_Editor::$oversize=false;}
 image_check($result===[]&&hash_file('sha256',$preserved)===$preserved_hash,'output byte cap rejects oversized derivatives and preserves previously completed bytes');
 image_check((glob(get_temp_dir().'shootcal-feed-image*')?:[])===$temps_before,'failed output leaves no streamed source or staged derivative');
 // Per-tick cap is independent of successful conversion; deferred work is scheduled.
 $option['instagram_account_id']=$account;update_option(OPTION_KEY,$option,false);$batch=[];for($index=0;$index<6;$index++)$batch[]=array_replace($item,['id'=>'fix3-batch-'.$index]);
 update_option(CACHE_KEY,['account'=>['id'=>$account],'items'=>$batch,'fetched_at'=>time()+20],false);delete_option(Image_Cache::OPTION);$manifest->setValue(null,null);wp_clear_scheduled_hook(Image_Cache::HOOK);$calls_before=$calls;$loads=ShootCal_Image_Cache_Test_Editor::$loads;
 (new Image_Cache)->run();$m=get_option(Image_Cache::OPTION);$bytes=0;
 foreach($m['items'] as $entry)foreach($entry['variants'] as $v){$path=$u['basedir'].'/shootcal-social-feed/'.$v['file'];$files[]=$path;$bytes+=filesize($path);}
 image_check($calls===$calls_before+4&&ShootCal_Image_Cache_Test_Editor::$loads===$loads+4&&count($m['items'])===4,'one tick downloads and decodes at most four still images');
 image_check($bytes<=32*1024*1024&&wp_next_scheduled(Image_Cache::HOOK)!==false,'tick output remains within32MiB and later items wait for another background job');
 image_check(hash_file('sha256',$fixture)!==false,'source image retained');
} finally {remove_filter('pre_http_request',$mock,10);remove_filter('wp_image_editors',$editors);foreach($extra_fixtures as $file)if(is_file($file))unlink($file);foreach($files as $file)if(is_file($file))unlink($file);unlink($fixture);shootcal_instagram_restore_test_options($before);$manifest->setValue(null,null);}
