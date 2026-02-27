<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class SchemaComparator
{
    public function __construct(
        private readonly SchemaIntrospector $introspector,
    ) {}

    /**
     * Perform a full schema comparison by creating a temp DB,
     * running migrations on it, and diffing both schemas.
     *
     * @param string $migrationPath Absolute path to the migrations directory
     * @param string[] $excludeFiles Migration filenames (without .php) to exclude from the temp DB run
     * @return array{missing_tables: string[], extra_tables: string[], column_diffs: array<string, array<string, mixed>>, index_diffs: array<string, array<string, mixed>>, fk_diffs: array<string, array<string, mixed>>, missing_table_details: array<string, array{columns: array<int, array<string, mixed>>, indexes: array<int, array<string, mixed>>, foreign_keys: array<int, array<string, mixed>>}>}
     */
    public function compare(
        string $migrationPath = '',
        array $excludeFiles = [],
    ): array
    {
        $defaultConnection = Config::get('database.default');
        $currentDb = Config::get(
            "database.connections.{$defaultConnection}.database"
        );

        if (!preg_match('/^[a-zA-Z0-9_]+$/', (string) $currentDb)) {
            throw new \RuntimeException(
                "Database name contains unsafe characters: "
                . "\"{$currentDb}\". Cannot create temp database"
                . ' for schema comparison.'
            );
        }

        $tempDb = $currentDb . '_drift_verify_'
            . bin2hex(random_bytes(4));
        $grammar = DB::connection()->getQueryGrammar();
        $quotedDb = $grammar->wrap($tempDb);
        $filteredDir = null;

        try {
            DB::statement("CREATE DATABASE {$quotedDb}");

            Config::set(
                'database.connections.drift_verify',
                array_merge(
                    Config::get(
                        "database.connections.{$defaultConnection}"
                    ),
                    ['database' => $tempDb],
                ),
            );

            $migrateArgs = [
                '--database' => 'drift_verify',
                '--force' => true,
            ];

            // Determine which migration files to run on the
            // temp DB. When excludeFiles is provided, copy
            // non-excluded files to a temp dir. Otherwise use
            // the resolved path directly so the temp DB matches
            // exactly what the command is analyzing.
            if (!empty($excludeFiles) && $migrationPath !== '') {
                $filteredDir = $this->createFilteredMigrationDir(
                    $migrationPath,
                    $excludeFiles,
                );

                if ($filteredDir !== null) {
                    $migrateArgs['--path'] = $filteredDir;
                    $migrateArgs['--realpath'] = true;
                }
            } elseif ($migrationPath !== '') {
                $migrateArgs['--path'] = $migrationPath;
                $migrateArgs['--realpath'] = true;
            }

            Artisan::call('migrate', $migrateArgs);

            $currentSchema = $this->introspector->getFullSchema(
                $defaultConnection,
            );
            $verifySchema = $this->introspector->getFullSchema(
                'drift_verify',
            );

            $diff = $this->diffSchemas(
                $currentSchema,
                $verifySchema,
            );

            // Capture full details for missing tables from
            // the verify DB before it is dropped
            $missingDetails = [];

            foreach ($diff['missing_tables'] as $table) {
                $missingDetails[$table] = [
                    'columns' => $this->introspector
                        ->getColumns('drift_verify', $table),
                    'indexes' => $this->introspector
                        ->getIndexes('drift_verify', $table),
                    'foreign_keys' => $this->introspector
                        ->getForeignKeys(
                            'drift_verify',
                            $table,
                        ),
                ];
            }

            $diff['missing_table_details'] = $missingDetails;

            return $diff;
        } finally {
            // Clean up filtered migration directory
            if ($filteredDir !== null) {
                $this->cleanFilteredMigrationDir($filteredDir);
            }

            try {
                DB::purge('drift_verify');
            } catch (\Throwable) {
                // Connection may not have been established
            }

            try {
                DB::statement(
                    "DROP DATABASE IF EXISTS {$quotedDb}"
                );
            } catch (\Throwable $e) {
                error_log(
                    'migration-drift: Failed to drop temp DB '
                    . $tempDb . ': ' . $e->getMessage()
                );
            }

            Config::set(
                'database.connections.drift_verify',
                null,
            );
        }
    }

    /**
     * Create a temp directory with migration files, excluding
     * specified filenames (used to skip NEW_MIGRATION files).
     *
     * @param string[] $excludeFiles Filenames without .php extension
     */
    private function createFilteredMigrationDir(
        string $migrationPath,
        array $excludeFiles,
    ): ?string {
        if (!is_dir($migrationPath)) {
            return null;
        }

        $tempDir = sys_get_temp_dir()
            . '/migration-drift-filtered-'
            . bin2hex(random_bytes(4));

        if (!mkdir($tempDir, 0700, true)) {
            return null;
        }

        $excludeSet = array_flip($excludeFiles);
        $files = glob($migrationPath . '/*.php');

        if ($files === false) {
            @rmdir($tempDir);

            return null;
        }

        foreach ($files as $file) {
            $basename = pathinfo($file, PATHINFO_FILENAME);

            if (isset($excludeSet[$basename])) {
                continue;
            }

            copy($file, $tempDir . '/' . basename($file));
        }

        return $tempDir;
    }

    /**
     * Clean up a filtered migration directory.
     */
    private function cleanFilteredMigrationDir(string $dir): void
    {
        $files = glob($dir . '/*.php');

        if ($files !== false) {
            array_map('unlink', $files);
        }

        @rmdir($dir);
    }

    /**
     * Check whether a diff array indicates any schema differences.
     *
     * @param array{missing_tables: string[], extra_tables: string[], column_diffs: array<string, array<string, mixed>>, index_diffs?: array<string, array<string, mixed>>, fk_diffs?: array<string, array<string, mixed>>} $diff
     */
    public function hasDifferences(array $diff): bool
    {
        if (
            !empty($diff['missing_tables'])
            || !empty($diff['extra_tables'])
        ) {
            return true;
        }

        foreach ($diff['column_diffs'] as $tableDiff) {
            if (
                !empty($tableDiff['missing'])
                || !empty($tableDiff['extra'])
                || !empty($tableDiff['type_mismatches'])
                || !empty($tableDiff['nullable_mismatches'])
                || !empty($tableDiff['default_mismatches'])
            ) {
                return true;
            }
        }

        foreach (($diff['index_diffs'] ?? []) as $indexDiff) {
            if (
                !empty($indexDiff['missing'])
                || !empty($indexDiff['extra'])
            ) {
                return true;
            }
        }

        foreach (($diff['fk_diffs'] ?? []) as $fkDiff) {
            if (
                !empty($fkDiff['missing'])
                || !empty($fkDiff['extra'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diff two captured schemas and return structured differences.
     *
     * @param array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>} $current
     * @param array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>} $verify
     * @return array{missing_tables: string[], extra_tables: string[], column_diffs: array<string, array<string, mixed>>, index_diffs: array<string, array<string, mixed>>, fk_diffs: array<string, array<string, mixed>>}
     */
    public function diffSchemas(
        array $current,
        array $verify,
    ): array {
        $currentTables = $current['tables'];
        $verifyTables = $verify['tables'];

        $missingInDb = array_values(
            array_diff($verifyTables, $currentTables)
        );
        $extraInDb = array_values(
            array_diff($currentTables, $verifyTables)
        );
        $commonTables = array_values(
            array_intersect($currentTables, $verifyTables)
        );

        $columnDiffs = [];
        $indexDiffs = [];
        $fkDiffs = [];

        foreach ($commonTables as $table) {
            $colDiff = $this->diffColumns(
                $current['columns'][$table] ?? [],
                $verify['columns'][$table] ?? [],
            );

            if (!empty($colDiff)) {
                $columnDiffs[$table] = $colDiff;
            }

            $idxDiff = $this->diffIndexes(
                $current['indexes'][$table] ?? [],
                $verify['indexes'][$table] ?? [],
            );

            if (!empty($idxDiff)) {
                $indexDiffs[$table] = $idxDiff;
            }

            $fkDiff = $this->diffForeignKeys(
                $current['foreign_keys'][$table] ?? [],
                $verify['foreign_keys'][$table] ?? [],
            );

            if (!empty($fkDiff)) {
                $fkDiffs[$table] = $fkDiff;
            }
        }

        return [
            'missing_tables' => $missingInDb,
            'extra_tables' => $extraInDb,
            'column_diffs' => $columnDiffs,
            'index_diffs' => $indexDiffs,
            'fk_diffs' => $fkDiffs,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $currentCols
     * @param array<int, array<string, mixed>> $verifyCols
     * @return array<string, mixed>
     */
    private function diffColumns(
        array $currentCols,
        array $verifyCols,
    ): array {
        $current = collect($currentCols)->keyBy('name');
        $verify = collect($verifyCols)->keyBy('name');

        $currentNames = $current->keys()->all();
        $verifyNames = $verify->keys()->all();

        $missing = array_values(
            array_diff($verifyNames, $currentNames)
        );
        $extra = array_values(
            array_diff($currentNames, $verifyNames)
        );

        $typeMismatches = [];
        $nullableMismatches = [];
        $defaultMismatches = [];
        $commonCols = array_intersect($currentNames, $verifyNames);

        foreach ($commonCols as $colName) {
            $curCol = $current[$colName];
            $verCol = $verify[$colName];

            $curType = $this->introspector->normalizeType(
                $curCol['type'] ?? ''
            );
            $verType = $this->introspector->normalizeType(
                $verCol['type'] ?? ''
            );

            if ($curType !== $verType) {
                $typeMismatches[] = [
                    'column' => $colName,
                    'current' => $curCol['type'] ?? '',
                    'expected' => $verCol['type'] ?? '',
                ];
            }

            $curNullable = $curCol['nullable'] ?? false;
            $verNullable = $verCol['nullable'] ?? false;

            if ($curNullable !== $verNullable) {
                $nullableMismatches[] = [
                    'column' => $colName,
                    'current' => $curNullable
                        ? 'nullable' : 'not null',
                    'expected' => $verNullable
                        ? 'nullable' : 'not null',
                ];
            }

            $curDefault = $this->normalizeDefault(
                $curCol['default'] ?? null
            );
            $verDefault = $this->normalizeDefault(
                $verCol['default'] ?? null
            );

            if ($curDefault !== $verDefault) {
                $defaultMismatches[] = [
                    'column' => $colName,
                    'current' => $curCol['default'],
                    'expected' => $verCol['default'],
                ];
            }
        }

        if (
            empty($missing) && empty($extra)
            && empty($typeMismatches)
            && empty($nullableMismatches)
            && empty($defaultMismatches)
        ) {
            return [];
        }

        return [
            'missing' => $missing,
            'extra' => $extra,
            'type_mismatches' => $typeMismatches,
            'nullable_mismatches' => $nullableMismatches,
            'default_mismatches' => $defaultMismatches,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $currentIndexes
     * @param array<int, array<string, mixed>> $verifyIndexes
     * @return array<string, mixed>
     */
    private function diffIndexes(
        array $currentIndexes,
        array $verifyIndexes,
    ): array {
        $currentByKey = $this->indexSignatures($currentIndexes);
        $verifyByKey = $this->indexSignatures($verifyIndexes);

        $missing = array_values(
            array_diff_key($verifyByKey, $currentByKey)
        );
        $extra = array_values(
            array_diff_key($currentByKey, $verifyByKey)
        );

        if (empty($missing) && empty($extra)) {
            return [];
        }

        return [
            'missing' => $missing,
            'extra' => $extra,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $currentFks
     * @param array<int, array<string, mixed>> $verifyFks
     * @return array<string, mixed>
     */
    private function diffForeignKeys(
        array $currentFks,
        array $verifyFks,
    ): array {
        $currentSigs = $this->fkSignatures($currentFks);
        $verifySigs = $this->fkSignatures($verifyFks);

        $missing = array_values(
            array_diff_key($verifySigs, $currentSigs)
        );
        $extra = array_values(
            array_diff_key($currentSigs, $verifySigs)
        );

        if (empty($missing) && empty($extra)) {
            return [];
        }

        return [
            'missing' => $missing,
            'extra' => $extra,
        ];
    }

    /**
     * Build a signature map for indexes using columns + unique + primary.
     *
     * @param array<int, array<string, mixed>> $indexes
     * @return array<string, array<string, mixed>>
     */
    private function indexSignatures(array $indexes): array
    {
        $map = [];

        foreach ($indexes as $index) {
            $cols = $index['columns'] ?? [];
            $key = implode(',', $cols)
                . ':' . (($index['unique'] ?? false)
                    ? 'unique' : 'index')
                . ':' . (($index['primary'] ?? false)
                    ? 'primary' : '');

            $map[$key] = $index;
        }

        return $map;
    }

    /**
     * Build a signature map for foreign keys using columns + ref.
     *
     * @param array<int, array<string, mixed>> $fks
     * @return array<string, array<string, mixed>>
     */
    private function fkSignatures(array $fks): array
    {
        $map = [];

        foreach ($fks as $fk) {
            $cols = $fk['columns'] ?? [];
            sort($cols);
            $refCols = $fk['foreign_columns'] ?? [];
            sort($refCols);

            $key = implode(',', $cols)
                . '->' . ($fk['foreign_table'] ?? '')
                . '(' . implode(',', $refCols) . ')';

            $map[$key] = $fk;
        }

        return $map;
    }

    /**
     * Normalize a default value for comparison.
     */
    private function normalizeDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = (string) $value;

        // Strip surrounding quotes
        $str = trim($str, "'\"");

        // Normalize boolean representations
        if (in_array(strtolower($str), ['true', '1'], true)) {
            return '1';
        }
        if (in_array(strtolower($str), ['false', '0'], true)) {
            return '0';
        }

        return $str;
    }
}
