<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use Illuminate\Support\Facades\Schema;

class SchemaIntrospector
{
    /**
     * Get all user table names (excluding the migrations table).
     *
     * @return string[]
     */
    public function getTables(string $connection): array
    {
        $schema = Schema::connection($connection);

        $migrationsTable = $this->getMigrationsTable();

        $tables = collect($schema->getTables())
            ->pluck('name')
            ->reject(fn (string $name): bool => $name === $migrationsTable)
            ->values()
            ->sort()
            ->values()
            ->all();

        return $tables;
    }

    /**
     * Get column info for a table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getColumns(
        string $connection,
        string $table,
    ): array {
        return Schema::connection($connection)->getColumns($table);
    }

    /**
     * Get index info for a table.
     *
     * @return array<int, array{name: string, columns: string[], type: ?string, unique: bool, primary: bool}>
     */
    public function getIndexes(
        string $connection,
        string $table,
    ): array {
        return Schema::connection($connection)->getIndexes($table);
    }

    /**
     * Get foreign key info for a table.
     *
     * @return array<int, array{name: ?string, columns: string[], foreign_schema: string, foreign_table: string, foreign_columns: string[], on_update: string, on_delete: string}>
     */
    public function getForeignKeys(
        string $connection,
        string $table,
    ): array {
        return Schema::connection($connection)
            ->getForeignKeys($table);
    }

    /**
     * Capture a complete schema snapshot.
     *
     * @return array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>}
     */
    public function getFullSchema(string $connection): array
    {
        $tables = $this->getTables($connection);

        $columns = [];
        $indexes = [];
        $foreignKeys = [];

        foreach ($tables as $table) {
            $columns[$table] = $this->getColumns(
                $connection,
                $table,
            );
            $indexes[$table] = $this->getIndexes(
                $connection,
                $table,
            );
            $foreignKeys[$table] = $this->getForeignKeys(
                $connection,
                $table,
            );
        }

        return [
            'tables' => $tables,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ];
    }

    /**
     * Normalize a column type for comparison.
     *
     * Handles driver-specific aliases so that equivalent types
     * are not reported as different.
     */
    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        // Check exact aliases first (before stripping widths)
        // so tinyint(1) → boolean is matched before
        // tinyint(1) → tinyint
        $exactAliases = [
            'tinyint(1)' => 'boolean',
            'double precision' => 'double',
            'character varying' => 'varchar',
        ];

        if (isset($exactAliases[$type])) {
            return $exactAliases[$type];
        }

        // Strip display widths from integer types (MySQL)
        // e.g. int(11) → int, bigint(20) → bigint
        $type = (string) preg_replace(
            '/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/',
            '$1',
            $type,
        );

        // Strip precision from temporal types
        // e.g. timestamp(6) → timestamp, datetime(3) → datetime
        $type = (string) preg_replace(
            '/^((?:timestamp|datetime|time)(?:tz)?)\(\d+\)/',
            '$1',
            $type,
        );

        $aliases = [
            'int' => 'integer',
            'bool' => 'boolean',
            'tinyint' => 'tinyinteger',
            'smallint' => 'smallinteger',
            'mediumint' => 'mediuminteger',
            'bigint' => 'biginteger',
            'real' => 'float',
        ];

        return $aliases[$type] ?? $type;
    }

    private function getMigrationsTable(): string
    {
        return MigrationTableResolver::resolve();
    }
}
