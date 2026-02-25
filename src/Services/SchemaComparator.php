<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchemaComparator
{
    /**
     * Perform a full schema comparison by creating a temp DB,
     * running migrations on it, and diffing both schemas.
     *
     * @return array{missing_tables: string[], extra_tables: string[], column_diffs: array<string, array>}
     */
    public function compare(): array
    {
        $currentDb = Config::get('database.connections.' . Config::get('database.default') . '.database');
        $tempDb = $currentDb . '_drift_verify';
        $defaultConnection = Config::get('database.default');

        try {
            DB::statement("CREATE DATABASE \"{$tempDb}\"");

            Config::set('database.connections.drift_verify', array_merge(
                Config::get("database.connections.{$defaultConnection}"),
                ['database' => $tempDb],
            ));

            Artisan::call('migrate', [
                '--database' => 'drift_verify',
                '--force' => true,
            ]);

            $currentSchema = $this->captureSchema($defaultConnection);
            $verifySchema = $this->captureSchema('drift_verify');

            return $this->diffSchemas($currentSchema, $verifySchema);
        } finally {
            DB::purge('drift_verify');
            DB::statement("DROP DATABASE IF EXISTS \"{$tempDb}\"");
        }
    }

    /**
     * Check whether a diff array indicates any schema differences.
     *
     * @param array{missing_tables: string[], extra_tables: string[], column_diffs: array<string, array>} $diff
     */
    public function hasDifferences(array $diff): bool
    {
        if (!empty($diff['missing_tables']) || !empty($diff['extra_tables'])) {
            return true;
        }

        foreach ($diff['column_diffs'] as $tableDiff) {
            if (!empty($tableDiff['missing']) || !empty($tableDiff['extra']) || !empty($tableDiff['type_mismatches'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Capture the full schema (tables, columns, indexes, foreign keys) for a connection.
     *
     * @return array{tables: string[], columns: array<string, array>, indexes: array<string, array>, foreignKeys: array<string, array>}
     */
    private function captureSchema(string $connection): array
    {
        $schema = Schema::connection($connection);

        $allTables = collect($schema->getTables())
            ->pluck('name')
            ->reject(fn (string $name): bool => $name === 'migrations')
            ->values()
            ->all();

        $columns = [];
        $indexes = [];
        $foreignKeys = [];

        foreach ($allTables as $table) {
            $columns[$table] = $schema->getColumns($table);
            $indexes[$table] = $schema->getIndexes($table);
            $foreignKeys[$table] = $schema->getForeignKeys($table);
        }

        sort($allTables);

        return [
            'tables' => $allTables,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreignKeys' => $foreignKeys,
        ];
    }

    /**
     * Diff two captured schemas and return structured differences.
     *
     * @return array{missing_tables: string[], extra_tables: string[], column_diffs: array<string, array>}
     */
    private function diffSchemas(array $current, array $verify): array
    {
        $currentTables = $current['tables'];
        $verifyTables = $verify['tables'];

        $missingInDb = array_values(array_diff($verifyTables, $currentTables));
        $extraInDb = array_values(array_diff($currentTables, $verifyTables));
        $commonTables = array_values(array_intersect($currentTables, $verifyTables));

        $columnDiffs = [];

        foreach ($commonTables as $table) {
            $currentCols = collect($current['columns'][$table] ?? [])
                ->keyBy('name');
            $verifyCols = collect($verify['columns'][$table] ?? [])
                ->keyBy('name');

            $currentColNames = $currentCols->keys()->all();
            $verifyColNames = $verifyCols->keys()->all();

            $missing = array_values(array_diff($verifyColNames, $currentColNames));
            $extra = array_values(array_diff($currentColNames, $verifyColNames));

            $typeMismatches = [];
            $commonCols = array_intersect($currentColNames, $verifyColNames);

            foreach ($commonCols as $colName) {
                $currentType = $currentCols[$colName]['type'] ?? '';
                $verifyType = $verifyCols[$colName]['type'] ?? '';

                if ($currentType !== $verifyType) {
                    $typeMismatches[] = [
                        'column' => $colName,
                        'current' => $currentType,
                        'expected' => $verifyType,
                    ];
                }
            }

            if (!empty($missing) || !empty($extra) || !empty($typeMismatches)) {
                $columnDiffs[$table] = [
                    'missing' => $missing,
                    'extra' => $extra,
                    'type_mismatches' => $typeMismatches,
                ];
            }
        }

        return [
            'missing_tables' => $missingInDb,
            'extra_tables' => $extraInDb,
            'column_diffs' => $columnDiffs,
        ];
    }
}
