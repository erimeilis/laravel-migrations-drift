<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Feature;

use EriMeilis\MigrationDrift\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class DetectCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'migration-drift.migrations_path',
            __DIR__ . '/../fixtures/migrations',
        );
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
            ->where(
                'migration',
                '2026_01_01_000001_create_test_users_table',
            )
            ->delete();

        $this->artisan('migrations:detect')
            ->expectsOutputToContain(
                '2026_01_01_000001_create_test_users_table',
            )
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

    public function test_rejects_path_outside_project(): void
    {
        $this->artisan('migrations:detect', ['--path' => '/etc'])
            ->expectsOutputToContain('must be within')
            ->assertExitCode(1);
    }

    public function test_json_output(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'stale_for_json_test',
            'batch' => 1,
        ]);

        $exitCode = Artisan::call(
            'migrations:detect',
            ['--json' => true],
        );
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            '"table_drift"',
            $output,
        );
        $this->assertStringContainsString(
            'stale_for_json_test',
            $output,
        );
    }

    public function test_json_output_clean(): void
    {
        $this->artisan('migrations:detect', ['--json' => true])
            ->expectsOutputToContain('"table_drift"')
            ->assertExitCode(0);
    }

    public function test_verify_roundtrip_stub(): void
    {
        $this->artisan('migrations:detect', [
            '--verify-roundtrip' => true,
        ])
            ->expectsOutputToContain('not yet implemented')
            ->assertFailed();
    }

    public function test_no_migration_files_shows_info(): void
    {
        $emptyDir = \dirname(__DIR__, 2)
            . '/tmp/migration-drift-empty-' . uniqid();
        mkdir($emptyDir, 0755, true);

        $this->artisan('migrations:detect', ['--path' => $emptyDir])
            ->expectsOutputToContain('No migration files')
            ->assertSuccessful();

        rmdir($emptyDir);

        $tmpParent = \dirname(__DIR__, 2) . '/tmp';
        if (is_dir($tmpParent) && \count(scandir($tmpParent)) === 2) {
            rmdir($tmpParent);
        }
    }

    public function test_schema_comparison_handles_unsupported_driver(): void
    {
        // SQLite (testbench default) can't create temp databases,
        // so schema comparison should degrade gracefully
        $this->artisan('migrations:detect')
            ->assertSuccessful();
    }
}
