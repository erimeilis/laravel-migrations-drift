<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

class TypeMapper
{
    /**
     * Blueprint method -> [SQL type, type_name].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const BLUEPRINT_TO_SQL = [
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
        'mediumInteger' => [
            'mediumint', 'mediumint',
        ],
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
        'ipAddress' => ['varchar(45)', 'varchar'],
        'macAddress' => ['varchar(17)', 'varchar'],
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

    /**
     * Map a DB column type to a Laravel Blueprint method call.
     *
     * @param array<string, mixed> $columnInfo Column info from SchemaIntrospector
     * @return string Blueprint method call, e.g. "string('name', 255)"
     */
    public function toBlueprintMethod(
        array $columnInfo,
    ): string {
        $name = $columnInfo['name'] ?? '';
        $type = strtolower($columnInfo['type'] ?? '');
        $typeName = strtolower(
            $columnInfo['type_name'] ?? $type,
        );
        $autoIncrement = $columnInfo['auto_increment']
            ?? false;

        // Auto-incrementing bigint → id()
        if ($autoIncrement && $this->isBigInt($typeName)) {
            return $name === 'id'
                ? 'id()'
                : "id('{$name}')";
        }

        // Auto-incrementing integer → increments()
        if ($autoIncrement && $this->isInt($typeName)) {
            return "increments('{$name}')";
        }

        return $this->mapType($name, $type, $typeName);
    }

    /**
     * Build a full column definition line including
     * modifiers.
     *
     * @param array<string, mixed> $columnInfo
     * @return string e.g. "\$table->string('name', 255)->nullable()->default('foo')"
     */
    public function toColumnDefinition(
        array $columnInfo,
    ): string {
        $method = $this->toBlueprintMethod($columnInfo);
        $line = "\$table->{$method}";

        $line .= $this->buildModifiers($columnInfo);

        return $line;
    }

    /**
     * Build an index definition line.
     *
     * @param array<string, mixed> $indexInfo
     * @return string e.g. "\$table->index(['col1', 'col2'])"
     */
    public function toIndexDefinition(
        array $indexInfo,
    ): string {
        $columns = $indexInfo['columns'] ?? [];
        $unique = $indexInfo['unique'] ?? false;
        $primary = $indexInfo['primary'] ?? false;

        if ($primary) {
            return $this->formatIndexCall(
                'primary',
                $columns,
            );
        }

        if ($unique) {
            return $this->formatIndexCall(
                'unique',
                $columns,
            );
        }

        return $this->formatIndexCall('index', $columns);
    }

    /**
     * Build a foreign key definition line.
     *
     * @param array<string, mixed> $fkInfo
     * @return string e.g. "\$table->foreign('user_id')->references('id')->on('users')"
     */
    public function toForeignKeyDefinition(
        array $fkInfo,
    ): string {
        $columns = $fkInfo['columns'] ?? [];
        $foreignTable = $fkInfo['foreign_table'] ?? '';
        $foreignColumns = $fkInfo['foreign_columns'] ?? [];
        $onUpdate = $fkInfo['on_update'] ?? 'NO ACTION';
        $onDelete = $fkInfo['on_delete'] ?? 'NO ACTION';

        $col = count($columns) === 1
            ? "'{$columns[0]}'"
            : $this->formatArray($columns);

        $refCol = count($foreignColumns) === 1
            ? "'{$foreignColumns[0]}'"
            : $this->formatArray($foreignColumns);

        $line = "\$table->foreign({$col})"
            . "->references({$refCol})"
            . "->on('{$foreignTable}')";

        if (
            strtoupper($onDelete) !== 'NO ACTION'
            && strtoupper($onDelete) !== 'RESTRICT'
        ) {
            $line .= '->onDelete('
                . "'" . strtolower($onDelete) . "')";
        }

        if (
            strtoupper($onUpdate) !== 'NO ACTION'
            && strtoupper($onUpdate) !== 'RESTRICT'
        ) {
            $line .= '->onUpdate('
                . "'" . strtolower($onUpdate) . "')";
        }

        return $line;
    }

    /**
     * Map a Blueprint method name to SQL type info.
     *
     * @return array{type: string, type_name: string}
     */
    public function fromBlueprintMethod(
        ?string $blueprintMethod,
    ): array {
        if (
            $blueprintMethod === null
            || !isset(
                self::BLUEPRINT_TO_SQL[$blueprintMethod],
            )
        ) {
            return [
                'type' => 'varchar(255)',
                'type_name' => 'varchar',
            ];
        }

        $entry = self::BLUEPRINT_TO_SQL[$blueprintMethod];

        return [
            'type' => $entry[0],
            'type_name' => $entry[1],
        ];
    }

