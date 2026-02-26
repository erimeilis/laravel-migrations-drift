<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\SchemaComparator;
use EriMeilis\MigrationDrift\Services\SchemaIntrospector;
use EriMeilis\MigrationDrift\Tests\TestCase;
use Illuminate\Support\Facades\Config;

class SchemaComparatorTest extends TestCase
{
    private SchemaComparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comparator = new SchemaComparator(
            new SchemaIntrospector(),
        );
    }

    public function test_has_differences_returns_false_when_no_diff(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'column_diffs' => [],
            'index_diffs' => [],
            'fk_diffs' => [],
        ];

        $this->assertFalse(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_detects_missing_tables(): void
    {
        $diff = [
            'missing_tables' => ['users'],
            'extra_tables' => [],
            'column_diffs' => [],
            'index_diffs' => [],
            'fk_diffs' => [],
        ];

        $this->assertTrue(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_detects_extra_tables(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => ['legacy_table'],
            'column_diffs' => [],
            'index_diffs' => [],
            'fk_diffs' => [],
        ];

        $this->assertTrue(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_detects_missing_columns(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'column_diffs' => [
                'users' => [
                    'missing' => ['email'],
                    'extra' => [],
                    'type_mismatches' => [],
                    'nullable_mismatches' => [],
                    'default_mismatches' => [],
                ],
            ],
            'index_diffs' => [],
            'fk_diffs' => [],
        ];

        $this->assertTrue(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_detects_type_mismatches(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'column_diffs' => [
                'users' => [
                    'missing' => [],
                    'extra' => [],
                    'type_mismatches' => [
                        [
                            'column' => 'age',
                            'current' => 'varchar',
                            'expected' => 'integer',
                        ],
                    ],
                    'nullable_mismatches' => [],
                    'default_mismatches' => [],
                ],
            ],
            'index_diffs' => [],
            'fk_diffs' => [],
        ];

        $this->assertTrue(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_detects_nullable_mismatches(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'column_diffs' => [
                'users' => [
                    'missing' => [],
                    'extra' => [],
                    'type_mismatches' => [],
                    'nullable_mismatches' => [
                        [
                            'column' => 'bio',
                            'current' => 'not null',
                            'expected' => 'nullable',
                        ],
                    ],
                    'default_mismatches' => [],
                ],
            ],
            'index_diffs' => [],
            'fk_diffs' => [],
        ];

        $this->assertTrue(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_detects_default_mismatches(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'column_diffs' => [
                'users' => [
                    'missing' => [],
                    'extra' => [],
                    'type_mismatches' => [],
                    'nullable_mismatches' => [],
                    'default_mismatches' => [
                        [
                            'column' => 'status',
                            'current' => 'active',
                            'expected' => 'pending',
                        ],
                    ],
                ],
            ],
            'index_diffs' => [],
            'fk_diffs' => [],
        ];

        $this->assertTrue(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_detects_missing_indexes(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'column_diffs' => [],
            'index_diffs' => [
                'users' => [
                    'missing' => [
                        [
                            'columns' => ['email'],
                            'unique' => true,
                            'primary' => false,
                        ],
                    ],
                    'extra' => [],
                ],
            ],
            'fk_diffs' => [],
        ];

        $this->assertTrue(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_detects_extra_indexes(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'column_diffs' => [],
            'index_diffs' => [
                'users' => [
                    'missing' => [],
                    'extra' => [
                        [
                            'columns' => ['name'],
                            'unique' => false,
                            'primary' => false,
                        ],
                    ],
                ],
            ],
            'fk_diffs' => [],
        ];

        $this->assertTrue(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_detects_missing_foreign_keys(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'column_diffs' => [],
            'index_diffs' => [],
            'fk_diffs' => [
                'posts' => [
                    'missing' => [
                        [
                            'columns' => ['user_id'],
                            'foreign_table' => 'users',
                            'foreign_columns' => ['id'],
                        ],
                    ],
                    'extra' => [],
                ],
            ],
        ];

        $this->assertTrue(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_has_differences_ignores_empty_column_diff(): void
    {
        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'column_diffs' => [
                'users' => [
                    'missing' => [],
                    'extra' => [],
                    'type_mismatches' => [],
                    'nullable_mismatches' => [],
                    'default_mismatches' => [],
                ],
            ],
            'index_diffs' => [],
            'fk_diffs' => [],
        ];

        $this->assertFalse(
            $this->comparator->hasDifferences($diff),
        );
    }

    public function test_compare_validates_database_name(): void
    {
        Config::set('database.default', 'testing');
        Config::set(
            'database.connections.testing.database',
            'test"; DROP DATABASE prod; --',
        );

        $comparator = new SchemaComparator(
            new SchemaIntrospector(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe characters');

        $comparator->compare();
    }

    public function test_diff_schemas_detects_missing_tables(): void
    {
        $current = [
            'tables' => ['users'],
            'columns' => ['users' => []],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];
        $verify = [
            'tables' => ['users', 'posts'],
            'columns' => [
                'users' => [],
                'posts' => [],
            ],
            'indexes' => [
                'users' => [],
                'posts' => [],
            ],
            'foreign_keys' => [
                'users' => [],
                'posts' => [],
            ],
        ];

        $result = $this->comparator->diffSchemas(
            $current,
            $verify,
        );

        $this->assertSame(['posts'], $result['missing_tables']);
        $this->assertSame([], $result['extra_tables']);
    }

    public function test_diff_schemas_detects_extra_tables(): void
    {
        $current = [
            'tables' => ['users', 'legacy'],
            'columns' => [
                'users' => [],
                'legacy' => [],
            ],
            'indexes' => [
                'users' => [],
                'legacy' => [],
            ],
            'foreign_keys' => [
                'users' => [],
                'legacy' => [],
            ],
        ];
        $verify = [
            'tables' => ['users'],
            'columns' => ['users' => []],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];

        $result = $this->comparator->diffSchemas(
            $current,
            $verify,
        );

        $this->assertSame([], $result['missing_tables']);
        $this->assertSame(['legacy'], $result['extra_tables']);
    }

    public function test_diff_schemas_detects_column_differences(): void
    {
        $current = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'id',
                        'type' => 'integer',
                        'nullable' => false,
                        'default' => null,
                    ],
                    [
                        'name' => 'extra_col',
                        'type' => 'varchar',
                        'nullable' => false,
                        'default' => null,
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];
        $verify = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'id',
                        'type' => 'integer',
                        'nullable' => false,
                        'default' => null,
                    ],
                    [
                        'name' => 'email',
                        'type' => 'varchar',
                        'nullable' => false,
                        'default' => null,
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];

        $result = $this->comparator->diffSchemas(
            $current,
            $verify,
        );

        $this->assertSame(
            ['email'],
            $result['column_diffs']['users']['missing'],
        );
        $this->assertSame(
            ['extra_col'],
            $result['column_diffs']['users']['extra'],
        );
    }

    public function test_diff_schemas_detects_type_mismatches(): void
    {
        $current = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'age',
                        'type' => 'varchar',
                        'nullable' => false,
                        'default' => null,
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];
        $verify = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'age',
                        'type' => 'integer',
                        'nullable' => false,
                        'default' => null,
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];

        $result = $this->comparator->diffSchemas(
            $current,
            $verify,
        );

        $mismatches = $result['column_diffs']['users']['type_mismatches'];
        $this->assertCount(1, $mismatches);
        $this->assertSame('age', $mismatches[0]['column']);
        $this->assertSame('varchar', $mismatches[0]['current']);
        $this->assertSame('integer', $mismatches[0]['expected']);
    }

    public function test_diff_schemas_detects_nullable_mismatches(): void
    {
        $current = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'bio',
                        'type' => 'text',
                        'nullable' => false,
                        'default' => null,
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];
        $verify = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'bio',
                        'type' => 'text',
                        'nullable' => true,
                        'default' => null,
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];

        $result = $this->comparator->diffSchemas(
            $current,
            $verify,
        );

        $mismatches
            = $result['column_diffs']['users']['nullable_mismatches'];
        $this->assertCount(1, $mismatches);
        $this->assertSame('bio', $mismatches[0]['column']);
        $this->assertSame(
            'not null',
            $mismatches[0]['current'],
        );
        $this->assertSame(
            'nullable',
            $mismatches[0]['expected'],
        );
    }

    public function test_diff_schemas_detects_default_mismatches(): void
    {
        $current = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'status',
                        'type' => 'varchar',
                        'nullable' => false,
                        'default' => 'active',
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];
        $verify = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'status',
                        'type' => 'varchar',
                        'nullable' => false,
                        'default' => 'pending',
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];

        $result = $this->comparator->diffSchemas(
            $current,
            $verify,
        );

        $mismatches
            = $result['column_diffs']['users']['default_mismatches'];
        $this->assertCount(1, $mismatches);
        $this->assertSame(
            'status',
            $mismatches[0]['column'],
        );
        $this->assertSame(
            'active',
            $mismatches[0]['current'],
        );
        $this->assertSame(
            'pending',
            $mismatches[0]['expected'],
        );
    }

    public function test_diff_schemas_detects_index_differences(): void
    {
        $current = [
            'tables' => ['users'],
            'columns' => ['users' => []],
            'indexes' => [
                'users' => [
                    [
                        'name' => 'users_name_index',
                        'columns' => ['name'],
                        'unique' => false,
                        'primary' => false,
                    ],
                ],
            ],
            'foreign_keys' => ['users' => []],
        ];
        $verify = [
            'tables' => ['users'],
            'columns' => ['users' => []],
            'indexes' => [
                'users' => [
                    [
                        'name' => 'users_email_unique',
                        'columns' => ['email'],
                        'unique' => true,
                        'primary' => false,
                    ],
                ],
            ],
            'foreign_keys' => ['users' => []],
        ];

        $result = $this->comparator->diffSchemas(
            $current,
            $verify,
        );

        $this->assertArrayHasKey(
            'users',
            $result['index_diffs'],
        );
        $this->assertCount(
            1,
            $result['index_diffs']['users']['missing'],
        );
        $this->assertCount(
            1,
            $result['index_diffs']['users']['extra'],
        );
    }

    public function test_diff_schemas_detects_fk_differences(): void
    {
        $current = [
            'tables' => ['posts'],
            'columns' => ['posts' => []],
            'indexes' => ['posts' => []],
            'foreign_keys' => [
                'posts' => [],
            ],
        ];
        $verify = [
            'tables' => ['posts'],
            'columns' => ['posts' => []],
            'indexes' => ['posts' => []],
            'foreign_keys' => [
                'posts' => [
                    [
                        'name' => 'posts_user_id_foreign',
                        'columns' => ['user_id'],
                        'foreign_table' => 'users',
                        'foreign_columns' => ['id'],
                    ],
                ],
            ],
        ];

        $result = $this->comparator->diffSchemas(
            $current,
            $verify,
        );

        $this->assertArrayHasKey(
            'posts',
            $result['fk_diffs'],
        );
        $this->assertCount(
            1,
            $result['fk_diffs']['posts']['missing'],
        );
    }

    public function test_diff_schemas_no_diffs_returns_empty(): void
    {
        $schema = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'id',
                        'type' => 'integer',
                        'nullable' => false,
                        'default' => null,
                    ],
                ],
            ],
            'indexes' => [
                'users' => [
                    [
                        'name' => 'users_pkey',
                        'columns' => ['id'],
                        'unique' => true,
                        'primary' => true,
                    ],
                ],
            ],
            'foreign_keys' => ['users' => []],
        ];

        $result = $this->comparator->diffSchemas(
            $schema,
            $schema,
        );

        $this->assertEmpty($result['missing_tables']);
        $this->assertEmpty($result['extra_tables']);
        $this->assertEmpty($result['column_diffs']);
        $this->assertEmpty($result['index_diffs']);
        $this->assertEmpty($result['fk_diffs']);
    }

    public function test_normalize_default_handles_booleans(): void
    {
        // Test through diffSchemas - boolean true/1 should be treated as same
        $current = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'active',
                        'type' => 'boolean',
                        'nullable' => false,
                        'default' => 'true',
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];
        $verify = [
            'tables' => ['users'],
            'columns' => [
                'users' => [
                    [
                        'name' => 'active',
                        'type' => 'boolean',
                        'nullable' => false,
                        'default' => '1',
                    ],
                ],
            ],
            'indexes' => ['users' => []],
            'foreign_keys' => ['users' => []],
        ];

        $result = $this->comparator->diffSchemas(
            $current,
            $verify,
        );

        // true and 1 should be normalized to same value
        $this->assertEmpty($result['column_diffs']);
    }
}
