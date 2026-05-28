<?php

declare(strict_types=1);

return [
    'backup_path' => storage_path('migrations-drift'),
    'max_backups' => 5,
    'migrations_path' => database_path('migrations'),
    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Ignored tables
    |--------------------------------------------------------------------------
    |
    | Tables listed here are excluded from drift analysis. The drift package
    | otherwise assumes every table was created by a migration — if it finds
    | one that wasn't, it generates a DROP migration for it. That's wrong for
    | tables the application creates at runtime (partition children, sharded
    | tables) or that an extension provides (PostGIS, TimescaleDB, etc.).
    |
    | Each entry is either:
    |   - a literal table name: 'spatial_ref_sys'
    |   - a regex pattern delimited with slashes: '/^events_\d{4}_\d{2}$/'
    |
    | Examples:
    |   'ignore_tables' => [
    |       'spatial_ref_sys',
    |       'geometry_columns',
    |       '/^events_\d{4}_\d{2}$/',  // monthly partitions
    |       '/^_timescaledb_/',         // TimescaleDB internal
    |   ],
    */
    'ignore_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Auto-detect partition children
    |--------------------------------------------------------------------------
    |
    | When true, the package queries pg_inherits (PostgreSQL only) to find
    | tables that are children of a partitioned or inherited parent. Those
    | tables are excluded from drift analysis automatically — no need to list
    | them under 'ignore_tables'.
    |
    | Has no effect on MySQL, MariaDB, SQLite, or SQL Server. Those drivers
    | do not expose partitions as separate tables.
    */
    'auto_detect_partitions' => true,
];
