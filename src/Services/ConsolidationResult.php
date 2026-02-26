<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

class ConsolidationResult
{
    /**
     * @param string[] $originalMigrations Filenames that were merged
     * @param string[] $skippedMigrations Filenames skipped (conditional, multi-table, data)
     * @param string[] $warnings Anything requiring manual review
     */
    public function __construct(
        public readonly string $tableName,
        public readonly string $generatedFilePath,
        public readonly array $originalMigrations,
        public readonly array $skippedMigrations = [],
        public readonly array $warnings = [],
    ) {}
}
