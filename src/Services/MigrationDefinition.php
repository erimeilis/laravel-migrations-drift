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
     * @param string[] $upIndexes Indexes added in up()
     * @param string[] $upForeignKeys Foreign keys added in up()
     * @param bool $hasDown down() method exists
     * @param bool $downIsEmpty down() body is empty or only has comments
     * @param string[] $downOperations Operations found in down()
     * @param bool $hasConditionalLogic if/switch/match in up() or down()
     * @param bool $isMultiTable Touches more than one table
     * @param bool $hasDataManipulation Contains DB::table()->insert/update/delete, model calls, raw SQL
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
    ) {}
}
