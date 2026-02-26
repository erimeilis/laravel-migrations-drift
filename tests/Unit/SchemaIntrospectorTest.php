<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\SchemaIntrospector;
use EriMeilis\MigrationDrift\Tests\TestCase;

class SchemaIntrospectorTest extends TestCase
{
    private SchemaIntrospector $introspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->introspector = new SchemaIntrospector();
    }

    public function test_get_tables_returns_user_tables(): void
    {
        $tables = $this->introspector->getTables('testing');

        $this->assertContains('test_users', $tables);
        $this->assertContains('test_posts', $tables);
    }

    public function test_get_tables_excludes_migrations_table(): void
    {
        $tables = $this->introspector->getTables('testing');

        $this->assertNotContains('migrations', $tables);
    }

    public function test_get_tables_returns_sorted_names(): void
    {
        $tables = $this->introspector->getTables('testing');

        $sorted = $tables;
        sort($sorted);

        $this->assertSame($sorted, $tables);
    }

    public function test_get_columns_returns_column_info(): void
    {
        $columns = $this->introspector->getColumns(
            'testing',
            'test_users',
        );

        $names = array_column($columns, 'name');

        $this->assertContains('id', $names);
        $this->assertContains('name', $names);
        $this->assertContains('email', $names);
        $this->assertContains('bio', $names);
    }

    public function test_get_columns_includes_type_info(): void
    {
        $columns = $this->introspector->getColumns(
            'testing',
            'test_users',
        );

        $nameCol = collect($columns)
            ->firstWhere('name', 'name');

        $this->assertNotNull($nameCol);
        $this->assertArrayHasKey('type', $nameCol);
        $this->assertArrayHasKey('nullable', $nameCol);
    }

    public function test_get_columns_detects_nullable(): void
    {
        $columns = $this->introspector->getColumns(
            'testing',
            'test_users',
        );

        $bioCol = collect($columns)
            ->firstWhere('name', 'bio');

        $this->assertNotNull($bioCol);
        $this->assertTrue($bioCol['nullable']);
    }

    public function test_get_indexes_returns_index_info(): void
    {
        $indexes = $this->introspector->getIndexes(
            'testing',
            'test_users',
        );

        $this->assertNotEmpty($indexes);

        // Should have at least a primary key index
        $primary = collect($indexes)
            ->firstWhere('primary', true);

        $this->assertNotNull($primary);
    }

    public function test_get_indexes_detects_unique_index(): void
    {
        $indexes = $this->introspector->getIndexes(
            'testing',
            'test_users',
        );

        // email column has a unique index
        $emailIndex = collect($indexes)->first(
            fn (array $idx): bool => in_array(
                'email',
                $idx['columns'],
                true,
            ),
        );

        $this->assertNotNull($emailIndex);
        $this->assertTrue($emailIndex['unique']);
    }

    public function test_get_foreign_keys_returns_fk_info(): void
    {
        $fks = $this->introspector->getForeignKeys(
            'testing',
            'test_posts',
        );

        // test_posts has a FK to test_users
        $this->assertNotEmpty($fks);

        $userFk = collect($fks)->first(
            fn (array $fk): bool => in_array(
                'user_id',
                $fk['columns'],
                true,
            ),
        );

        $this->assertNotNull($userFk);
        $this->assertSame(
            'test_users',
            $userFk['foreign_table'],
        );
        $this->assertContains('id', $userFk['foreign_columns']);
    }

    public function test_get_full_schema_returns_complete_snapshot(): void
    {
        $schema = $this->introspector->getFullSchema('testing');

        $this->assertArrayHasKey('tables', $schema);
        $this->assertArrayHasKey('columns', $schema);
        $this->assertArrayHasKey('indexes', $schema);
        $this->assertArrayHasKey('foreign_keys', $schema);

        $this->assertContains('test_users', $schema['tables']);
        $this->assertContains('test_posts', $schema['tables']);

        $this->assertArrayHasKey(
            'test_users',
            $schema['columns'],
        );
        $this->assertArrayHasKey(
            'test_posts',
            $schema['columns'],
        );
        $this->assertArrayHasKey(
            'test_users',
            $schema['indexes'],
        );
        $this->assertArrayHasKey(
            'test_posts',
            $schema['foreign_keys'],
        );
    }

    public function test_normalize_type_handles_integer_aliases(): void
    {
        $this->assertSame(
            'integer',
            $this->introspector->normalizeType('int'),
        );
        $this->assertSame(
            'integer',
            $this->introspector->normalizeType('INT'),
        );
        $this->assertSame(
            'integer',
            $this->introspector->normalizeType('int(11)'),
        );
    }

    public function test_normalize_type_handles_bigint(): void
    {
        $this->assertSame(
            'biginteger',
            $this->introspector->normalizeType('bigint'),
        );
        $this->assertSame(
            'biginteger',
            $this->introspector->normalizeType('bigint(20)'),
        );
    }

    public function test_normalize_type_handles_boolean(): void
    {
        $this->assertSame(
            'boolean',
            $this->introspector->normalizeType('bool'),
        );
        $this->assertSame(
            'boolean',
            $this->introspector->normalizeType('tinyint(1)'),
        );
    }

    public function test_normalize_type_handles_tinyint(): void
    {
        $this->assertSame(
            'tinyinteger',
            $this->introspector->normalizeType('tinyint'),
        );
    }

    public function test_normalize_type_handles_varchar(): void
    {
        $this->assertSame(
            'varchar',
            $this->introspector->normalizeType(
                'character varying',
            ),
        );
    }

    public function test_normalize_type_handles_float(): void
    {
        $this->assertSame(
            'float',
            $this->introspector->normalizeType('real'),
        );
        $this->assertSame(
            'double',
            $this->introspector->normalizeType(
                'double precision',
            ),
        );
    }

    public function test_normalize_type_preserves_unknown_types(): void
    {
        $this->assertSame(
            'json',
            $this->introspector->normalizeType('json'),
        );
        $this->assertSame(
            'text',
            $this->introspector->normalizeType('TEXT'),
        );
    }

    public function test_get_migrations_table_with_string_config(): void
    {
        config()->set('database.migrations', 'migrations');

        $tables = $this->introspector->getTables('testing');

        $this->assertNotContains('migrations', $tables);
    }

    public function test_get_migrations_table_with_array_config(): void
    {
        config()->set('database.migrations', [
            'table' => 'migrations',
        ]);

        $tables = $this->introspector->getTables('testing');

        $this->assertNotContains('migrations', $tables);
    }
}
