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

    private function makeDefinition(
        string $filename = 'test_migration',
        ?string $tableName = null,
        string $operationType = 'unknown',
        array $upColumns = [],
        array $upIndexes = [],
        array $upForeignKeys = [],
        bool $hasDataManipulation = false,
        bool $hasConditionalLogic = false,
    ): MigrationDefinition {
        return new MigrationDefinition(
            filename: $filename,
            tableName: $tableName,
            touchedTables: $tableName !== null ? [$tableName] : [],
            operationType: $operationType,
            upColumns: $upColumns,
            upColumnTypes: [],
            upIndexes: $upIndexes,
            upForeignKeys: $upForeignKeys,
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: $hasConditionalLogic,
            isMultiTable: false,
            hasDataManipulation: $hasDataManipulation,
        );
    }
}
