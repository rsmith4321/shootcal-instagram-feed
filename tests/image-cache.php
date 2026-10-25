<?php
declare(strict_types=1);
use ShootCalInstagramFeed\{Image_Cache,Shortcode};
use const ShootCalInstagramFeed\{OPTION_KEY,CACHE_KEY};
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','shootcal-plugin-dev.local'],true)) throw new RuntimeException('Disposable local WordPress only');
require __DIR__.'/restore-options.php';
function image_check(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label);echo "PASS $label\n"; }
$keys=[OPTION_KEY,CACHE_KEY,Image_Cache::OPTION,'shootcal_instagram_feed_images_lock','cron'];$before=[];foreach($keys as $key)$before[$key]=get_option($key,false);
$fixture=wp_tempnam('image-cache-fixture');$g=imagecreatetruecolor(1600,1000);for($y=0;$y<1000;$y+=5){imagefilledrectangle($g,0,$y,1599,$y+4,imagecolorallocate($g,$y%255,($y*7)%255,($y*11)%255));}imagejpeg($g,$fixture,95);
$account='17841499990000001';$calls=0;$bad_redirect=false;$files=[];
$mock=static function($pre,array $args,string $url)use(&$calls,&$bad_redirect,$fixture){
 ++$calls;
 if($bad_redirect)return ['headers'=>['location'=>'https://127.0.0.1/private'],'response'=>['code'=>302],'body'=>'','cookies'=>[]];
 copy($fixture,$args['filename']);return ['headers'=>['content-type'=>'image/jpeg'],'response'=>['code'=>200],'body'=>'','cookies'=>[]];
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
 image_check(hash_file('sha256',$fixture)!==false,'source image retained');
} finally {remove_filter('pre_http_request',$mock,10);foreach($files as $file)if(is_file($file))unlink($file);unlink($fixture);shootcal_instagram_restore_test_options($before);$manifest->setValue(null,null);}
