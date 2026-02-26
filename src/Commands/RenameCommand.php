<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Commands;

use EriMeilis\MigrationDrift\Concerns\InteractivePrompts;
use EriMeilis\MigrationDrift\Concerns\ResolvesPath;
use EriMeilis\MigrationDrift\Services\RenameService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

use function Laravel\Prompts\suggest;
use function Laravel\Prompts\table;

class RenameCommand extends Command
{
    use InteractivePrompts;
    use ResolvesPath;

    /** @var string */
    protected $signature = 'migrations:rename
        {--force : Apply renames (default is dry-run)}
        {--date= : Target date YYYY-MM-DD (default: today)}
        {--path= : Override migrations path}
        {--connection= : Database connection to use}';

    /** @var string */
    protected $description
        = 'Rename migration files to use a target date prefix with sequential numbering';

    public function handle(RenameService $renameService): int
    {
        try {
            $path = $this->resolveMigrationsPath($this->selectPath());
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dateOption = $this->resolveDate();

        if (
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $dateOption,
            )
        ) {
            $this->error(
                "Invalid date format: {$dateOption}."
                . ' Expected YYYY-MM-DD.',
            );

            return self::FAILURE;
        }

        $parsed = \DateTimeImmutable::createFromFormat(
            'Y-m-d',
            $dateOption,
        );

        if (
            $parsed === false
            || $parsed->format('Y-m-d') !== $dateOption
        ) {
            $this->error(
                "Invalid date: {$dateOption}.",
            );

            return self::FAILURE;
        }

        $plan = $renameService->computeRenames($path, $dateOption);

        if (empty($plan)) {
            $count = $this->getMigrationFileCount($path);
            $this->info("All {$count} files already correct.");

            return self::SUCCESS;
        }

        table(
            headers: ['Current', 'New'],
            rows: array_map(
                fn (array $item) => [$item['old'], $item['new']],
                $plan,
            ),
        );

        if (!$this->confirmForce('Apply renames?')) {
            $this->info(
                'Would rename ' . count($plan) . ' files.',
            );
            $this->comment('DRY RUN — use --force to apply.');

            return self::SUCCESS;
        }

        // Step 1: Update DB records first (transactional)
        $migrationsTable = $this->getMigrationsTable();

        try {
            DB::transaction(function () use (
                $migrationsTable,
                $plan,
            ): void {
                foreach ($plan as $item) {
                    $oldName = pathinfo(
                        $item['old'],
                        PATHINFO_FILENAME,
                    );
                    $newName = pathinfo(
                        $item['new'],
                        PATHINFO_FILENAME,
                    );

                    DB::table($migrationsTable)
                        ->where('migration', $oldName)
                        ->update(['migration' => $newName]);
                }
            });
        } catch (\Throwable $e) {
            $this->error(
                'Database update failed: '
                . $e->getMessage(),
            );

            return self::FAILURE;
        }

        // Step 2: Rename files (rollback DB on failure)
        try {
            $renameService->applyRenames($path, $plan);
        } catch (RuntimeException $e) {
            $this->warn(
                'File rename failed, rolling back'
                . ' database changes...',
            );

            try {
                DB::transaction(function () use (
                    $migrationsTable,
                    $plan,
                ): void {
                    foreach ($plan as $item) {
                        $oldName = pathinfo(
                            $item['old'],
                            PATHINFO_FILENAME,
                        );
                        $newName = pathinfo(
                            $item['new'],
                            PATHINFO_FILENAME,
                        );

                        DB::table($migrationsTable)
                            ->where('migration', $newName)
                            ->update(
                                ['migration' => $oldName],
                            );
                    }
                });
                $this->info(
                    'Database changes rolled back.',
                );
            } catch (\Throwable $rollbackError) {
                $this->error(
                    'Rollback also failed: '
                    . $rollbackError->getMessage(),
                );
                $this->warn(
                    'Run migrations:fix --table to sync'
                    . ' records.',
                );
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Renamed ' . count($plan) . ' files.');
        $this->info('Updated migrations table records.');

        return self::SUCCESS;
    }

    private function resolveDate(): string
    {
        if ($this->option('date')) {
            return $this->option('date');
        }

        $today = date('Y-m-d');

        if (!$this->isInteractive()) {
            return $today;
        }

        return suggest(
            label: 'Target date for migration prefix',
            options: [$today],
            default: $today,
            hint: 'Target date for migration prefix (YYYY-MM-DD)',
        );
    }
}
