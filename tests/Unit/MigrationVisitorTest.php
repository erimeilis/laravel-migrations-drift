<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\MigrationParser;
use EriMeilis\MigrationDrift\Tests\TestCase;

class MigrationVisitorTest extends TestCase
{
    private MigrationParser $parser;

    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new MigrationParser();
        $this->fixturesPath = dirname(__DIR__)
            . '/fixtures';
    }

    public function test_variable_table_name_is_null(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-visitor/'
            . '2026_01_01_000001_variable_table_name.php',
        );

        $this->assertNull($def->tableName);
        $this->assertEmpty($def->touchedTables);
    }

    public function test_dynamic_method_name_handled(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-visitor/'
            . '2026_01_01_000002_dynamic_method.php',
        );

        // Dynamic method ($table->$method) should be
        // skipped — no columns extracted from it
        $this->assertNotContains(
            'dynamic_col',
            $def->upColumns,
        );
        // But Schema::table still captures the table
        $this->assertSame('users', $def->tableName);
    }

    public function test_nop_only_down_is_empty(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000002_add_index_to_users_table.php',
        );

        $this->assertTrue($def->hasDown);
        $this->assertTrue($def->downIsEmpty);
    }

    public function test_down_drop_methods_captured(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-visitor/'
            . '2026_01_01_000003_drop_operations.php',
        );

        $this->assertNotEmpty($def->downOperations);

        $ops = implode('|', $def->downOperations);

        $this->assertStringContainsString(
            'dropColumn',
            $ops,
        );
        $this->assertStringContainsString(
            'dropForeign',
            $ops,
        );
        $this->assertStringContainsString(
            'dropIndex',
            $ops,
        );
        $this->assertStringContainsString(
            'dropUnique',
            $ops,
        );
        $this->assertStringContainsString(
            'dropPrimary',
            $ops,
        );
    }

    public function test_up_index_data_populated(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000002_add_index_to_users_table.php',
        );

        $this->assertNotEmpty($def->upIndexes);
        $this->assertSame(
            'index',
            $def->upIndexes[0]['type'],
        );
        $this->assertSame(
            ['email'],
            $def->upIndexes[0]['columns'],
        );
    }

    public function test_up_foreign_key_data_populated(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000003_add_fk_to_comments_table.php',
        );

        $this->assertNotEmpty($def->upForeignKeys);
        $this->assertSame(
            'post_id',
            $def->upForeignKeys[0]['column'],
        );
        $this->assertSame(
            'id',
            $def->upForeignKeys[0]['references'],
        );
        $this->assertSame(
            'posts',
            $def->upForeignKeys[0]['on'],
        );
    }

    public function test_down_add_column_operations(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-visitor/'
            . '2026_01_01_000004_down_adds_columns.php',
        );

        $this->assertNotEmpty($def->downOperations);
        $this->assertContains(
            "addColumn('name')",
            $def->downOperations,
        );
    }
}
