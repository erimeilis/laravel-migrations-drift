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
    public array $touchedTables = [];

    public ?string $operationType = null;

    /** @var string[] */
    public array $upColumns = [];

    /** @var array<string, string> Column name → Blueprint method */
    public array $upColumnTypes = [];

    /** @var string[] */
    public array $upIndexes = [];

    /** @var string[] */
    public array $upForeignKeys = [];

    public bool $hasDown = false;

    public bool $downIsEmpty = true;

    /** @var string[] */
    public array $downOperations = [];

    public bool $hasConditionalLogic = false;

    public bool $hasDataManipulation = false;

    private bool $inUpMethod = false;

    private bool $inDownMethod = false;

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

        if ($this->inUpMethod && $this->operationType === null) {
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
            'index', 'unique', 'primary', 'spatialIndex',
            'fullText',
        ];

        if (in_array($method, $indexMethods, true)) {
            $colName = $this->extractFirstStringArg($node);
            $this->upIndexes[] = $method
                . ($colName !== null
                    ? "({$colName})" : '');
        }

        // Foreign key methods
        if (
            $method === 'foreign'
            || $method === 'constrained'
        ) {
            $colName = $this->extractFirstStringArg($node);
            $this->upForeignKeys[] = $method
                . ($colName !== null
                    ? "({$colName})" : '');
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

    private function isMethodEmpty(
        Node\Stmt\ClassMethod $node,
    ): bool {
        if ($node->stmts === null || count($node->stmts) === 0) {
            return true;
        }

        // Check if all statements are just comments (Nop nodes)
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Nop) {
                return false;
            }
        }

        return true;
    }
}
