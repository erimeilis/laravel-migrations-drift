<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Feature;

use EriMeilis\MigrationDrift\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class DetectCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('migration-drift.migrations_path', __DIR__ . '/../fixtures/migrations');
    }

    public function test_no_drift_detected(): void
    {
        $this->artisan('migrations:detect')
            ->expectsOutputToContain('No drift detected')
            ->assertSuccessful();
    }

    public function test_detects_stale_records(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_name_migration',
            'batch' => 1,
        ]);

        $this->artisan('migrations:detect')
            ->expectsOutputToContain('old_name_migration')
            ->expectsOutputToContain('DRIFT DETECTED')
            ->assertFailed();
    }

    public function test_detects_missing_records(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_01_01_000001_create_test_users_table')
            ->delete();

        $this->artisan('migrations:detect')
            ->expectsOutputToContain('2026_01_01_000001_create_test_users_table')
            ->expectsOutputToContain('DRIFT DETECTED')
            ->assertFailed();
    }

    public function test_exit_code_zero_when_clean(): void
    {
        $this->artisan('migrations:detect')
            ->assertExitCode(0);
    }

    public function test_exit_code_one_when_drift(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'fake_stale_record',
            'batch' => 1,
        ]);

        $this->artisan('migrations:detect')
            ->assertExitCode(1);
    }
}
