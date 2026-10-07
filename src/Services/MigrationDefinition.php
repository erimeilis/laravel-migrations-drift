<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

class MigrationDefinition
{
    /**
     * @param string $filename Migration filename (without .php)
     * @param string|null $tableName Primary table affected
     * @param string[] $touchedTables All tables referenced
     * @param string $operationType 'create', 'alter', 'drop', 'unknown'
     * @param string[] $upColumns Columns added/modified in up()
     * @param array<string, string> $upColumnTypes Column name → Blueprint method name
     * @param array<int, array{type: string, columns: string[]}> $upIndexes Indexes added in up()
     * @param array<int, array{column: ?string, references: ?string, on: ?string}> $upForeignKeys Foreign keys added in up()
     * @param bool $hasDown down() method exists
     * @param bool $downIsEmpty down() body is empty or only has comments
     * @param string[] $downOperations Operations found in down()
     * @param bool $hasConditionalLogic if/switch/match in up() or down()
     * @param bool $isMultiTable Touches more than one table
     * @param bool $hasDataManipulation Contains DB::table()->insert/update/delete, model calls, raw SQL
     * @param array<string, string[]> $upColumnsByTable Columns grouped by table name
     * @param array<string, array<int, array{type: string, columns: string[]}>> $upIndexesByTable Indexes grouped by table name
     * @param array<string, array<int, array{column: ?string, references: ?string, on: ?string}>> $upForeignKeysByTable Foreign keys grouped by table name
     * @param array<string, string> $upChangedColumns Columns altered via ->change() in up(): column name → target Blueprint method
     * @param array<string, array<string, string>> $upChangedColumnsByTable Changed columns grouped by table name
     * @param array<string, array<int, int>> $upColumnArgs Column name → numeric type arguments (length / precision / scale) for added or changed columns
     * @param array<string, array<string, mixed>> $upColumnModifiers Column name → modifier map (nullable, default, unsigned, default_approximated) for added or changed columns
     */
    public function __construct(
        public readonly string $filename,
        public readonly ?string $tableName,
        public readonly array $touchedTables,
        public readonly string $operationType,
        public readonly array $upColumns,
        public readonly array $upColumnTypes,
        public readonly array $upIndexes,
        public readonly array $upForeignKeys,
        public readonly bool $hasDown,
        public readonly bool $downIsEmpty,
        public readonly array $downOperations,
        public readonly bool $hasConditionalLogic,
        public readonly bool $isMultiTable,
        public readonly bool $hasDataManipulation,
        public readonly array $upColumnsByTable = [],
        public readonly array $upIndexesByTable = [],
        public readonly array $upForeignKeysByTable = [],
        public readonly array $upChangedColumns = [],
        public readonly array $upChangedColumnsByTable = [],
        public readonly array $upColumnArgs = [],
        public readonly array $upColumnModifiers = [],
    ) {}
}
