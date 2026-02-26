<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Services\MigrationGenerator;
use EriMeilis\MigrationDrift\Services\TypeMapper;
use EriMeilis\MigrationDrift\Tests\TestCase;

class MigrationGeneratorTest extends TestCase
{
    private MigrationGenerator $generator;

    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new MigrationGenerator(
            new TypeMapper(),
        );

        $this->outputPath = sys_get_temp_dir()
            . '/migration-drift-gen-' . uniqid();
        mkdir($this->outputPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->outputPath . '/*.php');

        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        if (is_dir($this->outputPath)) {
            rmdir($this->outputPath);
        }

        parent::tearDown();
    }

    public function test_generate_add_column(): void
    {
        $file = $this->generator->generateAddColumn(
            'users',
            [
                'name' => 'bio',
                'type' => 'text',
                'type_name' => 'text',
                'nullable' => true,
            ],
            $this->outputPath,
            '2026-02-25',
        );

        $this->assertFileExists($file);

        $content = file_get_contents($file);
        $this->assertStringContainsString(
            "Schema::table('users'",
            $content,
        );
        $this->assertStringContainsString(
            "text('bio')",
            $content,
        );
        $this->assertStringContainsString(
            'nullable()',
            $content,
        );
        $this->assertStringContainsString(
            "dropColumn('bio')",
            $content,
        );
    }

    public function test_generate_drop_column(): void
    {
        $file = $this->generator->generateDropColumn(
            'users',
            'legacy_field',
            null,
            $this->outputPath,
            '2026-02-25',
        );

        $this->assertFileExists($file);

        $content = file_get_contents($file);
        $this->assertStringContainsString(
            "dropColumn('legacy_field')",
            $content,
        );
        $this->assertStringContainsString(
            'RuntimeException',
            $content,
        );
    }

    public function test_generate_drop_column_reversible(): void
    {
        $file = $this->generator->generateDropColumn(
            'users',
            'email',
            [
                'name' => 'email',
                'type' => 'varchar(255)',
                'type_name' => 'varchar',
                'nullable' => false,
            ],
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($file);
        $this->assertStringContainsString(
            "string('email')",
            $content,
        );
        $this->assertStringNotContainsString(
            'RuntimeException',
            $content,
        );
    }

    public function test_generate_create_table(): void
    {
        $file = $this->generator->generateCreateTable(
            'posts',
            [
                [
                    'name' => 'id',
                    'type' => 'bigint',
                    'type_name' => 'bigint',
                    'auto_increment' => true,
                ],
                [
                    'name' => 'title',
                    'type' => 'varchar(255)',
                    'type_name' => 'varchar',
                    'nullable' => false,
                ],
            ],
            [
                [
                    'columns' => ['id'],
                    'unique' => true,
                    'primary' => true,
                ],
            ],
            [],
            $this->outputPath,
            '2026-02-25',
        );

        $this->assertFileExists($file);

        $content = file_get_contents($file);
        $this->assertStringContainsString(
            "Schema::create('posts'",
            $content,
        );
        $this->assertStringContainsString(
            'id()',
            $content,
        );
        $this->assertStringContainsString(
            "string('title')",
            $content,
        );
        $this->assertStringContainsString(
            "dropIfExists('posts')",
            $content,
        );
    }

    public function test_generate_create_table_with_fks(): void
    {
        $file = $this->generator->generateCreateTable(
            'comments',
            [
                [
                    'name' => 'id',
                    'type' => 'bigint',
                    'type_name' => 'bigint',
                    'auto_increment' => true,
                ],
                [
                    'name' => 'post_id',
                    'type' => 'bigint',
                    'type_name' => 'bigint',
                    'nullable' => false,
                ],
            ],
            [],
            [
                [
                    'columns' => ['post_id'],
                    'foreign_table' => 'posts',
                    'foreign_columns' => ['id'],
                    'on_update' => 'NO ACTION',
                    'on_delete' => 'CASCADE',
                ],
            ],
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($file);
        $this->assertStringContainsString(
            "foreign('post_id')",
            $content,
        );
        $this->assertStringContainsString(
            "references('id')",
            $content,
        );
        $this->assertStringContainsString(
            "on('posts')",
            $content,
        );
        // down() should drop FK before table
        $this->assertStringContainsString(
            "dropForeign('post_id')",
            $content,
        );
    }

    public function test_generate_drop_table(): void
    {
        $file = $this->generator->generateDropTable(
            'legacy',
            [
                [
                    'name' => 'id',
                    'type' => 'bigint',
                    'type_name' => 'bigint',
                    'auto_increment' => true,
                ],
            ],
            [],
            [],
            $this->outputPath,
            '2026-02-25',
        );

        $this->assertFileExists($file);

        $content = file_get_contents($file);
        $this->assertStringContainsString(
            "dropIfExists('legacy')",
            $content,
        );
        // down() recreates
        $this->assertStringContainsString(
            "Schema::create('legacy'",
            $content,
        );
    }

    public function test_generate_add_index(): void
    {
        $file = $this->generator->generateAddIndex(
            'users',
            [
                'columns' => ['email'],
                'unique' => true,
            ],
            $this->outputPath,
            '2026-02-25',
        );

        $this->assertFileExists($file);

        $content = file_get_contents($file);
        $this->assertStringContainsString(
            "unique('email')",
            $content,
        );
        $this->assertStringContainsString(
            "dropUnique('email')",
            $content,
        );
    }

    public function test_generate_add_foreign_key(): void
    {
        $file = $this->generator->generateAddForeignKey(
            'posts',
            [
                'columns' => ['user_id'],
                'foreign_table' => 'users',
                'foreign_columns' => ['id'],
                'on_update' => 'NO ACTION',
                'on_delete' => 'CASCADE',
            ],
            $this->outputPath,
            '2026-02-25',
        );

        $this->assertFileExists($file);

        $content = file_get_contents($file);
        $this->assertStringContainsString(
            "foreign('user_id')",
            $content,
        );
        $this->assertStringContainsString(
            "dropForeign('user_id')",
            $content,
        );
    }

    public function test_generated_file_is_valid_php(): void
    {
        $file = $this->generator->generateAddColumn(
            'users',
            [
                'name' => 'age',
                'type' => 'integer',
                'type_name' => 'integer',
                'nullable' => true,
            ],
            $this->outputPath,
            '2026-02-25',
        );

        $result = exec(
            "php -l {$file} 2>&1",
            $output,
            $exitCode,
        );

        $this->assertSame(
            0,
            $exitCode,
            "Generated file has syntax errors: "
            . implode("\n", $output),
        );
    }

    public function test_filenames_are_sequential(): void
    {
        $file1 = $this->generator->generateAddColumn(
            'users',
            ['name' => 'a', 'type' => 'text', 'type_name' => 'text'],
            $this->outputPath,
            '2026-02-25',
        );

        $file2 = $this->generator->generateAddColumn(
            'users',
            ['name' => 'b', 'type' => 'text', 'type_name' => 'text'],
            $this->outputPath,
            '2026-02-25',
        );

        $this->assertNotSame(
            basename($file1),
            basename($file2),
        );

        // Both should have same date prefix
        $this->assertStringStartsWith(
            '2026_02_25_',
            basename($file1),
        );
        $this->assertStringStartsWith(
            '2026_02_25_',
            basename($file2),
        );
    }

    public function test_generated_file_has_correct_structure(): void
    {
        $file = $this->generator->generateAddColumn(
            'users',
            [
                'name' => 'phone',
                'type' => 'varchar(20)',
                'type_name' => 'varchar',
                'nullable' => true,
            ],
            $this->outputPath,
            '2026-02-25',
        );

        $content = file_get_contents($file);

        $this->assertStringContainsString(
            'declare(strict_types=1)',
            $content,
        );
        $this->assertStringContainsString(
            'use Illuminate\Database\Migrations\Migration',
            $content,
        );
        $this->assertStringContainsString(
            'use Illuminate\Database\Schema\Blueprint',
            $content,
        );
        $this->assertStringContainsString(
            'extends Migration',
            $content,
        );
        $this->assertStringContainsString(
            'public function up(): void',
            $content,
        );
        $this->assertStringContainsString(
            'public function down(): void',
            $content,
        );
    }
}
