<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\MigrationParser;
use EriMeilis\MigrationDrift\Tests\TestCase;
use RuntimeException;

class MigrationParserTest extends TestCase
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

    public function test_parse_create_table_migration(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations/'
            . '2026_01_01_000001_create_test_users_table.php',
        );

        $this->assertSame(
            '2026_01_01_000001_create_test_users_table',
            $def->filename,
        );
        $this->assertSame('test_users', $def->tableName);
        $this->assertSame('create', $def->operationType);
        $this->assertContains('name', $def->upColumns);
        $this->assertContains('email', $def->upColumns);
        $this->assertSame(
            'string',
            $def->upColumnTypes['name'],
        );
        $this->assertSame(
            'string',
            $def->upColumnTypes['email'],
        );
        $this->assertTrue($def->hasDown);
        $this->assertFalse($def->downIsEmpty);
        $this->assertFalse($def->hasConditionalLogic);
        $this->assertFalse($def->isMultiTable);
    }

    public function test_parse_alter_table_migration(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations/'
            . '2026_01_01_000003_add_bio_to_test_users_table.php',
        );

        $this->assertSame(
            'test_users',
            $def->tableName,
        );
        $this->assertSame('alter', $def->operationType);
        $this->assertContains('bio', $def->upColumns);
        $this->assertSame(
            'text',
            $def->upColumnTypes['bio'],
        );
        $this->assertTrue($def->hasDown);
        $this->assertFalse($def->downIsEmpty);
    }

    public function test_parse_migration_with_foreign_key(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations/'
            . '2026_01_01_000002_create_test_posts_table.php',
        );

        $this->assertSame('test_posts', $def->tableName);
        $this->assertSame('create', $def->operationType);
        $this->assertNotEmpty($def->upForeignKeys);
    }

    public function test_parse_empty_down_method(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000002_add_index_to_users_table.php',
        );

        $this->assertTrue($def->hasDown);
        $this->assertTrue($def->downIsEmpty);
    }

    public function test_parse_missing_down_method(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000004_no_down_method.php',
        );

        $this->assertFalse($def->hasDown);
    }

    public function test_parse_conditional_logic(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-conditional/'
            . '2026_01_01_000001_conditional_migration.php',
        );

        $this->assertTrue($def->hasConditionalLogic);
    }

    public function test_parse_multi_table_migration(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-conditional/'
            . '2026_01_01_000001_conditional_migration.php',
        );

        // This migration touches 'settings' twice
        // (create + table in if block)
        $this->assertContains(
            'settings',
            $def->touchedTables,
        );
    }

    public function test_parse_data_manipulation(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-conditional/'
            . '2026_01_01_000002_seed_settings.php',
        );

        $this->assertTrue($def->hasDataManipulation);
    }

    public function test_parse_index_in_up(): void
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

    public function test_parse_fk_in_alter(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000003_add_fk_to_comments_table.php',
        );

        $this->assertNotEmpty($def->upForeignKeys);
        $this->assertSame('alter', $def->operationType);
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

    public function test_parse_down_operations(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000003_add_fk_to_comments_table.php',
        );

        $this->assertNotEmpty($def->downOperations);

        // Should have dropColumn but NOT dropForeign
        $hasDropColumn = false;
        $hasDropForeign = false;

        foreach ($def->downOperations as $op) {
            if (str_starts_with($op, 'dropColumn')) {
                $hasDropColumn = true;
            }

            if (str_starts_with($op, 'dropForeign')) {
                $hasDropForeign = true;
            }
        }

        $this->assertTrue($hasDropColumn);
        $this->assertFalse($hasDropForeign);
    }

    public function test_parse_directory(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations',
        );

        $this->assertCount(3, $defs);

        $filenames = array_map(
            fn ($d) => $d->filename,
            $defs,
        );

        $this->assertContains(
            '2026_01_01_000001_create_test_users_table',
            $filenames,
        );
        $this->assertContains(
            '2026_01_01_000002_create_test_posts_table',
            $filenames,
        );
        $this->assertContains(
            '2026_01_01_000003_add_bio_to_test_users_table',
            $filenames,
        );
    }

    public function test_parse_throws_on_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->parser->parse('/nonexistent/file.php');
    }

    public function test_parse_directory_throws_on_missing_dir(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->parser->parseDirectory('/nonexistent/dir');
    }
}
