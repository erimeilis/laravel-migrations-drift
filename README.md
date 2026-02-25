# Laravel Migrations Drift

[![Tests](https://github.com/erimeilis/laravel-migrations-drift/actions/workflows/tests.yml/badge.svg)](https://github.com/erimeilis/laravel-migrations-drift/actions/workflows/tests.yml)

Detect and fix migration table drift in Laravel applications. When migration files get renamed, consolidated, or reorganized, the `migrations` table falls out of sync and `php artisan migrate` breaks. This package provides three artisan commands to detect, sync, and rename migrations safely.

## Problem

You consolidated migrations into clean, sequentially-numbered files. Production still has the old names in the `migrations` table. Running `migrate` tries to re-create existing tables and fails. Manual SQL fixes are fragile, non-portable, and not part of your deployed codebase.

This package solves it with diff-based sync, automatic backups, dry-run defaults, and full schema comparison -- all through standard artisan commands using Laravel's DB connection.

## Installation

```bash
composer require erimeilis/laravel-migrations-drift --dev
```

The service provider is auto-discovered. To publish the config file:

```bash
php artisan vendor:publish --tag=migration-drift-config
```

**Requirements:** PHP 8.2+, Laravel 11 or 12.

## Commands

### `migrations:detect` -- Detect drift

Read-only. Compares migration filenames on disk against records in the `migrations` table.

```bash
# Fast mode: filename comparison only
php artisan migrations:detect

# Full mode: creates temp DB, runs all migrations, compares schemas
php artisan migrations:detect --full
```

| Flag | Description |
|------|-------------|
| _(none)_ | Compare filenames vs DB records |
| `--full` | Full schema comparison (requires CREATE DATABASE permission) |
| `--path=` | Override migrations directory |

**Output (fast mode):**

```
Stale migration records (in DB but no file):
  - 0001_01_01_000000_create_users_table
  - 0001_01_01_000001_create_cache_table
Missing migration records (file exists but not in DB):
  ? 2026_02_23_000001_create_users_table
  ? 2026_02_23_000002_create_cache_table

DRIFT DETECTED
```

**Output (full mode):**

```
Running full schema comparison...
Tables missing in current DB:
  - telescope_entries
Column differences in 'users':
  ~ email type: varchar(191) -> varchar(255)

DRIFT DETECTED
```

**Exit codes:** `0` = no drift, `1` = drift detected. Use in CI pipelines.

---

### `migrations:sync` -- Sync the migrations table

Realigns the `migrations` table to match current migration filenames. Diff-based: only removes stale records and inserts missing ones. Matched records are untouched.

```bash
# Dry-run (default): shows what would change
php artisan migrations:sync

# Apply changes (creates backup first)
php artisan migrations:sync --force

# Restore from last backup
php artisan migrations:sync --restore
```

| Flag | Description |
|------|-------------|
| _(none)_ | Dry-run: show diff, change nothing |
| `--force` | Back up migrations table, then apply diff in a transaction |
| `--restore` | Restore migrations table from the last backup JSON |
| `--path=` | Override migrations directory |

**Dry-run output:**

```
- 0001_01_01_000000_create_users_table
- 0001_01_01_000001_create_cache_table
+ 2026_02_23_000001_create_users_table
+ 2026_02_23_000002_create_cache_table
58 migrations matched.
DRY RUN -- no changes made. Use --force to apply.
```

**Force output:**

```
Backup saved to: /app/storage/migrations-drift/backup-2026-02-25_143022.json
Removed 2 stale, added 2 missing records.
```

**Idempotent:** Running again after sync outputs `Already in sync. 60 migrations matched.`

**Backup:** Before applying changes, the current migrations table is dumped to `storage/migrations-drift/backup-{timestamp}.json`. The last 5 backups are kept; older ones are rotated out.

---

### `migrations:rename` -- Rename migration files

Renames migration files to use a target date prefix with sequential numbering. Useful for consolidating migrations under a single date.

```bash
# Dry-run (default): shows what would rename
php artisan migrations:rename

# Apply renames with specific date
php artisan migrations:rename --force --date=2026-02-25
```

| Flag | Description |
|------|-------------|
| _(none)_ | Dry-run: show renames, change nothing |
| `--force` | Apply file renames |
| `--date=YYYY-MM-DD` | Target date prefix (default: today) |
| `--path=` | Override migrations directory |

**Output:**

```
  0001_01_01_000000_create_users_table.php -> 2026_02_25_000001_create_users_table.php
  0001_01_01_000001_create_cache_table.php -> 2026_02_25_000002_create_cache_table.php
Would rename 2 files, 0 already correct.
DRY RUN -- use --force to apply.
```

Files that already match the target pattern are skipped.

## Configuration

```php
// config/migration-drift.php

return [
    // Where to store migrations table backups
    'backup_path' => storage_path('migrations-drift'),

    // Maximum number of backup files to keep
    'max_backups' => 5,

    // Path to migration files
    'migrations_path' => database_path('migrations'),
];
```

## Production Deployment Workflow

```bash
# 1. Deploy new code (with renamed migration files)

# 2. Preview what sync will do (dry-run, no changes)
php artisan migrations:sync

# 3. Review the diff output, then apply (creates backup first)
php artisan migrations:sync --force

# 4. Run any genuinely new migrations
php artisan migrate --force

# 5. Verify no drift remains
php artisan migrations:detect
```

If something goes wrong after step 3:

```bash
php artisan migrations:sync --restore
```

## Safety Matrix

| Scenario | Behavior |
|----------|----------|
| `sync` without `--force` | Dry-run. Shows diff, changes nothing. |
| `sync --force` | Backs up migrations table, then applies diff in a DB transaction. |
| `sync --force` run again | "Already in sync" -- idempotent, no changes. |
| `sync` against wrong database | Shows diff for review. No changes until `--force`. |
| Sync broke something | `sync --restore` loads the last backup. |
| `detect` | Read-only. Compares filenames only. |
| `detect --full` | Creates and drops a temp database. Main database is read-only. |
| `rename` without `--force` | Dry-run. Shows what would rename, changes nothing. |
| `rename --force` | Applies file renames. Skips files already matching target pattern. |

## Testing

```bash
vendor/bin/phpunit
```

## License

MIT
