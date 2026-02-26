<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\RenameService;
use EriMeilis\MigrationDrift\Tests\TestCase;

class RenameServiceTest extends TestCase
{
    private RenameService $service;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RenameService();

        $this->tempDir = $this->createTempDirectory();

        $fixtures = glob(__DIR__ . '/../fixtures/migrations/*.php');
        foreach ($fixtures as $file) {
            copy($file, $this->tempDir . '/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_compute_renames_returns_correct_plan(): void
    {
        $plan = $this->service->computeRenames($this->tempDir, '2099-12-31');

        $this->assertCount(3, $plan);
        $this->assertSame('2099_12_31_000001_create_test_users_table.php', $plan[0]['new']);
        $this->assertSame('2099_12_31_000002_create_test_posts_table.php', $plan[1]['new']);
        $this->assertSame('2099_12_31_000003_add_bio_to_test_users_table.php', $plan[2]['new']);
    }

    public function test_compute_renames_skips_already_correct(): void
    {
        $plan = $this->service->computeRenames($this->tempDir, '2026-01-01');

        $this->assertEmpty($plan);
    }

    public function test_compute_renames_skips_non_migration_files(): void
    {
        file_put_contents($this->tempDir . '/helpers.php', '<?php // not a migration');

        $plan = $this->service->computeRenames($this->tempDir, '2099-12-31');

        $this->assertCount(3, $plan);
        foreach ($plan as $item) {
            $this->assertStringStartsWith('2099_12_31_', $item['new']);
        }
    }

    public function test_apply_renames_files(): void
    {
        $plan = $this->service->computeRenames($this->tempDir, '2099-12-31');
        $this->service->applyRenames($this->tempDir, $plan);

        $files = array_map('basename', glob($this->tempDir . '/*.php'));

        $this->assertContains('2099_12_31_000001_create_test_users_table.php', $files);
        $this->assertContains('2099_12_31_000002_create_test_posts_table.php', $files);
        $this->assertContains('2099_12_31_000003_add_bio_to_test_users_table.php', $files);
    }

    public function test_apply_renames_throws_when_target_exists(): void
    {
        $plan = $this->service->computeRenames($this->tempDir, '2099-12-31');

        // Create a file that would collide with a target
        file_put_contents($this->tempDir . '/' . $plan[0]['new'], '<?php // collision');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->service->applyRenames($this->tempDir, $plan);
    }

    public function test_apply_renames_rolls_back_on_failure(): void
    {
        // Create a plan with 2 files, make the second rename impossible
        $files = glob($this->tempDir . '/*.php');
        sort($files);

        $plan = [
            ['old' => basename($files[0]), 'new' => '2099_12_31_000001_create_test_users_table.php'],
            ['old' => 'nonexistent_file.php', 'new' => '2099_12_31_000002_fake.php'],
        ];

        try {
            $this->service->applyRenames($this->tempDir, $plan);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // Original file should be restored
            $this->assertFileExists($this->tempDir . '/' . basename($files[0]));
        }
    }

    public function test_apply_renames_throws_on_missing_source_file(): void
    {
        $plan = [['old' => 'nonexistent_file.php', 'new' => 'target.php']];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to rename');

        $this->service->applyRenames($this->tempDir, $plan);
    }

    public function test_throws_on_invalid_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->computeRenames('/nonexistent/path', '2099-12-31');
    }
}
