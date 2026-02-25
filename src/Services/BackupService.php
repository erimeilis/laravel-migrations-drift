<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BackupService
{
    /**
     * Create a backup of the migrations table as a JSON file.
     *
     * @return string The filepath of the created backup
     */
    public function backup(): string
    {
        $records = DB::table('migrations')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'migration' => $row->migration,
                'batch' => $row->batch,
            ])
            ->toArray();

        $backupPath = config('migration-drift.backup_path');

        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        $filename = 'backup-' . date('Y-m-d_His') . '.json';
        $filepath = $backupPath . '/' . $filename;

        file_put_contents($filepath, json_encode($records, JSON_PRETTY_PRINT));

        $this->rotate();

        return $filepath;
    }

    /**
     * Restore the migrations table from a backup file.
     *
     * @throws InvalidArgumentException If the file does not exist
     */
    public function restore(string $filepath): void
    {
        if (!file_exists($filepath)) {
            throw new InvalidArgumentException("Backup file does not exist: {$filepath}");
        }

        $records = json_decode(file_get_contents($filepath), true);

        DB::transaction(function () use ($records): void {
            DB::table('migrations')->truncate();

            foreach ($records as $record) {
                DB::table('migrations')->insert([
                    'migration' => $record['migration'],
                    'batch' => $record['batch'],
                ]);
            }
        });
    }

    /**
     * Get the path of the latest backup file, or null if none exist.
     */
    public function getLatestBackupPath(): ?string
    {
        $backupPath = config('migration-drift.backup_path');

        if (!is_dir($backupPath)) {
            return null;
        }

        $files = glob($backupPath . '/backup-*.json');

        if ($files === false || count($files) === 0) {
            return null;
        }

        sort($files);

        return end($files);
    }

    /**
     * Remove oldest backups if count exceeds max_backups config.
     */
    private function rotate(): void
    {
        $backupPath = config('migration-drift.backup_path');
        $maxBackups = (int) config('migration-drift.max_backups');

        $files = glob($backupPath . '/backup-*.json');

        if ($files === false) {
            return;
        }

        sort($files);

        $excess = count($files) - $maxBackups;

        if ($excess > 0) {
            for ($i = 0; $i < $excess; $i++) {
                unlink($files[$i]);
            }
        }
    }
}
