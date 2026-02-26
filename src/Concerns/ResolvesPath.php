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
     * Resolve the project root by following the vendor symlink from base_path().
     *
     * In a standard Laravel app, base_path('vendor') is the real vendor dir,
     * so dirname() returns base_path() itself.
     * In Orchestra Testbench, base_path('vendor') is a symlink to the real
     * vendor dir, so realpath() + dirname() gives us the actual package root.
     */
    private function resolveProjectRoot(): ?string
    {
        $vendorPath = realpath(base_path('vendor'));

        if ($vendorPath === false) {
            $basePath = realpath(base_path());

            return $basePath !== false ? $basePath : null;
        }

        return \dirname($vendorPath);
    }
}
