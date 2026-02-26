<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\BackupService;
use EriMeilis\MigrationDrift\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class BackupServiceTest extends TestCase
{
    private BackupService $service;
    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupPath = $this->createTempDirectory();
        config()->set(
            'migration-drift.backup_path',
            $this->backupPath,
        );

        $this->service = new BackupService();
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirectory($this->backupPath);

        parent::tearDown();
    }

    public function test_creates_backup_file(): void
    {
        $filepath = $this->service->backup();

        $this->assertFileExists($filepath);

        $data = json_decode(file_get_contents($filepath), true);

        $this->assertCount(3, $data);
        foreach ($data as $record) {
            $this->assertArrayHasKey('migration', $record);
            $this->assertArrayHasKey('batch', $record);
        }
    }

    public function test_restore_replaces_table_contents(): void
    {
        $filepath = $this->service->backup();

        DB::table('migrations')->truncate();
        DB::table('migrations')->insert([
            'migration' => 'fake_migration_record',
            'batch' => 99,
        ]);

        $this->service->restore($filepath);

        $records = DB::table('migrations')->pluck('migration')->toArray();

        $this->assertCount(3, $records);
        $this->assertContains('2026_01_01_000001_create_test_users_table', $records);
        $this->assertContains('2026_01_01_000002_create_test_posts_table', $records);
        $this->assertContains('2026_01_01_000003_add_bio_to_test_users_table', $records);
        $this->assertNotContains('fake_migration_record', $records);
    }

    public function test_rotate_keeps_max_backups(): void
    {
        config()->set('migration-drift.max_backups', 3);

        for ($i = 0; $i < 5; $i++) {
            $this->service->backup();
        }

        $files = glob($this->backupPath . '/backup-*.json');

        $this->assertCount(3, $files);
    }

    public function test_get_latest_backup_path(): void
    {
        $first = $this->service->backup();
        $second = $this->service->backup();

        // Ensure second file has a definitively later mtime
        touch($second, time() + 10);

        $latest = $this->service->getLatestBackupPath();

        $this->assertNotNull($latest);
        $this->assertSame($second, $latest);
    }

    public function test_restore_throws_on_missing_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->restore('/nonexistent/backup.json');
    }

    public function test_get_latest_returns_null_when_no_backups(): void
    {
        $result = $this->service->getLatestBackupPath();

        $this->assertNull($result);
    }

    public function test_backup_throws_on_write_failure(): void
    {
        config()->set('migration-drift.backup_path', '/nonexistent/deeply/nested/path');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to');

        $this->service->backup();
    }

    public function test_restore_throws_on_corrupted_json(): void
    {
        $filepath = $this->service->backup();
        file_put_contents($filepath, '{{{invalid json}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid backup file');

        $this->service->restore($filepath);
    }

    public function test_restore_throws_on_invalid_structure(): void
    {
        $filepath = $this->service->backup();
        file_put_contents($filepath, json_encode([
            ['wrong_key' => 'value', 'batch' => 1],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid backup file');

        $this->service->restore($filepath);
    }

    public function test_restore_handles_large_record_sets(): void
    {
        $records = [];
        for ($i = 1; $i <= 100; $i++) {
            $records[] = ['migration' => "2026_01_01_{$i}_test_migration", 'batch' => 1];
        }

        $filepath = $this->backupPath . '/large-backup.json';
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
        file_put_contents($filepath, json_encode($records));

        $this->service->restore($filepath);

        $this->assertCount(100, DB::table('migrations')->get());
    }

    public function test_restore_rejects_non_string_migration(): void
    {
        $filepath = $this->backupPath . '/bad-types.json';
        file_put_contents($filepath, json_encode([
            ['migration' => 123, 'batch' => 1],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed record');

        $this->service->restore($filepath);
    }

    public function test_restore_rejects_non_int_batch(): void
    {
        $filepath = $this->backupPath . '/bad-batch.json';
        file_put_contents($filepath, json_encode([
            ['migration' => '2026_01_01_000001_test', 'batch' => 'one'],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed record');

        $this->service->restore($filepath);
    }

    public function test_restore_is_transactional_on_failure(): void
    {
        $filepath = $this->service->backup();

        // Write a corrupted backup that will cause insert to fail
        $corruptData = [
            ['migration' => 'valid_migration', 'batch' => 1],
            ['migration' => null, 'batch' => null], // null migration will violate NOT NULL
        ];
        file_put_contents($filepath, json_encode($corruptData));

        $originalRecords = DB::table('migrations')->pluck('migration')->toArray();

        try {
            $this->service->restore($filepath);
        } catch (\Throwable) {
            // Expected to fail
        }

        // Table should still have original data, not be empty
        $afterRecords = DB::table('migrations')->pluck('migration')->toArray();
        $this->assertNotEmpty($afterRecords, 'Table should not be empty after failed restore');
        $this->assertEqualsCanonicalizing($originalRecords, $afterRecords);
    }
}
