<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Schema;

class SchemaIntrospector
{
    public function __construct(
        private ?IgnoredTableResolver $resolver = null,
    ) {}

    /**
     * Get all user tables, minus the migrations table and any
     * tables filtered by the ignore resolver (partition children,
     * config-matched names/patterns).
     *
     * @return string[]
     */
    public function getTables(string $connection): array
    {
        $tables = $this->getRawTables($connection);
        $ignored = $this->resolver()
            ->resolve($connection, $tables);

        $filtered = array_values(
            array_diff($tables, array_keys($ignored)),
        );

        sort($filtered);

        return $filtered;
    }

    /**
     * Tables the resolver excluded, with reasons.
     *
     * @return array<string, string>
     */
    public function getIgnoredTables(string $connection): array
    {
        $tables = $this->getRawTables($connection);

        return $this->resolver()
            ->resolve($connection, $tables);
    }

    /**
     * Raw table list straight from the schema, minus the
     * migrations table. Pre-filter step shared by getTables()
     * and getIgnoredTables().
     *
     * @return string[]
     */
    private function getRawTables(string $connection): array
    {
        $builder = Schema::connection($connection);
        $migrationsTable = $this->getMigrationsTable();
        $default = $this->defaultSchema($connection);

        return collect($builder->getTables())
            ->map(
                fn (array $table): string => $this->canonicalName(
                    $table['schema'] ?? null,
                    (string) $table['name'],
                    $default,
                ),
            )
            ->reject(
                fn (string $name): bool
                    => $name === $migrationsTable,
            )
            ->values()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Canonicalize a table identifier so it matches what migration
     * authors write in Schema::create(): tables in the connection's
     * default schema stay bare ("users"); tables in any other schema
     * are qualified ("agency.principals").
     *
     * Without this, a table in a non-default Postgres schema is
     * introspected as the bare "name" and never matches the
     * "agency.principals" the migration declares — so the create is
     * misread as unapplied, its record is wiped as a bogus record, and
     * a spurious drop migration is generated.
     */
    public function canonicalName(
        ?string $schema,
        string $name,
        ?string $defaultSchema,
    ): string {
        if ($schema === null || $schema === $defaultSchema) {
            return $name;
        }

        return $schema . '.' . $name;
    }

    /**
     * The connection's default (current) schema — "public" on a stock
     * Postgres search_path, "main" on SQLite. Used to decide which
     * tables stay bare during canonicalization.
     */
    private function defaultSchema(string $connection): ?string
    {
        return Schema::connection($connection)->getCurrentSchemaName();
    }

    private function resolver(): IgnoredTableResolver
    {
        return $this->resolver
            ??= Container::getInstance()
                ->make(IgnoredTableResolver::class);
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
        $default = $this->defaultSchema($connection);

        return array_map(
            function (array $fk) use ($default): array {
                $fk['foreign_table'] = $this->canonicalName(
                    $fk['foreign_schema'] ?? null,
                    (string) $fk['foreign_table'],
                    $default,
                );

                return $fk;
            },
            Schema::connection($connection)->getForeignKeys($table),
        );
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
