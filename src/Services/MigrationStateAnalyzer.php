<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

class MigrationStateAnalyzer
{
    public function __construct(
        private readonly MigrationDiffService $diffService,
        private readonly MigrationParser $parser,
        private readonly SchemaIntrospector $introspector,
    ) {}

    /**
     * Analyze all migrations and classify each into one of 6 states.
     *
     * @param array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>}|null $schema Pre-fetched schema (for testing); auto-fetched if null
     * @return MigrationState[]
     */
    public function analyze(string $path, ?array $schema = null): array
    {
        $fileNames = $this->diffService->getMigrationFilenames($path);
        $dbRecords = $this->diffService->getMigrationRecords();

        $actualSchema = $schema ?? $this->introspector->getFullSchema(
            (string) config('database.default'),
        );

        $fileSet = array_flip($fileNames);
        $dbSet = array_flip($dbRecords);

        // Parse all files into definitions keyed by filename
        $definitions = [];
        foreach ($fileNames as $fileName) {
            try {
                $definitions[$fileName] = $this->parser->parse(
                    $path . '/' . $fileName . '.php',
                );
            } catch (\Throwable) {
                // If parsing fails, we still track the migration
                $definitions[$fileName] = null;
            }
        }

        $states = [];

        // Process all DB records
        foreach ($dbRecords as $record) {
            $hasFile = isset($fileSet[$record]);

            if ($hasFile) {
                // Record + File — check schema
                $def = $definitions[$record] ?? null;
                $states[] = $this->classifyRecordAndFile(
                    $record,
                    $def,
                    $actualSchema,
                );
            } else {
                // Record, no file — check schema
                $states[] = $this->classifyRecordOnly(
                    $record,
                    $actualSchema,
                );
            }
        }

        // Process files without DB records
        foreach ($fileNames as $fileName) {
            if (isset($dbSet[$fileName])) {
                continue; // Already processed above
            }

            $def = $definitions[$fileName] ?? null;
            $states[] = $this->classifyFileOnly(
                $fileName,
                $def,
                $actualSchema,
            );
        }

        return $states;
    }

    /**
     * Determine if a migration's changes are reflected in the actual schema.
     *
     * @param array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>} $actualSchema
     * @return bool|null True = applied, false = not applied, null = cannot determine
     */
    public function isAppliedToSchema(
        MigrationDefinition $def,
        array $actualSchema,
    ): ?bool {
        $tableName = $def->tableName;

        if ($tableName === null) {
            return null;
        }

        $tableExists = in_array($tableName, $actualSchema['tables'], true);

        return match ($def->operationType) {
            'create' => $this->isCreateApplied(
                $tableName,
                $tableExists,
            ),
            'alter' => $this->isAlterApplied(
                $def,
                $tableName,
                $tableExists,
                $actualSchema,
            ),
            'drop' => $this->isDropApplied(
                $tableName,
                $tableExists,
                $def,
                $actualSchema,
            ),
            default => null,
        };
    }

    private function isCreateApplied(
        string $tableName,
        bool $tableExists,
    ): bool {
        return $tableExists;
    }

