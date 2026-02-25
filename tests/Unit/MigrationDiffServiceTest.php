<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\MigrationDiffService;
use EriMeilis\MigrationDrift\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class MigrationDiffServiceTest extends TestCase
{
    private MigrationDiffService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MigrationDiffService();
    }

    public function test_returns_empty_diff_when_in_sync(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/migrations';

        $diff = $this->service->computeDiff($fixturesPath);

        $this->assertEmpty($diff['stale']);
        $this->assertEmpty($diff['missing']);
        $this->assertCount(3, $diff['matched']);
        $this->assertContains('2026_01_01_000001_create_test_users_table', $diff['matched']);
        $this->assertContains('2026_01_01_000002_create_test_posts_table', $diff['matched']);
        $this->assertContains('2026_01_01_000003_add_bio_to_test_users_table', $diff['matched']);
    }

    public function test_detects_stale_records(): void
    {
        DB::table('migrations')->insert([
            'migration' => '2026_01_01_999999_nonexistent_migration',
            'batch' => 99,
        ]);

        $fixturesPath = __DIR__ . '/../fixtures/migrations';
        $diff = $this->service->computeDiff($fixturesPath);

        $this->assertContains('2026_01_01_999999_nonexistent_migration', $diff['stale']);
        $this->assertCount(1, $diff['stale']);
        $this->assertEmpty($diff['missing']);
        $this->assertCount(3, $diff['matched']);
    }

    public function test_detects_missing_records(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_01_01_000003_add_bio_to_test_users_table')
            ->delete();

        $fixturesPath = __DIR__ . '/../fixtures/migrations';
        $diff = $this->service->computeDiff($fixturesPath);

        $this->assertContains('2026_01_01_000003_add_bio_to_test_users_table', $diff['missing']);
        $this->assertCount(1, $diff['missing']);
        $this->assertEmpty($diff['stale']);
        $this->assertCount(2, $diff['matched']);
    }

    public function test_detects_both_stale_and_missing(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_01_01_000001_create_test_users_table')
            ->update(['migration' => '0001_00_00_000001_create_test_users_table']);

        $fixturesPath = __DIR__ . '/../fixtures/migrations';
        $diff = $this->service->computeDiff($fixturesPath);

        $this->assertContains('0001_00_00_000001_create_test_users_table', $diff['stale']);
        $this->assertContains('2026_01_01_000001_create_test_users_table', $diff['missing']);
        $this->assertCount(2, $diff['matched']);
    }

    public function test_uses_configured_migrations_path(): void
    {
        $fixturesPath = __DIR__ . '/../fixtures/migrations';
        config()->set('migration-drift.migrations_path', $fixturesPath);

        $diff = $this->service->computeDiff();

        $this->assertEmpty($diff['stale']);
        $this->assertEmpty($diff['missing']);
        $this->assertCount(3, $diff['matched']);
    }

    public function test_throws_on_invalid_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->computeDiff('/nonexistent/path');
    }
}
