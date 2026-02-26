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
