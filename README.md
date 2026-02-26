# Laravel Migrations Drift

[![Tests](https://github.com/erimeilis/laravel-migrations-drift/actions/workflows/tests.yml/badge.svg)](https://github.com/erimeilis/laravel-migrations-drift/actions/workflows/tests.yml)

Detect and repair schema drift in Laravel applications. Compares your actual database against what your migrations describe, syncs the migrations table, generates corrective migrations, and consolidates redundant migration chains.

## The Problem

Over time, Laravel databases drift from their migrations. Files get renamed or consolidated, manual schema changes are made in production, and `php artisan migrate` starts failing because the `migrations` table no longer matches reality.

This package gives you three artisan commands to detect every kind of drift and fix it — all through standard artisan commands with dry-run defaults, automatic backups, and interactive prompts.

## Installation

```bash
composer require erimeilis/laravel-migrations-drift --dev
```

The service provider is auto-discovered. To publish the config:

```bash
php artisan vendor:publish --tag=migration-drift-config
```

**Requirements:** PHP 8.2+, Laravel 11 or 12.

## Quick Start

```bash
# 1. Detect all drift (filename + schema comparison + code quality)
php artisan migrations:detect

# 2. Fix what's broken
php artisan migrations:fix              # interactive: pick what to fix
php artisan migrations:fix --table      # sync migrations table records
php artisan migrations:fix --schema     # generate corrective migrations
php artisan migrations:fix --consolidate # merge redundant migrations
```

## Commands

### `migrations:detect`

Performs a comprehensive analysis in one pass:

1. **Filename drift** — compares migration files on disk against records in the `migrations` table
2. **Schema drift** — creates a temp database, runs all migrations, and diffs the resulting schema against your actual database (tables, columns, indexes, foreign keys)
3. **Code quality** — parses migration ASTs to detect issues (missing down methods, empty migrations, etc.)

```bash
php artisan migrations:detect
php artisan migrations:detect --json          # machine-readable output
php artisan migrations:detect --connection=mysql2
```

| Flag | Description |
|------|-------------|
| `--json` | Output results as JSON |
| `--connection=` | Database connection to use |
| `--path=` | Override migrations directory |

**Exit codes:** `0` = no drift, `1` = drift detected. Use in CI pipelines.

**Example output:**

```
Stale migration records (in DB but no file):
  - 0001_01_01_000000_create_users_table

Missing migration records (file exists but not in DB):
  ? 2026_02_25_000001_create_users_table

Schema comparison: no differences found.

DRIFT DETECTED
```

When schema differences exist, the output includes details on missing/extra tables, column type mismatches, nullable mismatches, default value mismatches, index differences, and foreign key differences.

> **Note:** Schema comparison requires `CREATE DATABASE` permission to create and drop a temporary database. If unavailable, it is skipped gracefully with a warning.

---

### `migrations:fix`

The all-in-one repair command with three fix modes that can be combined:

#### Table sync (`--table`)

Syncs the `migrations` table to match current files. Removes stale records, inserts missing ones. Matched records are untouched.

```bash
php artisan migrations:fix --table              # dry-run
php artisan migrations:fix --table --force       # apply (creates backup first)
```

#### Schema repair (`--schema`)

Compares your actual database schema against what migrations produce, then generates corrective migration files for any differences.

```bash
php artisan migrations:fix --schema             # dry-run: shows planned actions
php artisan migrations:fix --schema --force     # generates migration files
```

Generated migrations include proper `up()` and `down()` methods for:
- Missing/extra tables (CREATE TABLE / DROP TABLE)
- Missing/extra columns
- Missing indexes and foreign keys

#### Consolidation (`--consolidate`)

Parses migration ASTs to find tables with multiple migrations that can be merged into a single clean migration. Handles column additions, drops, index changes, and foreign keys.

```bash
php artisan migrations:fix --consolidate        # dry-run: shows candidates
php artisan migrations:fix --consolidate --force # consolidate selected tables
```

#### Interactive mode

Without flags, the command presents an interactive multi-select:

```bash
php artisan migrations:fix
# > What would you like to fix?
# > [ ] Migrations table - sync records to match files
# > [ ] Schema drift - generate corrective migrations
# > [ ] Consolidate - merge redundant migrations per table
```

#### Restore from backup

```bash
php artisan migrations:fix --restore
```

| Flag | Description |
|------|-------------|
| `--table` | Sync migration table records to match files |
| `--schema` | Generate corrective migrations for schema drift |
| `--consolidate` | Consolidate redundant migrations per table |
| `--force` | Apply changes (default is dry-run) |
| `--restore` | Restore migrations table from latest backup |
| `--connection=` | Database connection to use |
| `--path=` | Override migrations directory |

---

### `migrations:rename`

Renames migration files to use a target date prefix with sequential numbering. Updates both files and the migrations table atomically (DB first, files second, with rollback on failure).

```bash
php artisan migrations:rename                          # dry-run
php artisan migrations:rename --force --date=2026-02-25 # apply
```

| Flag | Description |
|------|-------------|
| `--force` | Apply renames |
| `--date=YYYY-MM-DD` | Target date prefix (default: today) |
| `--connection=` | Database connection to use |
| `--path=` | Override migrations directory |

**Example output:**

```
 Current                                       | New
 0001_01_01_000000_create_users_table.php      | 2026_02_25_000001_create_users_table.php
 0001_01_01_000001_create_cache_table.php      | 2026_02_25_000002_create_cache_table.php

Would rename 2 files.
DRY RUN - use --force to apply.
```

## Configuration

```php
// config/migration-drift.php

return [
    // Where to store migrations table backups
    'backup_path' => storage_path('migrations-drift'),

    // Maximum number of backup files to keep (oldest rotated out)
    'max_backups' => 5,

    // Path to migration files
    'migrations_path' => database_path('migrations'),

    // Database connection (null = default connection)
    'connection' => null,
];
```

## Production Workflow

```bash
# 1. Deploy new code (with reorganized migration files)

# 2. Preview what sync will do (dry-run)
php artisan migrations:fix --table

# 3. Review the diff, then apply (creates backup first)
php artisan migrations:fix --table --force

# 4. Run any genuinely new migrations
php artisan migrate --force

# 5. Verify no drift remains
php artisan migrations:detect
```

If something goes wrong after step 3:

```bash
php artisan migrations:fix --restore
```

## CI Integration

Add drift detection to your CI pipeline:

```yaml
- name: Check migration drift
  run: php artisan migrations:detect --json
```

The command exits with code `1` when drift is detected, failing the pipeline.

## Safety

| Scenario | Behavior |
|----------|----------|
| Any fix without `--force` | Dry-run. Shows what would change, changes nothing. |
| `--force` on table sync | Backs up migrations table to JSON, then applies diff in a DB transaction. |
| `--force` run again | "Already in sync" — idempotent, no changes. |
| Fix broke something | `--restore` loads the last backup. |
| `detect` | Read-only. Main database is never modified. |
| Schema comparison | Creates and drops a temp database. Main database is read-only. |
| `rename --force` | Updates DB records first (transactional), then renames files. Rolls back DB on file failure. |
| Consolidation | Archives original files atomically. Rolls back on any failure. |

Backups are stored as JSON in `storage/migrations-drift/`. The last 5 are kept by default.

## How It Works

### Detection

1. **Filename comparison** via `MigrationDiffService` — diffs file basenames against `migrations` table records
2. **Schema comparison** via `SchemaComparator` — creates a temporary database, runs `php artisan migrate` on it, then introspects both schemas using Laravel's `Schema::getColumns()`, `Schema::getIndexes()`, and `Schema::getForeignKeys()` to produce a structured diff
3. **AST analysis** via `MigrationParser` + `CodeQualityAnalyzer` — parses migrations with [nikic/php-parser](https://github.com/nikic/PHP-Parser) to detect code quality issues

### Repair

- **Table sync** — transactional DELETE/INSERT on the migrations table
- **Schema fix** — generates Laravel migration files with `$table->` calls derived from introspected schema info via `TypeMapper`
- **Consolidation** — replays migration operations (columns, indexes, FKs) in order via AST parsing to produce a single equivalent migration per table

## Testing

```bash
# Run the test suite (203 tests, 452 assertions)
vendor/bin/phpunit

# Static analysis
vendor/bin/phpstan analyse
```

## License

MIT
