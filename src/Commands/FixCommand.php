<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Commands;

use EriMeilis\MigrationDrift\Concerns\InteractivePrompts;
use EriMeilis\MigrationDrift\Concerns\ResolvesPath;
use EriMeilis\MigrationDrift\Services\BackupService;
use EriMeilis\MigrationDrift\Services\ConsolidationService;
use EriMeilis\MigrationDrift\Services\MigrationGenerator;
use EriMeilis\MigrationDrift\Services\MigrationParser;
use EriMeilis\MigrationDrift\Services\MigrationState;
use EriMeilis\MigrationDrift\Services\MigrationStateAnalyzer;
use EriMeilis\MigrationDrift\Services\MigrationStatus;
use EriMeilis\MigrationDrift\Services\SchemaComparator;
use EriMeilis\MigrationDrift\Services\SchemaIntrospector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\table;

class FixCommand extends Command
{
    use InteractivePrompts;
    use ResolvesPath;

    protected $signature = 'migrations:fix
        {--force : Apply changes (default is dry-run)}
        {--restore : Restore migrations table from latest backup}
        {--table : Deprecated — included in unified fix}
        {--schema : Deprecated — included in unified fix}
        {--consolidate : Consolidate redundant migrations per table}
        {--path= : Override migrations path}
        {--connection= : Database connection to use}';

    protected $description = 'Fix migration drift: sync records, generate corrections, consolidate';

