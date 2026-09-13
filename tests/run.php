<?php
foreach (['Settings', 'JsonFile', 'Http', 'Monitor', 'HappConfig'] as $class) {
    require_once __DIR__.'/../src/'.$class.'.php';
}
use Vkrapotkin\WatchdogBot\{Settings, JsonFile, Http, Monitor, HappConfig};
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function clean(string $path): void {
    foreach (new DirectoryIterator($path) as $file) {
        if ($file->isDot()) { continue; }
        $file->isDir() ? clean($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}
$config = Settings::validate(['id'=>'test', 'name'=>'Test', 'url'=>'https://example.com/', 'token'=>'123:test', 'chat_ids'=>['1','2']]);
$tests = [];
$tests['proxy used only for Telegram, no fallback on error'] = static function ($path) use ($config) {
    $config['telegram_proxy']='socks5h://127.0.0.1:10880';
    $calls=[];
    $transport=static function ($url,$timeout,$payload,$proxy) use (&$calls) {
        $calls[]=[$url,$payload,$proxy];
        return ['status'=>200,'error'=>$proxy ? 7 : 0,'body'=>'ok'];
    };
    check(Http::probe($config,$transport)[0], 'Direct site check succeeds');
    check(!Http::send($config,'1','test',$transport), 'Proxy failure fails Telegram send');
    check(count($calls)===2 && $calls[0][2]===null && $calls[1][2]===$config['telegram_proxy'], 'Only Telegram uses proxy; no fallback');
};
$tests['Happ import restricts proxy to local Telegram HTTPS'] = static function ($path) {
    $result=HappConfig::convert(['outbounds'=>[['protocol'=>'vless','settings'=>['vnext'=>[]],'streamSettings'=>['network'=>'tcp','security'=>'reality','sockopt'=>['mark'=>1]]]]]);
    check($result['inbounds'][0]['listen']==='127.0.0.1','Loopback only');
    check($result['outbounds'][0]['protocol']==='blackhole','Default deny');
    check($result['routing']['rules'][0]['domain']===['full:api.telegram.org'],'Telegram only');
    check($result['routing']['rules'][0]['port']==='443','HTTPS only');
    check(!isset($result['outbounds'][1]['streamSettings']['sockopt']),'Do not import routing marks');
};
$tests['HTTP status, redirects, marker and TLS failure'] = static function ($path) {
    foreach ([[200,0,'ok','ok',true],[500,0,'ok','',false],[302,0,'ok','',false],[200,0,'login','ok',false],[200,60,'ok','',false]] as [$status,$error,$body,$marker,$expected]) {
        check(Http::evaluate(compact('status','error','body'), $marker)[0] === $expected, 'Incorrect probe result');
    }
};
$tests['short outage stays silent'] = static function ($path) use ($config) {
    $sent=[]; $wait=[]; $results=[[false,'HTTP 500'],[true,'HTTP 200']];
    $monitor = new Monitor($config,$path,static function () use (&$results) { return array_shift($results); },static function (...$args) use (&$sent) { $sent[]=$args; return true; },static function ($seconds) use (&$wait) { $wait[]=$seconds; });
    check($monitor->run()['healthy'], 'Should recover on retry');
    check($wait===[30] && $sent===[], 'Transient failure must not notify');
};
$tests['down, restart, partial delivery, recovery ordering'] = static function ($path) use ($config) {
    $healthy=false; $allow=false; $sent=[]; $now=100;
    $probe=static function () use (&$healthy) { return [$healthy, $healthy ? 'HTTP 200':'HTTP 500']; };
    $sender=static function ($cfg,$chat,$text) use (&$sent,&$allow) { if ($chat==='2' && !$allow) { return false; } $sent[]=[$chat,$text]; return true; };
    $run=static function () use ($config,$path,$probe,$sender,&$now) { return (new Monitor($config,$path,$probe,$sender,static function () {},static function () use (&$now) { return $now; }))->run(); };
    check($run()['pending']===1, 'Only one failed recipient pending');
    $run(); check(count($sent)===1,'No duplicate on restart');
    $healthy=true; $now=200;
    check($run()['pending']===2,'Recovery follows pending down');
    check(count($sent)===2,'Healthy recipient receives recovery');
    $allow=true; $run();
    check(count($sent)===4,'Failed recipient receives both events');
    check(str_contains($sent[2][1],'недоступен') && str_contains($sent[3][1],'восстановлен'),'Event order');
    check($sent[2][0]==='2' && $sent[3][0]==='2','Only retry failed recipient');
};
$tests['removed recipients are not notified'] = static function ($path) use ($config) {
    (new Monitor($config,$path,static fn()=>[false,'500'],static fn()=>false,static function () {}))->run();
    $config['chat_ids']=[];
    $result=(new Monitor($config,$path,static fn()=>[false,'500'],static function () { throw new RuntimeException('Unexpected send'); },static function () {}))->run();
    check($result['pending']===0,'Old recipients removed');
};
$tests['wrong-site state rejected'] = static function ($path) use ($config) {
    (new Monitor($config,$path,static fn()=>[true,'200']))->run();
    $config['id']='other'; $rejected=false;
    try { (new Monitor($config,$path,static fn()=>[true,'200']))->run(); } catch (RuntimeException) { $rejected=true; }
    check($rejected,'Reject mismatched site');
};
$tests['corrupt state fails closed'] = static function ($path) use ($config) {
    file_put_contents($path.'/state.json','broken'); $rejected=false;
    try { (new Monitor($config,$path,static fn()=>[true,'200']))->run(); } catch (JsonException) { $rejected=true; }
    check($rejected && file_get_contents($path.'/state.json')==='broken','Preserve damaged state');
};
$tests['overlapping execution skipped'] = static function ($path) use ($config) {
    $lock=fopen($path.'/run.lock','c'); flock($lock,LOCK_EX);
    try {
        $result=(new Monitor($config,$path,static function () { throw new RuntimeException('Probe should not run'); }))->run();
        check($result===['skipped'=>true],'Second runner must skip');
    } finally { fclose($lock); }
};
$tests['invalid settings rejected'] = static function ($path) use ($config) {
    foreach (['url'=>'http://example.com','chat_ids'=>['--123'],'timeout_seconds'=>0,'token'=>'bad'] as $key=>$value) {
        $rejected=false;
        try { Settings::validate(array_replace($config,[$key=>$value])); } catch (InvalidArgumentException) { $rejected=true; }
        check($rejected,'Invalid setting accepted');
    }
};
$tests['standalone CLI without Laravel or vendor'] = static function ($path) {
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/watchdog').' --help', $output,$code);
    check($code===0 && str_contains(implode("\n",$output),'--config'),'Standalone executable works without vendor');
};
$failures=0;
foreach ($tests as $name=>$test) {
    $path=sys_get_temp_dir().'/watchdog-test-'.bin2hex(random_bytes(8)); mkdir($path,0700);
    try { $test($path); echo "PASS $name\n"; } catch (Throwable $error) { $failures++; echo "FAIL $name: ".$error->getMessage()."\n"; }
    finally { clean($path); }
}
echo count($tests)." tests, $failures failures\n";
exit($failures ? 1 : 0);