    /**
     * @param array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>} $actualSchema
     */
    private function isAlterApplied(
        MigrationDefinition $def,
        string $tableName,
        bool $tableExists,
        array $actualSchema,
    ): ?bool {
        if (!$tableExists) {
            return false;
        }

        $hasCheckableEvidence = !empty($def->upColumns)
            || !empty($def->upIndexes)
            || !empty($def->upForeignKeys);

        if (!$hasCheckableEvidence) {
            return null;
        }

        // Check columns — ALL added columns must be present
        if (!empty($def->upColumns)) {
            $schemaColumns = $this->extractColumnNames(
                $actualSchema['columns'][$tableName] ?? [],
            );

            foreach ($def->upColumns as $column) {
                if (!in_array($column, $schemaColumns, true)) {
                    return false;
                }
            }
        }

        // Check indexes — ALL added indexes must be present
        if (!empty($def->upIndexes)) {
            $schemaIndexColumns = $this->extractIndexColumnSets(
                $actualSchema['indexes'][$tableName] ?? [],
            );

            foreach ($def->upIndexes as $index) {
                $indexCols = $index['columns'];
                if (!empty($indexCols) && !$this->indexExistsInSchema(
                    $indexCols,
                    $schemaIndexColumns,
                )) {
                    return false;
                }
            }
        }

        // Check foreign keys — ALL added FKs must be present
        if (!empty($def->upForeignKeys)) {
            $schemaFks = $actualSchema['foreign_keys'][$tableName] ?? [];

            foreach ($def->upForeignKeys as $fk) {
                if (!$this->foreignKeyExistsInSchema(
                    $fk,
                    $schemaFks,
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>} $actualSchema
     */
    private function isDropApplied(
        string $tableName,
        bool $tableExists,
        MigrationDefinition $def,
        array $actualSchema,
    ): bool {
        // Dropping the whole table — table absent = applied
        if (empty($def->upColumns)) {
            return !$tableExists;
        }

        // Dropping specific columns — all should be absent
        if (!$tableExists) {
            return true;
        }

        $schemaColumns = $this->extractColumnNames(
            $actualSchema['columns'][$tableName] ?? [],
        );

        foreach ($def->upColumns as $column) {
            if (in_array($column, $schemaColumns, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Classify a migration that has both a DB record and a file.
     *
     * @param array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>} $actualSchema
     */
    private function classifyRecordAndFile(
        string $migrationName,
        ?MigrationDefinition $def,
        array $actualSchema,
    ): MigrationState {
        if ($def === null) {
            // Can't parse file — trust the record
            return new MigrationState(
                migrationName: $migrationName,
                status: MigrationStatus::OK,
                definition: null,
                tableName: null,
                warnings: ['Could not parse migration file'],
            );
        }

        $applied = $this->isAppliedToSchema($def, $actualSchema);
        $warnings = [];
        $partial = false;

        if ($def->hasDataManipulation || $def->hasConditionalLogic) {
            $partial = true;
            if ($def->hasDataManipulation) {
                $warnings[] = 'Contains data manipulation — schema check is partial';
            }
            if ($def->hasConditionalLogic) {
                $warnings[] = 'Contains conditional logic — schema check is partial';
            }
        }

        if ($applied === false) {
            return new MigrationState(
                migrationName: $migrationName,
                status: MigrationStatus::BOGUS_RECORD,
                definition: $def,
                tableName: $def->tableName,
                partialAnalysis: $partial,
                warnings: $warnings,
            );
        }

        // applied === true or null → trust the record (OK)
        return new MigrationState(
            migrationName: $migrationName,
            status: MigrationStatus::OK,
            definition: $def,
            tableName: $def->tableName,
            partialAnalysis: $partial,
            warnings: $warnings,
        );
    }

    /**
     * Classify a migration that has a DB record but no file.
     *
     * @param array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>} $actualSchema
     */
    private function classifyRecordOnly(
        string $migrationName,
        array $actualSchema,
    ): MigrationState {
        // Try to infer table name from migration name
        $tableName = $this->inferTableName($migrationName);

        if ($tableName !== null && in_array($tableName, $actualSchema['tables'], true)) {
            return new MigrationState(
                migrationName: $migrationName,
                status: MigrationStatus::MISSING_FILE,
                definition: null,
                tableName: $tableName,
            );
        }

        return new MigrationState(
            migrationName: $migrationName,
            status: MigrationStatus::ORPHAN_RECORD,
            definition: null,
            tableName: $tableName,
        );
    }

    /**
     * Classify a migration that has a file but no DB record.
     *
     * @param array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>} $actualSchema
     */
    private function classifyFileOnly(
        string $migrationName,
        ?MigrationDefinition $def,
        array $actualSchema,
    ): MigrationState {
        if ($def === null) {
            // Can't parse — safe default: leave for migrate
            return new MigrationState(
                migrationName: $migrationName,
                status: MigrationStatus::NEW_MIGRATION,
                definition: null,
                tableName: null,
                warnings: ['Could not parse migration file'],
            );
        }

        $applied = $this->isAppliedToSchema($def, $actualSchema);
        $warnings = [];
        $partial = false;

        if ($def->hasDataManipulation || $def->hasConditionalLogic) {
            $partial = true;
            if ($def->hasDataManipulation) {
                $warnings[] = 'Contains data manipulation — schema check is partial';
            }
            if ($def->hasConditionalLogic) {
                $warnings[] = 'Contains conditional logic — schema check is partial';
            }
        }

        if ($applied === true) {
            return new MigrationState(
                migrationName: $migrationName,
                status: MigrationStatus::LOST_RECORD,
                definition: $def,
                tableName: $def->tableName,
                partialAnalysis: $partial,
                warnings: $warnings,
            );
        }

        // applied === false or null → safe default: leave for migrate
        return new MigrationState(
            migrationName: $migrationName,
            status: MigrationStatus::NEW_MIGRATION,
            definition: $def,
            tableName: $def->tableName,
            partialAnalysis: $partial,
            warnings: $warnings,
        );
    }

    /**
     * Try to infer table name from a migration filename.
     *
     * Handles common Laravel patterns:
     * - YYYY_MM_DD_NNNNNN_create_TABLE_table
     * - YYYY_MM_DD_NNNNNN_add_COL_to_TABLE_table
     * - YYYY_MM_DD_NNNNNN_drop_TABLE_table
     */
    private function inferTableName(string $migrationName): ?string
    {
        // Strip date prefix: YYYY_MM_DD_NNNNNN_
        $description = (string) preg_replace(
            '/^\d{4}_\d{2}_\d{2}_\d{6}_/',
            '',
            $migrationName,
        );

        // create_TABLE_table
        if (preg_match('/^create_(.+)_table$/', $description, $matches)) {
            return $matches[1];
        }

        // add_*_to_TABLE_table / remove_*_from_TABLE_table
        if (preg_match('/(?:_to_|_from_)(.+)_table$/', $description, $matches)) {
            return $matches[1];
        }

        // drop_TABLE_table
        if (preg_match('/^drop_(.+)_table$/', $description, $matches)) {
            return $matches[1];
        }

        // modify_TABLE_table / update_TABLE_table / alter_TABLE_table
        if (preg_match('/^(?:modify|update|alter)_(.+)_table$/', $description, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract column names from schema column info array.
     *
     * @param array<int, array<string, mixed>> $columns
     * @return string[]
     */
    private function extractColumnNames(array $columns): array
    {
        return array_map(
            fn (array $col): string => (string) ($col['name'] ?? ''),
            $columns,
        );
    }

    /**
     * Extract column sets from schema indexes for comparison.
     *
     * @param array<int, array<string, mixed>> $indexes
     * @return array<int, string[]>
     */
    private function extractIndexColumnSets(array $indexes): array
    {
        return array_map(
            static fn (array $idx): array => $idx['columns'] ?? [],
            $indexes,
        );
    }

    /**
     * Check if an index (by its column set) exists in the schema.
     *
     * @param string[] $indexColumns
     * @param array<int, string[]> $schemaIndexColumns
     */
    private function indexExistsInSchema(
        array $indexColumns,
        array $schemaIndexColumns,
    ): bool {
        sort($indexColumns);

        foreach ($schemaIndexColumns as $schemaCols) {
            $sorted = $schemaCols;
            sort($sorted);

            if ($indexColumns === $sorted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a foreign key exists in the schema.
     *
     * @param array{column: ?string, references: ?string, on: ?string} $fk
     * @param array<int, array<string, mixed>> $schemaFks
     */
    private function foreignKeyExistsInSchema(
        array $fk,
        array $schemaFks,
    ): bool {
        $fkColumn = $fk['column'] ?? null;
        $fkReferences = $fk['references'] ?? null;
        $fkOn = $fk['on'] ?? null;

        if ($fkColumn === null) {
            return true; // Can't check — assume present
        }

        foreach ($schemaFks as $schemaFk) {
            $schemaColumns = $schemaFk['columns'] ?? [];
            $schemaTable = $schemaFk['foreign_table'] ?? null;
            $schemaRefColumns = $schemaFk['foreign_columns'] ?? [];

            if (!in_array($fkColumn, $schemaColumns, true)) {
                continue;
            }

            // Column matches — check references if we have them
            if ($fkOn !== null && $schemaTable !== $fkOn) {
                continue;
            }

            if (
                $fkReferences !== null
                && !in_array($fkReferences, $schemaRefColumns, true)
            ) {
                continue;
            }

            return true;
        }

        return false;
    }
}
