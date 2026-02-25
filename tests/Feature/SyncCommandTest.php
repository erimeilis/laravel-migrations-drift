<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Feature;

use EriMeilis\MigrationDrift\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class SyncCommandTest extends TestCase
{
    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupPath = sys_get_temp_dir() . '/migration-drift-sync-test-' . uniqid();
        config()->set('migration-drift.backup_path', $this->backupPath);
        config()->set('migration-drift.migrations_path', __DIR__ . '/../fixtures/migrations');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->backupPath)) {
            $files = glob($this->backupPath . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->backupPath);
        }

        parent::tearDown();
    }

    public function test_dry_run_shows_diff_without_changes(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_style_migration_name',
            'batch' => 1,
        ]);

        $this->artisan('migrations:sync')
            ->expectsOutputToContain('old_style_migration_name')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertDatabaseHas('migrations', ['migration' => 'old_style_migration_name']);
    }

    public function test_already_in_sync(): void
    {
        $this->artisan('migrations:sync')
            ->expectsOutputToContain('in sync')
            ->assertSuccessful();
    }

    public function test_force_applies_changes(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_style_migration_name',
            'batch' => 1,
        ]);

        DB::table('migrations')
            ->where('migration', '2026_01_01_000003_add_bio_to_test_users_table')
            ->delete();

        $this->artisan('migrations:sync', ['--force' => true])
            ->expectsOutputToContain('Removed 1 stale, added 1 missing records.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('migrations', ['migration' => 'old_style_migration_name']);
        $this->assertDatabaseHas('migrations', ['migration' => '2026_01_01_000003_add_bio_to_test_users_table']);
    }

    public function test_force_creates_backup(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_style_migration_name',
            'batch' => 1,
        ]);

        $this->artisan('migrations:sync', ['--force' => true])
            ->assertSuccessful();

        $files = glob($this->backupPath . '/backup-*.json');
        $this->assertNotEmpty($files, 'Backup file should have been created');
    }

    public function test_restore_from_backup(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_style_migration_name',
            'batch' => 1,
        ]);

        $this->artisan('migrations:sync', ['--force' => true])
            ->assertSuccessful();

        DB::table('migrations')->truncate();

        $this->artisan('migrations:sync', ['--restore' => true])
            ->expectsOutputToContain('Restored')
            ->assertSuccessful();

        $this->assertDatabaseHas('migrations', ['migration' => 'old_style_migration_name']);
    }

    public function test_restore_fails_without_backup(): void
    {
        $this->artisan('migrations:sync', ['--restore' => true])
            ->expectsOutputToContain('No backup')
            ->assertFailed();
    }

    public function test_idempotent_second_run(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_style_migration_name',
            'batch' => 1,
        ]);

        $this->artisan('migrations:sync', ['--force' => true])
            ->assertSuccessful();

        $this->artisan('migrations:sync')
            ->expectsOutputToContain('in sync')
            ->assertSuccessful();
    }
}
