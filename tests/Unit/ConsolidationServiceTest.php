<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\ConsolidationService;
use EriMeilis\MigrationDrift\Services\MigrationDefinition;
use EriMeilis\MigrationDrift\Services\MigrationGenerator;
use EriMeilis\MigrationDrift\Services\MigrationParser;
use EriMeilis\MigrationDrift\Services\TypeMapper;
use EriMeilis\MigrationDrift\Tests\TestCase;

class ConsolidationServiceTest extends TestCase
{
    private ConsolidationService $service;

    private MigrationParser $parser;

    private string $outputPath;

    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new MigrationParser();
        $typeMapper = new TypeMapper();
        $generator = new MigrationGenerator($typeMapper);
        $this->service = new ConsolidationService(
            $generator,
            $typeMapper,
        );

        $this->fixturesPath = dirname(__DIR__)
            . '/fixtures';

        $this->outputPath = $this->createTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirectory($this->outputPath);

        parent::tearDown();
    }

    public function test_find_candidates_with_redundant_migrations(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-consolidation',
        );

        $candidates = $this->service
            ->findConsolidationCandidates($defs);

        $this->assertArrayHasKey('users', $candidates);
        $this->assertNotEmpty(
            $candidates['users']['consolidatable'],
        );
    }

    public function test_find_candidates_excludes_data_only_migration(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-consolidation',
        );

        $candidates = $this->service
            ->findConsolidationCandidates($defs);

        // The seed migration has no Schema:: calls, so
        // tableName is null and it's excluded entirely
        // from candidates (not grouped under any table).
        $allFilenames = [];

        foreach ($candidates as $tableData) {
            foreach ($tableData['consolidatable'] as $d) {
                $allFilenames[] = $d->filename;
            }

            foreach ($tableData['skipped'] as $d) {
                $allFilenames[] = $d->filename;
            }
        }

        $this->assertNotContains(
            '2024_07_01_000001_seed_admin_user',
            $allFilenames,
        );
    }

    public function test_find_candidates_requires_two_consolidatable(): void
    {
        // Single migration table should not be a candidate
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations',
        );

        $candidates = $this->service
            ->findConsolidationCandidates($defs);

        // test_posts has only 1 migration, shouldn't be candidate
        $this->assertArrayNotHasKey(
            'test_posts',
            $candidates,
        );
    }

    public function test_is_consolidatable_returns_true_for_simple(): void
    {
        $def = new MigrationDefinition(
            filename: 'test',
            tableName: 'users',
            touchedTables: ['users'],
            operationType: 'alter',
            upColumns: ['email'],
            upColumnTypes: ['email' => 'string'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: ["dropColumn('email')"],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
        );

        $this->assertTrue(
            $this->service->isConsolidatable($def),
        );
    }

    public function test_is_consolidatable_rejects_conditional(): void
    {
        $def = new MigrationDefinition(
            filename: 'test',
            tableName: 'users',
            touchedTables: ['users'],
            operationType: 'alter',
            upColumns: [],
            upColumnTypes: [],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: true,
            isMultiTable: false,
            hasDataManipulation: false,
        );

        $this->assertFalse(
            $this->service->isConsolidatable($def),
        );
    }

    public function test_is_consolidatable_rejects_multi_table(): void
    {
        $def = new MigrationDefinition(
            filename: 'test',
            tableName: 'users',
            touchedTables: ['users', 'profiles'],
            operationType: 'alter',
            upColumns: [],
            upColumnTypes: [],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: true,
            hasDataManipulation: false,
        );

        $this->assertFalse(
            $this->service->isConsolidatable($def),
        );
    }

    public function test_is_consolidatable_rejects_data_manipulation(): void
    {
        $def = new MigrationDefinition(
            filename: 'test',
            tableName: 'users',
            touchedTables: ['users'],
            operationType: 'alter',
            upColumns: [],
            upColumnTypes: [],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: true,
        );

        $this->assertFalse(
            $this->service->isConsolidatable($def),
        );
    }

    public function test_consolidate_generates_single_file(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-consolidation',
        );

        $candidates = $this->service
            ->findConsolidationCandidates($defs);
        $consolidatable = $candidates['users']['consolidatable'];

        $result = $this->service->consolidate(
            $consolidatable,
            'users',
            $this->outputPath,
            '2026-02-25',
        );

        $this->assertSame('users', $result->tableName);
        $this->assertFileExists($result->generatedFilePath);
        $this->assertNotEmpty($result->originalMigrations);
    }

    public function test_consolidated_file_contains_all_columns(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-consolidation',
        );

        $candidates = $this->service
            ->findConsolidationCandidates($defs);
        $consolidatable = $candidates['users']['consolidatable'];

        $result = $this->service->consolidate(
            $consolidatable,
            'users',
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents(
            $result->generatedFilePath,
        );

        // Should contain columns from create + alter migrations
        $this->assertStringContainsString(
            'name',
            $content,
        );
        $this->assertStringContainsString(
            'email',
            $content,
        );
        $this->assertStringContainsString(
            'bio',
            $content,
        );
    }

    public function test_consolidated_file_applies_changed_column_type(): void
    {
        // A chain that creates a column as string() and later widens it
        // with text()->change() must consolidate to the CHANGED type, not
        // the original — otherwise the alteration is silently dropped.
        $create = new MigrationDefinition(
            filename: '2024_01_01_000001_create_docs_table',
            tableName: 'docs',
            touchedTables: ['docs'],
            operationType: 'create',
            upColumns: ['widened'],
            upColumnTypes: ['widened' => 'string'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
        );

        $change = new MigrationDefinition(
            filename: '2024_02_01_000001_widen_widened_on_docs_table',
            tableName: 'docs',
            touchedTables: ['docs'],
            operationType: 'alter',
            upColumns: [],
            upColumnTypes: [],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
            upChangedColumns: ['widened' => 'text'],
        );

        $result = $this->service->consolidate(
            [$create, $change],
            'docs',
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($result->generatedFilePath);

        $this->assertStringContainsString(
            "text('widened')",
            $content,
        );
        $this->assertStringNotContainsString(
            "string('widened')",
            $content,
        );
    }

    public function test_consolidated_file_preserves_changed_column_length(): void
    {
        // Narrowing string(255) → string(32) via ->change() must keep the
        // length in the consolidated migration, not reset to varchar(255).
        $create = new MigrationDefinition(
            filename: '2024_01_01_000001_create_items_table',
            tableName: 'items',
            touchedTables: ['items'],
            operationType: 'create',
            upColumns: ['code'],
            upColumnTypes: ['code' => 'string'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
        );

        $change = new MigrationDefinition(
            filename: '2024_02_01_000001_shrink_code_on_items_table',
            tableName: 'items',
            touchedTables: ['items'],
            operationType: 'alter',
            upColumns: [],
            upColumnTypes: [],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
            upChangedColumns: ['code' => 'string'],
            upColumnArgs: ['code' => [32]],
        );

        $result = $this->service->consolidate(
            [$create, $change],
            'items',
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($result->generatedFilePath);

        $this->assertStringContainsString("string('code', 32)", $content);
    }

    public function test_consolidated_file_preserves_added_column_precision(): void
    {
        // A parameterized added column must keep its precision/scale.
        $create = new MigrationDefinition(
            filename: '2024_01_01_000001_create_invoices_table',
            tableName: 'invoices',
            touchedTables: ['invoices'],
            operationType: 'create',
            upColumns: ['amount'],
            upColumnTypes: ['amount' => 'decimal'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
            upColumnArgs: ['amount' => [12, 4]],
        );

        $total = new MigrationDefinition(
            filename: '2024_02_01_000001_add_total_to_invoices_table',
            tableName: 'invoices',
            touchedTables: ['invoices'],
            operationType: 'alter',
            upColumns: ['total'],
            upColumnTypes: ['total' => 'decimal'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
            upColumnArgs: ['total' => [8, 2]],
        );

        $result = $this->service->consolidate(
            [$create, $total],
            'invoices',
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($result->generatedFilePath);

        $this->assertStringContainsString(
            "decimal('amount', 12, 4)",
            $content,
        );
    }

    public function test_consolidated_file_preserves_column_modifiers(): void
    {
        $create = new MigrationDefinition(
            filename: '2024_01_01_000001_create_accounts_table',
            tableName: 'accounts',
            touchedTables: ['accounts'],
            operationType: 'create',
            upColumns: ['nickname', 'credits', 'owner_id'],
            upColumnTypes: [
                'nickname' => 'string',
                'credits' => 'integer',
                'owner_id' => 'unsignedBigInteger',
            ],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
            upColumnModifiers: [
                'nickname' => ['nullable' => true],
                'credits' => ['default' => 100],
                'owner_id' => ['unsigned' => true],
            ],
        );

        $alter = new MigrationDefinition(
            filename: '2024_02_01_000001_add_note_to_accounts_table',
            tableName: 'accounts',
            touchedTables: ['accounts'],
            operationType: 'alter',
            upColumns: ['note'],
            upColumnTypes: ['note' => 'string'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
        );

        $result = $this->service->consolidate(
            [$create, $alter],
            'accounts',
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($result->generatedFilePath);

        $this->assertStringContainsString("->nullable()", $content);
        $this->assertStringContainsString("->default(100)", $content);
        $this->assertStringContainsString("->unsigned()", $content);
    }

    public function test_consolidated_file_applies_changed_column_modifiers(): void
    {
        // Column created NOT NULL, then changed to nullable via ->change().
        $create = new MigrationDefinition(
            filename: '2024_01_01_000001_create_posts_table',
            tableName: 'posts',
            touchedTables: ['posts'],
            operationType: 'create',
            upColumns: ['summary'],
            upColumnTypes: ['summary' => 'string'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
        );

        $change = new MigrationDefinition(
            filename: '2024_02_01_000001_make_summary_nullable_on_posts_table',
            tableName: 'posts',
            touchedTables: ['posts'],
            operationType: 'alter',
            upColumns: [],
            upColumnTypes: [],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
            upChangedColumns: ['summary' => 'text'],
            upColumnModifiers: ['summary' => ['nullable' => true]],
        );

        $result = $this->service->consolidate(
            [$create, $change],
            'posts',
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($result->generatedFilePath);

        $this->assertStringContainsString("text('summary')", $content);
        $this->assertStringContainsString("->nullable()", $content);
    }

    public function test_consolidated_file_preserves_temporal_precision(): void
    {
        $create = new MigrationDefinition(
            filename: '2024_01_01_000001_create_events_table',
            tableName: 'events',
            touchedTables: ['events'],
            operationType: 'create',
            upColumns: ['occurred_at'],
            upColumnTypes: ['occurred_at' => 'timestamp'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
            upColumnArgs: ['occurred_at' => [6]],
        );

        $alter = new MigrationDefinition(
            filename: '2024_02_01_000001_add_label_to_events_table',
            tableName: 'events',
            touchedTables: ['events'],
            operationType: 'alter',
            upColumns: ['label'],
            upColumnTypes: ['label' => 'string'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
        );

        $result = $this->service->consolidate(
            [$create, $alter],
            'events',
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($result->generatedFilePath);

        $this->assertStringContainsString(
            "timestamp('occurred_at', 6)",
            $content,
        );
    }

    public function test_consolidated_file_preserves_raw_default_and_imports_db(): void
    {
        $create = new MigrationDefinition(
            filename: '2024_01_01_000001_create_logs_table',
            tableName: 'logs',
            touchedTables: ['logs'],
            operationType: 'create',
            upColumns: ['created_at'],
            upColumnTypes: ['created_at' => 'timestamp'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
            upColumnModifiers: [
                'created_at' => ['default_raw' => 'CURRENT_TIMESTAMP'],
            ],
        );

        $alter = new MigrationDefinition(
            filename: '2024_02_01_000001_add_level_to_logs_table',
            tableName: 'logs',
            touchedTables: ['logs'],
            operationType: 'alter',
            upColumns: ['level'],
            upColumnTypes: ['level' => 'string'],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: false,
        );

        $result = $this->service->consolidate(
            [$create, $alter],
            'logs',
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($result->generatedFilePath);

        $this->assertStringContainsString(
            "->default(DB::raw('CURRENT_TIMESTAMP'))",
            $content,
        );
        $this->assertStringContainsString(
            'use Illuminate\\Support\\Facades\\DB;',
            $content,
        );

        // Generated file must still be valid PHP.
        exec("php -l {$result->generatedFilePath} 2>&1", $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_consolidated_file_is_valid_php(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-consolidation',
        );

        $candidates = $this->service
            ->findConsolidationCandidates($defs);
        $consolidatable = $candidates['users']['consolidatable'];

        $result = $this->service->consolidate(
            $consolidatable,
            'users',
            $this->outputPath,
            '2026-02-25',
        );

        exec(
            "php -l {$result->generatedFilePath} 2>&1",
            $output,
            $exitCode,
        );

        $this->assertSame(
            0,
            $exitCode,
            'Generated file has syntax errors: '
            . implode("\n", $output),
        );
    }

    public function test_consolidate_skips_unconsolidatable(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-consolidation',
        );

        // Get the real consolidatable defs
        $userDefs = array_filter(
            $defs,
            fn ($d) => $d->tableName === 'users',
        );

        // Add a manually constructed data-manipulation def
        // (the fixture's seed migration has tableName=null
        // since it only uses DB::table, not Schema::)
        $userDefs[] = new MigrationDefinition(
            filename: 'seed_admin_user',
            tableName: 'users',
            touchedTables: ['users'],
            operationType: 'alter',
            upColumns: [],
            upColumnTypes: [],
            upIndexes: [],
            upForeignKeys: [],
            hasDown: true,
            downIsEmpty: false,
            downOperations: [],
            hasConditionalLogic: false,
            isMultiTable: false,
            hasDataManipulation: true,
        );

        $result = $this->service->consolidate(
            $userDefs,
            'users',
            $this->outputPath,
            '2026-02-25',
        );

        // Data manipulation migration should be skipped
        $this->assertNotEmpty($result->skippedMigrations);
        $this->assertNotEmpty($result->warnings);
    }

    public function test_consolidate_result_has_original_filenames(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-consolidation',
        );

        $candidates = $this->service
            ->findConsolidationCandidates($defs);
        $consolidatable = $candidates['users']['consolidatable'];

        $result = $this->service->consolidate(
            $consolidatable,
            'users',
            $this->outputPath,
            '2026-02-25',
        );

        $this->assertGreaterThanOrEqual(
            2,
            count($result->originalMigrations),
        );

        // Original filenames should be present
        $this->assertContains(
            '2024_01_01_000001_create_users_table',
            $result->originalMigrations,
        );
    }

    public function test_no_candidates_for_single_migration_tables(): void
    {
        $defs = [
            new MigrationDefinition(
                filename: 'create_orders',
                tableName: 'orders',
                touchedTables: ['orders'],
                operationType: 'create',
                upColumns: ['id', 'total'],
                upColumnTypes: [
                    'id' => 'id',
                    'total' => 'decimal',
                ],
                upIndexes: [],
                upForeignKeys: [],
                hasDown: true,
                downIsEmpty: false,
                downOperations: [],
                hasConditionalLogic: false,
                isMultiTable: false,
                hasDataManipulation: false,
            ),
        ];

        $candidates = $this->service
            ->findConsolidationCandidates($defs);

        $this->assertEmpty($candidates);
    }

    public function test_null_table_name_definitions_ignored(): void
    {
        $defs = [
            new MigrationDefinition(
                filename: 'weird_migration',
                tableName: null,
                touchedTables: [],
                operationType: 'unknown',
                upColumns: [],
                upColumnTypes: [],
                upIndexes: [],
                upForeignKeys: [],
                hasDown: false,
                downIsEmpty: true,
                downOperations: [],
                hasConditionalLogic: false,
                isMultiTable: false,
                hasDataManipulation: false,
            ),
        ];

        $candidates = $this->service
            ->findConsolidationCandidates($defs);

        $this->assertEmpty($candidates);
    }
}