    public function handle(
        BackupService $backupService,
        MigrationStateAnalyzer $analyzer,
        SchemaComparator $schemaComparator,
        SchemaIntrospector $introspector,
        MigrationGenerator $generator,
        MigrationParser $parser,
        ConsolidationService $consolidationService,
    ): int {
        $connection = $this->selectConnection();
        $currentConnection = (string) config('database.default');

        if ($connection !== $currentConnection) {
            config()->set('database.default', $connection);
            DB::setDefaultConnection($connection);
            DB::purge($connection);
        }

        if ($this->option('restore')) {
            return $this->handleRestore($backupService);
        }

        // Deprecation warnings for removed flags
        if ($this->option('table') || $this->option('schema')) {
            $this->warn(
                'The --table and --schema flags are deprecated.'
                . ' The unified fix now handles both automatically.',
            );
        }

        $selectedPath = $this->selectPath();

        try {
            $path = $this->resolveMigrationsPath($selectedPath);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (!$this->ensureMigrationsTableExists()) {
            return self::FAILURE;
        }

        $fileCount = $this->getMigrationFileCount($path);

        if ($fileCount === 0) {
            $this->info('No migration files found in: ' . $path);

            return self::SUCCESS;
        }

        // Consolidation is a separate workflow
        if ($this->option('consolidate')) {
            return $this->handleConsolidation(
                $parser,
                $consolidationService,
                $backupService,
                $path,
            );
        }

        // Run state analysis
        $states = $analyzer->analyze($path);

        // Display classification results
        $this->displayStates($states);

        // Check for actionable states
        $actionable = array_filter(
            $states,
            fn (MigrationState $s): bool => !in_array(
                $s->status,
                [MigrationStatus::OK, MigrationStatus::NEW_MIGRATION],
                true,
            ),
        );

        if (empty($actionable)) {
            $this->newLine();
            $this->info('Everything in sync — no fixes needed.');

            return self::SUCCESS;
        }

        if (!$this->option('force')) {
            $this->newLine();
            $this->comment(
                'DRY RUN — use --force to apply changes.',
            );

            return self::SUCCESS;
        }

        // Backup first
        try {
            $backupPath = $backupService->backup();
            $this->info("Backup created: {$backupPath}");
        } catch (RuntimeException $e) {
            $this->error('Backup failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        // Fix bookkeeping in transaction
        $result = $this->fixBookkeeping($states);

        if ($result !== self::SUCCESS) {
            return $result;
        }

        // Collect NEW_MIGRATION filenames to exclude from schema
        // comparison — they haven't run yet, so including them
        // would generate duplicate corrective files for changes
        // that `php artisan migrate` will handle.
        $newMigrationFiles = array_map(
            fn (MigrationState $s): string => $s->migrationName,
            array_filter(
                $states,
                fn (MigrationState $s): bool => $s->status === MigrationStatus::NEW_MIGRATION,
            ),
        );

        // Run global schema comparison for remaining drift
        return $this->fixSchemaDrift(
            $schemaComparator,
            $introspector,
            $generator,
            $path,
            $newMigrationFiles,
        );
    }

    /**
     * Fix migration record bookkeeping based on state analysis.
     *
     * @param MigrationState[] $states
     */
    private function fixBookkeeping(array $states): int
    {
        $toDelete = [];
        $toInsert = [];
        $orphanWarnings = [];

        foreach ($states as $state) {
            if ($state->status === MigrationStatus::BOGUS_RECORD) {
                $toDelete[] = $state->migrationName;
            } elseif ($state->status === MigrationStatus::ORPHAN_RECORD) {
                $toDelete[] = $state->migrationName;
                $orphanWarnings[] = $state->migrationName;
            } elseif ($state->status === MigrationStatus::LOST_RECORD) {
                $toInsert[] = $state->migrationName;
            }
        }

        if (empty($toDelete) && empty($toInsert)) {
            return self::SUCCESS;
        }

        $table = $this->getMigrationsTable();

        try {
            DB::transaction(function () use ($table, $toDelete, $toInsert): void {
                if (!empty($toDelete)) {
                    DB::table($table)
                        ->whereIn('migration', $toDelete)
                        ->delete();
                }

                if (!empty($toInsert)) {
                    $records = array_map(
                        fn (string $name): array => [
                            'migration' => $name,
                            'batch' => 1,
                        ],
                        $toInsert,
                    );

                    foreach (array_chunk($records, 500) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->error('Bookkeeping fix failed: ' . $e->getMessage());
            $this->comment(
                "Restore from backup: php artisan migrations:fix --restore",
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Bookkeeping fixed:');

        if (!empty($toDelete)) {
            $bogusCount = count($toDelete) - count($orphanWarnings);
            if ($bogusCount > 0) {
                $this->line(
                    "  Removed {$bogusCount} bogus record(s).",
                );
            }
        }

        foreach ($orphanWarnings as $name) {
            $this->line(
                "  <fg=yellow>!</> Removed orphan record: {$name}",
            );
        }

        if (!empty($toInsert)) {
            $insertCount = count($toInsert);
            $this->line(
                "  Inserted {$insertCount} lost record(s).",
            );
        }

        return self::SUCCESS;
    }

    /**
     * Run global schema comparison and generate corrective migrations.
     *
     * @param string[] $excludeFiles Migration filenames to exclude from comparison
     */
    private function fixSchemaDrift(
        SchemaComparator $schemaComparator,
        SchemaIntrospector $introspector,
        MigrationGenerator $generator,
        string $path,
        array $excludeFiles = [],
    ): int {
        try {
            $schemaDiff = $schemaComparator->compare(
                $path,
                $excludeFiles,
            );
        } catch (\Throwable $e) {
            $this->warn(
                'Schema comparison skipped: '
                . $e->getMessage(),
            );

            return self::SUCCESS;
        }

        if (!$schemaComparator->hasDifferences($schemaDiff)) {
            $this->newLine();
            $this->info(
                'Schema is in sync — no corrective'
                . ' migrations needed.',
            );

            return self::SUCCESS;
        }

        $actions = $this->buildActions(
            $schemaDiff,
            $introspector,
        );

        if (empty($actions)) {
            return self::SUCCESS;
        }

        $this->displayActions($actions);

        return $this->generateMigrations(
            $actions,
            $generator,
            $path,
        );
    }

    /**
     * Display the classified migration states.
     *
     * @param MigrationState[] $states
     */
    private function displayStates(array $states): void
    {
        $grouped = [];
        foreach ($states as $state) {
            $grouped[$state->status->name][] = $state;
        }

        $okCount = count($grouped['OK'] ?? []);
        $newCount = count($grouped['NEW_MIGRATION'] ?? []);

        if ($okCount > 0) {
            $this->info("{$okCount} migration(s) OK.");
        }

        if ($newCount > 0) {
            $this->info(
                "{$newCount} new migration(s) pending"
                . ' (will run with `php artisan migrate`).',
            );
        }

        $statusConfig = [
            'BOGUS_RECORD' => ['color' => 'red', 'label' => 'Bogus record (registered but never ran)'],
            'MISSING_FILE' => ['color' => 'yellow', 'label' => 'Missing file (ran but file deleted)'],
            'ORPHAN_RECORD' => ['color' => 'magenta', 'label' => 'Orphan record (no file, no schema)'],
            'LOST_RECORD' => ['color' => 'cyan', 'label' => 'Lost record (ran but not registered)'],
        ];

        foreach ($statusConfig as $statusName => $config) {
            $items = $grouped[$statusName] ?? [];
            if (empty($items)) {
                continue;
            }

            $this->newLine();
            $this->warn($config['label'] . ':');

            foreach ($items as $state) {
                $this->line(
                    "  <fg={$config['color']}>"
                    . "{$state->migrationName}</>",
                );

                foreach ($state->warnings as $warning) {
                    $this->line(
                        "    <fg=yellow>!</> {$warning}",
                    );
                }
            }
        }
    }

    private function handleConsolidation(
        MigrationParser $parser,
        ConsolidationService $consolidationService,
        BackupService $backupService,
        string $path,
    ): int {
        try {
            $definitions = $parser->parseDirectory($path);
        } catch (\Throwable $e) {
            $this->error(
                'Failed to parse migrations: '
                . $e->getMessage(),
            );

            return self::FAILURE;
        }

        $candidates = $consolidationService
            ->findConsolidationCandidates($definitions);

        if (empty($candidates)) {
            $this->info(
                'No consolidation candidates found.',
            );

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Consolidation candidates:');

        $rows = [];

        foreach ($candidates as $tbl => $data) {
            $count = count($data['consolidatable']);
            $skipped = count($data['skipped']);
            $note = $skipped > 0
                ? " ({$skipped} skipped)"
                : '';
            $rows[] = [
                $tbl,
                "{$count} migrations{$note}",
            ];
        }

        table(
            headers: ['Table', 'Migrations'],
            rows: $rows,
        );

        if (!$this->option('force')) {
            if (!$this->isInteractive()) {
                $this->newLine();
                $this->comment(
                    'DRY RUN — use --force to consolidate.',
                );

                return self::SUCCESS;
            }

            $selectedTables = multiselect(
                label: 'Which tables to consolidate?',
                options: array_combine(
                    array_keys($candidates),
                    array_map(
                        fn (string $t): string => "{$t} ("
                            . count(
                                $candidates[$t]['consolidatable'],
                            )
                            . ' migrations)',
                        array_keys($candidates),
                    ),
                ),
                default: array_keys($candidates),
                hint: 'Space to toggle, Enter to confirm',
            );

            if (empty($selectedTables)) {
                $this->info('No tables selected.');

                return self::SUCCESS;
            }

            $candidates = array_intersect_key(
                $candidates,
                array_flip($selectedTables),
            );

            if (
                !confirm(
                    'Consolidate selected tables?',
                    default: false,
                )
            ) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $backupPath = $backupService->backup();
            $this->info("Backup created: {$backupPath}");
        } catch (RuntimeException $e) {
            $this->error(
                'Backup failed: ' . $e->getMessage(),
            );

            return self::FAILURE;
        }

        $date = date('Y_m_d');
        $migrationsTable = $this->getMigrationsTable();
        $results = [];

        foreach ($candidates as $tbl => $data) {
            try {
                $result = $consolidationService->consolidate(
                    $data['consolidatable'],
                    $tbl,
                    $path,
                    $date,
                );

                $results[] = $result;

                DB::transaction(
                    function () use (
                        $migrationsTable,
                        $result,
                    ): void {
                        DB::table($migrationsTable)
                            ->whereIn(
                                'migration',
                                $result->originalMigrations,
                            )
                            ->delete();

                        $maxBatch = (int) DB::table(
                            $migrationsTable,
                        )->max('batch');

                        $newFilename = pathinfo(
                            $result->generatedFilePath,
                            PATHINFO_FILENAME,
                        );

                        DB::table($migrationsTable)->insert([
                            'migration' => $newFilename,
                            'batch' => $maxBatch + 1,
                        ]);
                    },
                );

                $archiveDir = sys_get_temp_dir()
                    . '/migration-drift-archive-'
                    . bin2hex(random_bytes(4));

                if (!mkdir($archiveDir, 0700, true)) {
                    throw new RuntimeException(
                        "Failed to create archive directory:"
                        . " {$archiveDir}",
                    );
                }

                $archived = [];
                try {
                    foreach (
                        $result->originalMigrations
                        as $filename
                    ) {
                        $filePath = $path
                            . '/' . $filename . '.php';

                        if (file_exists($filePath)) {
                            $archivePath = $archiveDir
                                . '/' . $filename . '.php';
                            if (
                                !rename(
                                    $filePath,
                                    $archivePath,
                                )
                            ) {
                                throw new RuntimeException(
                                    "Failed to archive:"
                                    . " {$filePath}",
                                );
                            }
                            $archived[$filePath]
                                = $archivePath;
                        }
                    }
                } catch (\Throwable $archiveError) {
                    foreach (
                        $archived as $original => $archive
                    ) {
                        rename($archive, $original);
                    }
                    @rmdir($archiveDir);
                    throw $archiveError;
                }

                foreach ($archived as $archive) {
                    @unlink($archive);
                }
                @rmdir($archiveDir);
            } catch (\Throwable $e) {
                $this->error(
                    "Failed to consolidate '{$tbl}': "
                    . $e->getMessage(),
                );
            }
        }

        if (empty($results)) {
            $this->error('No tables were consolidated.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(
            count($results)
            . ' table(s) consolidated:',
        );

        foreach ($results as $r) {
            $origCount = count($r->originalMigrations);
            $this->line(
                "  <fg=green>+</> {$r->tableName}:"
                . " {$origCount} migrations → 1",
            );

            foreach ($r->warnings as $warning) {
                $this->line(
                    "    <fg=yellow>!</> {$warning}",
                );
            }
        }

        return self::SUCCESS;
    }

    /**
     * Build a list of corrective actions from schema diff.
     *
     * @param array<string, mixed> $schemaDiff
     * @return array<int, array{action: string, table: string, details: array<string, mixed>}>
     */
    private function buildActions(
        array $schemaDiff,
        SchemaIntrospector $introspector,
    ): array {
        $actions = [];

        $missingDetails = $schemaDiff['missing_table_details']
            ?? [];

        foreach (
            ($schemaDiff['missing_tables'] ?? []) as $table
        ) {
            $actions[] = [
                'action' => 'create_table',
                'table' => $table,
                'details' => $missingDetails[$table] ?? [],
            ];
        }

        foreach (
            ($schemaDiff['extra_tables'] ?? []) as $table
        ) {
            $actions[] = [
                'action' => 'drop_table',
                'table' => $table,
                'details' => $this->getTableDetails(
                    $table,
                    $introspector,
                ),
            ];
        }

        foreach (
            ($schemaDiff['column_diffs'] ?? [])
            as $table => $colDiff
        ) {
            foreach (($colDiff['missing'] ?? []) as $col) {
                $actions[] = [
                    'action' => 'add_column',
                    'table' => $table,
                    'details' => ['column' => $col],
                ];
            }

            foreach (($colDiff['extra'] ?? []) as $col) {
                $actions[] = [
                    'action' => 'drop_column',
                    'table' => $table,
                    'details' => ['column' => $col],
                ];
            }
        }

        foreach (
            ($schemaDiff['index_diffs'] ?? [])
            as $table => $idxDiff
        ) {
            foreach (
                ($idxDiff['missing'] ?? []) as $idx
            ) {
                $actions[] = [
                    'action' => 'add_index',
                    'table' => $table,
                    'details' => ['index' => $idx],
                ];
            }
        }

        foreach (
            ($schemaDiff['fk_diffs'] ?? [])
            as $table => $fkDiff
        ) {
            foreach (
                ($fkDiff['missing'] ?? []) as $fk
            ) {
                $actions[] = [
                    'action' => 'add_foreign_key',
                    'table' => $table,
                    'details' => ['foreign_key' => $fk],
                ];
            }
        }

        return $actions;
    }

    /**
     * @return array{columns: array<int, array<string, mixed>>, indexes: array<int, array<string, mixed>>, foreign_keys: array<int, array<string, mixed>>}
     */
    private function getTableDetails(
        string $table,
        SchemaIntrospector $introspector,
    ): array {
        $connection = (string) config('database.default');

        return [
            'columns' => $introspector->getColumns(
                $connection,
                $table,
            ),
            'indexes' => $introspector->getIndexes(
                $connection,
                $table,
            ),
            'foreign_keys' => $introspector->getForeignKeys(
                $connection,
                $table,
            ),
        ];
    }

    /**
     * @param array<int, array{action: string, table: string, details: array<string, mixed>}> $actions
     */
    private function displayActions(array $actions): void
    {
        $this->newLine();
        $this->warn('Planned corrective migrations:');

        $rows = [];

        foreach ($actions as $action) {
            $desc = match ($action['action']) {
                'create_table' => 'Create table',
                'drop_table' => 'Drop table',
                'add_column' => 'Add column: '
                    . ($action['details']['column'] ?? ''),
                'drop_column' => 'Drop column: '
                    . ($action['details']['column'] ?? ''),
                'add_index' => 'Add index: '
                    . implode(
                        ', ',
                        $action['details']['index']['columns']
                        ?? [],
                    ),
                'add_foreign_key' => 'Add FK: '
                    . implode(
                        ', ',
                        $action['details']['foreign_key']['columns']
                        ?? [],
                    ),
                default => $action['action'],
            };

            $rows[] = [$action['table'], $desc];
        }

        table(
            headers: ['Table', 'Action'],
            rows: $rows,
        );
    }

    /**
     * @param array<int, array{action: string, table: string, details: array<string, mixed>}> $actions
     */
    private function generateMigrations(
        array $actions,
        MigrationGenerator $generator,
        string $path,
    ): int {
        $date = date('Y_m_d');
        $generated = [];

        foreach ($actions as $action) {
            try {
                $file = $this->generateSingleMigration(
                    $action,
                    $generator,
                    $path,
                    $date,
                );

                if ($file !== null) {
                    $generated[] = basename($file);
                }
            } catch (\Throwable $e) {
                $this->error(
                    "Failed to generate migration for"
                    . " {$action['table']}"
                    . " ({$action['action']}): "
                    . $e->getMessage(),
                );
            }
        }

        if (empty($generated)) {
            $this->error('No migrations were generated.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(
            count($generated)
            . ' corrective migration(s) generated:',
        );

        foreach ($generated as $file) {
            $this->line("  <fg=green>+</> {$file}");
        }

        return self::SUCCESS;
    }

    /**
     * @param array{action: string, table: string, details: array<string, mixed>} $action
     */
    private function generateSingleMigration(
        array $action,
        MigrationGenerator $generator,
        string $path,
        string $date,
    ): ?string {
        return match ($action['action']) {
            'create_table' => $generator->generateCreateTable(
                $action['table'],
                $action['details']['columns'] ?? [],
                $action['details']['indexes'] ?? [],
                $action['details']['foreign_keys'] ?? [],
                $path,
                $date,
            ),
            'drop_table' => $generator->generateDropTable(
                $action['table'],
                $action['details']['columns'] ?? [],
                $action['details']['indexes'] ?? [],
                $action['details']['foreign_keys'] ?? [],
                $path,
                $date,
            ),
            'add_column' => $generator->generateAddColumn(
                $action['table'],
                $action['details']['column_info'] ?? [
                    'name' => $action['details']['column']
                        ?? 'unknown',
                ],
                $path,
                $date,
            ),
            'drop_column' => $generator->generateDropColumn(
                $action['table'],
                $action['details']['column'] ?? 'unknown',
                $action['details']['column_info'] ?? null,
                $path,
                $date,
            ),
            'add_index' => $generator->generateAddIndex(
                $action['table'],
                $action['details']['index'] ?? [],
                $path,
                $date,
            ),
            'add_foreign_key'
                => $generator->generateAddForeignKey(
                    $action['table'],
                    $action['details']['foreign_key'] ?? [],
                    $path,
                    $date,
                ),
            default => null,
        };
    }

    private function handleRestore(BackupService $backupService): int
    {
        $backupPath = $backupService->getLatestBackupPath();

        if ($backupPath === null) {
            $this->error('No backup files found.');

            return self::FAILURE;
        }

        $this->info("Restoring from: {$backupPath}");

        try {
            $backupService->restore($backupPath);
        } catch (InvalidArgumentException | RuntimeException $e) {
            $this->error('Restore failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Migrations table restored successfully.');

        return self::SUCCESS;
    }
}
