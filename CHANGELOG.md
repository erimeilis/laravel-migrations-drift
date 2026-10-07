# Changelog

All notable changes to `laravel-migrations-drift` are documented here.
This project adheres to [Semantic Versioning](https://semver.org).
Releases prior to `0.4.1` are recorded as Git tags only.

## [0.4.3] - 2026-10-07

### Fixed

- **`->change()` migrations are no longer silently skipped by `migrations:fix`.**
  A migration that only alters an existing column
  (`$table->text('source_id')->change()`) was parsed as a plain column *add*, so
  the analyzer saw the column present by name and classified the file as a **lost
  record** — inserting a `migrations` row so `php artisan migrate` skipped it, and
  the alteration never ran. `->change()` is now detected and tracked separately
  from added columns, and a column change is treated as *indeterminate* for
  schema-application purposes: introspection cannot confirm a change ran (the
  native type is driver-collapsed, and nullability / default / length / precision
  / signedness / timezone are not exposed by `type_name`). Such a migration is
  therefore left as a **new migration** for `migrate` to run, and a valid
  migration record is never deleted over a comparison that can't be trusted.

### Improved

- **Faithful column reproduction in consolidation.** `migrations:fix --consolidate`
  now preserves, for both added and changed columns: type length/precision
  (`string('x', 32)`, `decimal('x', 12, 4)`, single-argument `float`), temporal
  fractional-second precision (`timestamp('x', 6)`), and the `nullable`,
  `default` (including `DB::raw(...)` expressions), and `unsigned` modifiers. A
  `->change()`'s final definition is applied when replaying a create-then-change
  chain, and generated migrations import the `DB` facade only when a raw default
  needs it.

## [0.4.2] - 2026-06-25

### Removed

- **Dropped Laravel 11 support.** Laravel 11 reached end of security support on
  2026-03-12, so Composer 2.9's advisory blocking refuses to install any 11.x
  release (no further security patches will ship). Supported framework versions
  are now Laravel 12 and 13. `illuminate/*` constraints are `^12.0|^13.0` and the
  CI matrix tests Laravel 12 and 13 only. Projects still on Laravel 11 should
  pin `erimeilis/laravel-migrations-drift:^0.4.1`.

## [0.4.1] - 2026-06-25

### Fixed

- **Schema-qualified table matching.** Tables in a non-default database schema
  (e.g. Postgres `agency.principals`) are now introspected by their
  schema-qualified name, matching what migrations declare in
  `Schema::create('agency.principals', …)`. Previously the schema was discarded
  (`getTables()` was reduced with `->pluck('name')` to a bare `principals`), so
  such a table never matched its create migration. The chain of failures that
  caused:
  - the create migration was misclassified as a **bogus record** and its
    migration record was deleted even though the table existed;
  - the now-untracked table was then seen as **extra**, generating a spurious
    `drop_<table>_table` corrective migration (which itself targeted the bare,
    wrong-schema name and was a silent no-op);
  - the next `php artisan migrate` re-ran the "pending" create against the
    still-present table, failing with
    `SQLSTATE[42P07]: relation "…" already exists` — crash-looping any
    auto-migrating container.
- Foreign keys that reference a table in a non-default schema are canonicalized
  the same way, so cross-schema references (e.g. `orders.principal_id` →
  `agency.principals`) compare correctly.

Tables in the connection's default schema (`public` on Postgres, `main` on
SQLite) are unchanged — they stay bare, so existing single-schema projects are
unaffected.
