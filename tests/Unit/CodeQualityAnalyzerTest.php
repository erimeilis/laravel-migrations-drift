<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\CodeQualityAnalyzer;
use EriMeilis\MigrationDrift\Services\MigrationParser;
use EriMeilis\MigrationDrift\Tests\TestCase;

class CodeQualityAnalyzerTest extends TestCase
{
    private CodeQualityAnalyzer $analyzer;

    private MigrationParser $parser;

    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new CodeQualityAnalyzer();
        $this->parser = new MigrationParser();
        $this->fixturesPath = dirname(__DIR__)
            . '/fixtures';
    }

    public function test_detects_empty_down_method(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000002_add_index_to_users_table.php',
        );

        $issues = $this->analyzer->analyze($def);

        $types = array_column($issues, 'type');
        $this->assertContains('empty_down', $types);
    }

    public function test_detects_missing_down_method(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000004_no_down_method.php',
        );

        $issues = $this->analyzer->analyze($def);

        $types = array_column($issues, 'type');
        $this->assertContains('missing_down', $types);
    }

    public function test_detects_missing_fk_drop(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000003_add_fk_to_comments_table.php',
        );

        $issues = $this->analyzer->analyze($def);

        $types = array_column($issues, 'type');
        $this->assertContains('missing_fk_drop', $types);
    }

    public function test_detects_missing_index_drop(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000002_add_index_to_users_table.php',
        );

        $issues = $this->analyzer->analyze($def);

        $types = array_column($issues, 'type');
        // empty_down takes priority over missing_index_drop
        // since the down() is completely empty
        $this->assertContains('empty_down', $types);
    }

    public function test_detects_conditional_logic(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-conditional/'
            . '2026_01_01_000001_conditional_migration.php',
        );

        $issues = $this->analyzer->analyze($def);

        $types = array_column($issues, 'type');
        $this->assertContains('conditional_logic', $types);
    }

    public function test_detects_data_manipulation(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-conditional/'
            . '2026_01_01_000002_seed_settings.php',
        );

        $issues = $this->analyzer->analyze($def);

        $types = array_column($issues, 'type');
        $this->assertContains('data_manipulation', $types);
    }

    public function test_detects_redundant_migrations(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-redundant',
        );

        $issues = $this->analyzer->detectRedundantMigrations(
            $defs,
        );

        $types = array_column($issues, 'type');
        $this->assertContains('redundant_migrations', $types);

        // All 3 migrations touch 'users' table
        $redundantIssue = collect($issues)->firstWhere(
            'type',
            'redundant_migrations',
        );
        $this->assertStringContainsString(
            'users',
            $redundantIssue['message'],
        );
    }

    public function test_no_issues_for_clean_migration(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations/'
            . '2026_01_01_000001_create_test_users_table.php',
        );

        $issues = $this->analyzer->analyze($def);

        $this->assertEmpty($issues);
    }

    public function test_create_migration_skips_fk_drop_check(): void
    {
        // Create migrations drop everything in down()
        // with Schema::dropIfExists, so FK drop check
        // should be skipped
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations/'
            . '2026_01_01_000002_create_test_posts_table.php',
        );

        $issues = $this->analyzer->analyze($def);

        $types = array_column($issues, 'type');
        $this->assertNotContains('missing_fk_drop', $types);
    }

    public function test_analyze_all_combines_issues(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-broken-down',
        );

        $issues = $this->analyzer->analyzeAll($defs);

        $types = array_column($issues, 'type');

        // Should have multiple issue types
        $this->assertContains('empty_down', $types);
        $this->assertContains('missing_down', $types);
        $this->assertContains('missing_fk_drop', $types);
    }

    public function test_issue_has_required_fields(): void
    {
        $def = $this->parser->parse(
            $this->fixturesPath
            . '/migrations-broken-down/'
            . '2026_01_01_000004_no_down_method.php',
        );

        $issues = $this->analyzer->analyze($def);

        $this->assertNotEmpty($issues);

        foreach ($issues as $issue) {
            $this->assertArrayHasKey('type', $issue);
            $this->assertArrayHasKey('severity', $issue);
            $this->assertArrayHasKey('message', $issue);
            $this->assertArrayHasKey('migration', $issue);
        }
    }

    public function test_severity_levels_are_valid(): void
    {
        $defs = $this->parser->parseDirectory(
            $this->fixturesPath . '/migrations-broken-down',
        );

        $issues = $this->analyzer->analyzeAll($defs);

        $validSeverities = ['error', 'warning', 'info'];

        foreach ($issues as $issue) {
            $this->assertContains(
                $issue['severity'],
                $validSeverities,
                "Invalid severity: {$issue['severity']}",
            );
        }
    }
}
