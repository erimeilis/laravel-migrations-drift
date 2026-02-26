<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Concerns\ResolvesPath;
use EriMeilis\MigrationDrift\Tests\TestCase;
use InvalidArgumentException;

class ResolvesPathTest extends TestCase
{
    use ResolvesPath;

    public function test_valid_path_resolves(): void
    {
        $fixturesPath = dirname(__DIR__)
            . '/fixtures/migrations';

        $resolved = $this->resolveMigrationsPath(
            $fixturesPath,
        );

        $this->assertSame(
            realpath($fixturesPath),
            $resolved,
        );
    }

    public function test_nonexistent_path_throws(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );
        $this->expectExceptionMessage('does not exist');

        $this->resolveMigrationsPath(
            '/nonexistent/path/to/migrations',
        );
    }

    public function test_path_outside_project_throws(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );
        $this->expectExceptionMessage(
            'within the project',
        );

        $this->resolveMigrationsPath('/tmp');
    }
}
