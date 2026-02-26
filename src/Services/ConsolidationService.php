<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

class ConsolidationService
{
    public function __construct(
        private readonly MigrationGenerator $generator,
    ) {}

    /**
     * Find tables with multiple migrations that can be
     * consolidated.
     *
     * @param MigrationDefinition[] $definitions
     * @return array<string, array{consolidatable: MigrationDefinition[], skipped: MigrationDefinition[]}>
     */
    public function findConsolidationCandidates(
        array $definitions,
    ): array {
        $byTable = $this->groupByTable($definitions);
        $candidates = [];

        foreach ($byTable as $table => $defs) {
            if (count($defs) < 2) {
                continue;
            }

            $consolidatable = [];
            $skipped = [];

            foreach ($defs as $def) {
                if ($this->isConsolidatable($def)) {
                    $consolidatable[] = $def;
                } else {
                    $skipped[] = $def;
                }
            }

            if (count($consolidatable) >= 2) {
                $candidates[$table] = [
                    'consolidatable' => $consolidatable,
                    'skipped' => $skipped,
                ];
            }
        }

        return $candidates;
    }

    /**
     * Consolidate a set of migrations for a single table
     * into one clean create migration.
     *
     * @param MigrationDefinition[] $definitions
     *     Ordered list of migrations for one table
     */
    public function consolidate(
        array $definitions,
        string $table,
        string $migrationsPath,
        string $date,
    ): ConsolidationResult {
        $consolidatable = [];
        $skipped = [];
        $warnings = [];

        foreach ($definitions as $def) {
            if ($this->isConsolidatable($def)) {
                $consolidatable[] = $def;
            } else {
                $skipped[] = $def;
                $warnings[] = "Skipped '{$def->filename}'"
                    . ": {$this->skipReason($def)}";
            }
        }

        $state = $this->replayOperations($consolidatable);

        $filePath = $this->generator->generateCreateTable(
            $table,
            $state['columns'],
            $state['indexes'],
            $state['foreign_keys'],
            $migrationsPath,
            $date,
        );

        if ($state['has_approximated_types']) {
            $warnings[] = "Column types for '{$table}' are"
                . ' approximated as varchar(255). Run'
                . ' migrations:detect --schema after'
                . ' consolidation to verify.';
        }

        return new ConsolidationResult(
            tableName: $table,
            generatedFilePath: $filePath,
            originalMigrations: array_map(
                fn (MigrationDefinition $d): string
                    => $d->filename,
                $consolidatable,
            ),
            skippedMigrations: array_map(
                fn (MigrationDefinition $d): string
                    => $d->filename,
                $skipped,
            ),
            warnings: $warnings,
        );
    }

    /**
     * Check if a migration can be safely consolidated.
     */
    public function isConsolidatable(
        MigrationDefinition $def,
    ): bool {
        if ($def->hasConditionalLogic) {
            return false;
        }

        if ($def->isMultiTable) {
            return false;
        }

        if ($def->hasDataManipulation) {
            return false;
        }

        if ($def->operationType === 'unknown') {
            return false;
        }

        return true;
    }

    /**
     * Group definitions by their primary table name.
     *
     * @param MigrationDefinition[] $definitions
     * @return array<string, MigrationDefinition[]>
     */
    private function groupByTable(
        array $definitions,
    ): array {
        $grouped = [];

        foreach ($definitions as $def) {
            $table = $def->tableName;

            if ($table === null) {
                continue;
            }

            $grouped[$table][] = $def;
        }

        return $grouped;
    }

