<?php

declare(strict_types=1);

namespace Ipsocode\Serial;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\ServiceProvider;

class SerialServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/serial.php', 'serial');

        $this->app->singleton('serial', fn (Application $app): SerialManager => new SerialManager($app));

        $this->app->alias('serial', SerialManager::class);
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/serial.php' => config_path('serial.php'),
        ], 'serial-config');
    }

    /**
     * Get configuration arrays whose entries should be merged by name.
     *
     * Without this, an application that publishes config/serial.php with only its own
     * connection would lose the package's `default` connection instead of adding to it.
     *
     * @return array<int, string>
     */
    protected function mergeableOptions(string $name): array
    {
        return ['connections'];
    }
}
