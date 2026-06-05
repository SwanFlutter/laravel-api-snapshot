<?php

namespace SwanFlutter\LaravelApiSnapshot;

use Illuminate\Support\ServiceProvider;
use SwanFlutter\LaravelApiSnapshot\Console\SnapshotGenerateCommand;

class ApiSnapshotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/api-snapshot.php',
            'api-snapshot'
        );

        $this->app->singleton(ApiSnapshotGenerator::class, function ($app) {
            return new ApiSnapshotGenerator(
                $app['router'],
                $app['config']->get('api-snapshot', []),
                $app->make('cache')
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/api-snapshot.php' => config_path('api-snapshot.php'),
            ], 'api-snapshot-config');

            $this->commands([
                SnapshotGenerateCommand::class,
            ]);
        }
    }
}
