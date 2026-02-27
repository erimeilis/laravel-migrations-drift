<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

class MigrationState
{
    /**
     * @param string $migrationName Migration filename (without .php)
     * @param MigrationStatus $status Classification result
     * @param MigrationDefinition|null $definition Parsed definition (null when file is missing)
     * @param string|null $tableName Primary table affected (from definition or heuristic)
     * @param bool $partialAnalysis True when analysis was incomplete (e.g. raw SQL mixed with Blueprint)
     * @param string[] $warnings Human-readable warnings about this migration
     */
    public function __construct(
        public readonly string $migrationName,
        public readonly MigrationStatus $status,
        public readonly ?MigrationDefinition $definition,
        public readonly ?string $tableName,
        public readonly bool $partialAnalysis = false,
        public readonly array $warnings = [],
    ) {}
}
