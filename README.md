# Laravel Migrations Drift

**Keep your Laravel database in perfect sync with your migrations**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/erimeilis/laravel-migrations-drift.svg?style=flat-square)](https://packagist.org/packages/erimeilis/laravel-migrations-drift)
[![Total Downloads](https://img.shields.io/packagist/dt/erimeilis/laravel-migrations-drift.svg?style=flat-square)](https://packagist.org/packages/erimeilis/laravel-migrations-drift)
[![Tests](https://github.com/erimeilis/laravel-migrations-drift/actions/workflows/tests.yml/badge.svg)](https://github.com/erimeilis/laravel-migrations-drift/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/erimeilis/laravel-migrations-drift.svg?style=flat-square)](https://packagist.org/packages/erimeilis/laravel-migrations-drift)
[![Laravel 11+](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20.svg?style=flat-square)](https://laravel.com)
[![License: MIT](https://img.shields.io/packagist/l/erimeilis/laravel-migrations-drift.svg?style=flat-square)](https://opensource.org/licenses/MIT)

> :mag: Detect every kind of schema drift — filenames, columns, indexes, foreign keys
> :wrench: Fix it all — schema-aware record sync, corrective migrations, consolidation
> :shield: Safe by default — dry-run mode, automatic backups, transactional operations
> :dart: Interactive prompts — powered by `laravel/prompts` for a beautiful CLI experience

---

## :sparkles: Features

### :mag: Schema-Aware Detection

- :white_check_mark: **6-State Classification** — Every migration is classified as OK, Bogus Record, Missing File, Orphan Record, Lost Record, or New Migration
- :file_cabinet: **Schema Verification** — Checks actual DB schema to determine if migrations truly ran, not just if records exist
- :microscope: **Code Quality** — AST-parses migrations to detect missing `down()` methods, empty migrations, and more
- :bar_chart: **JSON Output** — Machine-readable output for CI pipelines with exit code `0`/`1`

### :wrench: Unified Fix Command

- :brain: **Schema-Aware Sync** — Cross-references files, DB records, and actual schema to make correct decisions
- :construction: **Schema Repair** — Generates corrective migration files for missing tables, columns, indexes, and FKs
- :link: **Consolidation** — Merges redundant per-table migration chains into single clean migrations via AST replay

### :shield: Safety First

- :lock: **Dry-Run Default** — Every command shows what would change before doing anything
- :floppy_disk: **Automatic Backups** — JSON snapshots before any destructive operation
- :recycle: **One-Command Restore** — Roll back to last backup instantly
- :arrows_counterclockwise: **Transactional Operations** — DB changes wrapped in transactions with rollback on failure
- :file_folder: **Atomic File Operations** — Archive-based consolidation with full rollback on error

### :dart: Developer Experience

- :art: **Interactive Prompts** — Beautiful multi-select, confirmations, and spinners via `laravel/prompts`
- :electric_plug: **Multi-Connection** — Works with any database connection, not just the default
- :zap: **CI-Ready** — Non-interactive mode with JSON output for automated pipelines
- :test_tube: **230 Tests** — Comprehensive test suite with PHPStan level 6 static analysis

---

## :package: Installation

```bash
composer require erimeilis/laravel-migrations-drift --dev
```

The service provider is auto-discovered. To publish the config:

```bash
php artisan vendor:publish --tag=migration-drift-config
```

**Requirements:**
- PHP 8.2 or higher
- Laravel 11.x or 12.x
- `CREATE DATABASE` permission for schema comparison (optional — gracefully skipped if unavailable)

---

## :rocket: Quick Start

```bash
# 1. Detect all drift in one pass
php artisan migrations:detect

# 2. Preview what fix would do (dry-run, safe)
php artisan migrations:fix

# 3. Apply fixes (creates backup first)
php artisan migrations:fix --force

# 4. Run any genuinely new migrations
php artisan migrate --force

# 5. Consolidate redundant migration chains
php artisan migrations:fix --consolidate --force
```

---

## :books: Commands

### :mag: `migrations:detect`

Performs a comprehensive three-layer analysis in one pass:

1. **State classification** — classifies every migration into one of 6 states by cross-referencing files, DB records, and actual schema
2. **Schema drift** — creates a temp database, runs all migrations, diffs the resulting schema against your actual database
3. **Code quality** — parses migration ASTs to detect structural issues

```bash
php artisan migrations:detect
php artisan migrations:detect --json              # machine-readable output
php artisan migrations:detect --connection=mysql2  # specific connection
```

| Flag | Description |
|------|-------------|
| `--json` | Output results as JSON |
| `--connection=` | Database connection to use |
| `--path=` | Override migrations directory |

**Exit codes:** `0` = no drift, `1` = drift detected.

**Example output:**

```
3 migration(s) OK.
1 new migration(s) pending (will run with `php artisan migrate`).

Bogus records (registered but never ran):
  2026_01_15_000001_create_widgets_table

Lost records (ran but not registered):
  2026_01_01_000003_add_bio_to_users_table

Schema comparison: no differences found.

DRIFT DETECTED
```

#### Migration States

| State | Meaning | Action taken by `fix --force` |
|-------|---------|-------------------------------|
| **OK** | Record + file + schema all match | None |
| **Bogus Record** | Record + file exist, but schema says it never ran | Delete record |
| **Missing File** | Record + schema exist, but file is gone | Warn (file can't be auto-regenerated) |
| **Orphan Record** | Record exists, no file, no schema evidence | Delete record |
| **Lost Record** | File + schema exist, but no DB record | Insert record |
| **New Migration** | File exists, no record, not in schema yet | Left alone — `php artisan migrate` will run it |

> **Note:** Schema comparison requires `CREATE DATABASE` permission to create and drop a temporary database. If unavailable, it degrades gracefully with a warning.

---

### :wrench: `migrations:fix`

The unified repair command. Analyzes all migrations against the actual database schema, then fixes bookkeeping and generates corrective migrations.

```bash
php artisan migrations:fix                  # dry-run: shows classified states
php artisan migrations:fix --force          # apply fixes (creates backup first)
php artisan migrations:fix --consolidate    # dry-run: shows consolidation candidates
php artisan migrations:fix --restore        # restore from latest backup
```

#### How It Works

1. **Classify** — Every migration is classified into one of 6 states (see table above)
2. **Fix bookkeeping** — In a single transaction: delete bogus/orphan records, insert lost records
3. **Schema repair** — Compare actual schema against what migrations produce, generate corrective migrations for any remaining drift
4. **New migrations** are never touched — they're left for `php artisan migrate`

**Dry-run output:**

```
3 migration(s) OK.
1 new migration(s) pending (will run with `php artisan migrate`).

Bogus record (registered but never ran):
  2026_01_15_000001_create_widgets_table

Lost record (ran but not registered):
  2026_01_01_000003_add_bio_to_users_table

DRY RUN — use --force to apply changes.
```

**After `--force`:**

```
Backup created: storage/migrations-drift/backup-2026-02-27-143022.json

Bookkeeping fixed:
  Removed 1 bogus record(s).
  Inserted 1 lost record(s).

Schema is in sync — no corrective migrations needed.
```

**Idempotent:** Running again after fix outputs `Everything in sync — no fixes needed.`

#### :link: Consolidation (`--consolidate`)

Parses migration ASTs with [nikic/php-parser](https://github.com/nikic/PHP-Parser) to find tables with multiple migrations that can be merged into a single clean migration.

```bash
php artisan migrations:fix --consolidate        # dry-run: shows candidates
php artisan migrations:fix --consolidate --force # consolidate selected tables
```

Handles column additions, drops, index changes, and foreign keys. Multi-table migrations are automatically skipped from consolidation to preserve safety.

#### :recycle: Restore from Backup

```bash
php artisan migrations:fix --restore
```

| Flag | Description |
|------|-------------|
| `--force` | Apply changes (default is dry-run) |
| `--restore` | Restore migrations table from latest backup |
| `--consolidate` | Consolidate redundant migrations per table |
| `--connection=` | Database connection to use |
| `--path=` | Override migrations directory |

---

### :pencil2: `migrations:rename`

Renames migration files to use a target date prefix with sequential numbering. Updates both files and the migrations table atomically — DB records first (transactional), files second, with automatic rollback on failure.

```bash
php artisan migrations:rename                           # dry-run
php artisan migrations:rename --force --date=2026-02-25  # apply
```

| Flag | Description |
|------|-------------|
| `--force` | Apply renames |
| `--date=YYYY-MM-DD` | Target date prefix (default: today) |
| `--connection=` | Database connection to use |
| `--path=` | Override migrations directory |

**Example output:**

```
 Current                                    | New
 0001_01_01_000000_create_users_table.php   | 2026_02_25_000001_create_users_table.php
 0001_01_01_000001_create_cache_table.php   | 2026_02_25_000002_create_cache_table.php

Would rename 2 files.
DRY RUN — use --force to apply.
```

Files that already match the target pattern are skipped.

---

## :gear: Configuration

```bash
php artisan vendor:publish --tag=migration-drift-config
```

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

---

## :ship: Production Workflow

```bash
# 1. Deploy new code

# 2. Preview what fix will do (dry-run, safe)
php artisan migrations:fix

# 3. Review the output, then apply (creates backup first)
php artisan migrations:fix --force

# 4. Run any genuinely new migrations
php artisan migrate --force

# 5. Verify no drift remains
php artisan migrations:detect
```

If something goes wrong after step 3:

```bash
php artisan migrations:fix --restore
```

---

## :robot: CI Integration

Add drift detection to your CI pipeline:

```yaml
- name: Check migration drift
  run: php artisan migrations:detect --json
```

The command exits with code `1` when drift is detected, failing the pipeline. JSON output includes structured `migration_states` with per-migration classification, `schema_drift`, and `quality_issues`.

---

## :shield: Safety Matrix

| Scenario | Behavior |
|----------|----------|
| Any fix without `--force` | Dry-run. Shows classified states and planned actions, changes nothing. |
| `--force` on fix | Backs up migrations table, then fixes bookkeeping in a transaction + generates corrective migrations. |
| `--force` run again | "Everything in sync" — idempotent, no changes. |
| Fix broke something | `--restore` loads the last backup. |
| `detect` | Read-only. Main database is never modified. |
| Schema comparison | Creates and drops a temp database. Main database is read-only. |
| `rename --force` | Updates DB records first (transactional), then renames files. Rolls back DB on file failure. |
| Consolidation | Archives original files atomically. Rolls back on any failure. |
| New migrations detected | Left alone. `php artisan migrate` handles them normally. |

Backups are stored as JSON in `storage/migrations-drift/`. The last 5 are kept by default.

---

## :mag: How It Works

### :brain: 6-State Classification

The core innovation: every migration is classified by cross-referencing three data sources:

```
                    Has DB Record?
                   /              \
                 YES               NO
                /                    \
          Has File?              Has File?
         /        \             /        \
       YES         NO        YES         NO
        |           |          |          (impossible)
  Schema says   Schema has   Schema says
   applied?     evidence?     applied?
    /    \       /    \       /    \
  YES    NO    YES    NO    YES    NO
   |      |     |      |     |      |
  OK   BOGUS  MISS-  ORPHAN LOST   NEW
       RECORD  ING   RECORD RECORD MIGR.
               FILE
```

### :construction: Architecture

```
migrations:detect / migrations:fix
       |
       v
 +------------------------+
 | MigrationStateAnalyzer |  <-- Cross-references all 3 sources
 +------------------------+
       |         |         |
       v         v         v
 +---------+ +--------+ +---------+
 | Diff    | | Parser | | Schema  |
 | Service | | (AST)  | | Intro-  |
 | (files  | |        | | spector |
 | vs DB)  | |        | | (actual |
 +---------+ +--------+ | schema) |
                         +---------+

migrations:fix --force
       |
       +-- fixBookkeeping --> DELETE/INSERT in transaction
       |
       +-- fixSchemaDrift --> SchemaComparator --> MigrationGenerator --> .php files
       |
       +-- --consolidate --> MigrationParser --> AST replay --> single migration
```

### :wrench: Key Components

- **MigrationStateAnalyzer** — The brain: classifies every migration into one of 6 states using files, DB records, and actual schema
- **SchemaComparator** — Creates a temporary database, runs all migrations on it, then diffs both schemas column-by-column
- **MigrationParser** + **MigrationVisitor** — Uses [nikic/php-parser](https://github.com/nikic/PHP-Parser) to extract structured column, index, and FK data from migration ASTs
- **SchemaIntrospector** — Queries actual database schema via INFORMATION_SCHEMA
- **TypeMapper** — Bidirectional mapping between SQL types and Laravel Blueprint methods
- **MigrationGenerator** — Generates properly formatted migration files with `up()` and `down()` from schema diff actions
- **ConsolidationService** — Replays migration operations in order to produce a single equivalent migration per table
- **BackupService** — JSON snapshots of the migrations table with automatic rotation

---

## :warning: Limitations

- **Schema comparison** requires `CREATE DATABASE` permission (gracefully skipped on SQLite or restricted permissions)
- **Consolidation** skips multi-table migrations to preserve safety
- **Type normalization** covers common MySQL/PostgreSQL/SQLite types — exotic custom types may need manual review
- **Column modifiers** (nullable, default) are detected in schema comparison but not fully replayed during consolidation
- **Partial analysis** — Migrations with raw SQL or conditional logic are flagged with warnings; schema checks cover only the parseable Blueprint parts

---

## :test_tube: Testing

```bash
# Run the full test suite (230 tests, 497 assertions)
vendor/bin/phpunit

# Static analysis (PHPStan level 6 with Larastan)
vendor/bin/phpstan analyse
```

Tested across:
- PHP 8.2, 8.3, 8.4, 8.5
- Laravel 11.x, 12.x

---

## :pray: Acknowledgements

- [roslov/laravel-migration-checker](https://github.com/roslov/laravel-migration-checker) — inspiration for this package

---

## :page_facing_up: License

MIT License — see [LICENSE](LICENSE) file

---

**Made with :blue_heart::yellow_heart: for the Laravel community**
