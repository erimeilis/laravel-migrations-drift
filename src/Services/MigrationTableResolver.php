<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

class MigrationTableResolver
{
    /**
     * Resolve the migrations table name from config.
     *
     * Handles both string and array config formats:
     *   'migrations' => 'migrations'
     *   'migrations' => ['table' => 'migrations']
     */
    public static function resolve(): string
    {
        $migrations = config(
            'database.migrations',
            'migrations',
        );

        if (is_array($migrations)) {
            return (string) ($migrations['table']
                ?? 'migrations');
        }

        return (string) $migrations;
    }
}
