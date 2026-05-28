<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Decides which DB tables should be excluded from drift analysis.
 *
 * Two layers:
 *   1. Auto-detection of DB-native partition/inheritance children
 *      (PostgreSQL pg_inherits).
 *   2. User-configured patterns (literal names or /regex/).
 *
 * The drift package assumes every table comes from a migration. Tables
 * that the database itself creates (partition children) or that the
 * application creates at runtime would otherwise be reported as
 * "extra_tables" and become DROP migrations — corrupting the schema.
 */
class IgnoredTableResolver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ConnectionResolverInterface $db,
    ) {}

    /**
     * Resolve which tables in $candidates should be ignored.
     *
     * @param string[] $candidates
     * @return array<string, string> [tableName => human-readable reason]
     */
    public function resolve(string $connection, array $candidates): array
    {
        $ignored = [];

        $autoDetect = (bool) $this->config->get(
            'migration-drift.auto_detect_partitions',
            true,
        );

        if ($autoDetect) {
            $candidateSet = array_flip($candidates);

            foreach (
                $this->fetchPartitionChildren($connection)
                as $child => $parent
            ) {
                if (isset($candidateSet[$child])) {
                    $ignored[$child] = "partition of {$parent}";
                }
            }
        }

        /** @var mixed[] $patterns */
        $patterns = (array) $this->config->get(
            'migration-drift.ignore_tables',
            [],
        );

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            foreach (
                $this->matchPattern($pattern, $candidates)
                as $matched
            ) {
                if (!isset($ignored[$matched])) {
                    $ignored[$matched]
                        = $this->describePattern($pattern);
                }
            }
        }

        return $ignored;
    }

    /**
     * Fetch partition/inheritance children for the given connection.
     *
     * Returns [childTableName => parentTableName]. Empty array when
     * the driver does not expose partition children as separate
     * tables (MySQL, SQLite, SQL Server) or when the query fails.
     *
     * @return array<string, string>
     */
    protected function fetchPartitionChildren(string $connection): array
    {
        $driver = $this->driverFor($connection);

        if ($driver !== 'pgsql') {
            return [];
        }

        try {
            $rows = $this->db->connection($connection)->select(
                'select child.relname as child,'
                . ' parent.relname as parent'
                . ' from pg_inherits i'
                . ' join pg_class child'
                . ' on child.oid = i.inhrelid'
                . ' join pg_class parent'
                . ' on parent.oid = i.inhparent'
                . ' join pg_namespace n'
                . ' on n.oid = child.relnamespace'
                . ' where n.nspname = current_schema()',
            );
        } catch (\Throwable) {
            // Permissions, missing system catalogs, or non-pg
            // compatible. Fail open: detect nothing.
            return [];
        }

        $map = [];

        foreach ($rows as $row) {
            $child = is_object($row)
                ? ($row->child ?? null)
                : ($row['child'] ?? null);
            $parent = is_object($row)
                ? ($row->parent ?? null)
                : ($row['parent'] ?? null);

            if (is_string($child) && is_string($parent)) {
                $map[$child] = $parent;
            }
        }

        return $map;
    }

    /**
     * @param string[] $tables
     * @return string[] matched table names
     */
    private function matchPattern(
        string $pattern,
        array $tables,
    ): array {
        if ($this->isRegexPattern($pattern)) {
            $matched = [];

            foreach ($tables as $table) {
                $result = @preg_match($pattern, $table);

                if ($result === 1) {
                    $matched[] = $table;
                }
                // $result === false means an invalid regex —
                // silently ignored. preg_last_error() is left
                // for an optional `migrations:validate-config`
                // command to surface later.
            }

            return $matched;
        }

        return in_array($pattern, $tables, true)
            ? [$pattern]
            : [];
    }

    private function isRegexPattern(string $pattern): bool
    {
        // A regex pattern is /.../[modifiers]. We require at least
        // one closing slash after the opening one.
        return str_starts_with($pattern, '/')
            && strrpos($pattern, '/') > 0;
    }

    private function describePattern(string $pattern): string
    {
        return $this->isRegexPattern($pattern)
            ? "config pattern: {$pattern}"
            : 'config: literal';
    }

    private function driverFor(string $connection): string
    {
        try {
            $conn = $this->db->connection($connection);
        } catch (\Throwable) {
            return '';
        }

        if ($conn instanceof Connection) {
            return $conn->getDriverName();
        }

        return '';
    }
}
