<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Commands;

use EriMeilis\MigrationDrift\Concerns\InteractivePrompts;
use EriMeilis\MigrationDrift\Concerns\ResolvesPath;
use EriMeilis\MigrationDrift\Services\CodeQualityAnalyzer;
use EriMeilis\MigrationDrift\Services\MigrationParser;
use EriMeilis\MigrationDrift\Services\MigrationState;
use EriMeilis\MigrationDrift\Services\MigrationStateAnalyzer;
use EriMeilis\MigrationDrift\Services\MigrationStatus;
use EriMeilis\MigrationDrift\Services\SchemaComparator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class DetectCommand extends Command
{
    use InteractivePrompts;
    use ResolvesPath;

    private const TABLE_DISPLAY_THRESHOLD = 3;

    protected $signature = 'migrations:detect
        {--connection= : Database connection to use}
        {--path= : Override migrations path}
        {--json : Output as JSON}
        {--verify-roundtrip : Verify migration roundtrip (up/down)}';

    protected $description
        = 'Detect drift between migration files and database state';

    public function handle(
        MigrationStateAnalyzer $analyzer,
        SchemaComparator $schemaComparator,
        MigrationParser $parser,
        CodeQualityAnalyzer $qualityAnalyzer,
    ): int {
        $connection = $this->selectConnection();
        $originalConnection = (string) config('database.default');

        if ($connection !== $originalConnection) {
            config()->set('database.default', $connection);
            DB::setDefaultConnection($connection);
            DB::purge($connection);
        }

        try {
            try {
                $path = $this->resolveMigrationsPath(
                    $this->selectPath()
                );
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            if (!$this->ensureMigrationsTableExists()) {
                return self::FAILURE;
            }

            if ($this->getMigrationFileCount($path) === 0) {
                $this->info("No migration files found in {$path}");

                return self::SUCCESS;
            }

            if ($this->option('verify-roundtrip')) {
                $this->warn(
                    'Roundtrip verification is not yet'
                    . ' implemented. Skipping.',
                );
            }

            if ($this->isInteractive()) {
                $result = spin(
                    callback: fn (): array => $this->analyze(
                        $analyzer,
                        $schemaComparator,
                        $parser,
                        $qualityAnalyzer,
                        $path,
                    ),
                    message: 'Analyzing migration drift...',
                );
            } else {
                $result = $this->analyze(
                    $analyzer,
                    $schemaComparator,
                    $parser,
                    $qualityAnalyzer,
                    $path,
                );
            }

            /** @var MigrationState[] $states */
            $states = $result['states'];

            /** @var array{missing_tables: string[], extra_tables: string[], column_diffs: array<string, array<string, mixed>>, index_diffs: array<string, array<string, mixed>>, fk_diffs: array<string, array<string, mixed>>}|null $schemaDiff */
            $schemaDiff = $result['schema'];

            /** @var array<int, array{type: string, severity: string, message: string, migration: string}> $qualityIssues */
            $qualityIssues = $result['quality'] ?? [];

            if ($this->option('json')) {
                $stateData = array_map(
                    fn (MigrationState $s): array => [
                        'migration' => $s->migrationName,
                        'status' => $s->status->name,
                        'table' => $s->tableName,
                        'partial_analysis' => $s->partialAnalysis,
                        'warnings' => $s->warnings,
                    ],
                    $states,
                );

                $this->line((string) json_encode(
                    [
                        'migration_states' => $stateData,
                        'schema_drift' => $schemaDiff,
                        'quality_issues' => $qualityIssues,
                    ],
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                ));

                $hasDrift = $this->statesHaveDrift($states);

                if (
                    !$hasDrift
                    && $schemaDiff !== null
                    && $schemaComparator->hasDifferences($schemaDiff)
                ) {
                    $hasDrift = true;
                }

                return $hasDrift ? self::FAILURE : self::SUCCESS;
            }

            $hasDrift = false;

            $hasDrift = $this->renderStateAnalysis(
                $states,
                $hasDrift,
            );

            $hasDrift = $this->renderSchemaDiff(
                $schemaDiff,
                $schemaComparator,
                $hasDrift,
            );

            $this->renderQualityIssues($qualityIssues);

            /** @var string[] $analysisWarnings */
            $analysisWarnings = $result['warnings'] ?? [];

            foreach ($analysisWarnings as $warning) {
                $this->warn($warning);
            }

            $this->newLine();

            if ($hasDrift) {
                $this->error('DRIFT DETECTED');

                return self::FAILURE;
            }

            $this->info('No drift detected.');

            return self::SUCCESS;
        } finally {
            if ($connection !== $originalConnection) {
                config()->set('database.default', $originalConnection);
                DB::setDefaultConnection($originalConnection);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(
        MigrationStateAnalyzer $analyzer,
        SchemaComparator $schemaComparator,
        MigrationParser $parser,
        CodeQualityAnalyzer $qualityAnalyzer,
        string $path,
    ): array {
        $states = $analyzer->analyze($path);
        $schemaDiff = null;
        $warnings = [];

        try {
            $schemaDiff = $schemaComparator->compare();
        } catch (\Throwable $e) {
            $warnings[] = 'Schema comparison failed: '
                . $e->getMessage();
        }

        $qualityIssues = [];

        try {
            $definitions = $parser->parseDirectory($path);
            $qualityIssues = $qualityAnalyzer->analyzeAll(
                $definitions,
            );
        } catch (\Throwable $e) {
            $warnings[] = 'Quality analysis failed: '
                . $e->getMessage();
        }

        return [
            'states' => $states,
            'schema' => $schemaDiff,
            'quality' => $qualityIssues,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param MigrationState[] $states
     */
    private function renderStateAnalysis(
        array $states,
        bool $hasDrift,
    ): bool {
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
            $names = array_map(
                fn (MigrationState $s): string => $s->migrationName,
                $grouped['NEW_MIGRATION'],
            );
            $this->info(
                "{$newCount} new migration(s) pending:",
            );
            foreach ($names as $name) {
                $this->line("  <fg=green>+ {$name}</>");
            }
        }

        $statusConfig = [
            'BOGUS_RECORD' => [
                'color' => 'red',
                'label' => 'Bogus records (registered but never ran)',
                'isDrift' => true,
            ],
            'MISSING_FILE' => [
                'color' => 'yellow',
                'label' => 'Missing files (ran but file deleted)',
                'isDrift' => true,
            ],
            'ORPHAN_RECORD' => [
                'color' => 'magenta',
                'label' => 'Orphan records (no file, no schema)',
                'isDrift' => true,
            ],
            'LOST_RECORD' => [
                'color' => 'cyan',
                'label' => 'Lost records (ran but not registered)',
                'isDrift' => true,
            ],
        ];

        foreach ($statusConfig as $statusName => $config) {
            $items = $grouped[$statusName] ?? [];
            if (empty($items)) {
                continue;
            }

            $hasDrift = true;

            $this->newLine();
            $this->warn($config['label'] . ':');

            if (count($items) > self::TABLE_DISPLAY_THRESHOLD) {
                table(
                    headers: ['Migration', 'Table'],
                    rows: array_map(
                        static fn (MigrationState $s): array => [
                            $s->migrationName,
                            $s->tableName ?? '(unknown)',
                        ],
                        $items,
                    ),
                );
            } else {
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

        return $hasDrift;
    }

    /**
     * @param MigrationState[] $states
     */
    private function statesHaveDrift(array $states): bool
    {
        foreach ($states as $state) {
            if (!in_array(
                $state->status,
                [MigrationStatus::OK, MigrationStatus::NEW_MIGRATION],
                true,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{type: string, severity: string, message: string, migration: string}> $issues
     */
    private function renderQualityIssues(
        array $issues,
    ): void {
        if (empty($issues)) {
            return;
        }

        $this->newLine();
        $this->warn('Code quality issues:');

        $severityColors = [
            'error' => 'red',
            'warning' => 'yellow',
            'info' => 'cyan',
        ];

        foreach ($issues as $issue) {
            $color = $severityColors[$issue['severity']]
                ?? 'white';
            $badge = strtoupper($issue['severity']);
            $this->line(sprintf(
                '  <fg=%s>[%s]</> %s',
                $color,
                $badge,
                $issue['message'],
            ));
            $this->line(sprintf(
                '         %s',
                $issue['migration'],
            ));
        }
    }

    /**
     * @param array{missing_tables: string[], extra_tables: string[], column_diffs: array<string, array<string, mixed>>, index_diffs?: array<string, array<string, mixed>>, fk_diffs?: array<string, array<string, mixed>>}|null $schemaDiff
     */
    private function renderSchemaDiff(
        ?array $schemaDiff,
        SchemaComparator $schemaComparator,
        bool $hasDrift,
    ): bool {
        if ($schemaDiff === null) {
            $this->newLine();
            $this->warn(
                'Schema comparison skipped '
                . '(requires CREATE DATABASE permissions).'
            );

            return $hasDrift;
        }

        if (!$schemaComparator->hasDifferences($schemaDiff)) {
            $this->newLine();
            $this->info(
                'Schema comparison: no differences found.'
            );

            return $hasDrift;
        }

        $this->newLine();

        $this->renderTableDiffs($schemaDiff);
        $this->renderColumnDiffs($schemaDiff);
        $this->renderIndexDiffs($schemaDiff);
        $this->renderForeignKeyDiffs($schemaDiff);

        return true;
    }

    /**
     * @param array<string, mixed> $schemaDiff
     */
    private function renderTableDiffs(array $schemaDiff): void
    {
        if (!empty($schemaDiff['missing_tables'])) {
            $this->warn('Tables missing in current DB:');
            foreach ($schemaDiff['missing_tables'] as $t) {
                $this->line("  <fg=red>- {$t}</>");
            }
        }

        if (!empty($schemaDiff['extra_tables'])) {
            $this->warn(
                'Extra tables in current DB '
                . '(not in migrations):'
            );
            foreach ($schemaDiff['extra_tables'] as $t) {
                $this->line("  <fg=yellow>+ {$t}</>");
            }
        }
    }

    /**
     * @param array<string, mixed> $schemaDiff
     */
    private function renderColumnDiffs(array $schemaDiff): void
    {
        foreach (($schemaDiff['column_diffs'] ?? []) as $tbl => $colDiff) {
            $this->warn("Column differences in '{$tbl}':");

            /** @var string[] $missingCols */
            $missingCols = $colDiff['missing'] ?? [];
            foreach ($missingCols as $col) {
                $this->line(
                    "  <fg=red>- {$col}</> (missing)"
                );
            }

            /** @var string[] $extraCols */
            $extraCols = $colDiff['extra'] ?? [];
            foreach ($extraCols as $col) {
                $this->line(
                    "  <fg=yellow>+ {$col}</> (extra)"
                );
            }

            /** @var array<int, array{column: string, current: string, expected: string}> $typeMismatches */
            $typeMismatches = $colDiff['type_mismatches'] ?? [];
            foreach ($typeMismatches as $m) {
                $this->line(sprintf(
                    '  <fg=magenta>~ %s</>'
                    . ' type: %s -> %s',
                    $m['column'],
                    $m['current'],
                    $m['expected'],
                ));
            }

            /** @var array<int, array{column: string, current: string, expected: string}> $nullableMismatches */
            $nullableMismatches
                = $colDiff['nullable_mismatches'] ?? [];
            foreach ($nullableMismatches as $m) {
                $this->line(sprintf(
                    '  <fg=magenta>~ %s</>'
                    . ' nullable: %s -> %s',
                    $m['column'],
                    $m['current'],
                    $m['expected'],
                ));
            }

            /** @var array<int, array{column: string, current: mixed, expected: mixed}> $defaultMismatches */
            $defaultMismatches
                = $colDiff['default_mismatches'] ?? [];
            foreach ($defaultMismatches as $m) {
                $this->line(sprintf(
                    '  <fg=magenta>~ %s</>'
                    . ' default: %s -> %s',
                    $m['column'],
                    $m['current'] ?? 'NULL',
                    $m['expected'] ?? 'NULL',
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $schemaDiff
     */
    private function renderIndexDiffs(array $schemaDiff): void
    {
        foreach (
            ($schemaDiff['index_diffs'] ?? []) as $tbl => $idxDiff
        ) {
            $this->warn("Index differences in '{$tbl}':");

            foreach (($idxDiff['missing'] ?? []) as $idx) {
                $cols = implode(
                    ', ',
                    $idx['columns'] ?? [],
                );
                $type = ($idx['primary'] ?? false)
                    ? 'primary'
                    : (($idx['unique'] ?? false)
                        ? 'unique' : 'index');
                $this->line(
                    "  <fg=red>- [{$cols}]</>"
                    . " ({$type}, missing)",
                );
            }

            foreach (($idxDiff['extra'] ?? []) as $idx) {
                $cols = implode(
                    ', ',
                    $idx['columns'] ?? [],
                );
                $type = ($idx['primary'] ?? false)
                    ? 'primary'
                    : (($idx['unique'] ?? false)
                        ? 'unique' : 'index');
                $this->line(
                    "  <fg=yellow>+ [{$cols}]</>"
                    . " ({$type}, extra)",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $schemaDiff
     */
    private function renderForeignKeyDiffs(
        array $schemaDiff,
    ): void {
        foreach (
            ($schemaDiff['fk_diffs'] ?? []) as $tbl => $fkDiff
        ) {
            $this->warn(
                "Foreign key differences in '{$tbl}':"
            );

            foreach (($fkDiff['missing'] ?? []) as $fk) {
                $cols = implode(
                    ', ',
                    $fk['columns'] ?? [],
                );
                $ref = ($fk['foreign_table'] ?? '')
                    . '('
                    . implode(
                        ', ',
                        $fk['foreign_columns'] ?? [],
                    ) . ')';
                $this->line(
                    "  <fg=red>- [{$cols}]</>"
                    . " -> {$ref} (missing)",
                );
            }

            foreach (($fkDiff['extra'] ?? []) as $fk) {
                $cols = implode(
                    ', ',
                    $fk['columns'] ?? [],
                );
                $ref = ($fk['foreign_table'] ?? '')
                    . '('
                    . implode(
                        ', ',
                        $fk['foreign_columns'] ?? [],
                    ) . ')';
                $this->line(
                    "  <fg=yellow>+ [{$cols}]</>"
                    . " -> {$ref} (extra)",
                );
            }
        }
    }
}
