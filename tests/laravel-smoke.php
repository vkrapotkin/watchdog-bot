<?php
// Integration smoke test against an existing local Laravel installation.
// Exports real watchdog settings only; never sends Telegram messages.
$base = $argv[1] ?? throw new InvalidArgumentException('Provide Laravel project directory');
require $base.'/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Vkrapotkin\\WatchdogBot\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/../src/'.substr($class, strlen($prefix)).'.php';
    }
});
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$app->register(Vkrapotkin\WatchdogBot\WatchdogServiceProvider::class);
$code = $kernel->call('watchdog:configure');
if ($code !== 0) { exit($code); }
$path = $app->storagePath('app/watchdog/config.json');
$config = Vkrapotkin\WatchdogBot\Settings::validate(Vkrapotkin\WatchdogBot\JsonFile::read($path));
echo 'Laravel '.$app->version().": provider registered, command executed, private config validated\n";
echo 'Site: '.$config['id'].'; recipients: '.count($config['chat_ids'])."\n";
