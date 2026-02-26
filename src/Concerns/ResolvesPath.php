<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Concerns;

trait ResolvesPath
{
    private function resolveMigrationsPath(?string $pathOption): string
    {
        $path = $pathOption ?: (string) config('migration-drift.migrations_path', database_path('migrations'));

        $realPath = realpath($path);

        if ($realPath === false || !is_dir($realPath)) {
            throw new \InvalidArgumentException("Migrations path does not exist: {$path}");
        }

        $realBasePath = $this->resolveProjectRoot();

        if ($realBasePath !== null && !str_starts_with($realPath, $realBasePath)) {
            throw new \InvalidArgumentException(
                "Migrations path must be within the project directory. Got: {$realPath}"
            );
        }

        return $realPath;
    }

    /**
     * Resolve the project root directory.
     *
     * Strategy:
     * 1. Follow base_path('vendor') symlink (works in real
     *    Laravel apps and Testbench when symlink exists)
     * 2. Walk up from this package's source to find the
     *    project root via vendor/autoload.php (works on CI
     *    and when the vendor symlink is absent)
     * 3. Fall back to base_path() itself
     */
    private function resolveProjectRoot(): ?string
    {
        $vendorPath = realpath(base_path('vendor'));

        if ($vendorPath !== false) {
            return \dirname($vendorPath);
        }

        // Package source lives at vendor/<pkg>/src/Concerns/
        // so dirname(__DIR__, 2) is the package root; walk up
        // to find the project root containing vendor/.
        $autoloadPath = realpath(
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
        );

        if ($autoloadPath !== false) {
            return \dirname($autoloadPath, 2);
        }

        $basePath = realpath(base_path());

        return $basePath !== false ? $basePath : null;
    }
}
