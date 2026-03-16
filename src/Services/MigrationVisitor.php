<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * @internal
 */
class MigrationVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    private array $touchedTables = [];

    private ?string $operationType = null;

    /** @var string[] */
    private array $upColumns = [];

    /** @var array<string, string> Column name -> Blueprint method */
    private array $upColumnTypes = [];

    /**
     * @var array<int, array{
     *     type: string,
     *     columns: string[],
     * }>
     */
    private array $upIndexes = [];

    /**
     * @var array<int, array{
     *     column: ?string,
     *     references: ?string,
     *     on: ?string,
     * }>
     */
    private array $upForeignKeys = [];

    private bool $hasDown = false;

    private bool $downIsEmpty = true;

    /** @var string[] */
    private array $downOperations = [];

    private bool $hasConditionalLogic = false;

    private bool $hasDataManipulation = false;

    private bool $inUpMethod = false;

    private bool $inDownMethod = false;

    private ?string $currentSchemaTable = null;

    /** @var array<string, string[]> */
    private array $upColumnsByTable = [];

    /** @var array<string, array<int, array{type: string, columns: string[]}>> */
    private array $upIndexesByTable = [];

    /** @var array<string, array<int, array{column: ?string, references: ?string, on: ?string}>> */
    private array $upForeignKeysByTable = [];

    public function enterNode(Node $node): ?int
    {
        // Detect up() and down() methods
        if ($node instanceof Node\Stmt\ClassMethod) {
            $name = $node->name->toString();

            if ($name === 'up') {
                $this->inUpMethod = true;
            } elseif ($name === 'down') {
                $this->hasDown = true;
                $this->inDownMethod = true;
                $this->downIsEmpty = $this->isMethodEmpty(
                    $node,
                );
            }
        }

        // Detect Schema:: calls
        if ($node instanceof Node\Expr\StaticCall) {
            $this->visitSchemaCall($node);
        }

        // Detect Blueprint method calls ($table->...)
        if (
            $node instanceof Node\Expr\MethodCall
            && ($this->inUpMethod || $this->inDownMethod)
        ) {
            $this->visitMethodCall($node);
        }

        // Detect conditional logic
        if (
            ($this->inUpMethod || $this->inDownMethod)
            && ($node instanceof Node\Stmt\If_
                || $node instanceof Node\Stmt\Switch_
                || $node instanceof Node\Expr\Match_)
        ) {
            $this->hasConditionalLogic = true;
        }

        // Detect data manipulation
        if (
            $node instanceof Node\Expr\MethodCall
            || $node instanceof Node\Expr\StaticCall
        ) {
            $this->detectDataManipulation($node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Update FK chain info (leaveNode processes
        // inner->outer: foreign() then references()
        // then on())
        if (
            $node instanceof Node\Expr\MethodCall
            && $this->inUpMethod
            && !empty($this->upForeignKeys)
        ) {
            $method = $node->name instanceof Node\Identifier
                ? $node->name->toString()
                : null;
            $lastIdx = count($this->upForeignKeys) - 1;

            if ($method === 'references') {
                $arg = $this->extractFirstStringArg(
                    $node,
                );
                if ($arg !== null) {
                    $this->upForeignKeys[$lastIdx]
                        ['references'] = $arg;
                }
            } elseif ($method === 'on') {
                $arg = $this->extractFirstStringArg(
                    $node,
                );
                if ($arg !== null) {
                    $this->upForeignKeys[$lastIdx]
                        ['on'] = $arg;
                }
            }
        }

        if (
            $node instanceof Node\Expr\StaticCall
            && $this->isSchemaFacadeCall($node)
        ) {
            $this->currentSchemaTable = null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $name = $node->name->toString();

            if ($name === 'up') {
                $this->inUpMethod = false;
            } elseif ($name === 'down') {
                $this->inDownMethod = false;
            }
        }

        return null;
    }

    /**
     * Build a MigrationDefinition from collected data.
     */
    public function toDefinition(
        string $filename,
    ): MigrationDefinition {
        $touchedTables = array_values(
            array_unique($this->touchedTables),
        );

        return new MigrationDefinition(
            filename: $filename,
            tableName: $this->touchedTables[0] ?? null,
            touchedTables: $touchedTables,
            operationType: $this->operationType
                ?? 'unknown',
            upColumns: $this->upColumns,
            upColumnTypes: $this->upColumnTypes,
            upIndexes: $this->upIndexes,
            upForeignKeys: $this->upForeignKeys,
            hasDown: $this->hasDown,
            downIsEmpty: $this->hasDown
                && $this->downIsEmpty,
            downOperations: $this->downOperations,
            hasConditionalLogic: $this->hasConditionalLogic,
            isMultiTable: count($touchedTables) > 1,
            hasDataManipulation: $this->hasDataManipulation,
            upColumnsByTable: $this->upColumnsByTable,
            upIndexesByTable: $this->upIndexesByTable,
            upForeignKeysByTable: $this->upForeignKeysByTable,
        );
    }

    private function visitSchemaCall(
        Node\Expr\StaticCall $node,
    ): void {
        if (!$this->isSchemaFacadeCall($node)) {
            return;
        }

        $method = $node->name instanceof Node\Identifier
            ? $node->name->toString()
            : null;

        if ($method === null) {
            return;
        }

        $tableName = $this->extractTableName($node);

        if ($tableName !== null) {
            $this->touchedTables[] = $tableName;
        }

        if ($this->inUpMethod && $tableName !== null) {
            $this->currentSchemaTable = $tableName;
        }

        if (
            $this->inUpMethod
            && $this->operationType === null
        ) {
            $this->operationType = match ($method) {
                'create' => 'create',
                'table' => 'alter',
                'drop', 'dropIfExists' => 'drop',
                'rename' => 'alter',
                default => 'unknown',
            };
        }

        if ($this->inDownMethod) {
            $this->downOperations[] = "Schema::{$method}"
                . ($tableName !== null
                    ? "('{$tableName}')" : '()');
        }
    }

    private function visitMethodCall(
        Node\Expr\MethodCall $node,
    ): void {
        $method = $node->name instanceof Node\Identifier
            ? $node->name->toString()
            : null;

        if ($method === null) {
            return;
        }

        if ($this->inUpMethod) {
            $this->classifyUpOperation($method, $node);
        }

        if ($this->inDownMethod) {
            $this->classifyDownOperation($method, $node);
        }
    }

    private function classifyUpOperation(
        string $method,
        Node\Expr\MethodCall $node,
    ): void {
        // Column-adding methods
        $columnMethods = [
            'id', 'uuid', 'ulid', 'string', 'text',
            'integer', 'bigInteger', 'smallInteger',
            'tinyInteger', 'mediumInteger',
            'unsignedBigInteger', 'unsignedInteger',
            'unsignedSmallInteger', 'unsignedTinyInteger',
            'unsignedMediumInteger',
            'float', 'double', 'decimal',
            'boolean', 'date', 'dateTime', 'dateTimeTz',
            'time', 'timeTz', 'timestamp', 'timestampTz',
            'timestamps', 'timestampsTz', 'softDeletes',
            'softDeletesTz', 'json', 'jsonb', 'binary',
            'enum', 'set', 'char', 'mediumText', 'longText',
            'tinyText', 'year', 'morphs', 'nullableMorphs',
            'uuidMorphs', 'nullableUuidMorphs',
            'foreignId', 'foreignUuid', 'foreignUlid',
            'rememberToken', 'ipAddress', 'macAddress',
            'geometry', 'point', 'lineString', 'polygon',
            'multiPoint', 'multiLineString', 'multiPolygon',
            'geometryCollection',
        ];

        if (in_array($method, $columnMethods, true)) {
            $colName = $this->extractFirstStringArg($node);

            if ($colName !== null) {
                $this->upColumns[] = $colName;
                $this->upColumnTypes[$colName] = $method;
                if ($this->currentSchemaTable !== null) {
                    $this->upColumnsByTable[$this->currentSchemaTable][] = $colName;
                }
            } elseif (
                in_array($method, [
                    'id', 'timestamps', 'timestampsTz',
                    'softDeletes', 'softDeletesTz',
                    'rememberToken', 'morphs',
                    'nullableMorphs',
                ], true)
            ) {
                $this->upColumns[] = $method;
                $this->upColumnTypes[$method] = $method;
            }
        }

        // Index methods
        $indexMethods = [
            'index', 'unique', 'primary',
            'spatialIndex', 'fullText',
        ];

        if (in_array($method, $indexMethods, true)) {
            $columns = $this->extractStringOrArrayArg($node);
            $indexEntry = [
                'type' => $method,
                'columns' => $columns,
            ];
            $this->upIndexes[] = $indexEntry;
            if ($this->currentSchemaTable !== null) {
                $this->upIndexesByTable[$this->currentSchemaTable][] = $indexEntry;
            }
        }

        // rawIndex('expression', 'name') — parse columns from SQL expression
        if ($method === 'rawIndex') {
            $expression = $this->extractFirstStringArg($node);
            $columns = $expression !== null
                ? $this->parseRawIndexColumns($expression)
                : [];
            $indexEntry = [
                'type' => 'index',
                'columns' => $columns,
            ];
            $this->upIndexes[] = $indexEntry;
            if ($this->currentSchemaTable !== null) {
                $this->upIndexesByTable[$this->currentSchemaTable][] = $indexEntry;
            }
        }

        // Foreign key methods
        if ($method === 'foreign') {
            $colName = $this->extractFirstStringArg(
                $node,
            );
            $fkEntry = [
                'column' => $colName,
                'references' => null,
                'on' => null,
            ];
            $this->upForeignKeys[] = $fkEntry;
            if ($this->currentSchemaTable !== null) {
                $this->upForeignKeysByTable[$this->currentSchemaTable][] = $fkEntry;
            }
        }

        // constrained() is a shorthand for
        // foreign()->references('id')->on(table)
        if ($method === 'constrained') {
            // The table arg is optional (first arg)
            $tableName = $this->extractFirstStringArg(
                $node,
            );
            // Column comes from the preceding
            // foreignId/foreignUuid call — use the last
            // upColumns entry as the FK column
            $fkColumn = !empty($this->upColumns)
                ? end($this->upColumns) : null;
            $fkEntry = [
                'column' => $fkColumn,
                'references' => 'id',
                'on' => $tableName,
            ];
            $this->upForeignKeys[] = $fkEntry;
            if ($this->currentSchemaTable !== null) {
                $this->upForeignKeysByTable[$this->currentSchemaTable][] = $fkEntry;
            }
        }
    }

    private function classifyDownOperation(
        string $method,
        Node\Expr\MethodCall $node,
    ): void {
        $downMethods = [
            'dropColumn', 'dropForeign', 'dropIndex',
            'dropUnique', 'dropPrimary', 'dropSpatialIndex',
            'dropFullText', 'dropSoftDeletes',
            'dropSoftDeletesTz', 'dropTimestamps',
            'dropTimestampsTz', 'dropRememberToken',
            'dropMorphs', 'dropConstrainedForeignId',
        ];

        if (in_array($method, $downMethods, true)) {
            $arg = $this->extractFirstStringArg($node);
            $this->downOperations[] = $method
                . ($arg !== null ? "('{$arg}')" : '()');
        }

        // Column-adding methods in down() indicate
        // that up() dropped these columns
        $columnMethods = [
            'string', 'text', 'integer', 'bigInteger',
            'boolean', 'date', 'dateTime', 'timestamp',
            'json', 'binary', 'float', 'double', 'decimal',
            'char', 'enum', 'set', 'uuid', 'ulid',
            'tinyInteger', 'smallInteger', 'mediumInteger',
            'mediumText', 'longText', 'tinyText',
        ];

        if (in_array($method, $columnMethods, true)) {
            $colName = $this->extractFirstStringArg(
                $node,
            );
            if ($colName !== null) {
                $this->downOperations[]
                    = "addColumn('{$colName}')";
            }
        }
    }

    /**
     * @param Node\Expr\StaticCall|Node\Expr\MethodCall $node
     */
    private function detectDataManipulation(
        Node\Expr $node,
    ): void {
        if (!$this->inUpMethod && !$this->inDownMethod) {
            return;
        }

        // DB::table()->insert/update/delete
        // DB::statement(), DB::unprepared()
        if ($node instanceof Node\Expr\StaticCall) {
            if (!$this->isDbFacadeCall($node)) {
                return;
            }

            $method = $node->name instanceof Node\Identifier
                ? $node->name->toString()
                : null;

            if (
                $method !== null
                && in_array($method, [
                    'statement', 'unprepared', 'insert',
                    'update', 'delete', 'table',
                ], true)
            ) {
                $this->hasDataManipulation = true;
            }
        }

        // Chained calls: DB::table()->insert()
        if ($node instanceof Node\Expr\MethodCall) {
            $method = $node->name instanceof Node\Identifier
                ? $node->name->toString()
                : null;

            if (
                $method !== null
                && in_array($method, [
                    'insert', 'update', 'delete',
                    'truncate', 'upsert',
                ], true)
            ) {
                $this->hasDataManipulation = true;
            }
        }
    }

    private function isSchemaFacadeCall(
        Node\Expr\StaticCall $node,
    ): bool {
        if (!$node->class instanceof Node\Name) {
            return false;
        }

        $className = $node->class->toString();

        return $className === 'Schema'
            || str_ends_with($className, '\\Schema');
    }

    private function isDbFacadeCall(
        Node\Expr\StaticCall $node,
    ): bool {
        if (!$node->class instanceof Node\Name) {
            return false;
        }

        $className = $node->class->toString();

        return $className === 'DB'
            || str_ends_with($className, '\\DB');
    }

    private function extractTableName(
        Node\Expr\StaticCall $node,
    ): ?string {
        if (!isset($node->args[0])) {
            return null;
        }

        $arg = $node->args[0];

        if (!$arg instanceof Node\Arg) {
            return null;
        }

        if ($arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        return null;
    }

    private function extractFirstStringArg(
        Node\Expr\MethodCall $node,
    ): ?string {
        if (!isset($node->args[0])) {
            return null;
        }

        $arg = $node->args[0];

        if (!$arg instanceof Node\Arg) {
            return null;
        }

        if ($arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        return null;
    }

    /**
     * Extract a string or array of strings from the first argument.
     *
     * Handles: ->index('col'), ->index(['col1', 'col2'])
     *
     * @return string[]
     */
    private function extractStringOrArrayArg(
        Node\Expr\MethodCall $node,
    ): array {
        if (!isset($node->args[0])) {
            return [];
        }

        $arg = $node->args[0];

        if (!$arg instanceof Node\Arg) {
            return [];
        }

        if ($arg->value instanceof Node\Scalar\String_) {
            return [$arg->value->value];
        }

        if ($arg->value instanceof Node\Expr\Array_) {
            $columns = [];
            foreach ($arg->value->items as $item) {
                if ($item->value instanceof Node\Scalar\String_) {
                    $columns[] = $item->value->value;
                }
            }

            return $columns;
        }

        return [];
    }

    /**
     * Parse column names from a raw SQL index expression.
     *
     * E.g. 'status, created_at DESC' → ['status', 'created_at']
     *
     * @return string[]
     */
    private function parseRawIndexColumns(string $expression): array
    {
        $columns = [];
        $parts = explode(',', $expression);

        foreach ($parts as $part) {
            $part = trim($part);
            // Strip SQL modifiers: ASC, DESC, NULLS FIRST, NULLS LAST
            $part = (string) preg_replace(
                '/\b(ASC|DESC|NULLS\s+FIRST|NULLS\s+LAST)\b/i',
                '',
                $part,
            );
            $col = trim($part);

            if ($col !== '') {
                $columns[] = $col;
            }
        }

        return $columns;
    }

    private function isMethodEmpty(
        Node\Stmt\ClassMethod $node,
    ): bool {
        if (
            $node->stmts === null
            || count($node->stmts) === 0
        ) {
            return true;
        }

        // Check if all statements are just comments
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Nop) {
                return false;
            }
        }

        return true;
    }
}
