<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Tests\Unit;

use EriMeilis\MigrationDrift\Concerns\InteractivePrompts;
use EriMeilis\MigrationDrift\Tests\TestCase;
use Illuminate\Console\Command;

class TestablePromptCommand extends Command
{
    use InteractivePrompts;

    protected $signature = 'test:prompts
        {--connection=}
        {--path=}
        {--force}';

    public function handle(): int
    {
        return self::SUCCESS;
    }

    public function testSelectConnection(): string
    {
        return $this->selectConnection();
    }

    public function testGetMigrationFileCount(
        string $path,
    ): int {
        return $this->getMigrationFileCount($path);
    }

    public function testIsInteractive(): bool
    {
        return $this->isInteractive();
    }
}

class InteractivePromptsTest extends TestCase
{
    public function test_get_migration_file_count_php_only(): void
    {
        $dir = $this->createTempDirectory();

        try {
            file_put_contents(
                $dir . '/migration_one.php',
                '<?php // migration',
            );
            file_put_contents(
                $dir . '/migration_two.php',
                '<?php // migration',
            );
            file_put_contents(
                $dir . '/readme.txt',
                'not a migration',
            );
            file_put_contents(
                $dir . '/notes.md',
                '# notes',
            );

            $command = $this->createCommand();
            $count = $command->testGetMigrationFileCount(
                $dir,
            );

            $this->assertSame(2, $count);
        } finally {
            $this->cleanTempDirectory($dir);
        }
    }

    public function test_is_interactive_false_in_tests(): void
    {
        $command = $this->createCommand();

        $this->assertFalse($command->testIsInteractive());
    }

    public function test_select_connection_from_option(): void
    {
        $command = $this->createCommand([
            '--connection' => 'pgsql',
        ]);

        $this->assertSame(
            'pgsql',
            $command->testSelectConnection(),
        );
    }

    public function test_select_connection_from_config(): void
    {
        config()->set(
            'migration-drift.connection',
            'mysql',
        );

        $command = $this->createCommand();

        $this->assertSame(
            'mysql',
            $command->testSelectConnection(),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createCommand(
        array $options = [],
    ): TestablePromptCommand {
        $command = new TestablePromptCommand();
        $command->setLaravel($this->app);

        $input = new \Symfony\Component\Console\Input\ArrayInput(
            $options,
            $command->getDefinition(),
        );
        $output = new \Symfony\Component\Console\Output\NullOutput();

        $command->setInput($input);
        $command->setOutput(
            new \Illuminate\Console\OutputStyle(
                $input,
                $output,
            ),
        );

        return $command;
    }
}
