<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;

trait InteractivePrompts
{
    protected function selectConnection(): string
    {
        if ($this->option('connection')) {
            return $this->option('connection');
        }

        $configured = config(
            'migration-drift.connection',
        );
        if (is_string($configured)) {
            return $configured;
        }

        $connections = array_keys(
            config('database.connections', []),
        );
        $default = config(
            'database.default',
            'mysql',
        );

        if (
            count($connections) <= 1
            || !$this->isInteractive()
        ) {
            return $default;
        }

        return select(
            label: 'Select database connection',
            options: array_combine(
                $connections,
                $connections,
            ),
            default: $default,
            hint: 'The database connection to analyze',
        );
    }

    /**
     * Execute a callback with the selected database connection,
     * restoring the original connection afterward.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function withSelectedConnection(callable $callback): mixed
    {
        $connection = $this->selectConnection();
        $original = (string) config('database.default');

        if ($connection !== $original) {
            config()->set('database.default', $connection);
            DB::setDefaultConnection($connection);
            DB::purge($connection);
        }

        try {
            return $callback();
        } finally {
            if ($connection !== $original) {
                config()->set('database.default', $original);
                DB::setDefaultConnection($original);
            }
        }
    }

    protected function selectPath(): string
    {
        if ($this->option('path')) {
            return $this->option('path');
        }

        $default = (string) config(
            'migration-drift.migrations_path',
            database_path('migrations'),
        );

        if (!$this->isInteractive()) {
            return $default;
        }

        return suggest(
            label: 'Migrations path',
            options: [$default],
            default: $default,
            hint: 'Path to migration files',
        );
    }

    protected function confirmForce(string $message = 'Apply changes?'): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (!$this->isInteractive()) {
            return false;
        }

        return confirm(
            label: $message,
            default: false,
        );
    }

    protected function ensureMigrationsTableExists(): bool
    {
        $table = $this->getMigrationsTable();

        try {
            if (Schema::hasTable($table)) {
                return true;
            }
        } catch (\Throwable) {
            // Table check failed — likely table doesn't exist
        }

        $this->warn("The '{$table}' table does not exist.");

        if (!$this->isInteractive()) {
            $this->error(
                "Run 'php artisan migrate:install' to create the "
                . "'{$table}' table.",
            );

            return false;
        }

        $shouldInstall = confirm(
            label: "Run 'php artisan migrate:install' to create it?",
            default: true,
        );

        if (!$shouldInstall) {
            $this->error(
                "Cannot proceed without the '{$table}' table. "
                . "Run 'php artisan migrate:install' manually.",
            );

            return false;
        }

        try {
            Artisan::call('migrate:install');
            $this->info("Created '{$table}' table.");

            return true;
        } catch (\Throwable $e) {
            $this->error(
                "Failed to create '{$table}' table: "
                . $e->getMessage(),
            );

            return false;
        }
    }

    protected function getMigrationsTable(): string
    {
        return \EriMeilis\MigrationDrift\Services\MigrationTableResolver::resolve();
    }

    protected function getMigrationFileCount(string $path): int
    {
        $files = glob($path . '/*.php');

        return $files === false ? 0 : count($files);
    }

    protected function isInteractive(): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        return $this->input->isInteractive();
    }
}
