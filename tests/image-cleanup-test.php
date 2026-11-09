<?php
/** Actual disposable filesystem failures; no WordPress, provider or real uploads. */
declare(strict_types=1);
namespace ShootCalInstagramFeed {
    const CACHE_KEY='fixture_feed';
    final class Config { public static string $account=''; public static function get():array { return ['instagram_account_id'=>self::$account]; } }
    final class Feed_Store { public static function cache_for_account(string $account):array { return []; } }
}
namespace {
    define('ABSPATH',__DIR__);
    define('MINUTE_IN_SECONDS',60);
    define('DAY_IN_SECONDS',86400);
    use ShootCalInstagramFeed\Image_Cache;
    require dirname(__DIR__).'/includes/class-image-cache.php';
    $root=sys_get_temp_dir().'/social-cleanup-'.bin2hex(random_bytes(6));
    $directory=$root.'/shootcal-social-feed';
    mkdir($root,0700);
    $options=[];$events=[];$uploadError=false;$stuck=false;$checks=0;
    // Atomic option SQL only; the real WordPress fixture separately exercises actual wpdb/options/object-cache behavior.
    $wpdb=new class {
        public string $options='fixture_options';public string $last_error='';public $beforeAck=null;public $afterAck=null;
        public function prepare(string $sql,...$args):string {return json_encode([$sql,$args],JSON_THROW_ON_ERROR);}
        public function get_var(string $prepared){[$sql,$args]=json_decode($prepared,true);return $GLOBALS['options'][$args[0]]??null;}
        public function query(string $prepared):int {[$sql,$args]=json_decode($prepared,true);
            if(str_starts_with($sql,'INSERT')){$GLOBALS['options'][$args[0]]=$args[1];return 1;}
            if($this->beforeAck!==null){$f=$this->beforeAck;$this->beforeAck=null;$f();}
            $removed=($GLOBALS['options'][$args[0]]??null)===$args[1];if($removed)unset($GLOBALS['options'][$args[0]]);
            if($this->afterAck!==null){$f=$this->afterAck;$this->afterAck=null;$f();}
            return $removed?1:0;
        }
    };
    function wp_upload_dir($a=null,$b=false):array {return ['basedir'=>$GLOBALS['root'],'error'=>$GLOBALS['uploadError']];}
    function get_option(string $key,$default=false){return $GLOBALS['options'][$key]??$default;}
    function add_option(string $key,$value,$a='',$b=false):bool {if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;return true;}
    function update_option(string $key,$value,$autoload=false):bool {
        if($key===Image_Cache::OPTION&&isset($GLOBALS['before_manifest_write'])){$f=$GLOBALS['before_manifest_write'];unset($GLOBALS['before_manifest_write']);$f();}
        $GLOBALS['options'][$key]=$value;return true;
    }
    function delete_option(string $key):bool {unset($GLOBALS['options'][$key]);return true;}
    function wp_delete_file(string $path):void {if(!$GLOBALS['stuck'])unlink($path);}
    function wp_next_scheduled(string $hook):int|false {return $GLOBALS['events'][$hook]??false;}
    function wp_schedule_single_event(int $at,string $hook):bool {$GLOBALS['events'][$hook]=$at;return true;}
    function wp_cache_delete(string $key,string $group=''):bool {return true;}
    function check(bool $ok,string $why):void {if(!$ok)throw new \RuntimeException($why);++$GLOBALS['checks'];}
    function freshProcess():void {(new \ReflectionProperty(Image_Cache::class,'manifest'))->setValue(null,null);}
    function pending():bool {return is_string($GLOBALS['options'][Image_Cache::PURGE_OPTION]??null);}
    $owned=$directory.'/'.str_repeat('a',64).'-640.jpg';
    $unrelated=$directory.'/unrelated.jpg';
    try {
        check(Image_Cache::purge(),'Absent directory is complete.');
        check(!pending(),'Absent directory leaves no pending marker.');
        mkdir($directory,0700);file_put_contents($owned,'disposable thumbnail');file_put_contents($unrelated,'unrelated bytes');
        check(Image_Cache::purge()&&!is_file($owned),'Readable owned thumbnail is removed.');
        check(file_get_contents($unrelated)==='unrelated bytes','Unrelated files survive.');

        file_put_contents($owned,'temporarily stuck');$stuck=true;
        check(!Image_Cache::purge()&&pending(),'Failed unlink persists pending cleanup.');
        freshProcess();Image_Cache::schedule();
        check(isset($events[Image_Cache::HOOK]),'Pending cleanup schedules even with empty feed and no image references.');
        $events=[];(new Image_Cache)->run();
        check(($events[Image_Cache::HOOK]??0)>=time()+DAY_IN_SECONDS-2,'Disconnected failed cleanup is retried daily.');
        $stuck=false;$events=[];freshProcess();(new Image_Cache)->run();
        check(!is_file($owned)&&!pending()&&!isset($options[Image_Cache::OPTION]),'A later successful sweep removes the copy and acknowledges cleanup.');
        check($events===[],'Successful cleanup ends retries.');

        foreach([0000,0400]as$mode){
            file_put_contents($owned,'permission-bound thumbnail');chmod($directory,$mode);clearstatcache();
            if(is_readable($directory)&&$mode===0000){
                chmod($directory,0700);echo "SKIP unreadable-directory cases: this user bypasses file permissions\n";break;
            }
            try{
                check(!Image_Cache::purge()&&pending(),'An unreadable or unsearchable directory cannot acknowledge deletion.');
                freshProcess();$events=[];(new Image_Cache)->run();
                check(($events[Image_Cache::HOOK]??0)>=time()+DAY_IN_SECONDS-2,'Permission failure survives a fresh process and retains retry.');
            }finally{chmod($directory,0700);clearstatcache();}
            check(is_file($owned),'Permission failure did not claim a remaining copy was gone.');
            $events=[];freshProcess();(new Image_Cache)->run();
            check(!is_file($owned)&&!pending()&&!isset($options[Image_Cache::OPTION])&&$events===[],'Permission repair completes cleanup and ends retry.');
        }

        file_put_contents($owned,'inaccessible uploads ancestor');chmod($root,0000);clearstatcache();
        if(!is_readable($root)){
            try{check(!Image_Cache::purge()&&pending(),'An inaccessible uploads ancestor is not mistaken for an absent cache directory.');}
            finally{chmod($root,0700);clearstatcache();}
            check(is_file($owned),'A remaining thumbnail behind an inaccessible parent retains cleanup.');
            freshProcess();$events=[];(new Image_Cache)->run();
            check(!is_file($owned)&&!pending()&&$events===[],'Ancestor permission repair completes pending cleanup.');
        }else{chmod($root,0700);Image_Cache::purge();}

        $uploadError='fixture unavailable';check(!Image_Cache::purge()&&pending(),'Uploads lookup failure retains cleanup.');$uploadError=false;
        check(Image_Cache::purge()&&!pending(),'A successful retry clears the lookup failure.');

        file_put_contents($owned,'cleanup before reconnect');$stuck=true;
        check(!Image_Cache::purge()&&pending(),'Reconnect fixture begins with pending cleanup.');
        \ShootCalInstagramFeed\Config::$account='synthetic-new-account';
        $events=[];freshProcess();(new Image_Cache)->run();
        check(is_file($owned)&&pending()&&isset($events[Image_Cache::HOOK]),'Reconnect cannot discard unfinished cleanup or its retry.');
        \ShootCalInstagramFeed\Config::$account='';$stuck=false;$events=[];freshProcess();(new Image_Cache)->run();
        check(!is_file($owned)&&!pending(),'Pending cleanup completes without changing saved account settings.');

        // An already-running empty-feed batch saves its local manifest after a concurrent disconnect/reconnect.
        file_put_contents($owned,'old connection thumbnail');$options[Image_Cache::OPTION]=['retired'=>[basename($owned)=>time()]];
        \ShootCalInstagramFeed\Config::$account='synthetic-old-account';$events=[];freshProcess();
        $before_manifest_write=static function()use($owned):void{
            \ShootCalInstagramFeed\Config::$account='';
            check(!Image_Cache::purge()&&pending(),'Disconnect records durable intent without waiting for the active image batch.');
            file_put_contents($owned,'late old-batch copy');
            \ShootCalInstagramFeed\Config::$account='synthetic-reconnected-account';
        };
        (new Image_Cache)->run();
        check(pending()&&is_file($owned),'An older manifest save cannot erase the disconnect intent, including a late copy.');
        $events=[];freshProcess();Image_Cache::schedule();
        check(isset($events[Image_Cache::HOOK]),'A restarted reconnected process schedules durable cleanup independently of manifest entries.');
        $events=[];freshProcess();(new Image_Cache)->run();
        check(!pending()&&!is_file($owned),'The reconnected sweep finishes the old cleanup before any ordinary retirement/build.');

        // Another disconnect before/after the exact acknowledgment must remain pending.
        foreach(['beforeAck','afterAck']as$when){
            file_put_contents($owned,'first disconnect copy');$events=[];
            $wpdb->$when=static function()use($owned):void{
                check(!Image_Cache::purge()&&pending(),'A concurrent disconnect records a new generation while the sweep owns the image lock.');
                file_put_contents($owned,'new generation late copy');
            };
            check(!Image_Cache::purge()&&pending(),'An acknowledgment cannot consume a disconnect '.$when.'.');
            check(is_file($owned),'The late copy still requires the newer cleanup generation.');
            $events=[];freshProcess();(new Image_Cache)->run();
            check(!pending()&&!is_file($owned),'The next locked sweep acknowledges the newer generation after '.$when.'.');
        }

        // Older versions may already have a manifest-only pending marker.
        file_put_contents($owned,'legacy pending copy');$options[Image_Cache::OPTION]=['purge_pending'=>true];$events=[];freshProcess();
        (new Image_Cache)->run();
        check(!pending()&&!is_file($owned),'A reconnected legacy manifest marker is upgraded and swept.');
        \ShootCalInstagramFeed\Config::$account='';

        rename($directory,$root.'/protected');symlink($root.'/protected',$directory);
        check(!Image_Cache::purge()&&pending(),'A symlinked cache directory is refused.');
        check(file_get_contents($directory.'/unrelated.jpg')==='unrelated bytes','Refused symlink target remains untouched.');
        unlink($directory);rename($root.'/protected',$directory);
        check(Image_Cache::purge(),'Restored real directory completes cleanup.');
        echo "image-cleanup: $checks checks passed\n";
    }finally{
        if(is_link($directory))unlink($directory);
        foreach([$directory,$root.'/protected']as$folder)if(is_dir($folder)){
            chmod($folder,0700);foreach(new \DirectoryIterator($folder)as$file)if(!$file->isDot())unlink($file->getPathname());rmdir($folder);
        }
        rmdir($root);
    }
}
