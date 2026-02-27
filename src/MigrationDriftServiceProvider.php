<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift;

use EriMeilis\MigrationDrift\Commands\DetectCommand;
use EriMeilis\MigrationDrift\Commands\FixCommand;
use EriMeilis\MigrationDrift\Commands\RenameCommand;
use EriMeilis\MigrationDrift\Services\BackupService;
use EriMeilis\MigrationDrift\Services\CodeQualityAnalyzer;
use EriMeilis\MigrationDrift\Services\ConsolidationService;
use EriMeilis\MigrationDrift\Services\DependencyResolver;
use EriMeilis\MigrationDrift\Services\MigrationDiffService;
use EriMeilis\MigrationDrift\Services\MigrationGenerator;
use EriMeilis\MigrationDrift\Services\MigrationParser;
use EriMeilis\MigrationDrift\Services\MigrationStateAnalyzer;
use EriMeilis\MigrationDrift\Services\RenameService;
use EriMeilis\MigrationDrift\Services\SchemaComparator;
use EriMeilis\MigrationDrift\Services\SchemaIntrospector;
use EriMeilis\MigrationDrift\Services\TypeMapper;
use Illuminate\Support\ServiceProvider;

class MigrationDriftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/migration-drift.php',
            'migration-drift',
        );

        $this->app->singleton(
            SchemaIntrospector::class,
        );

        $this->app->singleton(
            SchemaComparator::class,
            fn ($app) => new SchemaComparator(
                $app->make(SchemaIntrospector::class),
            ),
        );

        $this->app->singleton(TypeMapper::class);

        $this->app->bind(
            MigrationGenerator::class,
            fn ($app) => new MigrationGenerator(
                $app->make(TypeMapper::class),
            ),
        );

        $this->app->singleton(MigrationParser::class);

        $this->app->singleton(
            ConsolidationService::class,
            fn ($app) => new ConsolidationService(
                $app->make(MigrationGenerator::class),
                $app->make(TypeMapper::class),
            ),
        );

        $this->app->singleton(BackupService::class);

        $this->app->singleton(MigrationDiffService::class);

        $this->app->singleton(CodeQualityAnalyzer::class);

        $this->app->singleton(RenameService::class);

        $this->app->singleton(DependencyResolver::class);

        $this->app->singleton(
            MigrationStateAnalyzer::class,
            fn ($app) => new MigrationStateAnalyzer(
                $app->make(MigrationDiffService::class),
                $app->make(MigrationParser::class),
                $app->make(SchemaIntrospector::class),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DetectCommand::class,
                FixCommand::class,
                RenameCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/config/migration-drift.php' => config_path('migration-drift.php'),
            ], 'migration-drift-config');
        }
    }
}