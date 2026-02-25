<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Commands;

use EriMeilis\MigrationDrift\Services\MigrationDiffService;
use EriMeilis\MigrationDrift\Services\SchemaComparator;
use Illuminate\Console\Command;

class DetectCommand extends Command
{
    protected $signature = 'migrations:detect
        {--full : Full schema comparison (creates temp DB)}
        {--path= : Override migrations path}';

    protected $description = 'Detect drift between migration files and database state';

    public function handle(MigrationDiffService $diffService, SchemaComparator $schemaComparator): int
    {
        $path = $this->option('path') ?? config('migration-drift.migrations_path');

        $diff = $diffService->computeDiff($path);

        $hasDrift = false;

        if (!empty($diff['stale'])) {
            $hasDrift = true;
            $this->warn('Stale migration records (in DB but no file):');
            foreach ($diff['stale'] as $name) {
                $this->line("  <fg=red>- {$name}</>");
            }
        }

        if (!empty($diff['missing'])) {
            $hasDrift = true;
            $this->warn('Missing migration records (file exists but not in DB):');
            foreach ($diff['missing'] as $name) {
                $this->line("  <fg=yellow>? {$name}</>");
            }
        }

        if (!$hasDrift) {
            $count = count($diff['matched']);
            $this->info("{$count} migrations matched.");
        }

        if ($this->option('full')) {
            $this->newLine();
            $this->info('Running full schema comparison...');

            try {
                $schemaDiff = $schemaComparator->compare();

                if ($schemaComparator->hasDifferences($schemaDiff)) {
                    $hasDrift = true;

                    if (!empty($schemaDiff['missing_tables'])) {
                        $this->warn('Tables missing in current DB:');
                        foreach ($schemaDiff['missing_tables'] as $table) {
                            $this->line("  <fg=red>- {$table}</>");
                        }
                    }

                    if (!empty($schemaDiff['extra_tables'])) {
                        $this->warn('Extra tables in current DB (not in migrations):');
                        foreach ($schemaDiff['extra_tables'] as $table) {
                            $this->line("  <fg=yellow>+ {$table}</>");
                        }
                    }

                    foreach ($schemaDiff['column_diffs'] as $table => $colDiff) {
                        $this->warn("Column differences in '{$table}':");

                        foreach ($colDiff['missing'] as $col) {
                            $this->line("  <fg=red>- {$col}</> (missing in DB)");
                        }

                        foreach ($colDiff['extra'] as $col) {
                            $this->line("  <fg=yellow>+ {$col}</> (extra in DB)");
                        }

                        foreach ($colDiff['type_mismatches'] as $mismatch) {
                            $this->line("  <fg=magenta>~ {$mismatch['column']}</> type: {$mismatch['current']} -> {$mismatch['expected']}");
                        }
                    }
                } else {
                    $this->info('Schema comparison: no differences found.');
                }
            } catch (\Throwable $e) {
                $this->error('Schema comparison failed: ' . $e->getMessage());
                $this->error('The --full option requires CREATE DATABASE permissions.');
                $hasDrift = true;
            }
        }

        $this->newLine();

        if ($hasDrift) {
            $this->error('DRIFT DETECTED');

            return self::FAILURE;
        }

        $this->info('No drift detected.');

        return self::SUCCESS;
    }
}
