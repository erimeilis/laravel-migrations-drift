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

    public function test_raw_index_columns_parsed(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000005_add_raw_and_array_indexes.php',
        );

        $this->assertNotEmpty($def->upIndexes);

        // rawIndex('status, created_at DESC') → ['status', 'created_at']
        $rawIndex = $def->upIndexes[0];
        $this->assertSame('index', $rawIndex['type']);
        $this->assertSame(
            ['status', 'created_at'],
            $rawIndex['columns'],
        );
    }

    public function test_array_index_columns_parsed(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000005_add_raw_and_array_indexes.php',
        );

        // index(['email', 'name']) → ['email', 'name']
        // Find the index that has 'email' — skip the rawIndex/conditional ones
        $arrayIndex = null;
        foreach ($def->upIndexes as $idx) {
            if (in_array('email', $idx['columns'], true)) {
                $arrayIndex = $idx;
                break;
            }
        }

        $this->assertNotNull($arrayIndex);
        $this->assertSame('index', $arrayIndex['type']);
        $this->assertSame(
            ['email', 'name'],
            $arrayIndex['columns'],
        );
    }

    public function test_conditional_logic_detected_with_raw_index(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000005_add_raw_and_array_indexes.php',
        );

        $this->assertTrue($def->hasConditionalLogic);
    }

    public function test_multi_table_indexes_grouped_by_table(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000006_add_indexes_to_multiple_tables.php',
        );

        $this->assertTrue($def->isMultiTable);
        $this->assertNotEmpty($def->upIndexesByTable);

        $this->assertArrayHasKey('orders', $def->upIndexesByTable);
        $ordersIndexCols = array_map(
            fn (array $idx) => $idx['columns'],
            $def->upIndexesByTable['orders'],
        );
        $this->assertContains(['paymentstatus'], $ordersIndexCols);

        $this->assertArrayHasKey('products', $def->upIndexesByTable);
        $productsIndexCols = array_map(
            fn (array $idx) => $idx['columns'],
            $def->upIndexesByTable['products'],
        );
        $this->assertContains(['sale_status'], $productsIndexCols);
        $this->assertContains(['availability'], $productsIndexCols);

        $this->assertArrayHasKey('customers', $def->upIndexesByTable);
        $customersIndexCols = array_map(
            fn (array $idx) => $idx['columns'],
            $def->upIndexesByTable['customers'],
        );
        $this->assertContains(['accountmanager_id'], $customersIndexCols);
    }

    public function test_multi_table_columns_grouped_by_table(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000007_add_columns_to_multiple_tables.php',
        );

        $this->assertTrue($def->isMultiTable);
        $this->assertNotEmpty($def->upColumnsByTable);

        $this->assertArrayHasKey('orders', $def->upColumnsByTable);
        $this->assertContains('payment_date', $def->upColumnsByTable['orders']);
        $this->assertContains('type', $def->upColumnsByTable['orders']);

        $this->assertArrayHasKey('products', $def->upColumnsByTable);
        $this->assertContains('is_featured', $def->upColumnsByTable['products']);
    }

    public function test_change_modifier_captured_separately_from_added_columns(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-visitor/'
            . '2026_01_01_000005_change_column_type.php',
        );

        // ->change() columns are tracked with their target Blueprint type.
        $this->assertArrayHasKey(
            'source_document_id',
            $def->upChangedColumns,
        );
        $this->assertSame(
            'text',
            $def->upChangedColumns['source_document_id'],
        );
        $this->assertArrayHasKey('is_active', $def->upChangedColumns);
        $this->assertSame(
            'boolean',
            $def->upChangedColumns['is_active'],
        );

        // ->change() columns are NOT counted as added columns — this is
        // the bug: a change looked identical to an add.
        $this->assertNotContains(
            'source_document_id',
            $def->upColumns,
        );
        $this->assertNotContains('is_active', $def->upColumns);

        // A genuine new column is still tracked as an add, not a change.
        $this->assertContains('new_note', $def->upColumns);
        $this->assertArrayNotHasKey(
            'new_note',
            $def->upChangedColumns,
        );
    }

    public function test_change_modifier_grouped_by_table(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-visitor/'
            . '2026_01_01_000005_change_column_type.php',
        );

        $this->assertArrayHasKey(
            'documents',
            $def->upChangedColumnsByTable,
        );
        $this->assertSame(
            'text',
            $def->upChangedColumnsByTable['documents']['source_document_id'],
        );
        $this->assertSame(
            'boolean',
            $def->upChangedColumnsByTable['documents']['is_active'],
        );
    }

    public function test_change_and_add_capture_type_arguments(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-visitor/'
            . '2026_01_01_000006_parameterized_change.php',
        );

        // ->change() length / precision arguments are captured.
        $this->assertSame([32], $def->upColumnArgs['sku']);
        $this->assertSame([12, 4], $def->upColumnArgs['price']);

        // Added columns capture their arguments too.
        $this->assertSame([120], $def->upColumnArgs['label']);

        $this->assertSame('string', $def->upChangedColumns['sku']);
        $this->assertSame('decimal', $def->upChangedColumns['price']);
        $this->assertContains('label', $def->upColumns);
    }

    public function test_column_modifiers_are_captured(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-visitor/'
            . '2026_01_01_000007_column_modifiers.php',
        );

        $this->assertTrue(
            $def->upColumnModifiers['nickname']['nullable'],
        );
        $this->assertSame(
            100,
            $def->upColumnModifiers['credits']['default'],
        );
        $this->assertTrue(
            $def->upColumnModifiers['owner_id']['unsigned'],
        );

        // Modifiers on a ->change() chain are captured too.
        $this->assertSame(
            'boolean',
            $def->upChangedColumns['active'],
        );
        $this->assertTrue(
            $def->upColumnModifiers['active']['default'],
        );
    }

    public function test_temporal_precision_and_raw_default_captured(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-visitor/'
            . '2026_01_01_000008_temporal_and_raw_default.php',
        );

        $this->assertSame([6], $def->upColumnArgs['occurred_at']);
        $this->assertTrue(
            $def->upColumnModifiers['occurred_at']['nullable'],
        );

        $this->assertSame(
            'CURRENT_TIMESTAMP',
            $def->upColumnModifiers['created_at']['default_raw'],
        );
    }
}
