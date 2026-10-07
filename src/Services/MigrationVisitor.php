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
    /**
     * Blueprint methods that define a column's type. Used both to
     * detect added columns and to resolve the target type of a
     * ->change() call.
     *
     * @var string[]
     */
    private const COLUMN_METHODS = [
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

    /**
     * Column modifier methods whose effect must be preserved when a
     * column is replayed during consolidation.
     *
     * @var string[]
     */
    private const MODIFIER_METHODS = [
        'nullable', 'default', 'unsigned',
    ];

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

    /** @var array<string, string> Column name -> target Blueprint method (for ->change()) */
    private array $upChangedColumns = [];

    /** @var array<string, array<string, string>> table -> (column -> target Blueprint method) */
    private array $upChangedColumnsByTable = [];

    /** @var array<string, array<int, int>> Column name -> numeric type args (length / precision / scale) */
    private array $upColumnArgs = [];

    /** @var array<string, array<string, mixed>> Column name -> {nullable?, default?, unsigned?, default_approximated?} */
    private array $upColumnModifiers = [];

    /**
     * Modifier calls (nullable/default/unsigned) seen in the current
     * column chain, awaiting the column method they wrap.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $pendingModifiers = [];

    /**
     * Column names recorded as a ->change() in the current up() body,
     * awaiting their inner column call so it is not double-counted as
     * an added column.
     *
     * @var array<string, true>
     */
    private array $pendingChangeColumns = [];

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
            upChangedColumns: $this->upChangedColumns,
            upChangedColumnsByTable: $this->upChangedColumnsByTable,
            upColumnArgs: $this->upColumnArgs,
            upColumnModifiers: $this->upColumnModifiers,
        );
    }

    private function visitSchemaCall(
        Node\Expr\StaticCall $node,
    ): void {
        if (!$this->isSchemaFacadeCall($node)) {
            return;
        }

        // New table block — drop any modifier buffer so it can't leak
        // across statements.
        $this->pendingModifiers = [];

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
        // A ->change() alters an existing column rather than adding one.
        // Record it (with its target type) separately so it is not
        // mistaken for an added column, then stop — the inner column call
        // is handled below and must be excluded from the added-columns set.
        if ($method === 'change') {
            $this->recordChangedColumn($node);

            return;
        }

        // Column modifiers wrap the column method and are entered before
        // it (outermost first), so buffer them until the column appears.
        if (in_array($method, self::MODIFIER_METHODS, true)) {
            $this->pendingModifiers[] = $this->describeModifier(
                $method,
                $node,
            );

            return;
        }

        if (in_array($method, self::COLUMN_METHODS, true)) {
            // Consume any buffered modifiers for this column chain.
            $modifiers = $this->buildColumnModifiers(
                $method,
                $this->pendingModifiers,
            );
            $this->pendingModifiers = [];

            $colName = $this->extractFirstStringArg($node);

            if ($colName !== null) {
                $this->upColumnModifiers[$colName] = $modifiers;

                // The inner column call of a ->change() chain reaches here
                // after the change was already recorded — don't count it
                // as an added column.
                if (isset($this->pendingChangeColumns[$colName])) {
                    unset($this->pendingChangeColumns[$colName]);
                } else {
                    $this->upColumns[] = $colName;
                    $this->upColumnTypes[$colName] = $method;
                    $this->upColumnArgs[$colName]
                        = $this->extractIntArgs($node);
                    if ($this->currentSchemaTable !== null) {
                        $this->upColumnsByTable[$this->currentSchemaTable][] = $colName;
                    }
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

    /**
     * Record a ->change() call as a modified column.
     *
     * Walks the chained call inward (e.g.
     * $table->string('x', 100)->nullable()->change()) to the
     * column-defining method, capturing the column name and its target
     * Blueprint type. The column is also flagged pending so its inner
     * call is not double-counted as an added column.
     */
    private function recordChangedColumn(
        Node\Expr\MethodCall $changeNode,
    ): void {
        $cursor = $changeNode->var;

        while ($cursor instanceof Node\Expr\MethodCall) {
            $name = $cursor->name instanceof Node\Identifier
                ? $cursor->name->toString()
                : null;

            if (
                $name !== null
                && in_array($name, self::COLUMN_METHODS, true)
            ) {
                $colName = $this->extractFirstStringArg($cursor);

                if ($colName !== null) {
                    $this->upChangedColumns[$colName] = $name;
                    $this->upColumnArgs[$colName]
                        = $this->extractIntArgs($cursor);
                    $this->pendingChangeColumns[$colName] = true;
                    if ($this->currentSchemaTable !== null) {
                        $this->upChangedColumnsByTable
                            [$this->currentSchemaTable]
                            [$colName] = $name;
                    }
                }

                return;
            }

            $cursor = $cursor->var;
        }
    }

    /**
     * Collect numeric type arguments (length / precision / scale) from a
     * column call, i.e. the integer literals after the column name:
     * string('x', 32) → [32], decimal('x', 12, 4) → [12, 4]. Stops at the
     * first non-integer argument so value lists (enum/set) are ignored.
     *
     * @return array<int, int>
     */
    private function extractIntArgs(Node\Expr\MethodCall $node): array
    {
        $args = [];

        foreach (array_slice($node->args, 1) as $arg) {
            if (!$arg instanceof Node\Arg) {
                break;
            }

            if (!$arg->value instanceof Node\Scalar\Int_) {
                break;
            }

            $args[] = $arg->value->value;
        }

        return $args;
    }

    /**
     * Describe a single modifier call (nullable/default/unsigned).
     *
     * @return array<string, mixed>
     */
    private function describeModifier(
        string $method,
        Node\Expr\MethodCall $node,
    ): array {
        if ($method === 'unsigned') {
            return ['type' => 'unsigned'];
        }

        if ($method === 'nullable') {
            return [
                'type' => 'nullable',
                'value' => $this->extractBoolArg($node, true),
            ];
        }

        // default(...)
        $arg = $node->args[0] ?? null;

        if ($arg instanceof Node\Arg) {
            $raw = $this->extractRawExpression($arg->value);
            if ($raw !== null) {
                return ['type' => 'default', 'raw' => $raw];
            }
        }

        $scalar = $this->extractScalarArg($node);

        if ($scalar === null) {
            // Non-scalar / expression default — can't reproduce exactly.
            return ['type' => 'default', 'approximated' => true];
        }

        return ['type' => 'default', 'value' => $scalar['value']];
    }

    /**
     * Extract the SQL string from a DB::raw('...') expression, or null
     * when the value is not a DB::raw() call with a literal string.
     */
    private function extractRawExpression(Node\Expr $value): ?string
    {
        if (!$value instanceof Node\Expr\StaticCall) {
            return null;
        }

        if (!$value->class instanceof Node\Name) {
            return null;
        }

        $class = $value->class->toString();
        $isDb = $class === 'DB' || str_ends_with($class, '\\DB');

        $method = $value->name instanceof Node\Identifier
            ? $value->name->toString()
            : null;

        if (!$isDb || $method !== 'raw') {
            return null;
        }

        if (
            !isset($value->args[0])
            || !$value->args[0] instanceof Node\Arg
            || !$value->args[0]->value instanceof Node\Scalar\String_
        ) {
            return null;
        }

        return $value->args[0]->value->value;
    }

    /**
     * Fold buffered modifier descriptors into a column's modifier map.
     * Unsigned is also implied by the column method name (unsignedBigInteger, …).
     *
     * @param array<int, array<string, mixed>> $modifiers
     * @return array<string, mixed>
     */
    private function buildColumnModifiers(
        string $method,
        array $modifiers,
    ): array {
        $result = [];

        if (str_starts_with($method, 'unsigned')) {
            $result['unsigned'] = true;
        }

        foreach ($modifiers as $mod) {
            switch ($mod['type']) {
                case 'nullable':
                    $result['nullable'] = $mod['value'];
                    break;
                case 'unsigned':
                    $result['unsigned'] = true;
                    break;
                case 'default':
                    if (array_key_exists('raw', $mod)) {
                        $result['default_raw'] = $mod['raw'];
                    } elseif (array_key_exists('value', $mod)) {
                        $result['default'] = $mod['value'];
                    } else {
                        $result['default_approximated'] = true;
                    }
                    break;
            }
        }

        return $result;
    }

    private function extractBoolArg(
        Node\Expr\MethodCall $node,
        bool $default,
    ): bool {
        if (!isset($node->args[0]) || !$node->args[0] instanceof Node\Arg) {
            return $default;
        }

        $value = $node->args[0]->value;

        if ($value instanceof Node\Expr\ConstFetch) {
            $name = strtolower($value->name->toString());
            if ($name === 'true') {
                return true;
            }
            if ($name === 'false') {
                return false;
            }
        }

        return $default;
    }

    /**
     * Extract a scalar literal argument, or null when the argument is
     * absent, null, or a non-literal expression.
     *
     * @return array{value: string|int|float|bool}|null
     */
    private function extractScalarArg(
        Node\Expr\MethodCall $node,
    ): ?array {
        if (!isset($node->args[0]) || !$node->args[0] instanceof Node\Arg) {
            return null;
        }

        $value = $node->args[0]->value;

        if ($value instanceof Node\Scalar\String_) {
            return ['value' => $value->value];
        }

        if ($value instanceof Node\Scalar\Int_) {
            return ['value' => $value->value];
        }

        if ($value instanceof Node\Scalar\Float_) {
            return ['value' => $value->value];
        }

        if ($value instanceof Node\Expr\ConstFetch) {
            $name = strtolower($value->name->toString());
            if ($name === 'true') {
                return ['value' => true];
            }
            if ($name === 'false') {
                return ['value' => false];
            }
        }

        return null;
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
