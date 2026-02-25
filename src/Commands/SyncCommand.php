<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Commands;

use EriMeilis\MigrationDrift\Services\BackupService;
use EriMeilis\MigrationDrift\Services\MigrationDiffService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncCommand extends Command
{
    protected $signature = 'migrations:sync
        {--force : Apply changes (default is dry-run)}
        {--restore : Restore from last backup}
        {--path= : Override migrations path}';

    protected $description = 'Sync the migrations table to match current migration filenames';

    public function handle(MigrationDiffService $diffService, BackupService $backupService): int
    {
        if ($this->option('restore')) {
            return $this->handleRestore($backupService);
        }

        $path = $this->option('path') ?: (string) config('migration-drift.migrations_path');

        try {
            $diff = $diffService->computeDiff($path);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $stale = $diff['stale'];
        $missing = $diff['missing'];
        $matched = $diff['matched'];

        if (count($stale) === 0 && count($missing) === 0) {
            $this->info("Already in sync. " . count($matched) . " migrations matched.");

            return self::SUCCESS;
        }

        foreach ($stale as $name) {
            $this->warn("- {$name}");
        }

        foreach ($missing as $name) {
            $this->info("+ {$name}");
        }

        $this->line(count($matched) . " migrations matched.");

        if (!$this->option('force')) {
            $this->line('DRY RUN — no changes made. Use --force to apply.');

            return self::SUCCESS;
        }

        $backupPath = $backupService->backup();
        $this->info("Backup saved to: {$backupPath}");

        DB::transaction(function () use ($stale, $missing): void {
            foreach ($stale as $name) {
                DB::table('migrations')->where('migration', $name)->delete();
            }

            foreach ($missing as $name) {
                DB::table('migrations')->insert([
                    'migration' => $name,
                    'batch' => 1,
                ]);
            }
        });

        $this->info("Removed " . count($stale) . " stale, added " . count($missing) . " missing records.");

        return self::SUCCESS;
    }

    private function handleRestore(BackupService $backupService): int
    {
        $latestPath = $backupService->getLatestBackupPath();

        if ($latestPath === null) {
            $this->error('No backup found to restore from.');

            return self::FAILURE;
        }

        $backupService->restore($latestPath);
        $this->info("Restored migrations table from: {$latestPath}");

        return self::SUCCESS;
    }
}
