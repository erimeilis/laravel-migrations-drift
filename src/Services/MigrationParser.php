<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

class MigrationParser
{
    private ?Parser $parser = null;

    /**
     * Parse a migration file and extract structured data.
     */
    public function parse(string $filePath): MigrationDefinition
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException(
                "Migration file not found: {$filePath}",
            );
        }

        $code = file_get_contents($filePath);

        if ($code === false) {
            throw new RuntimeException(
                "Failed to read migration file: {$filePath}",
            );
        }

        $this->parser ??= (new ParserFactory())
            ->createForNewestSupportedVersion();
        $stmts = $this->parser->parse($code);

        if ($stmts === null) {
            throw new RuntimeException(
                "Failed to parse migration file: {$filePath}",
            );
        }

        $visitor = new MigrationVisitor();

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        $filename = basename($filePath, '.php');

        return $visitor->toDefinition($filename);
    }

    /**
     * Parse all migration files in a directory.
     *
     * @return MigrationDefinition[]
     */
    public function parseDirectory(string $path): array
    {
        if (!is_dir($path)) {
            throw new RuntimeException(
                "Migrations directory not found: {$path}",
            );
        }

        $files = glob($path . '/*.php');

        if ($files === false) {
            return [];
        }

        sort($files);

        $definitions = [];

        foreach ($files as $file) {
            $definitions[] = $this->parse($file);
        }

        return $definitions;
    }
}
