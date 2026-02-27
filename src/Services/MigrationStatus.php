<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

enum MigrationStatus
{
    /** Record + File + Schema match — everything consistent. */
    case OK;

    /** Record + File exist, but schema says it never ran — delete the bogus record. */
    case BOGUS_RECORD;

    /** Record + Schema present, but file is gone — regenerate file. */
    case MISSING_FILE;

    /** Record only, no file, no schema evidence — warn and remove. */
    case ORPHAN_RECORD;

    /** File + Schema present, but no record — insert record. */
    case LOST_RECORD;

    /** File only, not yet run — leave for `php artisan migrate`. */
    case NEW_MIGRATION;
}