    /**
     * Replay migration operations in order to compute
     * the net final column, index, and FK state.
     *
     * @param MigrationDefinition[] $definitions
     * @return array{columns: array<int, array<string, mixed>>, indexes: array<int, array<string, mixed>>, foreign_keys: array<int, array<string, mixed>>, has_approximated_types: bool}
     */
    private function replayOperations(
        array $definitions,
    ): array {
        /** @var array<string, array<string, mixed>> $columns */
        $columns = [];

        /** @var array<string, array<string, mixed>> $indexes */
        $indexes = [];

        /** @var array<string, array<string, mixed>> $foreignKeys */
        $foreignKeys = [];

        $hasApproximatedTypes = false;

        foreach ($definitions as $def) {
            if ($def->operationType === 'drop') {
                // Drop table resets everything
                $columns = [];
                $indexes = [];
                $foreignKeys = [];

                continue;
            }

            // Process columns from up()
            foreach ($def->upColumns as $col) {
                $blueprintMethod = $def->upColumnTypes[$col]
                    ?? null;

                if ($blueprintMethod === null) {
                    $hasApproximatedTypes = true;
                }

                $typeInfo = $this->blueprintToType(
                    $blueprintMethod,
                );
                $columns[$col] = [
                    'name' => $col,
                    'type' => $typeInfo['type'],
                    'type_name' => $typeInfo['type_name'],
                    'nullable' => false,
                ];
            }

            // Process indexes from up()
            foreach ($def->upIndexes as $idx) {
                $indexes[$idx] = [
                    'columns' => [$idx],
                    'unique' => false,
                    'primary' => false,
                ];
            }

            // Process foreign keys from up()
            foreach ($def->upForeignKeys as $fk) {
                $foreignKeys[$fk] = [
                    'columns' => [$fk],
                    'foreign_table' => '',
                    'foreign_columns' => ['id'],
                    'on_update' => 'NO ACTION',
                    'on_delete' => 'NO ACTION',
                ];
            }

            // Process down() operations to detect net-zero
            // (columns added then dropped = absent)
            foreach ($def->downOperations as $op) {
                if (
                    preg_match(
                        '/^dropColumn\((.+)\)$/',
                        $op,
                        $m,
                    )
                ) {
                    // This is a down() drop — meaning the up()
                    // added this column. If a later migration's
                    // up() drops it, we process that separately.
                    continue;
                }

                // If down() has addColumn, the up() dropped it
                if (
                    preg_match(
                        '/^addColumn\((.+)\)$/',
                        $op,
                        $m,
                    )
                ) {
                    // The up() dropped this column — remove it
                    $colName = trim($m[1], "'\" ");
                    unset($columns[$colName]);
                }
            }

            // Handle explicit drop operations in up()
            $this->processDropOperations(
                $def,
                $columns,
                $indexes,
                $foreignKeys,
            );
        }

        return [
            'columns' => array_values($columns),
            'indexes' => array_values($indexes),
            'foreign_keys' => array_values($foreignKeys),
            'has_approximated_types' => $hasApproximatedTypes,
        ];
    }

    /**
     * Detect and process column/index/FK drops from
     * the down() method (inverse tells us what up() did).
     *
     * @param array<string, array<string, mixed>> $columns
     * @param array<string, array<string, mixed>> $indexes
     * @param array<string, array<string, mixed>> $foreignKeys
     */
    private function processDropOperations(
        MigrationDefinition $def,
        array &$columns,
        array &$indexes,
        array &$foreignKeys,
    ): void {
        foreach ($def->downOperations as $op) {
            // down() recreates a column → up() dropped it
            if (str_starts_with($op, 'addColumn(')) {
                $colName = $this->extractName($op);

                if ($colName !== null) {
                    unset($columns[$colName]);
                }
            }

            // down() adds an index → up() dropped it
            if (
                str_starts_with($op, 'addIndex(')
                || str_starts_with($op, 'index(')
            ) {
                $idxName = $this->extractName($op);

                if ($idxName !== null) {
                    unset($indexes[$idxName]);
                }
            }

            // down() adds an FK → up() dropped it
            if (str_starts_with($op, 'addForeign(')) {
                $fkName = $this->extractName($op);

                if ($fkName !== null) {
                    unset($foreignKeys[$fkName]);
                }
            }
        }
    }

    /**
     * Extract a name from a parsed operation string like
     * "addColumn('name')" or "dropColumn('name')".
     */
    private function extractName(string $op): ?string
    {
        if (
            preg_match(
                "/[a-zA-Z]+\\(['\"]([^'\"]+)['\"]/",
                $op,
                $m,
            )
        ) {
            return $m[1];
        }

        return null;
    }

