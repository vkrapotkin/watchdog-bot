<?php

namespace Vkrapotkin\WatchdogBot;

use Illuminate\Support\ServiceProvider;

final class WatchdogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watchdog.php', 'watchdog');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/watchdog.php' => config_path('watchdog.php')], 'watchdog-config');
            $this->commands([ConfigureCommand::class]);
        }
    }
}
