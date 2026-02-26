# Laravel Migrations Drift

**Keep your Laravel database in perfect sync with your migrations**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/erimeilis/laravel-migrations-drift.svg)](https://packagist.org/packages/erimeilis/laravel-migrations-drift)
[![Total Downloads](https://img.shields.io/packagist/dt/erimeilis/laravel-migrations-drift.svg)](https://packagist.org/packages/erimeilis/laravel-migrations-drift)
[![Tests](https://github.com/erimeilis/laravel-migrations-drift/actions/workflows/tests.yml/badge.svg)](https://github.com/erimeilis/laravel-migrations-drift/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/erimeilis/laravel-migrations-drift.svg)](https://packagist.org/packages/erimeilis/laravel-migrations-drift)
[![Laravel 11+](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20.svg)](https://laravel.com)
[![License: MIT](https://img.shields.io/packagist/l/erimeilis/laravel-migrations-drift.svg)](https://opensource.org/licenses/MIT)

> 🔍 Detect every kind of schema drift — filenames, columns, indexes, foreign keys
> 🔧 Fix it all — sync records, generate corrective migrations, consolidate chains
> 🛡️ Safe by default — dry-run mode, automatic backups, transactional operations
> 🎯 Interactive prompts — powered by `laravel/prompts` for a beautiful CLI experience

---

## ✨ Features

### 🔍 Comprehensive Detection

- ✅ **Filename Drift** — Finds stale and missing records in the `migrations` table
- 🗄️ **Schema Drift** — Compares actual DB schema against what migrations produce (tables, columns, types, indexes, FKs)
- 🔬 **Code Quality** — AST-parses migrations to detect missing `down()` methods, empty migrations, and more
- 📊 **JSON Output** — Machine-readable output for CI pipelines with exit code `0`/`1`

### 🔧 Three Fix Modes

- 📋 **Table Sync** — Realigns migration records to match files on disk
- 🏗️ **Schema Repair** — Generates corrective migration files for missing tables, columns, indexes, and FKs
- 🔗 **Consolidation** — Merges redundant per-table migration chains into single clean migrations via AST replay

### 🛡️ Safety First

- 🔒 **Dry-Run Default** — Every command shows what would change before doing anything
- 💾 **Automatic Backups** — JSON snapshots before any destructive operation
- ♻️ **One-Command Restore** — Roll back to last backup instantly
- 🔄 **Transactional Operations** — DB changes wrapped in transactions with rollback on failure
- 📁 **Atomic File Operations** — Archive-based consolidation with full rollback on error

### 🎯 Developer Experience

- 🎨 **Interactive Prompts** — Beautiful multi-select, confirmations, and spinners via `laravel/prompts`
- 🔌 **Multi-Connection** — Works with any database connection, not just the default
- ⚡ **CI-Ready** — Non-interactive mode with JSON output for automated pipelines
- 🧪 **203 Tests** — Comprehensive test suite with PHPStan level 6 static analysis

---

## 📦 Installation

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

## 🚀 Quick Start

```bash
# 1. Detect all drift in one pass
php artisan migrations:detect

# 2. Fix interactively — pick what to repair
php artisan migrations:fix

# 3. Or fix specific issues directly
php artisan migrations:fix --table --force       # sync migration records
php artisan migrations:fix --schema --force      # generate corrective migrations
php artisan migrations:fix --consolidate --force # merge redundant migrations
```

---

## 📚 Commands

### 🔍 `migrations:detect`

Performs a comprehensive three-layer analysis in one pass:

1. **Filename drift** — compares migration files on disk against records in the `migrations` table
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
Stale migration records (in DB but no file):
  - 0001_01_01_000000_create_users_table
  - 0001_01_01_000001_create_cache_table

Missing migration records (file exists but not in DB):
  ? 2026_02_25_000001_create_users_table
  ? 2026_02_25_000002_create_cache_table

Schema comparison: no differences found.

DRIFT DETECTED
```

When schema differences exist, the output includes:

```
Tables missing in current DB:
  - telescope_entries

Column differences in 'users':
  ~ email type: varchar(191) -> varchar(255)
  ~ remember_token nullable: not null -> nullable

Index differences in 'posts':
  - [slug] (unique, missing)

Foreign key differences in 'comments':
  - [user_id] -> users(id) (missing)
```

> **Note:** Schema comparison requires `CREATE DATABASE` permission to create and drop a temporary database. If unavailable, it degrades gracefully with a warning.

---

### 🔧 `migrations:fix`

The all-in-one repair command with three fix modes that can be combined.

#### 📋 Table Sync (`--table`)

Syncs the `migrations` table to match current files. Removes stale records, inserts missing ones. Matched records are untouched.

```bash
php artisan migrations:fix --table              # dry-run: shows diff
php artisan migrations:fix --table --force       # apply (creates backup first)
```

**Dry-run output:**

```
Stale records (in DB, no matching file):
  - 0001_01_01_000000_create_users_table
  - 0001_01_01_000001_create_cache_table
Missing records (file exists, not in DB):
  + 2026_02_25_000001_create_users_table
  + 2026_02_25_000002_create_cache_table
  58 migration(s) already matched.

DRY RUN — use --force to apply changes.
```

**Idempotent:** Running again after sync outputs `Already in sync: 60 migration(s) matched.`

#### 🏗️ Schema Repair (`--schema`)

Compares your actual database schema against what migrations produce, then generates corrective migration files for any differences.

```bash
php artisan migrations:fix --schema             # dry-run: shows planned actions
php artisan migrations:fix --schema --force     # generates migration files
```

Generated migrations include proper `up()` and `down()` methods for:
- ✅ Missing/extra tables (CREATE TABLE / DROP TABLE)
- ✅ Missing/extra columns
- ✅ Missing indexes and foreign keys

#### 🔗 Consolidation (`--consolidate`)

Parses migration ASTs with [nikic/php-parser](https://github.com/nikic/PHP-Parser) to find tables with multiple migrations that can be merged into a single clean migration.

```bash
php artisan migrations:fix --consolidate        # dry-run: shows candidates
php artisan migrations:fix --consolidate --force # consolidate selected tables
```

Handles column additions, drops, index changes, and foreign keys. Multi-table migrations are automatically skipped from consolidation to preserve safety.

#### 🎨 Interactive Mode

Without flags, the command presents a beautiful multi-select prompt:

```
 What would you like to fix?
 > [x] Migrations table — sync records to match files
 > [ ] Schema drift — generate corrective migrations
 > [ ] Consolidate — merge redundant migrations per table
```

#### ♻️ Restore from Backup

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

### ✏️ `migrations:rename`

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

## ⚙️ Configuration

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

## 🚢 Production Workflow

```bash
# 1. Deploy new code (with reorganized migration files)

# 2. Preview what sync will do (dry-run, safe)
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

---

## 🤖 CI Integration

Add drift detection to your CI pipeline:

```yaml
- name: Check migration drift
  run: php artisan migrations:detect --json
```

The command exits with code `1` when drift is detected, failing the pipeline. JSON output includes structured data for `table_drift`, `schema_drift`, and `quality_issues`.

---

## 🛡️ Safety Matrix

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

---

## 🔍 How It Works

### 🏗️ Architecture

```
migrations:detect
       |
       v
 +-----------------+     +------------------+     +-------------------+
 | MigrationDiff   |     | SchemaComparator |     | MigrationParser   |
 | Service         |     |                  |     | + CodeQuality     |
 | (filename diff) |     | (temp DB diff)   |     | Analyzer (AST)    |
 +-----------------+     +------------------+     +-------------------+
                                |
                         Creates temp DB,
                         runs migrations,
                         introspects both
                                |
                                v
                     Schema::getColumns()
                     Schema::getIndexes()
                     Schema::getForeignKeys()
```

```
migrations:fix
       |
       +-- --table ------> MigrationDiffService --> DELETE/INSERT in transaction
       |
       +-- --schema -----> SchemaComparator --> MigrationGenerator --> .php files
       |
       +-- --consolidate -> MigrationParser --> AST replay --> single migration
```

### 🔧 Key Components

- **SchemaComparator** — Creates a temporary database, runs all migrations on it, then diffs both schemas column-by-column
- **MigrationParser** + **MigrationVisitor** — Uses [nikic/php-parser](https://github.com/nikic/PHP-Parser) to extract structured column, index, and FK data from migration ASTs
- **TypeMapper** — Bidirectional mapping between SQL types and Laravel Blueprint methods
- **MigrationGenerator** — Generates properly formatted migration files with `up()` and `down()` from schema diff actions
- **ConsolidationService** — Replays migration operations in order to produce a single equivalent migration per table
- **BackupService** — JSON snapshots of the migrations table with automatic rotation

---

## ⚠️ Limitations

- **Schema comparison** requires `CREATE DATABASE` permission (gracefully skipped on SQLite or restricted permissions)
- **Consolidation** skips multi-table migrations to preserve safety
- **Type normalization** covers common MySQL/PostgreSQL/SQLite types — exotic custom types may need manual review
- **Column modifiers** (nullable, default) are detected in schema comparison but not fully replayed during consolidation

---

## 🧪 Testing

```bash
# Run the full test suite (203 tests, 452 assertions)
vendor/bin/phpunit

# Static analysis (PHPStan level 6 with Larastan)
vendor/bin/phpstan analyse
```

Tested across:
- PHP 8.2, 8.3, 8.4
- Laravel 11.x, 12.x

---

## 📄 License

MIT License — see [LICENSE](LICENSE) file

---

**Made with :blue_heart::yellow_heart: for the Laravel community**
