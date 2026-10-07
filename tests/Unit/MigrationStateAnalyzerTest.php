<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\MigrationDefinition;
use EriMeilis\MigrationDrift\Services\MigrationDiffService;
use EriMeilis\MigrationDrift\Services\MigrationParser;
use EriMeilis\MigrationDrift\Services\MigrationStateAnalyzer;
use EriMeilis\MigrationDrift\Services\MigrationStatus;
use EriMeilis\MigrationDrift\Services\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

class MigrationStateAnalyzerTest extends TestCase
{
    private MigrationStateAnalyzer $analyzer;

    private MigrationDiffService $diffService;

    private MigrationParser $parser;

    private SchemaIntrospector $introspector;

    /** @var array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>} */
    private array $currentSchema;

    protected function setUp(): void
    {
        $this->diffService = $this->createMock(MigrationDiffService::class);
        $this->parser = $this->createMock(MigrationParser::class);
        $this->introspector = $this->createMock(SchemaIntrospector::class);

        $this->analyzer = new MigrationStateAnalyzer(
            $this->diffService,
            $this->parser,
            $this->introspector,
        );
    }

    public function test_is_applied_to_schema_create_table_exists(): void
    {
        $def = $this->makeDefinition(
            tableName: 'users',
            operationType: 'create',
        );

        $schema = $this->makeSchema(tables: ['users']);

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertTrue($result);
    }

    public function test_is_applied_to_schema_create_table_missing(): void
    {
        $def = $this->makeDefinition(
            tableName: 'users',
            operationType: 'create',
        );

        $schema = $this->makeSchema(tables: ['posts']);

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertFalse($result);
    }

