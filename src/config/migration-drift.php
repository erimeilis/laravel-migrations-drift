<?php

declare(strict_types=1);

return [
    'backup_path' => storage_path('migrations-drift'),
    'max_backups' => 5,
    'migrations_path' => database_path('migrations'),
];