    private function mapType(
        string $name,
        string $type,
        string $typeName,
    ): string {
        // Parse varchar/char with length
        if (
            preg_match(
                '/^(varchar|character varying)\((\d+)\)$/',
                $type,
                $m,
            )
        ) {
            $len = (int) $m[2];

            return $len === 255
                ? "string('{$name}')"
                : "string('{$name}', {$len})";
        }

        if (preg_match('/^char\((\d+)\)$/', $type, $m)) {
            $len = (int) $m[1];

            if ($len === 36) {
                return "uuid('{$name}')";
            }

            if ($len === 26) {
                return "ulid('{$name}')";
            }

            return "char('{$name}', {$len})";
        }

        // Decimal/numeric with precision
        if (
            preg_match(
                '/^(decimal|numeric)\((\d+),\s*(\d+)\)$/',
                $type,
                $m,
            )
        ) {
            return "decimal('{$name}', {$m[2]}, {$m[3]})";
        }

        // Float/double with precision
        if (
            preg_match(
                '/^(float|double)\((\d+),\s*(\d+)\)$/',
                $type,
                $m,
            )
        ) {
            $method = $m[1] === 'float'
                ? 'float' : 'double';

            return "{$method}('{$name}', {$m[2]}, {$m[3]})";
        }

        // Enum
        if (
            preg_match(
                "/^enum\((.+)\)$/i",
                $type,
                $m,
            )
        ) {
            return "enum('{$name}', [{$m[1]}])";
        }

        // Set
        if (
            preg_match(
                "/^set\((.+)\)$/i",
                $type,
                $m,
            )
        ) {
            return "set('{$name}', [{$m[1]}])";
        }

        // Simple type mappings using type_name
        $map = [
            'bigint' => "bigInteger('{$name}')",
            'integer' => "integer('{$name}')",
            'int' => "integer('{$name}')",
            'smallint' => "smallInteger('{$name}')",
            'tinyint' => "tinyInteger('{$name}')",
            'mediumint' => "mediumInteger('{$name}')",
            'boolean' => "boolean('{$name}')",
            'bool' => "boolean('{$name}')",
            'text' => "text('{$name}')",
            'mediumtext' => "mediumText('{$name}')",
            'longtext' => "longText('{$name}')",
            'tinytext' => "tinyText('{$name}')",
            'varchar' => "string('{$name}')",
            'string' => "string('{$name}')",
            'json' => "json('{$name}')",
            'jsonb' => "jsonb('{$name}')",
            'binary' => "binary('{$name}')",
            'blob' => "binary('{$name}')",
            'date' => "date('{$name}')",
            'datetime' => "dateTime('{$name}')",
            'datetimetz' => "dateTimeTz('{$name}')",
            'timestamp' => "timestamp('{$name}')",
            'timestamptz' => "timestampTz('{$name}')",
            'time' => "time('{$name}')",
            'timetz' => "timeTz('{$name}')",
            'year' => "year('{$name}')",
            'float' => "float('{$name}')",
            'double' => "double('{$name}')",
            'decimal' => "decimal('{$name}')",
            'uuid' => "uuid('{$name}')",
            'ulid' => "ulid('{$name}')",
            'ipaddress' => "ipAddress('{$name}')",
            'macaddress' => "macAddress('{$name}')",
            'point' => "point('{$name}')",
            'geometry' => "geometry('{$name}')",
            'linestring' => "lineString('{$name}')",
            'polygon' => "polygon('{$name}')",
            'multipoint' => "multiPoint('{$name}')",
            'multilinestring'
                => "multiLineString('{$name}')",
            'multipolygon' => "multiPolygon('{$name}')",
            'geometrycollection'
                => "geometryCollection('{$name}')",
        ];

        // Try type_name first, then type
        return $map[$typeName]
            ?? $map[$type]
            ?? "addColumn('"
            . addcslashes($type, "'\\") . "', '{$name}')";
    }

    /**
     * @param array<string, mixed> $columnInfo
     */
    private function buildModifiers(
        array $columnInfo,
    ): string {
        $modifiers = '';
        $nullable = $columnInfo['nullable'] ?? false;
        $default = $columnInfo['default'] ?? null;
        $autoIncrement = $columnInfo['auto_increment']
            ?? false;

        // Skip modifiers for auto-increment columns
        if ($autoIncrement) {
            return $modifiers;
        }

        if ($nullable) {
            $modifiers .= '->nullable()';
        }

        if ($default !== null) {
            $defaultStr = $this->formatDefault($default);
            $modifiers .= "->default({$defaultStr})";
        }

        return $modifiers;
    }

    private function formatDefault(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $str = (string) $value;

        // Check for DB expressions like
        // CURRENT_TIMESTAMP, NOW(), etc.
        $upperStr = strtoupper($str);

        if (
            str_contains($upperStr, 'CURRENT_TIMESTAMP')
            || str_contains($upperStr, 'NOW()')
            || str_starts_with($upperStr, 'NEXTVAL')
        ) {
            $escaped = addcslashes($str, "'\\");
            return "DB::raw('{$escaped}')";
        }

        // Escape single quotes and backslashes in string values
        $escaped = addcslashes($str, "'\\");

        return "'{$escaped}'";
    }

    /**
     * @param string[] $items
     */
    private function formatArray(array $items): string
    {
        $quoted = array_map(
            fn (string $i): string => "'{$i}'",
            $items,
        );

        return '[' . implode(', ', $quoted) . ']';
    }

    /**
     * @param string[] $columns
     */
    private function formatIndexCall(
        string $method,
        array $columns,
    ): string {
        if (count($columns) === 1) {
            return "\$table->{$method}('{$columns[0]}')";
        }

        $colStr = $this->formatArray($columns);

        return "\$table->{$method}({$colStr})";
    }

    private function isBigInt(string $typeName): bool
    {
        return in_array($typeName, [
            'bigint', 'biginteger',
        ], true);
    }

    private function isInt(string $typeName): bool
    {
        return in_array($typeName, [
            'int', 'integer',
        ], true);
    }
}
