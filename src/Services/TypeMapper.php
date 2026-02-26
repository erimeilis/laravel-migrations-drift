<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

class TypeMapper
{
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
            ?? "addColumn('{$type}', '{$name}')";
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
            return "DB::raw('{$str}')";
        }

        // Escape single quotes in string values
        $escaped = str_replace("'", "\\'", $str);

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
