<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Commands;

use Illuminate\Console\Command;

class RenameCommand extends Command
{
    protected $signature = 'migrations:rename
        {--force : Apply renames (default is dry-run)}
        {--date= : Target date YYYY-MM-DD (default: today)}
        {--path= : Override migrations path}';

    protected $description = 'Rename migration files to use a target date prefix with sequential numbering';

    public function handle(): int
    {
        $path = $this->option('path')
            ?? config('migration-drift.migrations_path', database_path('migrations'));

        if (! is_dir($path)) {
            $this->error("Migrations directory does not exist: {$path}");

            return self::FAILURE;
        }

        $dateOption = $this->option('date') ?? date('Y-m-d');
        $datePrefix = str_replace('-', '_', $dateOption);

        $force = (bool) $this->option('force');

        $files = glob($path . '/*.php');
        if ($files === false) {
            $files = [];
        }
        sort($files);

        $renamed = 0;
        $skipped = 0;
        $counter = 0;

        foreach ($files as $file) {
            $counter++;
            $filename = basename($file);

            // Strip leading date+sequence prefix (e.g. 2026_01_01_000001_)
            $namePart = preg_replace('/^\d+_\d+_\d+_\d+_/', '', $filename);

            $seqPadded = str_pad((string) $counter, 6, '0', STR_PAD_LEFT);
            $newFilename = "{$datePrefix}_{$seqPadded}_{$namePart}";

            if ($filename === $newFilename) {
                $skipped++;

                continue;
            }

            if ($force) {
                rename($file, $path . '/' . $newFilename);
            }

            $this->line("  {$filename} -> {$newFilename}");
            $renamed++;
        }

        if ($renamed === 0 && $skipped > 0) {
            $this->info("All {$skipped} files already correct.");

            return self::SUCCESS;
        }

        if ($force) {
            $this->info("Renamed {$renamed} files, {$skipped} already correct.");
        } else {
            $this->info("Would rename {$renamed} files, {$skipped} already correct.");
            $this->comment('DRY RUN — use --force to apply.');
        }

        return self::SUCCESS;
    }
}
