<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\TypeMapper;
use EriMeilis\MigrationDrift\Tests\TestCase;

class TypeMapperTest extends TestCase
{
    private TypeMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new TypeMapper();
    }

    public function test_auto_increment_bigint_maps_to_id(): void
    {
        $col = [
            'name' => 'id',
            'type' => 'bigint',
            'type_name' => 'bigint',
            'auto_increment' => true,
        ];

        $this->assertSame(
            'id()',
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_auto_increment_bigint_named_maps_to_id_with_name(): void
    {
        $col = [
            'name' => 'user_id',
            'type' => 'bigint',
            'type_name' => 'bigint',
            'auto_increment' => true,
        ];

        $this->assertSame(
            "id('user_id')",
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_auto_increment_integer_maps_to_increments(): void
    {
        $col = [
            'name' => 'id',
            'type' => 'int',
            'type_name' => 'integer',
            'auto_increment' => true,
        ];

        $this->assertSame(
            "increments('id')",
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_varchar_with_length(): void
    {
        $col = [
            'name' => 'email',
            'type' => 'varchar(100)',
            'type_name' => 'varchar',
        ];

        $this->assertSame(
            "string('email', 100)",
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_varchar_255_omits_length(): void
    {
        $col = [
            'name' => 'name',
            'type' => 'varchar(255)',
            'type_name' => 'varchar',
        ];

        $this->assertSame(
            "string('name')",
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_char_36_maps_to_uuid(): void
    {
        $col = [
            'name' => 'uuid',
            'type' => 'char(36)',
            'type_name' => 'char',
        ];

        $this->assertSame(
            "uuid('uuid')",
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_char_26_maps_to_ulid(): void
    {
        $col = [
            'name' => 'ulid',
            'type' => 'char(26)',
            'type_name' => 'char',
        ];

        $this->assertSame(
            "ulid('ulid')",
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_decimal_with_precision(): void
    {
        $col = [
            'name' => 'price',
            'type' => 'decimal(10, 2)',
            'type_name' => 'decimal',
        ];

        $this->assertSame(
            "decimal('price', 10, 2)",
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_enum_type(): void
    {
        $col = [
            'name' => 'status',
            'type' => "enum('active','inactive')",
            'type_name' => 'enum',
        ];

        $this->assertSame(
            "enum('status', ['active','inactive'])",
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_simple_type_mapping(): void
    {
        $types = [
            'text' => "text('body')",
            'json' => "json('data')",
            'boolean' => "boolean('active')",
            'date' => "date('born_on')",
            'timestamp' => "timestamp('created')",
        ];

        foreach ($types as $typeName => $expected) {
            $name = match ($typeName) {
                'text' => 'body',
                'json' => 'data',
                'boolean' => 'active',
                'date' => 'born_on',
                'timestamp' => 'created',
            };

            $col = [
                'name' => $name,
                'type' => $typeName,
                'type_name' => $typeName,
            ];

            $this->assertSame(
                $expected,
                $this->mapper->toBlueprintMethod($col),
                "Failed for type: {$typeName}",
            );
        }
    }

    public function test_unknown_type_falls_back_to_add_column(): void
    {
        $col = [
            'name' => 'custom',
            'type' => 'custom_type',
            'type_name' => 'custom_type',
        ];

        $this->assertSame(
            "addColumn('custom_type', 'custom')",
            $this->mapper->toBlueprintMethod($col),
        );
    }

    public function test_column_definition_with_nullable(): void
    {
        $col = [
            'name' => 'bio',
            'type' => 'text',
            'type_name' => 'text',
            'nullable' => true,
        ];

        $this->assertSame(
            "\$table->text('bio')->nullable()",
            $this->mapper->toColumnDefinition($col),
        );
    }

    public function test_column_definition_with_default(): void
    {
        $col = [
            'name' => 'active',
            'type' => 'boolean',
            'type_name' => 'boolean',
            'nullable' => false,
            'default' => true,
        ];

        $this->assertSame(
            "\$table->boolean('active')->default(true)",
            $this->mapper->toColumnDefinition($col),
        );
    }

    public function test_column_definition_with_string_default(): void
    {
        $col = [
            'name' => 'role',
            'type' => 'varchar(255)',
            'type_name' => 'varchar',
            'nullable' => false,
            'default' => 'user',
        ];

        $this->assertSame(
            "\$table->string('role')->default('user')",
            $this->mapper->toColumnDefinition($col),
        );
    }

    public function test_column_definition_with_numeric_default(): void
    {
        $col = [
            'name' => 'sort_order',
            'type' => 'integer',
            'type_name' => 'integer',
            'nullable' => false,
            'default' => 0,
        ];

        $this->assertSame(
            "\$table->integer('sort_order')->default(0)",
            $this->mapper->toColumnDefinition($col),
        );
    }

    public function test_column_definition_with_db_expression_default(): void
    {
        $col = [
            'name' => 'created_at',
            'type' => 'timestamp',
            'type_name' => 'timestamp',
            'nullable' => true,
            'default' => 'CURRENT_TIMESTAMP',
        ];

        $def = $this->mapper->toColumnDefinition($col);

        $this->assertStringContainsString(
            'DB::raw',
            $def,
        );
        $this->assertStringContainsString(
            'CURRENT_TIMESTAMP',
            $def,
        );
    }

    public function test_auto_increment_skips_modifiers(): void
    {
        $col = [
            'name' => 'id',
            'type' => 'bigint',
            'type_name' => 'bigint',
            'auto_increment' => true,
            'nullable' => false,
        ];

        $this->assertSame(
            '$table->id()',
            $this->mapper->toColumnDefinition($col),
        );
    }

    public function test_index_definition_single_column(): void
    {
        $idx = [
            'columns' => ['email'],
            'unique' => false,
            'primary' => false,
        ];

        $this->assertSame(
            "\$table->index('email')",
            $this->mapper->toIndexDefinition($idx),
        );
    }

    public function test_index_definition_unique(): void
    {
        $idx = [
            'columns' => ['email'],
            'unique' => true,
            'primary' => false,
        ];

        $this->assertSame(
            "\$table->unique('email')",
            $this->mapper->toIndexDefinition($idx),
        );
    }

    public function test_index_definition_primary(): void
    {
        $idx = [
            'columns' => ['id'],
            'unique' => true,
            'primary' => true,
        ];

        $this->assertSame(
            "\$table->primary('id')",
            $this->mapper->toIndexDefinition($idx),
        );
    }

    public function test_index_definition_composite(): void
    {
        $idx = [
            'columns' => ['first_name', 'last_name'],
            'unique' => false,
            'primary' => false,
        ];

        $this->assertSame(
            "\$table->index(['first_name', 'last_name'])",
            $this->mapper->toIndexDefinition($idx),
        );
    }

    public function test_foreign_key_definition(): void
    {
        $fk = [
            'columns' => ['user_id'],
            'foreign_table' => 'users',
            'foreign_columns' => ['id'],
            'on_update' => 'NO ACTION',
            'on_delete' => 'CASCADE',
        ];

        $this->assertSame(
            "\$table->foreign('user_id')"
            . "->references('id')"
            . "->on('users')"
            . "->onDelete('cascade')",
            $this->mapper->toForeignKeyDefinition($fk),
        );
    }

    public function test_foreign_key_no_actions(): void
    {
        $fk = [
            'columns' => ['user_id'],
            'foreign_table' => 'users',
            'foreign_columns' => ['id'],
            'on_update' => 'NO ACTION',
            'on_delete' => 'RESTRICT',
        ];

        $def = $this->mapper->toForeignKeyDefinition($fk);

        $this->assertStringNotContainsString(
            'onDelete',
            $def,
        );
        $this->assertStringNotContainsString(
            'onUpdate',
            $def,
        );
    }

    public function test_from_blueprint_method_maps_known_types(): void
    {
        $result = $this->mapper->fromBlueprintMethod(
            'string',
        );

        $this->assertSame(
            'varchar(255)',
            $result['type'],
        );
        $this->assertSame(
            'varchar',
            $result['type_name'],
        );
    }

    public function test_from_blueprint_method_returns_varchar_for_null(): void
    {
        $result = $this->mapper->fromBlueprintMethod(
            null,
        );

        $this->assertSame(
            'varchar(255)',
            $result['type'],
        );
    }

    public function test_from_blueprint_method_returns_varchar_for_unknown(): void
    {
        $result = $this->mapper->fromBlueprintMethod(
            'nonExistentMethod',
        );

        $this->assertSame(
            'varchar(255)',
            $result['type'],
        );
    }

    public function test_foreign_key_composite(): void
    {
        $fk = [
            'columns' => ['org_id', 'user_id'],
            'foreign_table' => 'org_users',
            'foreign_columns' => ['org_id', 'user_id'],
            'on_update' => 'NO ACTION',
            'on_delete' => 'NO ACTION',
        ];

        $def = $this->mapper->toForeignKeyDefinition($fk);

        $this->assertStringContainsString(
            "['org_id', 'user_id']",
            $def,
        );
    }
}
