<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use InvalidArgumentException;
use RuntimeException;

class RenameService
{
    private const MIGRATION_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_/';

    /**
     * Compute rename plan without applying changes.
     *
     * @return array<int, array{old: string, new: string}>
     */
    public function computeRenames(string $path, string $date): array
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException("Migrations directory does not exist: {$path}");
        }

        $datePrefix = str_replace('-', '_', $date);

        $files = glob($path . '/*.php');
        if ($files === false) {
            $files = [];
        }
        sort($files);

        // Filter to migration files only
        $files = array_values(array_filter($files, function (string $file): bool {
            return preg_match(self::MIGRATION_PATTERN, basename($file)) === 1;
        }));

        $plan = [];
        $counter = 0;

        foreach ($files as $file) {
            $counter++;
            $filename = basename($file);

            $namePart = preg_replace(self::MIGRATION_PATTERN, '', $filename);

            $seqPadded = str_pad((string) $counter, 6, '0', STR_PAD_LEFT);
            $newFilename = "{$datePrefix}_{$seqPadded}_{$namePart}";

            if ($filename !== $newFilename) {
                $plan[] = ['old' => $filename, 'new' => $newFilename];
            }
        }

        return $plan;
    }

    /**
     * Apply a rename plan to the filesystem.
     *
     * @param array<int, array{old: string, new: string}> $plan
     */
    public function applyRenames(string $path, array $plan): void
    {
        // Pre-flight: verify no target filenames already exist
        foreach ($plan as $item) {
            $oldPath = $path . '/' . $item['old'];
            $newPath = $path . '/' . $item['new'];

            if ($oldPath !== $newPath && file_exists($newPath)) {
                throw new RuntimeException("Target file already exists: {$item['new']}");
            }
        }

        $completed = [];

        try {
            foreach ($plan as $item) {
                $oldPath = $path . '/' . $item['old'];
                $newPath = $path . '/' . $item['new'];

                if (!@rename($oldPath, $newPath)) {
                    throw new RuntimeException("Failed to rename: {$item['old']}");
                }

                $completed[] = $item;
            }
        } catch (RuntimeException $e) {
            // Rollback completed renames
            foreach (array_reverse($completed) as $done) {
                $rollbackResult = rename(
                    $path . '/' . $done['new'],
                    $path . '/' . $done['old'],
                );

                if (!$rollbackResult) {
                    error_log(
                        'migration-drift: Failed to rollback'
                        . " rename: {$done['new']}"
                        . " -> {$done['old']}",
                    );
                }
            }

            throw $e;
        }
    }
}
