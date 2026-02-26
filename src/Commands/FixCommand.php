<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Commands;

use EriMeilis\MigrationDrift\Concerns\InteractivePrompts;
use EriMeilis\MigrationDrift\Concerns\ResolvesPath;
use EriMeilis\MigrationDrift\Services\BackupService;
use EriMeilis\MigrationDrift\Services\ConsolidationService;
use EriMeilis\MigrationDrift\Services\MigrationDiffService;
use EriMeilis\MigrationDrift\Services\MigrationGenerator;
use EriMeilis\MigrationDrift\Services\MigrationParser;
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
        {--table : Sync migration table records to match files}
        {--schema : Generate corrective migrations for schema drift}
        {--consolidate : Consolidate redundant migrations per table}
        {--path= : Override migrations path}
        {--connection= : Database connection to use}';

    protected $description = 'Fix migration drift: sync table records, generate corrections, consolidate';

    public function handle(
        BackupService $backupService,
        MigrationDiffService $diffService,
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

        $fixes = $this->selectFixes();

        if (empty($fixes)) {
            $this->info('No fixes selected.');

            return self::SUCCESS;
        }

        $result = self::SUCCESS;

        if (in_array('table_sync', $fixes, true)) {
            $result = $this->handleTableSync(
                $diffService,
                $backupService,
                $path,
            );

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        if (in_array('schema_fix', $fixes, true)) {
            $result = $this->handleSchemaFix(
                $schemaComparator,
                $introspector,
                $generator,
                $path,
            );

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        if (in_array('consolidate', $fixes, true)) {
            $result = $this->handleConsolidation(
                $parser,
                $consolidationService,
                $backupService,
                $path,
            );
        }

        return $result;
    }

    /**
     * Prompt user to select which fixes to apply.
     *
     * @return string[]
     */
    private function selectFixes(): array
    {
        $fixes = [];

        if ($this->option('table')) {
            $fixes[] = 'table_sync';
        }

        if ($this->option('schema')) {
            $fixes[] = 'schema_fix';
        }

        if ($this->option('consolidate')) {
            $fixes[] = 'consolidate';
        }

        if (!empty($fixes)) {
            return $fixes;
        }

        if (!$this->isInteractive()) {
            return ['table_sync'];
        }

        return multiselect(
            label: 'What would you like to fix?',
            options: [
                'table_sync'
                    => 'Migrations table — sync records'
                    . ' to match files',
                'schema_fix'
                    => 'Schema drift — generate corrective'
                    . ' migrations',
                'consolidate'
                    => 'Consolidate — merge redundant'
                    . ' migrations per table',
            ],
            default: ['table_sync'],
            hint: 'Space to toggle, Enter to confirm',
        );
    }

    private function handleTableSync(
        MigrationDiffService $diffService,
        BackupService $backupService,
        string $path,
    ): int {
        $diff = $diffService->computeDiff($path);

        if (empty($diff['stale']) && empty($diff['missing'])) {
            $matchedCount = count($diff['matched']);
            $this->info(
                "Already in sync: {$matchedCount} migration(s) matched."
            );

            return self::SUCCESS;
        }

        $this->displayDiff($diff);

        if (!$this->option('force')) {
            $this->newLine();
            $this->comment(
                'DRY RUN — use --force to apply changes.'
            );

            return self::SUCCESS;
        }

        try {
            $backupPath = $backupService->backup();
            $this->info("Backup created: {$backupPath}");
        } catch (RuntimeException $e) {
            $this->error('Backup failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $table = $this->getMigrationsTable();

        try {
            DB::transaction(function () use ($table, $diff): void {
                if (!empty($diff['stale'])) {
                    DB::table($table)
                        ->whereIn('migration', $diff['stale'])
                        ->delete();
                }

                if (!empty($diff['missing'])) {
                    $records = array_map(
                        fn (string $name): array => [
                            'migration' => $name,
                            'batch' => 1,
                        ],
                        $diff['missing'],
                    );

                    foreach (array_chunk($records, 500) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            $this->comment(
                "Restore from backup: php artisan migrations:fix --restore"
            );

            return self::FAILURE;
        }

        $this->displaySummary($diff);

        return self::SUCCESS;
    }

    private function handleSchemaFix(
        SchemaComparator $schemaComparator,
        SchemaIntrospector $introspector,
        MigrationGenerator $generator,
        string $path,
    ): int {
        try {
            $schemaDiff = $schemaComparator->compare();
        } catch (\Throwable $e) {
            $this->error(
                'Schema comparison failed: '
                . $e->getMessage(),
            );

            return self::FAILURE;
        }

        if (!$schemaComparator->hasDifferences($schemaDiff)) {
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
            $this->info('No actionable schema differences.');

            return self::SUCCESS;
        }

        $this->displayActions($actions);

        if (!$this->option('force')) {
            if (!$this->isInteractive()) {
                $this->newLine();
                $this->comment(
                    'DRY RUN — use --force to generate'
                    . ' migrations.',
                );

                return self::SUCCESS;
            }

            if (
                !confirm(
                    'Generate corrective migrations?',
                    default: false,
                )
            ) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        return $this->generateMigrations(
            $actions,
            $generator,
            $path,
        );
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

                // Update migrations table first (safe to
                // rollback via transaction on failure)
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

                // Archive original files (atomic rename)
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
                    // Restore any already-archived files
                    foreach (
                        $archived as $original => $archive
                    ) {
                        rename($archive, $original);
                    }
                    @rmdir($archiveDir);
                    throw $archiveError;
                }

                // Clean up archive directory
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

        // Missing tables — need to be created
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

        // Extra tables — should be dropped
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

        // Column diffs on common tables
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

        // Index diffs
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

        // FK diffs
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
     * Get column, index, and FK details for a table
     * (used for reversible drop migrations).
     *
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
     * Display the planned corrective actions.
     *
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
     * Generate migration files from the planned actions.
     *
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
     * Generate a single corrective migration file.
     *
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

    /**
     * @param array{stale: string[], missing: string[], matched: string[]} $diff
     */
    private function displayDiff(array $diff): void
    {
        if (!empty($diff['stale'])) {
            $this->warn('Stale records (in DB, no matching file):');
            foreach ($diff['stale'] as $name) {
                $this->line("  <fg=red>- {$name}</>");
            }
        }

        if (!empty($diff['missing'])) {
            $this->warn('Missing records (file exists, not in DB):');
            foreach ($diff['missing'] as $name) {
                $this->line("  <fg=green>+ {$name}</>");
            }
        }

        $matchedCount = count($diff['matched']);
        $this->info("  {$matchedCount} migration(s) already matched.");
    }

    /**
     * @param array{stale: string[], missing: string[], matched: string[]} $diff
     */
    private function displaySummary(array $diff): void
    {
        $this->newLine();
        $staleCount = count($diff['stale']);
        $missingCount = count($diff['missing']);

        $this->info('Sync complete:');

        if ($staleCount > 0) {
            $this->line(
                "  Removed {$staleCount} stale record(s)."
            );
        }

        if ($missingCount > 0) {
            $this->line(
                "  Inserted {$missingCount} missing record(s)."
            );
        }
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
