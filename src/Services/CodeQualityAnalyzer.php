<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

class CodeQualityAnalyzer
{
    /**
     * Analyze a single migration for code quality issues.
     *
     * @return array<int, array{type: string, severity: string, message: string, migration: string}>
     */
    public function analyze(
        MigrationDefinition $migration,
    ): array {
        $issues = [];

        $issues = array_merge(
            $issues,
            $this->checkDownMethod($migration),
            $this->checkForeignKeyDrops($migration),
            $this->checkIndexDrops($migration),
            $this->checkConditionalLogic($migration),
            $this->checkDataManipulation($migration),
        );

        return $issues;
    }

    /**
     * Analyze multiple migrations for quality issues
     * including cross-migration analysis.
     *
     * @param MigrationDefinition[] $migrations
     * @return array<int, array{type: string, severity: string, message: string, migration: string}>
     */
    public function analyzeAll(array $migrations): array
    {
        $issues = [];

        foreach ($migrations as $migration) {
            $issues = array_merge(
                $issues,
                $this->analyze($migration),
            );
        }

        $issues = array_merge(
            $issues,
            $this->detectRedundantMigrations($migrations),
        );

        return $issues;
    }

    /**
     * Check for missing or empty down() method.
     *
     * @return array<int, array{type: string, severity: string, message: string, migration: string}>
     */
    public function checkDownMethod(
        MigrationDefinition $migration,
    ): array {
        $issues = [];

        if (!$migration->hasDown) {
            $issues[] = [
                'type' => 'missing_down',
                'severity' => 'warning',
                'message' => 'Migration has no down() method'
                    . ' — rollback will fail.',
                'migration' => $migration->filename,
            ];
        } elseif ($migration->downIsEmpty) {
            $issues[] = [
                'type' => 'empty_down',
                'severity' => 'warning',
                'message' => 'Migration has an empty down()'
                    . ' method — rollback will be a no-op.',
                'migration' => $migration->filename,
            ];
        }

        return $issues;
    }

    /**
     * Check that foreign keys added in up() are dropped
     * in down().
     *
     * @return array<int, array{type: string, severity: string, message: string, migration: string}>
     */
    public function checkForeignKeyDrops(
        MigrationDefinition $migration,
    ): array {
        $issues = [];

        if (
            empty($migration->upForeignKeys)
            || !$migration->hasDown
            || $migration->operationType === 'create'
        ) {
            return [];
        }

        $hasDropForeign = false;

        foreach ($migration->downOperations as $op) {
            if (str_starts_with($op, 'dropForeign')) {
                $hasDropForeign = true;

                break;
            }

            if (str_starts_with($op, 'dropConstrainedForeignId')) {
                $hasDropForeign = true;

                break;
            }
        }

        if (
            !$hasDropForeign
            && !$this->downDropsTable($migration)
        ) {
            $issues[] = [
                'type' => 'missing_fk_drop',
                'severity' => 'error',
                'message' => 'Foreign keys added in up()'
                    . ' but not dropped in down()'
                    . ' — rollback may fail with'
                    . ' constraint violation.',
                'migration' => $migration->filename,
            ];
        }

        return $issues;
    }

    /**
     * Check that indexes added in up() are dropped in down().
     *
     * @return array<int, array{type: string, severity: string, message: string, migration: string}>
     */
    public function checkIndexDrops(
        MigrationDefinition $migration,
    ): array {
        $issues = [];

        if (
            empty($migration->upIndexes)
            || !$migration->hasDown
            || $migration->operationType === 'create'
        ) {
            return [];
        }

        $hasDropIndex = false;

        foreach ($migration->downOperations as $op) {
            if (
                str_starts_with($op, 'dropIndex')
                || str_starts_with($op, 'dropUnique')
                || str_starts_with($op, 'dropPrimary')
                || str_starts_with($op, 'dropSpatialIndex')
                || str_starts_with($op, 'dropFullText')
            ) {
                $hasDropIndex = true;

                break;
            }
        }

        if (
            !$hasDropIndex
            && !$this->downDropsTable($migration)
        ) {
            $issues[] = [
                'type' => 'missing_index_drop',
                'severity' => 'warning',
                'message' => 'Indexes added in up() but'
                    . ' not explicitly dropped in down().',
                'migration' => $migration->filename,
            ];
        }

        return $issues;
    }

    /**
     * Flag migrations with conditional logic.
     *
     * @return array<int, array{type: string, severity: string, message: string, migration: string}>
     */
    public function checkConditionalLogic(
        MigrationDefinition $migration,
    ): array {
        if (!$migration->hasConditionalLogic) {
            return [];
        }

        return [
            [
                'type' => 'conditional_logic',
                'severity' => 'info',
                'message' => 'Migration contains conditional'
                    . ' logic (if/switch/match)'
                    . ' — may behave differently across'
                    . ' environments.',
                'migration' => $migration->filename,
            ],
        ];
    }

    /**
     * Flag migrations with data manipulation.
     *
     * @return array<int, array{type: string, severity: string, message: string, migration: string}>
     */
    public function checkDataManipulation(
        MigrationDefinition $migration,
    ): array {
        if (!$migration->hasDataManipulation) {
            return [];
        }

        return [
            [
                'type' => 'data_manipulation',
                'severity' => 'info',
                'message' => 'Migration contains data'
                    . ' manipulation (insert/update/delete)'
                    . ' — cannot be safely consolidated.',
                'migration' => $migration->filename,
            ],
        ];
    }

    /**
     * Detect tables with many migrations that could be
     * consolidated.
     *
     * @param MigrationDefinition[] $migrations
     * @return array<int, array{type: string, severity: string, message: string, migration: string}>
     */
    public function detectRedundantMigrations(
        array $migrations,
    ): array {
        $issues = [];
        $tableGroups = [];

        foreach ($migrations as $migration) {
            if ($migration->tableName === null) {
                continue;
            }

            $tableGroups[$migration->tableName][]
                = $migration->filename;
        }

        foreach ($tableGroups as $table => $filenames) {
            if (count($filenames) >= 3) {
                $issues[] = [
                    'type' => 'redundant_migrations',
                    'severity' => 'info',
                    'message' => "Table '{$table}' has "
                        . count($filenames) . ' migrations'
                        . ' — consider consolidating.',
                    'migration' => implode(', ', $filenames),
                ];
            }
        }

        return $issues;
    }

    private function downDropsTable(
        MigrationDefinition $migration,
    ): bool {
        foreach ($migration->downOperations as $op) {
            if (
                str_starts_with($op, 'Schema::drop')
                || str_starts_with($op, 'Schema::dropIfExists')
            ) {
                return true;
            }
        }

        return false;
    }
}
