<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Feature;

use EriMeilis\MigrationDrift\Services\SchemaComparator;
use EriMeilis\MigrationDrift\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class FixCommandTest extends TestCase
{
    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupPath = $this->createTempDirectory();
        config()->set(
            'migration-drift.backup_path',
            $this->backupPath,
        );
        config()->set(
            'migration-drift.migrations_path',
            __DIR__ . '/../fixtures/migrations',
        );
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirectory($this->backupPath);

        parent::tearDown();
    }

    public function test_dry_run_shows_diff_without_changes(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_style_migration_name',
            'batch' => 1,
        ]);

        $this->artisan('migrations:fix', ['--table' => true])
            ->expectsOutputToContain('old_style_migration_name')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertDatabaseHas(
            'migrations',
            ['migration' => 'old_style_migration_name'],
        );
    }

    public function test_already_in_sync(): void
    {
        $this->artisan('migrations:fix', ['--table' => true])
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
            ->where(
                'migration',
                '2026_01_01_000003_add_bio_to_test_users_table',
            )
            ->delete();

        $this->artisan('migrations:fix', [
            '--table' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Sync complete')
            ->assertSuccessful();

        $this->assertDatabaseMissing(
            'migrations',
            ['migration' => 'old_style_migration_name'],
        );
        $this->assertDatabaseHas(
            'migrations',
            ['migration' => '2026_01_01_000003_add_bio_to_test_users_table'],
        );
    }

    public function test_force_creates_backup(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_style_migration_name',
            'batch' => 1,
        ]);

        $this->artisan('migrations:fix', [
            '--table' => true,
            '--force' => true,
        ])
            ->assertSuccessful();

        $files = glob($this->backupPath . '/backup-*.json');
        $this->assertNotEmpty(
            $files,
            'Backup file should have been created',
        );
    }

    public function test_restore_from_backup(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_style_migration_name',
            'batch' => 1,
        ]);

        $this->artisan('migrations:fix', [
            '--table' => true,
            '--force' => true,
        ])->assertSuccessful();

        DB::table('migrations')->truncate();

        $this->artisan('migrations:fix', ['--restore' => true])
            ->expectsOutputToContain('restored')
            ->assertSuccessful();

        $this->assertDatabaseHas(
            'migrations',
            ['migration' => 'old_style_migration_name'],
        );
    }

    public function test_restore_fails_without_backup(): void
    {
        $this->artisan('migrations:fix', ['--restore' => true])
            ->expectsOutputToContain('No backup')
            ->assertFailed();
    }

    public function test_fails_with_invalid_path(): void
    {
        $this->artisan('migrations:fix', [
            '--table' => true,
            '--path' => '/nonexistent/path/to/migrations',
        ])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);
    }

    public function test_idempotent_second_run(): void
    {
        DB::table('migrations')->insert([
            'migration' => 'old_style_migration_name',
            'batch' => 1,
        ]);

        $this->artisan('migrations:fix', [
            '--table' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->artisan('migrations:fix', ['--table' => true])
            ->expectsOutputToContain('in sync')
            ->assertSuccessful();
    }

    public function test_consolidate_dry_run_shows_candidates(): void
    {
        config()->set(
            'migration-drift.migrations_path',
            __DIR__ . '/../fixtures/migrations-consolidation',
        );

        $this->artisan('migrations:fix', [
            '--consolidate' => true,
            '--path' => __DIR__
                . '/../fixtures/migrations-consolidation',
        ])
            ->expectsOutputToContain('Consolidation candidates')
            ->expectsOutputToContain('users')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();
    }

    public function test_consolidate_no_candidates(): void
    {
        // Use fixtures path with only one migration per table
        // Create a temp dir under the project root (required
        // by path validation)
        $projectRoot = \dirname(__DIR__, 2);
        $tmpDir = $projectRoot . '/tmp/no-candidates-'
            . uniqid();
        mkdir($tmpDir, 0755, true);

        file_put_contents(
            $tmpDir . '/2026_01_01_000001_create_orders.php',
            "<?php\n\nuse Illuminate\\Database\\Migrations"
            . "\\Migration;\nuse Illuminate\\Database\\Schema"
            . "\\Blueprint;\nuse Illuminate\\Support\\Facades"
            . "\\Schema;\n\nreturn new class extends Migration"
            . "\n{\n    public function up(): void\n    {\n"
            . "        Schema::create('orders', function "
            . "(Blueprint \$table) {\n"
            . "            \$table->id();\n"
            . "        });\n    }\n\n"
            . "    public function down(): void\n    {\n"
            . "        Schema::dropIfExists('orders');\n"
            . "    }\n};\n",
        );

        $this->artisan('migrations:fix', [
            '--consolidate' => true,
            '--path' => $tmpDir,
        ])
            ->expectsOutputToContain(
                'No consolidation candidates',
            )
            ->assertSuccessful();

        unlink(
            $tmpDir . '/2026_01_01_000001_create_orders.php',
        );
        rmdir($tmpDir);

        // Clean up parent if empty
        $tmpParent = $projectRoot . '/tmp';
        if (
            is_dir($tmpParent)
            && count(scandir($tmpParent)) === 2
        ) {
            rmdir($tmpParent);
        }
    }

    public function test_schema_force_generates_migrations(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => ['legacy_temp'],
            'column_diffs' => [
                'users' => [
                    'missing' => ['phone'],
                    'extra' => [],
                    'type_mismatches' => [],
                    'nullable_mismatches' => [],
                    'default_mismatches' => [],
                ],
            ],
            'index_diffs' => [],
            'fk_diffs' => [],
            'missing_table_details' => [],
        ];

        $mock = $this->createMock(
            SchemaComparator::class,
        );
        $mock->method('compare')
            ->willReturn($diff);
        $mock->method('hasDifferences')
            ->willReturn(true);

        $this->app->instance(
            SchemaComparator::class,
            $mock,
        );

        $projectRoot = \dirname(__DIR__, 2);
        $tmpDir = $projectRoot
            . '/tmp/schema-force-' . uniqid();
        mkdir($tmpDir, 0755, true);

        // Create a dummy migration so fileCount > 0
        file_put_contents(
            $tmpDir . '/2026_01_01_000001_stub.php',
            "<?php\nreturn new class extends "
            . "\\Illuminate\\Database\\Migrations"
            . "\\Migration {\n"
            . "    public function up(): void {}\n"
            . "    public function down(): void {}\n"
            . "};\n",
        );

        $this->artisan('migrations:fix', [
            '--schema' => true,
            '--force' => true,
            '--path' => $tmpDir,
        ])
            ->expectsOutputToContain('corrective migration')
            ->assertSuccessful();

        // Clean up
        $files = glob($tmpDir . '/*.php');
        if ($files !== false) {
            array_map('unlink', $files);
        }
        rmdir($tmpDir);

        $tmpParent = $projectRoot . '/tmp';
        if (
            is_dir($tmpParent)
            && \count(scandir($tmpParent)) === 2
        ) {
            rmdir($tmpParent);
        }
    }

    public function test_schema_fix_dry_run_on_sqlite(): void
    {
        // SQLite doesn't support CREATE DATABASE, so
        // --schema should fail gracefully
        $this->artisan('migrations:fix', [
            '--schema' => true,
        ])
            ->expectsOutputToContain('Schema comparison failed')
            ->assertFailed();
    }

    public function test_no_migration_files_shows_info(): void
    {
        $emptyDir = \dirname(__DIR__, 2)
            . '/tmp/migration-drift-empty-' . uniqid();
        mkdir($emptyDir, 0755, true);

        $this->artisan('migrations:fix', [
            '--table' => true,
            '--path' => $emptyDir,
        ])
            ->expectsOutputToContain('No migration files')
            ->assertSuccessful();

        rmdir($emptyDir);

        $tmpParent = \dirname(__DIR__, 2) . '/tmp';
        if (is_dir($tmpParent) && \count(scandir($tmpParent)) === 2) {
            rmdir($tmpParent);
        }
    }
}
