<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\DependencyResolver;
use EriMeilis\MigrationDrift\Tests\TestCase;

class DependencyResolverTest extends TestCase
{
    private DependencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new DependencyResolver();
    }

    public function test_topological_sort_no_deps(): void
    {
        $tables = ['users', 'posts', 'tags'];

        $sorted = $this->resolver->topologicalSort(
            $tables,
            [],
        );

        $this->assertCount(3, $sorted);
        $this->assertEqualsCanonicalizing(
            $tables,
            $sorted,
        );
    }

    public function test_topological_sort_simple_deps(): void
    {
        $tables = ['posts', 'users'];
        $fks = [
            'posts' => [
                [
                    'foreign_table' => 'users',
                    'columns' => ['user_id'],
                ],
            ],
        ];

        $sorted = $this->resolver->topologicalSort(
            $tables,
            $fks,
        );

        $usersIdx = array_search('users', $sorted, true);
        $postsIdx = array_search('posts', $sorted, true);

        $this->assertLessThan(
            $postsIdx,
            $usersIdx,
            'users should come before posts',
        );
    }

    public function test_topological_sort_chain(): void
    {
        $tables = ['comments', 'posts', 'users'];
        $fks = [
            'posts' => [
                ['foreign_table' => 'users'],
            ],
            'comments' => [
                ['foreign_table' => 'posts'],
            ],
        ];

        $sorted = $this->resolver->topologicalSort(
            $tables,
            $fks,
        );

        $usersIdx = array_search('users', $sorted, true);
        $postsIdx = array_search('posts', $sorted, true);
        $commentsIdx = array_search(
            'comments',
            $sorted,
            true,
        );

        $this->assertLessThan($postsIdx, $usersIdx);
        $this->assertLessThan($commentsIdx, $postsIdx);
    }

    public function test_topological_sort_ignores_self_reference(): void
    {
        $tables = ['categories'];
        $fks = [
            'categories' => [
                ['foreign_table' => 'categories'],
            ],
        ];

        $sorted = $this->resolver->topologicalSort(
            $tables,
            $fks,
        );

        $this->assertSame(['categories'], $sorted);
    }

    public function test_topological_sort_ignores_unknown_refs(): void
    {
        $tables = ['posts'];
        $fks = [
            'posts' => [
                ['foreign_table' => 'nonexistent_table'],
            ],
        ];

        $sorted = $this->resolver->topologicalSort(
            $tables,
            $fks,
        );

        $this->assertSame(['posts'], $sorted);
    }

    public function test_detect_circular_dependencies_none(): void
    {
        $tables = ['users', 'posts'];
        $fks = [
            'posts' => [
                ['foreign_table' => 'users'],
            ],
        ];

        $cycles = $this->resolver->detectCircularDependencies(
            $tables,
            $fks,
        );

        $this->assertEmpty($cycles);
    }

    public function test_detect_circular_dependencies_found(): void
    {
        $tables = ['a', 'b'];
        $fks = [
            'a' => [['foreign_table' => 'b']],
            'b' => [['foreign_table' => 'a']],
        ];

        $cycles = $this->resolver->detectCircularDependencies(
            $tables,
            $fks,
        );

        $this->assertNotEmpty($cycles);

        // Cycle should contain both tables
        $cycleFlat = array_merge(...$cycles);
        $this->assertContains('a', $cycleFlat);
        $this->assertContains('b', $cycleFlat);
    }

    public function test_get_creation_order(): void
    {
        $tables = ['posts', 'users'];
        $fks = [
            'posts' => [
                ['foreign_table' => 'users'],
            ],
        ];

        $order = $this->resolver->getCreationOrder(
            $tables,
            $fks,
        );

        $this->assertSame('users', $order[0]);
        $this->assertSame('posts', $order[1]);
    }

    public function test_get_drop_order(): void
    {
        $tables = ['posts', 'users'];
        $fks = [
            'posts' => [
                ['foreign_table' => 'users'],
            ],
        ];

        $order = $this->resolver->getDropOrder(
            $tables,
            $fks,
        );

        // Drop order is reverse of creation
        $this->assertSame('posts', $order[0]);
        $this->assertSame('users', $order[1]);
    }

    public function test_detect_pivot_tables(): void
    {
        $tables = ['users', 'roles', 'role_user'];
        $fks = [
            'role_user' => [
                ['foreign_table' => 'users'],
                ['foreign_table' => 'roles'],
            ],
        ];

        $pivots = $this->resolver->detectPivotTables(
            $tables,
            $fks,
        );

        $this->assertContains('role_user', $pivots);
    }

    public function test_detect_pivot_ignores_non_pivot(): void
    {
        $tables = ['users', 'posts'];
        $fks = [
            'posts' => [
                ['foreign_table' => 'users'],
            ],
        ];

        $pivots = $this->resolver->detectPivotTables(
            $tables,
            $fks,
        );

        $this->assertEmpty($pivots);
    }

    public function test_detect_pivot_three_fks_not_pivot(): void
    {
        $tables = ['a', 'b', 'c', 'complex'];
        $fks = [
            'complex' => [
                ['foreign_table' => 'a'],
                ['foreign_table' => 'b'],
                ['foreign_table' => 'c'],
            ],
        ];

        $pivots = $this->resolver->detectPivotTables(
            $tables,
            $fks,
        );

        $this->assertNotContains('complex', $pivots);
    }

    public function test_handles_circular_in_topological_sort(): void
    {
        $tables = ['a', 'b', 'c'];
        $fks = [
            'a' => [['foreign_table' => 'b']],
            'b' => [['foreign_table' => 'a']],
        ];

        // Should not infinite loop
        $sorted = $this->resolver->topologicalSort(
            $tables,
            $fks,
        );

        $this->assertCount(3, $sorted);
        $this->assertContains('c', $sorted);
    }
}
