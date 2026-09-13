<?php

namespace Vkrapotkin\WatchdogBot;

use Illuminate\Console\Command;

final class ConfigureCommand extends Command
{
    protected $signature = 'watchdog:configure';
    protected $description = 'Export private settings for the independent watchdog runner';

    public function handle(): int
    {
        $config = Settings::validate(config('watchdog'));
        JsonFile::write(storage_path('app/watchdog/config.json'), $config);
        $this->info('Watchdog settings exported. Tokens were not printed.');
        $this->line('Standalone command: php vendor/vkrapotkin/watchdog-bot/bin/watchdog --config='.storage_path('app/watchdog/config.json'));
        return self::SUCCESS;
    }
}
