<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use RuntimeException;

/**
 * Generates migration files from schema diff actions.
 *
 * IMPORTANT: This class uses an internal counter to produce
 * unique filenames within a single generate session. It is
 * registered as a non-singleton (bind) in the service provider
 * so each injection gets a fresh counter starting at 0.
 */
class MigrationGenerator
{
    private int $filenameCounter = 0;

    public function __construct(
        private readonly TypeMapper $typeMapper,
    ) {}

    /**
     * Validate that a value is safe for interpolation into
     * generated PHP code.
     *
     * @throws RuntimeException If the value contains unsafe characters
     */
    private static function assertSafeIdentifier(
        string $value,
        string $context,
    ): void {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
            throw new RuntimeException(
                "Unsafe {$context} for code generation:"
                . " '{$value}'",
            );
        }
    }

    /**
     * Generate a migration to add a missing column.
     *
     * @param array<string, mixed> $columnInfo
     * @return string The generated file path
     */
    public function generateAddColumn(
        string $table,
        array $columnInfo,
        string $migrationsPath,
        string $date,
    ): string {
        self::assertSafeIdentifier($table, 'table name');

        $colName = $columnInfo['name'] ?? 'unknown';
        self::assertSafeIdentifier($colName, 'column name');

        $colDef = $this->typeMapper->toColumnDefinition(
            $columnInfo,
        );

        $up = "        Schema::table('{$table}', "
            . "function (Blueprint \$table) {\n"
            . "            {$colDef};\n"
            . '        });';

        $down = "        Schema::table('{$table}', "
            . "function (Blueprint \$table) {\n"
            . "            \$table->dropColumn('{$colName}');\n"
            . '        });';

        $filename = $this->buildFilename(
            $date,
            "add_{$colName}_to_{$table}_table",
        );

        return $this->writeFile(
            $migrationsPath,
            $filename,
            $up,
            $down,
        );
    }

    /**
     * Generate a migration to drop an extra column.
     *
     * @param array<string, mixed>|null $columnInfo Column info for reversibility
     * @return string The generated file path
     */
    public function generateDropColumn(
        string $table,
        string $column,
        ?array $columnInfo,
        string $migrationsPath,
        string $date,
    ): string {
        self::assertSafeIdentifier($table, 'table name');
        self::assertSafeIdentifier($column, 'column name');

        $up = "        Schema::table('{$table}', "
            . "function (Blueprint \$table) {\n"
            . "            \$table->dropColumn('{$column}');\n"
            . '        });';

        if ($columnInfo !== null) {
            $colDef = $this->typeMapper->toColumnDefinition(
                $columnInfo,
            );
            $down = "        Schema::table('{$table}', "
                . "function (Blueprint \$table) {\n"
                . "            {$colDef};\n"
                . '        });';
        } else {
            $down = "        // Cannot reverse: column type"
                . " info not available\n"
                . '        throw new \\RuntimeException('
                . "'Cannot reverse dropping column "
                . "{$column} from {$table}');";
        }

        $filename = $this->buildFilename(
            $date,
            "drop_{$column}_from_{$table}_table",
        );

        return $this->writeFile(
            $migrationsPath,
            $filename,
            $up,
            $down,
        );
    }

    /**
     * Generate a migration to create a missing table.
     *
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, array<string, mixed>> $indexes
     * @param array<int, array<string, mixed>> $foreignKeys
     * @return string The generated file path
     */
    public function generateCreateTable(
        string $table,
        array $columns,
        array $indexes,
        array $foreignKeys,
        string $migrationsPath,
        string $date,
    ): string {
        self::assertSafeIdentifier($table, 'table name');

        $colLines = [];

        foreach ($columns as $col) {
            $colLines[] = '            '
                . $this->typeMapper->toColumnDefinition($col)
                . ';';
        }

        // Non-primary indexes
        $idxLines = [];

        foreach ($indexes as $idx) {
            if ($idx['primary'] ?? false) {
                continue;
            }

            $idxLines[] = '            '
                . $this->typeMapper->toIndexDefinition($idx)
                . ';';
        }

        $fkLines = [];

        foreach ($foreignKeys as $fk) {
            $fkLines[] = '            '
                . $this->typeMapper->toForeignKeyDefinition(
                    $fk,
                ) . ';';
        }

        $body = implode(
            "\n",
            array_merge($colLines, $idxLines, $fkLines),
        );

        $up = "        Schema::create('{$table}', "
            . "function (Blueprint \$table) {\n"
            . "{$body}\n"
            . '        });';

        // down() drops FKs first if needed, then table
        $downLines = [];

        if (!empty($foreignKeys)) {
            $downLines[] = "        Schema::table('{$table}', "
                . "function (Blueprint \$table) {";

            foreach ($foreignKeys as $fk) {
                $fkCols = $fk['columns'] ?? [];
                $fkColStr = count($fkCols) === 1
                    ? "'{$fkCols[0]}'"
                    : "['"
                    . implode("', '", $fkCols) . "']";
                $downLines[] = '            '
                    . "\$table->dropForeign({$fkColStr});";
            }

            $downLines[] = '        });';
            $downLines[] = '';
        }

        $downLines[] = "        Schema::dropIfExists('{$table}');";

        $down = implode("\n", $downLines);

        $filename = $this->buildFilename(
            $date,
            "create_{$table}_table",
        );

        return $this->writeFile(
            $migrationsPath,
            $filename,
            $up,
            $down,
        );
    }

    /**
     * Generate a migration to drop an extra table.
     *
     * @param array<int, array<string, mixed>> $columns For reversibility
     * @param array<int, array<string, mixed>> $indexes For reversibility
     * @param array<int, array<string, mixed>> $foreignKeys Dropped before table
     * @return string The generated file path
     */
    public function generateDropTable(
        string $table,
        array $columns,
        array $indexes,
        array $foreignKeys,
        string $migrationsPath,
        string $date,
    ): string {
        self::assertSafeIdentifier($table, 'table name');

        // up() drops FKs first, then the table
        $upLines = [];

        if (!empty($foreignKeys)) {
            $upLines[] = "        Schema::table('{$table}', "
                . "function (Blueprint \$table) {";

            foreach ($foreignKeys as $fk) {
                $fkCols = $fk['columns'] ?? [];
                $fkColStr = count($fkCols) === 1
                    ? "'{$fkCols[0]}'"
                    : "['"
                    . implode("', '", $fkCols) . "']";
                $upLines[] = '            '
                    . "\$table->dropForeign({$fkColStr});";
            }

            $upLines[] = '        });';
            $upLines[] = '';
        }

        $upLines[] = "        Schema::dropIfExists('{$table}');";

        $up = implode("\n", $upLines);

        // down() recreates the table
        $colLines = [];

        foreach ($columns as $col) {
            $colLines[] = '            '
                . $this->typeMapper->toColumnDefinition($col)
                . ';';
        }

        $idxLines = [];

        foreach ($indexes as $idx) {
            if ($idx['primary'] ?? false) {
                continue;
            }

            $idxLines[] = '            '
                . $this->typeMapper->toIndexDefinition($idx)
                . ';';
        }

        $fkLines = [];

        foreach ($foreignKeys as $fk) {
            $fkLines[] = '            '
                . $this->typeMapper->toForeignKeyDefinition(
                    $fk,
                ) . ';';
        }

        $body = implode(
            "\n",
            array_merge($colLines, $idxLines, $fkLines),
        );

        $down = "        Schema::create('{$table}', "
            . "function (Blueprint \$table) {\n"
            . "{$body}\n"
            . '        });';

        $filename = $this->buildFilename(
            $date,
            "drop_{$table}_table",
        );

        return $this->writeFile(
            $migrationsPath,
            $filename,
            $up,
            $down,
        );
    }

    /**
     * Generate a migration to add a missing index.
     *
     * @param array<string, mixed> $indexInfo
     * @return string The generated file path
     */
    public function generateAddIndex(
        string $table,
        array $indexInfo,
        string $migrationsPath,
        string $date,
    ): string {
        self::assertSafeIdentifier($table, 'table name');

        $idxDef = $this->typeMapper->toIndexDefinition(
            $indexInfo,
        );
        $columns = $indexInfo['columns'] ?? [];
        $unique = $indexInfo['unique'] ?? false;
        $suffix = $unique ? 'unique' : 'index';
        $colSlug = implode('_', $columns);

        $up = "        Schema::table('{$table}', "
            . "function (Blueprint \$table) {\n"
            . "            {$idxDef};\n"
            . '        });';

        $dropMethod = $unique ? 'dropUnique' : 'dropIndex';
        $colStr = count($columns) === 1
            ? "'{$columns[0]}'"
            : "['" . implode("', '", $columns) . "']";

        $down = "        Schema::table('{$table}', "
            . "function (Blueprint \$table) {\n"
            . "            \$table->{$dropMethod}({$colStr});\n"
            . '        });';

        $filename = $this->buildFilename(
            $date,
            "add_{$colSlug}_{$suffix}_to_{$table}_table",
        );

        return $this->writeFile(
            $migrationsPath,
            $filename,
            $up,
            $down,
        );
    }

    /**
     * Generate a migration to add a missing foreign key.
     *
     * @param array<string, mixed> $fkInfo
     * @return string The generated file path
     */
    public function generateAddForeignKey(
        string $table,
        array $fkInfo,
        string $migrationsPath,
        string $date,
    ): string {
        self::assertSafeIdentifier($table, 'table name');

        $fkDef = $this->typeMapper->toForeignKeyDefinition(
            $fkInfo,
        );
        $columns = $fkInfo['columns'] ?? [];
        $colSlug = implode('_', $columns);

        $up = "        Schema::table('{$table}', "
            . "function (Blueprint \$table) {\n"
            . "            {$fkDef};\n"
            . '        });';

        $colStr = count($columns) === 1
            ? "'{$columns[0]}'"
            : "['" . implode("', '", $columns) . "']";

        $down = "        Schema::table('{$table}', "
            . "function (Blueprint \$table) {\n"
            . "            \$table->dropForeign({$colStr});\n"
            . '        });';

        $filename = $this->buildFilename(
            $date,
            "add_{$colSlug}_fk_to_{$table}_table",
        );

        return $this->writeFile(
            $migrationsPath,
            $filename,
            $up,
            $down,
        );
    }

    private function buildFilename(
        string $date,
        string $description,
    ): string {
        $dateFormatted = str_replace('-', '_', $date);

        $this->filenameCounter++;
        $seq = str_pad(
            (string) $this->filenameCounter,
            6,
            '0',
            STR_PAD_LEFT,
        );

        return "{$dateFormatted}_{$seq}_{$description}";
    }

    private function writeFile(
        string $migrationsPath,
        string $filename,
        string $up,
        string $down,
    ): string {
        $filepath = $migrationsPath . '/' . $filename . '.php';

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            use Illuminate\\Database\\Migrations\\Migration;
            use Illuminate\\Database\\Schema\\Blueprint;
            use Illuminate\\Support\\Facades\\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
            {$up}
                }

                public function down(): void
                {
            {$down}
                }
            };

            PHP;

        // Dedent — the heredoc adds extra indentation
        $content = $this->dedent($content);

        $result = file_put_contents($filepath, $content);

        if ($result === false) {
            throw new RuntimeException(
                "Failed to write migration file: {$filepath}",
            );
        }

        return $filepath;
    }

    private function dedent(string $content): string
    {
        $lines = explode("\n", $content);

        // Find minimum indentation across
        // non-empty lines
        $minIndent = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $stripped = ltrim($line, ' ');
            $indent = strlen($line)
                - strlen($stripped);
            if ($indent < $minIndent) {
                $minIndent = $indent;
            }
        }

        if (
            $minIndent === 0
            || $minIndent === PHP_INT_MAX
        ) {
            return $content;
        }

        $dedented = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                $dedented[] = '';
            } else {
                $dedented[] = substr(
                    $line,
                    $minIndent,
                );
            }
        }

        return implode("\n", $dedented);
    }
}
