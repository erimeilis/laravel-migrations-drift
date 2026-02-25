<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests;

use EriMeilis\MigrationDrift\MigrationDriftServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MigrationDriftServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/fixtures/migrations');
    }
}