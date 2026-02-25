<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Feature;

use EriMeilis\MigrationDrift\Tests\TestCase;

class RenameCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/migration-drift-rename-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        // Copy fixture migrations to temp dir
        $fixtures = glob(__DIR__ . '/../fixtures/migrations/*.php');
        foreach ($fixtures as $file) {
            copy($file, $this->tempDir . '/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_dry_run_shows_renames(): void
    {
        $this->artisan('migrations:rename', [
            '--path' => $this->tempDir,
            '--date' => '2099-12-31',
        ])
            ->expectsOutputToContain('2099_12_31_000001')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        // Files should NOT be renamed in dry run
        $files = glob($this->tempDir . '/*.php');
        $filenames = array_map('basename', $files);

        $this->assertContains('2026_01_01_000001_create_test_users_table.php', $filenames);
        $this->assertContains('2026_01_01_000002_create_test_posts_table.php', $filenames);
        $this->assertContains('2026_01_01_000003_add_bio_to_test_users_table.php', $filenames);
    }

    public function test_force_renames_files(): void
    {
        $this->artisan('migrations:rename', [
            '--force' => true,
            '--path' => $this->tempDir,
            '--date' => '2099-12-31',
        ])
            ->expectsOutputToContain('Renamed 3 files')
            ->assertSuccessful();

        // Verify actual files have 2099_12_31 prefix
        $files = glob($this->tempDir . '/*.php');
        $filenames = array_map('basename', $files);

        foreach ($filenames as $filename) {
            $this->assertStringStartsWith('2099_12_31_', $filename);
        }

        $this->assertContains('2099_12_31_000001_create_test_users_table.php', $filenames);
        $this->assertContains('2099_12_31_000002_create_test_posts_table.php', $filenames);
        $this->assertContains('2099_12_31_000003_add_bio_to_test_users_table.php', $filenames);
    }

    public function test_skips_already_matching_files(): void
    {
        $this->artisan('migrations:rename', [
            '--force' => true,
            '--path' => $this->tempDir,
            '--date' => '2026-01-01',
        ])
            ->expectsOutputToContain('already correct')
            ->assertSuccessful();
    }

    public function test_defaults_to_today(): void
    {
        $this->artisan('migrations:rename', [
            '--force' => true,
            '--path' => $this->tempDir,
        ])
            ->assertSuccessful();

        $todayPrefix = date('Y_m_d');
        $files = glob($this->tempDir . '/*.php');
        $filenames = array_map('basename', $files);

        foreach ($filenames as $filename) {
            $this->assertStringStartsWith($todayPrefix . '_', $filename);
        }
    }
}