    public function test_is_applied_to_schema_alter_all_columns_present(): void
    {
        $def = $this->makeDefinition(
            tableName: 'users',
            operationType: 'alter',
            upColumns: ['bio', 'avatar'],
        );

        $schema = $this->makeSchema(
            tables: ['users'],
            columns: [
                'users' => [
                    ['name' => 'id'],
                    ['name' => 'bio'],
                    ['name' => 'avatar'],
                ],
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertTrue($result);
    }

    public function test_is_applied_to_schema_alter_column_missing(): void
    {
        $def = $this->makeDefinition(
            tableName: 'users',
            operationType: 'alter',
            upColumns: ['bio', 'avatar'],
        );

        $schema = $this->makeSchema(
            tables: ['users'],
            columns: [
                'users' => [
                    ['name' => 'id'],
                    ['name' => 'bio'],
                ],
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertFalse($result);
    }

    public function test_is_applied_to_schema_alter_table_missing(): void
    {
        $def = $this->makeDefinition(
            tableName: 'users',
            operationType: 'alter',
            upColumns: ['bio'],
        );

        $schema = $this->makeSchema(tables: ['posts']);

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertFalse($result);
    }

    public function test_is_applied_to_schema_drop_table_absent(): void
    {
        $def = $this->makeDefinition(
            tableName: 'temp_data',
            operationType: 'drop',
        );

        $schema = $this->makeSchema(tables: ['users']);

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertTrue($result);
    }

    public function test_is_applied_to_schema_drop_table_still_exists(): void
    {
        $def = $this->makeDefinition(
            tableName: 'temp_data',
            operationType: 'drop',
        );

        $schema = $this->makeSchema(tables: ['temp_data']);

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertFalse($result);
    }

    public function test_is_applied_to_schema_unknown_type_returns_null(): void
    {
        $def = $this->makeDefinition(
            tableName: 'users',
            operationType: 'unknown',
        );

        $schema = $this->makeSchema(tables: ['users']);

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertNull($result);
    }

    public function test_is_applied_to_schema_null_table_returns_null(): void
    {
        $def = $this->makeDefinition(
            tableName: null,
            operationType: 'unknown',
        );

        $schema = $this->makeSchema();

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertNull($result);
    }

    public function test_is_applied_to_schema_alter_no_evidence_returns_null(): void
    {
        $def = $this->makeDefinition(
            tableName: 'users',
            operationType: 'alter',
            upColumns: [],
            hasDataManipulation: true,
        );

        $schema = $this->makeSchema(tables: ['users']);

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertNull($result);
    }

    public function test_is_applied_to_schema_alter_fk_present(): void
    {
        $def = $this->makeDefinition(
            tableName: 'backorders',
            operationType: 'alter',
            upForeignKeys: [
                ['column' => 'user_id', 'references' => 'id', 'on' => 'users'],
            ],
        );

        $schema = $this->makeSchema(
            tables: ['backorders', 'users'],
            foreignKeys: [
                'backorders' => [
                    [
                        'name' => 'backorders_user_id_foreign',
                        'columns' => ['user_id'],
                        'foreign_schema' => '',
                        'foreign_table' => 'users',
                        'foreign_columns' => ['id'],
                        'on_update' => 'no action',
                        'on_delete' => 'cascade',
                    ],
                ],
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertTrue($result);
    }

    public function test_is_applied_to_schema_alter_fk_missing(): void
    {
        $def = $this->makeDefinition(
            tableName: 'backorders',
            operationType: 'alter',
            upForeignKeys: [
                ['column' => 'user_id', 'references' => 'id', 'on' => 'users'],
            ],
        );

        $schema = $this->makeSchema(
            tables: ['backorders', 'users'],
            foreignKeys: [
                'backorders' => [], // no FKs
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertFalse($result);
    }

    public function test_is_applied_to_schema_alter_index_present(): void
    {
        $def = $this->makeDefinition(
            tableName: 'users',
            operationType: 'alter',
            upIndexes: [
                ['type' => 'index', 'columns' => ['email']],
            ],
        );

        $schema = $this->makeSchema(
            tables: ['users'],
            indexes: [
                'users' => [
                    [
                        'name' => 'users_email_index',
                        'columns' => ['email'],
                        'type' => 'btree',
                        'unique' => false,
                        'primary' => false,
                    ],
                ],
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertTrue($result);
    }

    public function test_is_applied_to_schema_alter_index_missing(): void
    {
        $def = $this->makeDefinition(
            tableName: 'users',
            operationType: 'alter',
            upIndexes: [
                ['type' => 'index', 'columns' => ['email']],
            ],
        );

        $schema = $this->makeSchema(
            tables: ['users'],
            indexes: [
                'users' => [], // no indexes
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertFalse($result);
    }

    public function test_is_applied_to_schema_alter_fk_only_no_columns_detected_as_applied(): void
    {
        // This is the exact scenario: migration adds FK, no columns,
        // schema already has the FK from a backup restore
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_add_foreign_keys_to_backorders_table'],
            dbRecords: [],
            tables: ['backorders', 'users'],
            foreignKeys: [
                'backorders' => [
                    [
                        'name' => 'backorders_user_id_foreign',
                        'columns' => ['user_id'],
                        'foreign_schema' => '',
                        'foreign_table' => 'users',
                        'foreign_columns' => ['id'],
                        'on_update' => 'no action',
                        'on_delete' => 'cascade',
                    ],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_add_foreign_keys_to_backorders_table',
            tableName: 'backorders',
            operationType: 'alter',
            upForeignKeys: [
                ['column' => 'user_id', 'references' => 'id', 'on' => 'users'],
            ],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        // Should be LOST_RECORD (schema present, no DB record), not NEW_MIGRATION
        $this->assertSame(MigrationStatus::LOST_RECORD, $states[0]->status);
    }

    public function test_classify_ok_record_and_file_with_schema(): void
    {
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_create_users_table'],
            dbRecords: ['2026_01_01_000001_create_users_table'],
            tables: ['users'],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_create_users_table',
            tableName: 'users',
            operationType: 'create',
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::OK, $states[0]->status);
    }

    public function test_classify_bogus_record(): void
    {
        // Record + file exist but schema says table doesn't exist
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_create_users_table'],
            dbRecords: ['2026_01_01_000001_create_users_table'],
            tables: [], // table not in schema
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_create_users_table',
            tableName: 'users',
            operationType: 'create',
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::BOGUS_RECORD, $states[0]->status);
    }

    public function test_classify_lost_record(): void
    {
        // File exists + schema matches, but no DB record
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_create_users_table'],
            dbRecords: [], // no record
            tables: ['users'],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_create_users_table',
            tableName: 'users',
            operationType: 'create',
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::LOST_RECORD, $states[0]->status);
    }

    public function test_classify_new_migration(): void
    {
        // File exists, no record, table doesn't exist in schema
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_create_widgets_table'],
            dbRecords: [],
            tables: [], // widgets not in schema
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_create_widgets_table',
            tableName: 'widgets',
            operationType: 'create',
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::NEW_MIGRATION, $states[0]->status);
    }

    public function test_classify_missing_file(): void
    {
        // DB record exists, no file, but table exists in schema
        $this->setupMocksForAnalyze(
            fileNames: [],
            dbRecords: ['2026_01_01_000001_create_users_table'],
            tables: ['users'],
        );

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::MISSING_FILE, $states[0]->status);
    }

    public function test_classify_orphan_record(): void
    {
        // DB record exists, no file, table NOT in schema
        $this->setupMocksForAnalyze(
            fileNames: [],
            dbRecords: ['2026_01_01_000001_create_ghosts_table'],
            tables: [],
        );

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::ORPHAN_RECORD, $states[0]->status);
    }

    public function test_partial_analysis_with_data_manipulation(): void
    {
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_seed_data'],
            dbRecords: ['2026_01_01_000001_seed_data'],
            tables: ['users'],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_seed_data',
            tableName: 'users',
            operationType: 'alter',
            upColumns: [],
            hasDataManipulation: true,
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::OK, $states[0]->status);
        $this->assertTrue($states[0]->partialAnalysis);
        $this->assertNotEmpty($states[0]->warnings);
    }

    public function test_partial_analysis_with_conditional_logic(): void
    {
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_conditional'],
            dbRecords: [],
            tables: [],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_conditional',
            tableName: 'users',
            operationType: 'alter',
            upColumns: [],
            hasConditionalLogic: true,
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        // Unknown applied status + no record → NEW_MIGRATION (safe default)
        $this->assertSame(MigrationStatus::NEW_MIGRATION, $states[0]->status);
        $this->assertTrue($states[0]->partialAnalysis);
    }

    public function test_multiple_states_mixed(): void
    {
        $this->setupMocksForAnalyze(
            fileNames: [
                '2026_01_01_000001_create_users_table',
                '2026_01_01_000002_create_posts_table',
            ],
            dbRecords: [
                '2026_01_01_000001_create_users_table',
                'old_orphan_record',
            ],
            tables: ['users'],
        );

        $createUsers = $this->makeDefinition(
            filename: '2026_01_01_000001_create_users_table',
            tableName: 'users',
            operationType: 'create',
        );

        $createPosts = $this->makeDefinition(
            filename: '2026_01_01_000002_create_posts_table',
            tableName: 'posts',
            operationType: 'create',
        );

        $this->parser->method('parse')
            ->willReturnCallback(fn (string $path): MigrationDefinition => match (true) {
                str_contains($path, 'create_users') => $createUsers,
                str_contains($path, 'create_posts') => $createPosts,
                default => throw new \RuntimeException("Unexpected path: {$path}"),
            });

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(3, $states);

        $statusMap = [];
        foreach ($states as $state) {
            $statusMap[$state->migrationName] = $state->status;
        }

        // users: record + file + schema → OK
        $this->assertSame(
            MigrationStatus::OK,
            $statusMap['2026_01_01_000001_create_users_table'],
        );

        // orphan: record, no file, no schema
        $this->assertSame(
            MigrationStatus::ORPHAN_RECORD,
            $statusMap['old_orphan_record'],
        );

        // posts: file, no record, no schema → NEW_MIGRATION
        $this->assertSame(
            MigrationStatus::NEW_MIGRATION,
            $statusMap['2026_01_01_000002_create_posts_table'],
        );
    }

    /**
     * @param string[] $fileNames
     * @param string[] $dbRecords
     * @param string[] $tables
     * @param array<string, array<int, array<string, mixed>>> $columns
     */
    private function setupMocksForAnalyze(
        array $fileNames,
        array $dbRecords,
        array $tables = [],
        array $columns = [],
        array $indexes = [],
        array $foreignKeys = [],
    ): void {
        $this->diffService->method('getMigrationFilenames')
            ->willReturn($fileNames);
        $this->diffService->method('getMigrationRecords')
            ->willReturn($dbRecords);

        $this->currentSchema = $this->makeSchema(
            $tables,
            $columns,
            $indexes,
            $foreignKeys,
        );
    }

    /**
     * @param string[] $tables
     * @param array<string, array<int, array<string, mixed>>> $columns
     * @param array<string, array<int, array<string, mixed>>> $indexes
     * @param array<string, array<int, array<string, mixed>>> $foreignKeys
     * @return array{tables: string[], columns: array<string, array<int, array<string, mixed>>>, indexes: array<string, array<int, array<string, mixed>>>, foreign_keys: array<string, array<int, array<string, mixed>>>}
     */
    private function makeSchema(
        array $tables = [],
        array $columns = [],
        array $indexes = [],
        array $foreignKeys = [],
    ): array {
        return [
            'tables' => $tables,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ];
    }

    public function test_multi_table_alter_with_record_and_all_indexes_present_is_ok(): void
    {
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_add_indexes_to_multiple_tables'],
            dbRecords: ['2026_01_01_000001_add_indexes_to_multiple_tables'],
            tables: ['orders', 'products', 'customers'],
            indexes: [
                'orders' => [
                    ['name' => 'idx1', 'columns' => ['paymentstatus'], 'type' => 'btree', 'unique' => false, 'primary' => false],
                ],
                'products' => [
                    ['name' => 'idx2', 'columns' => ['sale_status'], 'type' => 'btree', 'unique' => false, 'primary' => false],
                ],
                'customers' => [
                    ['name' => 'idx3', 'columns' => ['accountmanager_id'], 'type' => 'btree', 'unique' => false, 'primary' => false],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_add_indexes_to_multiple_tables',
            tableName: 'orders',
            touchedTables: ['orders', 'products', 'customers'],
            operationType: 'alter',
            upIndexesByTable: [
                'orders' => [['type' => 'index', 'columns' => ['paymentstatus']]],
                'products' => [['type' => 'index', 'columns' => ['sale_status']]],
                'customers' => [['type' => 'index', 'columns' => ['accountmanager_id']]],
            ],
            hasConditionalLogic: true,
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::OK, $states[0]->status);
    }

    public function test_multi_table_alter_with_record_and_missing_index_is_bogus(): void
    {
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_add_indexes_to_multiple_tables'],
            dbRecords: ['2026_01_01_000001_add_indexes_to_multiple_tables'],
            tables: ['orders', 'products', 'customers'],
            indexes: [
                'orders' => [
                    ['name' => 'idx1', 'columns' => ['paymentstatus'], 'type' => 'btree', 'unique' => false, 'primary' => false],
                ],
                'products' => [],
                'customers' => [
                    ['name' => 'idx3', 'columns' => ['accountmanager_id'], 'type' => 'btree', 'unique' => false, 'primary' => false],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_add_indexes_to_multiple_tables',
            tableName: 'orders',
            touchedTables: ['orders', 'products', 'customers'],
            operationType: 'alter',
            upIndexesByTable: [
                'orders' => [['type' => 'index', 'columns' => ['paymentstatus']]],
                'products' => [['type' => 'index', 'columns' => ['sale_status']]],
                'customers' => [['type' => 'index', 'columns' => ['accountmanager_id']]],
            ],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::BOGUS_RECORD, $states[0]->status);
    }

    public function test_multi_table_alter_columns_by_table_all_present_is_ok(): void
    {
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_add_columns_multi'],
            dbRecords: ['2026_01_01_000001_add_columns_multi'],
            tables: ['orders', 'products'],
            columns: [
                'orders' => [['name' => 'id'], ['name' => 'payment_date'], ['name' => 'type']],
                'products' => [['name' => 'id'], ['name' => 'is_featured']],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_add_columns_multi',
            tableName: 'orders',
            touchedTables: ['orders', 'products'],
            operationType: 'alter',
            upColumnsByTable: [
                'orders' => ['payment_date', 'type'],
                'products' => ['is_featured'],
            ],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(MigrationStatus::OK, $states[0]->status);
    }

    private function makeDefinition(
        string $filename = 'test_migration',
        ?string $tableName = null,
        array $touchedTables = [],
        string $operationType = 'unknown',
        array $upColumns = [],
        array $upIndexes = [],
        array $upForeignKeys = [],
        array $upColumnsByTable = [],
        array $upIndexesByTable = [],
        array $upForeignKeysByTable = [],
        bool $hasDataManipulation = false,
        bool $hasConditionalLogic = false,
        array $upChangedColumns = [],
        array $upChangedColumnsByTable = [],
    ): MigrationDefinition {
        $resolvedTables = !empty($touchedTables)
            ? $touchedTables
            : ($tableName !== null ? [$tableName] : []);

        return new MigrationDefinition(
            filename: $filename,
            tableName: $tableName,
            touchedTables: $resolvedTables,
            operationType: $operationType,
            upColumns: $upColumns,
            upColumnTypes: [],
            upIndexes: $upIndexes,
            upForeignKeys: $upForeignKeys,
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: $hasConditionalLogic,
            isMultiTable: count($resolvedTables) > 1,
            hasDataManipulation: $hasDataManipulation,
            upColumnsByTable: $upColumnsByTable,
            upIndexesByTable: $upIndexesByTable,
            upForeignKeysByTable: $upForeignKeysByTable,
            upChangedColumns: $upChangedColumns,
            upChangedColumnsByTable: $upChangedColumnsByTable,
        );
    }

    public function test_is_applied_to_schema_changed_column_is_indeterminate(): void
    {
        // A ->change() can't be confirmed or refuted from the schema (type
        // is driver-collapsed; length/nullability/etc. are opaque), so the
        // result is indeterminate — never a confident applied/not-applied.
        $def = $this->makeDefinition(
            tableName: 'documents',
            operationType: 'alter',
            upChangedColumns: ['source_document_id' => 'text'],
        );

        $schema = $this->makeSchema(
            tables: ['documents'],
            columns: [
                'documents' => [
                    ['name' => 'id', 'type_name' => 'bigint'],
                    ['name' => 'source_document_id', 'type_name' => 'varchar'],
                ],
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertNull($result);
    }

    public function test_is_applied_to_schema_changed_column_type_match_is_indeterminate(): void
    {
        // Base type matches, but type_name can't prove the ->change() ran
        // (it may have altered nullability/length/etc.) — so the result is
        // indeterminate, never a confident "applied".
        $def = $this->makeDefinition(
            tableName: 'documents',
            operationType: 'alter',
            upChangedColumns: ['source_document_id' => 'text'],
        );

        $schema = $this->makeSchema(
            tables: ['documents'],
            columns: [
                'documents' => [
                    ['name' => 'id', 'type_name' => 'bigint'],
                    ['name' => 'source_document_id', 'type_name' => 'text'],
                ],
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertNull($result);
    }

    public function test_is_applied_to_schema_changed_column_present_table_is_indeterminate(): void
    {
        // Even when the changed column isn't in the snapshot, we don't
        // claim "not applied" from a bookkeeping check — the table exists,
        // so the change stays indeterminate and migrate decides.
        $def = $this->makeDefinition(
            tableName: 'documents',
            operationType: 'alter',
            upChangedColumns: ['source_document_id' => 'text'],
        );

        $schema = $this->makeSchema(
            tables: ['documents'],
            columns: [
                'documents' => [
                    ['name' => 'id', 'type_name' => 'bigint'],
                ],
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertNull($result);
    }

    public function test_change_only_migration_with_stale_type_is_new_migration_not_lost_record(): void
    {
        // The production trap: a ->change()-only migration with a file but
        // no DB record, whose target type has NOT yet been applied, must be
        // left for `migrate` to run — NOT silently inserted as a lost record.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_widen_source_document_id_on_documents_table'],
            dbRecords: [],
            tables: ['documents'],
            columns: [
                'documents' => [
                    ['name' => 'id', 'type_name' => 'bigint'],
                    ['name' => 'source_document_id', 'type_name' => 'varchar'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_widen_source_document_id_on_documents_table',
            tableName: 'documents',
            operationType: 'alter',
            upChangedColumns: ['source_document_id' => 'text'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::NEW_MIGRATION,
            $states[0]->status,
        );
    }

    public function test_change_only_migration_with_matching_type_is_still_new_migration(): void
    {
        // Same migration, target base type already live. We can't confirm
        // the ->change() actually ran (nullability/length/etc. are opaque),
        // so it is left as NEW for migrate to re-run — a no-op if already
        // applied — rather than recorded as a lost record and skipped.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_widen_source_document_id_on_documents_table'],
            dbRecords: [],
            tables: ['documents'],
            columns: [
                'documents' => [
                    ['name' => 'id', 'type_name' => 'bigint'],
                    ['name' => 'source_document_id', 'type_name' => 'text'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_widen_source_document_id_on_documents_table',
            tableName: 'documents',
            operationType: 'alter',
            upChangedColumns: ['source_document_id' => 'text'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::NEW_MIGRATION,
            $states[0]->status,
        );
    }

    public function test_multi_table_changed_column_is_indeterminate(): void
    {
        // Per-table ->change() data routes through the multi-table path;
        // with the tables present, changed columns are indeterminate.
        $def = $this->makeDefinition(
            tableName: 'documents',
            touchedTables: ['documents', 'invoices'],
            operationType: 'alter',
            upChangedColumnsByTable: [
                'documents' => ['doc_ref' => 'text'],
                'invoices' => ['inv_ref' => 'text'],
            ],
        );

        $schema = $this->makeSchema(
            tables: ['documents', 'invoices'],
            columns: [
                'documents' => [
                    ['name' => 'doc_ref', 'type_name' => 'text'],
                ],
                'invoices' => [
                    ['name' => 'inv_ref', 'type_name' => 'varchar'],
                ],
            ],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertNull($result);
    }

    public function test_multi_table_changed_column_missing_table_not_applied(): void
    {
        // If a touched table is absent entirely, the change definitely
        // hasn't applied — that is the one hard signal we keep.
        $def = $this->makeDefinition(
            tableName: 'documents',
            touchedTables: ['documents', 'invoices'],
            operationType: 'alter',
            upChangedColumnsByTable: [
                'invoices' => ['inv_ref' => 'text'],
            ],
        );

        $schema = $this->makeSchema(
            tables: ['documents'], // invoices missing
            columns: [],
        );

        $result = $this->analyzer->isAppliedToSchema($def, $schema);
        $this->assertFalse($result);
    }

    public function test_recorded_change_postgres_native_type_not_flagged_as_drift(): void
    {
        // integer()->change() on PostgreSQL (live type_name 'int4') shares a
        // logical type with Blueprint 'integer'. A recorded migration must
        // NOT be flagged BOGUS_RECORD over that driver-spelling difference.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_change_qty_on_orders_table'],
            dbRecords: ['2026_01_01_000001_change_qty_on_orders_table'],
            tables: ['orders'],
            columns: [
                'orders' => [
                    ['name' => 'qty', 'type_name' => 'int4'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_change_qty_on_orders_table',
            tableName: 'orders',
            operationType: 'alter',
            upChangedColumns: ['qty' => 'integer'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::OK,
            $states[0]->status,
        );
    }

    public function test_recorded_change_mysql_boolean_tinyint_not_flagged_as_drift(): void
    {
        // boolean()->change() on MySQL (live type_name 'tinyint'): same
        // logical type, must not be flagged BOGUS_RECORD.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_change_active_on_orders_table'],
            dbRecords: ['2026_01_01_000001_change_active_on_orders_table'],
            tables: ['orders'],
            columns: [
                'orders' => [
                    ['name' => 'active', 'type_name' => 'tinyint'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_change_active_on_orders_table',
            tableName: 'orders',
            operationType: 'alter',
            upChangedColumns: ['active' => 'boolean'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::OK,
            $states[0]->status,
        );
    }

    public function test_change_only_nullability_modifier_is_new_migration(): void
    {
        // boolean('is_active')->nullable()->change() where the live column
        // is already tinyint but still NOT NULL: the base type matches, so
        // type_name can't prove the nullability change ran. It must stay
        // NEW (migrate applies the modifier), never recorded-and-skipped.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_change_is_active_on_orders_table'],
            dbRecords: [],
            tables: ['orders'],
            columns: [
                'orders' => [
                    ['name' => 'is_active', 'type_name' => 'tinyint'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_change_is_active_on_orders_table',
            tableName: 'orders',
            operationType: 'alter',
            upChangedColumns: ['is_active' => 'boolean'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::NEW_MIGRATION,
            $states[0]->status,
        );
    }

    public function test_change_only_unsigned_integer_is_new_migration(): void
    {
        // unsignedInteger()->change() where the live column is still a
        // signed int: MySQL carries signedness outside type_name, so the
        // base type matches and the change can't be confirmed applied. It
        // must stay NEW, never recorded-and-skipped.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_change_ref_on_orders_table'],
            dbRecords: [],
            tables: ['orders'],
            columns: [
                'orders' => [
                    ['name' => 'ref', 'type_name' => 'int'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_change_ref_on_orders_table',
            tableName: 'orders',
            operationType: 'alter',
            upChangedColumns: ['ref' => 'unsignedInteger'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::NEW_MIGRATION,
            $states[0]->status,
        );
    }

    public function test_changed_column_widen_integer_to_bigint_is_indeterminate(): void
    {
        // bigInteger()->change() with the column still a 32-bit int4 looks
        // like a genuine widen — but SQLite stores both as INTEGER, so the
        // schema can't be trusted to confirm or refute it. Indeterminate
        // (migrate re-runs the widen) is the only safe answer.
        $def = $this->makeDefinition(
            tableName: 'orders',
            operationType: 'alter',
            upChangedColumns: ['ref_id' => 'bigInteger'],
        );

        $schema = $this->makeSchema(
            tables: ['orders'],
            columns: [
                'orders' => [
                    ['name' => 'ref_id', 'type_name' => 'int4'],
                ],
            ],
        );

        $this->assertNull(
            $this->analyzer->isAppliedToSchema($def, $schema),
        );
    }

    public function test_recorded_change_with_unknown_live_type_is_ok_not_bogus(): void
    {
        // The anti-deletion guarantee: a recorded ->change() migration whose
        // live type can't be confidently classified (unknown driver token)
        // must NOT be flagged BOGUS_RECORD — fix would delete a valid record.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_change_body_on_articles_table'],
            dbRecords: ['2026_01_01_000001_change_body_on_articles_table'],
            tables: ['articles'],
            columns: [
                'articles' => [
                    ['name' => 'body', 'type_name' => 'citext'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_change_body_on_articles_table',
            tableName: 'articles',
            operationType: 'alter',
            upChangedColumns: ['body' => 'text'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::OK,
            $states[0]->status,
        );
    }

    public function test_recorded_change_is_never_flagged_bogus_from_type(): void
    {
        // A recorded ->change() is never torn down as BOGUS over a schema
        // type comparison (the comparison isn't reliable across drivers).
        // Genuine column drift is the schema-drift path's job; the record
        // is trusted here and preserved.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_change_body_on_articles_table'],
            dbRecords: ['2026_01_01_000001_change_body_on_articles_table'],
            tables: ['articles'],
            columns: [
                'articles' => [
                    ['name' => 'body', 'type_name' => 'varchar'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_change_body_on_articles_table',
            tableName: 'articles',
            operationType: 'alter',
            upChangedColumns: ['body' => 'text'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::OK,
            $states[0]->status,
        );
    }

    public function test_real_parsed_change_migration_is_indeterminate(): void
    {
        // End-to-end: the real parser produces per-table changed-column
        // data, which the analyzer classifies as indeterminate (the
        // ->change()s can't be confirmed from the schema). The genuine
        // add (new_note) is present, so nothing forces not-applied.
        $parser = new MigrationParser();
        $def = $parser->parse(
            dirname(__DIR__)
            . '/fixtures/migrations-visitor/'
            . '2026_01_01_000005_change_column_type.php',
        );

        $schema = $this->makeSchema(
            tables: ['documents'],
            columns: [
                'documents' => [
                    ['name' => 'source_document_id', 'type_name' => 'varchar'],
                    ['name' => 'is_active', 'type_name' => 'tinyint'],
                    ['name' => 'new_note', 'type_name' => 'varchar'],
                ],
            ],
        );

        $this->assertNull(
            $this->analyzer->isAppliedToSchema($def, $schema),
        );
    }

    public function test_real_parsed_change_migration_matching_types_is_indeterminate(): void
    {
        // End-to-end: when the live base types already match the targets,
        // the result is indeterminate (not a confident "applied") — the
        // changes can't be verified from type_name, so the migration is
        // left for migrate rather than claimed as drift.
        $parser = new MigrationParser();
        $def = $parser->parse(
            dirname(__DIR__)
            . '/fixtures/migrations-visitor/'
            . '2026_01_01_000005_change_column_type.php',
        );

        $schema = $this->makeSchema(
            tables: ['documents'],
            columns: [
                'documents' => [
                    ['name' => 'source_document_id', 'type_name' => 'text'],
                    ['name' => 'is_active', 'type_name' => 'tinyint'],
                    ['name' => 'new_note', 'type_name' => 'varchar'],
                ],
            ],
        );

        $this->assertNull(
            $this->analyzer->isAppliedToSchema($def, $schema),
        );
    }

    public function test_changed_column_length_narrowing_is_not_confirmed_applied(): void
    {
        // Narrowing string(255) → string(100): base type (varchar) is
        // unchanged, so type_name can't prove the change ran. It must NOT
        // be reported applied (which would record-and-skip it) — it stays
        // indeterminate, leaving it for `migrate`.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_narrow_code_on_items_table'],
            dbRecords: [],
            tables: ['items'],
            columns: [
                'items' => [
                    ['name' => 'code', 'type_name' => 'varchar'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_narrow_code_on_items_table',
            tableName: 'items',
            operationType: 'alter',
            upChangedColumns: ['code' => 'string'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::NEW_MIGRATION,
            $states[0]->status,
        );
    }

    public function test_changed_column_timezone_change_is_not_confirmed_applied(): void
    {
        // timestampTz() → change against a plain timestamp column. The
        // timezone qualifier isn't exposed by the canonical base type, so
        // the change can't be confirmed applied and stays indeterminate.
        $this->setupMocksForAnalyze(
            fileNames: ['2026_01_01_000001_change_seen_at_on_visits_table'],
            dbRecords: [],
            tables: ['visits'],
            columns: [
                'visits' => [
                    ['name' => 'seen_at', 'type_name' => 'timestamp'],
                ],
            ],
        );

        $def = $this->makeDefinition(
            filename: '2026_01_01_000001_change_seen_at_on_visits_table',
            tableName: 'visits',
            operationType: 'alter',
            upChangedColumns: ['seen_at' => 'timestampTz'],
        );

        $this->parser->method('parse')->willReturn($def);

        $states = $this->analyzer->analyze('/path', $this->currentSchema);

        $this->assertCount(1, $states);
        $this->assertSame(
            MigrationStatus::NEW_MIGRATION,
            $states[0]->status,
        );
    }
}
