<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use Illuminate\Support\Facades\DB;

class MigrationDiffService
{
    private function migrationsTable(): string
    {
        return MigrationTableResolver::resolve();
    }

    /**
     * Compute the diff between migration files on disk and DB records.
     *
     * @return array{stale: string[], missing: string[], matched: string[]}
     */
    public function computeDiff(?string $migrationsPath = null): array
    {
        $path = $migrationsPath ?? config('migration-drift.migrations_path');

        if (!is_string($path) || !is_dir($path)) {
            throw new \InvalidArgumentException(
                'Migrations path does not exist: '
                . (is_string($path) ? $path : '(not configured)'),
            );
        }

        $fileNames = $this->getMigrationFilenames($path);
        $dbRecords = $this->getMigrationRecords();

        $fileSet = array_flip($fileNames);
        $dbSet = array_flip($dbRecords);

        $matched = [];
        $stale = [];
        $missing = [];

        foreach ($dbRecords as $record) {
            if (isset($fileSet[$record])) {
                $matched[] = $record;
            } else {
                $stale[] = $record;
            }
        }

        foreach ($fileNames as $fileName) {
            if (!isset($dbSet[$fileName])) {
                $missing[] = $fileName;
            }
        }

        return [
            'stale' => $stale,
            'missing' => $missing,
            'matched' => $matched,
        ];
    }

    /**
     * Get sorted migration filenames (without .php extension) from a directory.
     *
     * @return string[]
     */
    public function getMigrationFilenames(string $path): array
    {
        $files = glob($path . '/*.php');

        if ($files === false) {
            return [];
        }

        $names = array_map(
            fn (string $file): string => basename($file, '.php'),
            $files,
        );

        sort($names);

        return $names;
    }

    /**
     * Get sorted migration names from the database migrations table.
     *
     * @return string[]
     */
    public function getMigrationRecords(): array
    {
        $records = DB::table($this->migrationsTable())
            ->pluck('migration')
            ->toArray();

        sort($records);

        return $records;
    }
}
