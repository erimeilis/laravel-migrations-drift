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
        $this->loadMigrationsFrom(
            __DIR__ . '/fixtures/migrations',
        );
    }

    protected function createTempDirectory(): string
    {
        $dir = sys_get_temp_dir()
            . '/migration-drift-test-'
            . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        return $dir;
    }

    protected function cleanTempDirectory(
        string $dir,
    ): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');

        if ($files !== false) {
            array_map('unlink', $files);
        }

        rmdir($dir);
    }
}