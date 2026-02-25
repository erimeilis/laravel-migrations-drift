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

        $this->backupPath = sys_get_temp_dir() . '/migration-drift-test-' . uniqid();
        config()->set('migration-drift.backup_path', $this->backupPath);

        $this->service = new BackupService();
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
            usleep(1100000); // 1.1s to ensure different timestamps
        }

        $files = glob($this->backupPath . '/backup-*.json');

        $this->assertCount(3, $files);
    }

    public function test_get_latest_backup_path(): void
    {
        $first = $this->service->backup();
        usleep(1100000);
        $second = $this->service->backup();

        $latest = $this->service->getLatestBackupPath();

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
}
