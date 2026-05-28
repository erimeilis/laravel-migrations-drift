<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\IgnoredTableResolver;
use EriMeilis\MigrationDrift\Tests\TestCase;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionResolverInterface;

class IgnoredTableResolverTest extends TestCase
{
    /**
     * @param array<string, string>|null $partitionChildren
     *     When null, the real PG detection runs (returns [] on SQLite).
     *     When provided, the resolver returns this map regardless of driver.
     */
    private function makeResolver(
        ?array $partitionChildren = null,
    ): IgnoredTableResolver {
        /** @var ConfigRepository $config */
        $config = $this->app->make('config');
        /** @var ConnectionResolverInterface $db */
        $db = $this->app->make('db');

        if ($partitionChildren === null) {
            return new IgnoredTableResolver($config, $db);
        }

        return new class ($config, $db, $partitionChildren) extends IgnoredTableResolver {
            /**
             * @param array<string, string> $stub
             */
            public function __construct(
                ConfigRepository $config,
                ConnectionResolverInterface $db,
                private readonly array $stub,
            ) {
                parent::__construct($config, $db);
            }

            protected function fetchPartitionChildren(
                string $connection,
            ): array {
                return $this->stub;
            }
        };
    }

    public function test_empty_config_returns_no_ignored_tables(): void
    {
        config()->set('migration-drift.ignore_tables', []);
        config()->set(
            'migration-drift.auto_detect_partitions',
            false,
        );

        $resolver = $this->makeResolver();

        $this->assertSame(
            [],
            $resolver->resolve('testing', ['users', 'posts']),
        );
    }

    public function test_literal_pattern_matches_exact_name(): void
    {
        config()->set(
            'migration-drift.ignore_tables',
            ['spatial_ref_sys'],
        );
        config()->set(
            'migration-drift.auto_detect_partitions',
            false,
        );

        $resolver = $this->makeResolver();

        $result = $resolver->resolve(
            'testing',
            ['users', 'spatial_ref_sys', 'posts'],
        );

        $this->assertArrayHasKey('spatial_ref_sys', $result);
        $this->assertArrayNotHasKey('users', $result);
        $this->assertArrayNotHasKey('posts', $result);
    }

    public function test_literal_pattern_not_present_is_skipped(): void
    {
        config()->set(
            'migration-drift.ignore_tables',
            ['nonexistent'],
        );
        config()->set(
            'migration-drift.auto_detect_partitions',
            false,
        );

        $resolver = $this->makeResolver();

        $this->assertSame(
            [],
            $resolver->resolve('testing', ['users']),
        );
    }

    public function test_regex_pattern_matches_multiple_tables(): void
    {
        config()->set(
            'migration-drift.ignore_tables',
            ['/^events_\d{4}_\d{2}$/'],
        );
        config()->set(
            'migration-drift.auto_detect_partitions',
            false,
        );

        $resolver = $this->makeResolver();

        $result = $resolver->resolve('testing', [
            'events',
            'events_2024_01',
            'events_2024_02',
            'events_summary',
        ]);

        $this->assertArrayHasKey('events_2024_01', $result);
        $this->assertArrayHasKey('events_2024_02', $result);
        $this->assertArrayNotHasKey('events', $result);
        $this->assertArrayNotHasKey('events_summary', $result);
    }

    public function test_invalid_regex_is_silently_ignored(): void
    {
        config()->set(
            'migration-drift.ignore_tables',
            ['/[invalid(regex/'],
        );
        config()->set(
            'migration-drift.auto_detect_partitions',
            false,
        );

        $resolver = $this->makeResolver();

        $result = $resolver->resolve('testing', ['users']);

        $this->assertSame([], $result);
    }

    public function test_reason_for_literal_pattern(): void
    {
        config()->set(
            'migration-drift.ignore_tables',
            ['spatial_ref_sys'],
        );
        config()->set(
            'migration-drift.auto_detect_partitions',
            false,
        );

        $resolver = $this->makeResolver();
        $result = $resolver->resolve(
            'testing',
            ['spatial_ref_sys'],
        );

        $this->assertSame(
            'config: literal',
            $result['spatial_ref_sys'],
        );
    }

    public function test_reason_for_regex_pattern_includes_pattern(): void
    {
        config()->set(
            'migration-drift.ignore_tables',
            ['/^evt_/'],
        );
        config()->set(
            'migration-drift.auto_detect_partitions',
            false,
        );

        $resolver = $this->makeResolver();
        $result = $resolver->resolve('testing', ['evt_x']);

        $this->assertStringContainsString(
            '/^evt_/',
            $result['evt_x'],
        );
    }

    public function test_partition_children_are_detected_when_enabled(): void
    {
        config()->set('migration-drift.ignore_tables', []);
        config()->set(
            'migration-drift.auto_detect_partitions',
            true,
        );

        $resolver = $this->makeResolver([
            'events_2024_01' => 'events',
            'events_2024_02' => 'events',
        ]);

        $result = $resolver->resolve('testing', [
            'events',
            'events_2024_01',
            'events_2024_02',
            'users',
        ]);

        $this->assertSame(
            'partition of events',
            $result['events_2024_01'],
        );
        $this->assertSame(
            'partition of events',
            $result['events_2024_02'],
        );
        $this->assertArrayNotHasKey('events', $result);
        $this->assertArrayNotHasKey('users', $result);
    }

    public function test_partition_detection_disabled_skips_partition_check(): void
    {
        config()->set('migration-drift.ignore_tables', []);
        config()->set(
            'migration-drift.auto_detect_partitions',
            false,
        );

        // Stub still returns partition children, but resolver
        // must not consult it when the flag is off.
        $resolver = $this->makeResolver([
            'events_2024_01' => 'events',
        ]);

        $result = $resolver->resolve(
            'testing',
            ['events_2024_01'],
        );

        $this->assertSame([], $result);
    }

    public function test_partition_child_not_in_candidate_list_is_skipped(): void
    {
        config()->set(
            'migration-drift.auto_detect_partitions',
            true,
        );

        $resolver = $this->makeResolver([
            'events_2024_01' => 'events',
        ]);

        $result = $resolver->resolve('testing', ['users']);

        $this->assertSame([], $result);
    }

    public function test_partition_reason_wins_when_pattern_also_matches(): void
    {
        config()->set(
            'migration-drift.ignore_tables',
            ['/^events_/'],
        );
        config()->set(
            'migration-drift.auto_detect_partitions',
            true,
        );

        $resolver = $this->makeResolver([
            'events_2024_01' => 'events',
        ]);

        $result = $resolver->resolve(
            'testing',
            ['events_2024_01'],
        );

        $this->assertSame(
            'partition of events',
            $result['events_2024_01'],
        );
    }

    public function test_sqlite_driver_returns_no_partitions(): void
    {
        config()->set(
            'migration-drift.auto_detect_partitions',
            true,
        );
        config()->set('migration-drift.ignore_tables', []);

        // No stub: real fetchPartitionChildren runs and must
        // detect the SQLite driver and return [] without error.
        $resolver = $this->makeResolver();

        $result = $resolver->resolve(
            'testing',
            ['test_users', 'test_posts'],
        );

        $this->assertSame([], $result);
    }

    public function test_non_string_pattern_is_silently_ignored(): void
    {
        config()->set(
            'migration-drift.ignore_tables',
            [123, null, true],
        );
        config()->set(
            'migration-drift.auto_detect_partitions',
            false,
        );

        $resolver = $this->makeResolver();

        $this->assertSame(
            [],
            $resolver->resolve('testing', ['users']),
        );
    }
}