    /**
     * Map a Blueprint method name to SQL type info.
     *
     * @return array{type: string, type_name: string}
     */
    private function blueprintToType(
        ?string $blueprintMethod,
    ): array {
        if ($blueprintMethod === null) {
            return [
                'type' => 'varchar(255)',
                'type_name' => 'varchar',
            ];
        }

        $map = [
            'id' => ['bigint', 'bigint'],
            'uuid' => ['char(36)', 'uuid'],
            'ulid' => ['char(26)', 'ulid'],
            'string' => ['varchar(255)', 'varchar'],
            'text' => ['text', 'text'],
            'mediumText' => ['mediumtext', 'mediumtext'],
            'longText' => ['longtext', 'longtext'],
            'tinyText' => ['tinytext', 'tinytext'],
            'integer' => ['integer', 'integer'],
            'bigInteger' => ['bigint', 'bigint'],
            'smallInteger' => ['smallint', 'smallint'],
            'tinyInteger' => ['tinyint', 'tinyint'],
            'mediumInteger' => ['mediumint', 'mediumint'],
            'unsignedBigInteger' => ['bigint', 'bigint'],
            'unsignedInteger' => ['integer', 'integer'],
            'unsignedSmallInteger' => [
                'smallint', 'smallint',
            ],
            'unsignedTinyInteger' => [
                'tinyint', 'tinyint',
            ],
            'unsignedMediumInteger' => [
                'mediumint', 'mediumint',
            ],
            'float' => ['float', 'float'],
            'double' => ['double', 'double'],
            'decimal' => ['decimal(8,2)', 'decimal'],
            'boolean' => ['boolean', 'boolean'],
            'date' => ['date', 'date'],
            'dateTime' => ['datetime', 'datetime'],
            'dateTimeTz' => ['datetime', 'datetimetz'],
            'time' => ['time', 'time'],
            'timeTz' => ['time', 'timetz'],
            'timestamp' => ['timestamp', 'timestamp'],
            'timestampTz' => [
                'timestamp', 'timestamptz',
            ],
            'timestamps' => [
                'timestamp', 'timestamp',
            ],
            'timestampsTz' => [
                'timestamp', 'timestamptz',
            ],
            'softDeletes' => [
                'timestamp', 'timestamp',
            ],
            'softDeletesTz' => [
                'timestamp', 'timestamptz',
            ],
            'json' => ['json', 'json'],
            'jsonb' => ['jsonb', 'jsonb'],
            'binary' => ['blob', 'binary'],
            'enum' => ['enum', 'enum'],
            'set' => ['set', 'set'],
            'char' => ['char(255)', 'char'],
            'year' => ['year', 'year'],
            'foreignId' => ['bigint', 'bigint'],
            'foreignUuid' => ['char(36)', 'uuid'],
            'foreignUlid' => ['char(26)', 'ulid'],
            'rememberToken' => [
                'varchar(100)', 'varchar',
            ],
            'ipAddress' => [
                'varchar(45)', 'varchar',
            ],
            'macAddress' => [
                'varchar(17)', 'varchar',
            ],
            'morphs' => ['varchar(255)', 'varchar'],
            'nullableMorphs' => [
                'varchar(255)', 'varchar',
            ],
            'uuidMorphs' => ['char(36)', 'uuid'],
            'nullableUuidMorphs' => [
                'char(36)', 'uuid',
            ],
            'geometry' => ['geometry', 'geometry'],
            'point' => ['point', 'point'],
            'lineString' => [
                'linestring', 'linestring',
            ],
            'polygon' => ['polygon', 'polygon'],
            'multiPoint' => [
                'multipoint', 'multipoint',
            ],
            'multiLineString' => [
                'multilinestring', 'multilinestring',
            ],
            'multiPolygon' => [
                'multipolygon', 'multipolygon',
            ],
            'geometryCollection' => [
                'geometrycollection',
                'geometrycollection',
            ],
        ];

        if (isset($map[$blueprintMethod])) {
            return [
                'type' => $map[$blueprintMethod][0],
                'type_name' => $map[$blueprintMethod][1],
            ];
        }

        return [
            'type' => 'varchar(255)',
            'type_name' => 'varchar',
        ];
    }

    private function skipReason(
        MigrationDefinition $def,
    ): string {
        if ($def->hasConditionalLogic) {
            return 'contains conditional logic';
        }

        if ($def->isMultiTable) {
            return 'touches multiple tables';
        }

        if ($def->hasDataManipulation) {
            return 'contains data manipulation';
        }

        return 'unknown operation type';
    }
}
