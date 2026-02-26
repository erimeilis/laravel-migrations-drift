<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Commands;

use EriMeilis\MigrationDrift\Concerns\InteractivePrompts;
use EriMeilis\MigrationDrift\Concerns\ResolvesPath;
use EriMeilis\MigrationDrift\Services\CodeQualityAnalyzer;
use EriMeilis\MigrationDrift\Services\MigrationDiffService;
use EriMeilis\MigrationDrift\Services\MigrationParser;
use EriMeilis\MigrationDrift\Services\SchemaComparator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class DetectCommand extends Command
{
    use InteractivePrompts;
    use ResolvesPath;

    protected $signature = 'migrations:detect
        {--connection= : Database connection to use}
        {--path= : Override migrations path}
        {--json : Output as JSON}
        {--verify-roundtrip : Verify migration roundtrip (up/down)}';

    protected $description
        = 'Detect drift between migration files and database state';

    public function handle(
        MigrationDiffService $diffService,
        SchemaComparator $schemaComparator,
        MigrationParser $parser,
        CodeQualityAnalyzer $analyzer,
    ): int {
        $connection = $this->selectConnection();
        $currentConnection = (string) config('database.default');

        if ($connection !== $currentConnection) {
            config()->set('database.default', $connection);
            DB::setDefaultConnection($connection);
            DB::purge($connection);
        }

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
            $this->error(
                'Roundtrip verification is not yet implemented.',
            );

            return self::FAILURE;
        }

        if ($this->isInteractive()) {
            $result = spin(
                callback: fn (): array => $this->analyze(
                    $diffService,
                    $schemaComparator,
                    $parser,
                    $analyzer,
                    $path,
                ),
                message: 'Analyzing migration drift...',
            );
        } else {
            $result = $this->analyze(
                $diffService,
                $schemaComparator,
                $parser,
                $analyzer,
                $path,
            );
        }

        /** @var array{stale: string[], missing: string[], matched: string[]} $tableDiff */
        $tableDiff = $result['table'];

        /** @var array{missing_tables: string[], extra_tables: string[], column_diffs: array<string, array<string, mixed>>, index_diffs: array<string, array<string, mixed>>, fk_diffs: array<string, array<string, mixed>>}|null $schemaDiff */
        $schemaDiff = $result['schema'];

        /** @var array<int, array{type: string, severity: string, message: string, migration: string}> $qualityIssues */
        $qualityIssues = $result['quality'] ?? [];

        if ($this->option('json')) {
            $this->line((string) json_encode(
                [
                    'table_drift' => $tableDiff,
                    'schema_drift' => $schemaDiff,
                    'quality_issues' => $qualityIssues,
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));

            $hasDrift = !empty($tableDiff['stale'])
                || !empty($tableDiff['missing']);

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

        $hasDrift = $this->renderStaleEntries(
            $tableDiff['stale'],
            $hasDrift,
        );

        $hasDrift = $this->renderMissingEntries(
            $tableDiff['missing'],
            $hasDrift,
        );

        if (!$hasDrift && !empty($tableDiff['matched'])) {
            $count = count($tableDiff['matched']);
            $this->info("{$count} migrations matched.");
        }

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
    }

    /**
     * Run table diff, schema comparison, and code quality
     * analysis.
     *
     * @return array<string, mixed>
     */
    private function analyze(
        MigrationDiffService $diffService,
        SchemaComparator $schemaComparator,
        MigrationParser $parser,
        CodeQualityAnalyzer $analyzer,
        string $path,
    ): array {
        $tableDiff = $diffService->computeDiff($path);
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
            $qualityIssues = $analyzer->analyzeAll(
                $definitions,
            );
        } catch (\Throwable $e) {
            $warnings[] = 'Quality analysis failed: '
                . $e->getMessage();
        }

        return [
            'table' => $tableDiff,
            'schema' => $schemaDiff,
            'quality' => $qualityIssues,
            'warnings' => $warnings,
        ];
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
     * @param string[] $stale
     */
    private function renderStaleEntries(
        array $stale,
        bool $hasDrift,
    ): bool {
        if (empty($stale)) {
            return $hasDrift;
        }

        $this->warn(
            'Stale migration records (in DB but no file):'
        );

        if (count($stale) > 3) {
            table(
                headers: ['Migration'],
                rows: array_values(array_map(
                    static fn (string $n): array => [$n],
                    $stale,
                )),
            );
        } else {
            foreach ($stale as $name) {
                $this->line("  <fg=red>- {$name}</>");
            }
        }

        return true;
    }

    /**
     * @param string[] $missing
     */
    private function renderMissingEntries(
        array $missing,
        bool $hasDrift,
    ): bool {
        if (empty($missing)) {
            return $hasDrift;
        }

        $this->warn(
            'Missing migration records '
            . '(file exists but not in DB):'
        );

        if (count($missing) > 3) {
            table(
                headers: ['Migration'],
                rows: array_values(array_map(
                    static fn (string $n): array => [$n],
                    $missing,
                )),
            );
        } else {
            foreach ($missing as $name) {
                $this->line("  <fg=yellow>? {$name}</>");
            }
        }

        return true;
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
