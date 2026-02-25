<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift;

use EriMeilis\MigrationDrift\Commands\DetectCommand;
use EriMeilis\MigrationDrift\Commands\RenameCommand;
use EriMeilis\MigrationDrift\Commands\SyncCommand;
use Illuminate\Support\ServiceProvider;

class MigrationDriftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/migration-drift.php', 'migration-drift');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCommand::class,
                DetectCommand::class,
                RenameCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/config/migration-drift.php' => config_path('migration-drift.php'),
            ], 'migration-drift-config');
        }
    }
}