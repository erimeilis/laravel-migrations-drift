<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class BackupService
{
    private function migrationsTable(): string
    {
        return MigrationTableResolver::resolve();
    }

    /**
     * Create a backup of the migrations table as a JSON file.
     *
     * @return string The filepath of the created backup
     */
    public function backup(): string
    {
        $records = DB::table($this->migrationsTable())
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'migration' => $row->migration,
                'batch' => $row->batch,
            ])
            ->toArray();

        $backupPath = config('migration-drift.backup_path');

        if (!is_string($backupPath) || $backupPath === '') {
            throw new RuntimeException(
                'Backup path is not configured.'
                . ' Set migration-drift.backup_path'
                . ' in your config.',
            );
        }

        if (!is_dir($backupPath)) {
            if (!@mkdir($backupPath, 0700, true) && !is_dir($backupPath)) {
                throw new RuntimeException("Failed to create backup directory: {$backupPath}");
            }
        }

        $filename = 'backup-' . date('Y-m-d_His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $filepath = $backupPath . '/' . $filename;

        $result = file_put_contents(
            $filepath,
            json_encode(
                $records,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ),
        );
        if ($result === false) {
            throw new RuntimeException("Failed to write backup file: {$filepath}");
        }

        $this->rotate();

        return $filepath;
    }

    /**
     * Restore the migrations table from a backup file.
     *
     * @throws InvalidArgumentException If the file does not exist
     * @throws RuntimeException If the file cannot be read or contains invalid data
     */
    public function restore(string $filepath): void
    {
        if (!file_exists($filepath)) {
            throw new InvalidArgumentException("Backup file does not exist: {$filepath}");
        }

        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new RuntimeException("Failed to read backup file: {$filepath}");
        }

        $records = json_decode($contents, true);

        if (!is_array($records)) {
            throw new RuntimeException("Invalid backup file: failed to decode JSON from {$filepath}");
        }

        foreach ($records as $index => $record) {
            if (!is_array($record)
                || !array_key_exists('migration', $record)
                || !array_key_exists('batch', $record)
                || !is_string($record['migration'])
                || !is_int($record['batch'])
            ) {
                throw new RuntimeException(
                    "Invalid backup file: malformed record at index {$index} in {$filepath}"
                );
            }
        }

        DB::transaction(function () use ($records): void {
            DB::table($this->migrationsTable())->delete();

            $insertData = array_map(fn (array $record) => [
                'migration' => $record['migration'],
                'batch' => $record['batch'],
            ], $records);

            foreach (array_chunk($insertData, 500) as $chunk) {
                DB::table($this->migrationsTable())->insert($chunk);
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

        // Sort by modification time to get the most recent
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
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

        // Sort by modification time, oldest first
        usort($files, fn (string $a, string $b) => filemtime($a) <=> filemtime($b));

        $excess = count($files) - $maxBackups;

        if ($excess > 0) {
            for ($i = 0; $i < $excess; $i++) {
                if (!unlink($files[$i])) {
                    error_log("migration-drift: Failed to delete old backup: {$files[$i]}");
                }
            }
        }
    }
}
