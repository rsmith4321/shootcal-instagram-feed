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
 image_check(Image_Cache::attributes($account,$item,5)===['src'=>$item['image_url']]&&$calls===0,'cold rendering uses original with zero image requests');
 (new Image_Cache)->run();$a=Image_Cache::attributes($account,$item,5);
 image_check($calls===1&&!empty($a['srcset']),'one background download creates responsive sources');
 $m=get_option(Image_Cache::OPTION);$variants=reset($m['items'])['variants'];$u=wp_upload_dir();
 foreach($variants as $v){$path=$u['basedir'].'/shootcal-social-feed/'.$v['file'];$files[]=$path;$size=getimagesize($path);image_check($size[0]===$v['width']&&$size[1]===$v['height']&&abs($size[0]/$size[1]-1.6)<.01,'derived dimensions preserve original aspect ratio');}
 image_check(count($variants)===3&&$a['width']===640,'320/640/1280 sizes and useful fallback');
 $html=(new Shortcode)->render(['limit'=>1,'columns'=>5]);image_check(str_contains($html,'srcset=')&&str_contains($html,'width="640"'),'shortcode emits responsive sources and dimensions');
 image_check($calls===1,'rendering performs no download');
 image_check(Image_Cache::attributes('another-account',$item,5)===['src'=>$item['image_url']],'another account cannot reuse the saved thumbnails');
 (new Image_Cache)->run();image_check($calls===1,'ready images are reused without network');
 $bad_redirect=true;$build=new ReflectionMethod(Image_Cache::class,'build');$result=$build->invoke(new Image_Cache,hash('sha256','rejected-redirect'),$item['image_url']);image_check($result===[]&&$calls===2,'unsafe redirect rejected before a second request');
 unlink($files[0]);image_check(Image_Cache::attributes($account,$item,5)===['src'=>$item['image_url']],'missing local derivative falls back without a broken image');
 image_check(hash_file('sha256',$fixture)!==false,'source image retained');
} finally {remove_filter('pre_http_request',$mock,10);foreach($files as $file)if(is_file($file))unlink($file);unlink($fixture);shootcal_instagram_restore_test_options($before);$manifest->setValue(null,null);}
