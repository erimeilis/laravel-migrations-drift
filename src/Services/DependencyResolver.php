<?php

declare(strict_types=1);

namespace EriMeilis\MigrationDrift\Services;

use Illuminate\Support\Str;

class DependencyResolver
{
    /**
     * Topological sort of tables by foreign key dependencies.
     * Tables with no dependencies come first, dependent
     * tables come after their references.
     *
     * @param string[] $tables
     * @param array<string, array<int, array<string, mixed>>> $foreignKeys Keyed by table name
     * @return string[] Ordered table list
     */
    public function topologicalSort(
        array $tables,
        array $foreignKeys,
    ): array {
        $graph = $this->buildDependencyGraph(
            $tables,
            $foreignKeys,
        );

        $sorted = [];
        $visited = [];
        $visiting = [];

        foreach ($tables as $table) {
            if (!isset($visited[$table])) {
                $this->dfs(
                    $table,
                    $graph,
                    $visited,
                    $visiting,
                    $sorted,
                );
            }
        }

        return $sorted;
    }

    /**
     * Detect circular FK dependencies.
     *
     * @param string[] $tables
     * @param array<string, array<int, array<string, mixed>>> $foreignKeys
     * @return array<int, string[]> List of cycles, each cycle is an array of table names
     */
    public function detectCircularDependencies(
        array $tables,
        array $foreignKeys,
    ): array {
        $graph = $this->buildDependencyGraph(
            $tables,
            $foreignKeys,
        );

        $cycles = [];
        $visited = [];
        $visiting = [];
        $path = [];

        foreach ($tables as $table) {
            if (!isset($visited[$table])) {
                $this->detectCycles(
                    $table,
                    $graph,
                    $visited,
                    $visiting,
                    $path,
                    $cycles,
                );
            }
        }

        return $this->deduplicateCycles($cycles);
    }

    /**
     * Get the creation order: tables that should be created
     * first (no deps) → tables with deps last.
     *
     * @param string[] $tables
     * @param array<string, array<int, array<string, mixed>>> $foreignKeys
     * @return string[]
     */
    public function getCreationOrder(
        array $tables,
        array $foreignKeys,
    ): array {
        return $this->topologicalSort(
            $tables,
            $foreignKeys,
        );
    }

    /**
     * Get the drop order: reverse of creation order.
     * Dependent tables are dropped first.
     *
     * @param string[] $tables
     * @param array<string, array<int, array<string, mixed>>> $foreignKeys
     * @return string[]
     */
    public function getDropOrder(
        array $tables,
        array $foreignKeys,
    ): array {
        return array_reverse(
            $this->topologicalSort($tables, $foreignKeys),
        );
    }

    /**
     * Detect pivot tables heuristically.
     * A pivot table has exactly 2 FK columns and its name
     * is a combination of the two referenced tables.
     *
     * @param string[] $tables
     * @param array<string, array<int, array<string, mixed>>> $foreignKeys
     * @return string[] Table names that appear to be pivots
     */
    public function detectPivotTables(
        array $tables,
        array $foreignKeys,
    ): array {
        $pivots = [];

        foreach ($tables as $table) {
            $fks = $foreignKeys[$table] ?? [];

            if (count($fks) !== 2) {
                continue;
            }

            $refTables = array_map(
                fn (array $fk): string => $fk['foreign_table'] ?? '',
                $fks,
            );

            sort($refTables);

            // Check if table name contains both referenced
            // table names (common pivot naming convention)
            $allFound = true;

            foreach ($refTables as $ref) {
                $singular = Str::singular($ref);

                if (
                    !str_contains($table, $ref)
                    && !str_contains($table, $singular)
                ) {
                    $allFound = false;

                    break;
                }
            }

            if ($allFound) {
                $pivots[] = $table;
            }
        }

        return $pivots;
    }

    /**
     * Deduplicate cycles by normalizing rotation.
     *
     * @param array<int, string[]> $cycles
     * @return array<int, string[]>
     */
    private function deduplicateCycles(array $cycles): array
    {
        $seen = [];
        $unique = [];

        foreach ($cycles as $cycle) {
            // Remove the repeated last element
            $clean = array_slice($cycle, 0, -1);

            // Rotate to start with lexicographically smallest
            $minIdx = 0;
            for ($i = 1; $i < count($clean); $i++) {
                if ($clean[$i] < $clean[$minIdx]) {
                    $minIdx = $i;
                }
            }
            $normalized = array_merge(
                array_slice($clean, $minIdx),
                array_slice($clean, 0, $minIdx),
            );

            $key = implode(',', $normalized);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                // Re-add the closing element
                $normalized[] = $normalized[0];
                $unique[] = $normalized;
            }
        }

        return $unique;
    }

    /**
     * Build adjacency list: table → [tables it depends on].
     *
     * @param string[] $tables
     * @param array<string, array<int, array<string, mixed>>> $foreignKeys
     * @return array<string, string[]>
     */
    private function buildDependencyGraph(
        array $tables,
        array $foreignKeys,
    ): array {
        $graph = [];

        foreach ($tables as $table) {
            $graph[$table] = [];
        }

        foreach ($foreignKeys as $table => $fks) {
            foreach ($fks as $fk) {
                $refTable = $fk['foreign_table'] ?? '';

                if (
                    $refTable !== ''
                    && $refTable !== $table
                    && in_array($refTable, $tables, true)
                ) {
                    $graph[$table][] = $refTable;
                }
            }
        }

        return $graph;
    }

    /**
     * @param array<string, string[]> $graph
     * @param array<string, bool> $visited
     * @param array<string, bool> $visiting
     * @param string[] $sorted
     */
    private function dfs(
        string $node,
        array $graph,
        array &$visited,
        array &$visiting,
        array &$sorted,
    ): void {
        if (isset($visiting[$node])) {
            // Circular dependency — skip to avoid infinite
            // loop, place at end
            return;
        }

        if (isset($visited[$node])) {
            return;
        }

        $visiting[$node] = true;

        foreach (($graph[$node] ?? []) as $dep) {
            $this->dfs(
                $dep,
                $graph,
                $visited,
                $visiting,
                $sorted,
            );
        }

        unset($visiting[$node]);
        $visited[$node] = true;
        $sorted[] = $node;
    }

    /**
     * @param array<string, string[]> $graph
     * @param array<string, bool> $visited
     * @param array<string, bool> $visiting
     * @param string[] $path
     * @param array<int, string[]> $cycles
     */
    private function detectCycles(
        string $node,
        array $graph,
        array &$visited,
        array &$visiting,
        array &$path,
        array &$cycles,
    ): void {
        if (isset($visiting[$node])) {
            // Found cycle: extract from path
            $cycleStart = array_search($node, $path, true);

            if ($cycleStart !== false) {
                $cycle = array_slice($path, $cycleStart);
                $cycle[] = $node;
                $cycles[] = $cycle;
            }

            return;
        }

        if (isset($visited[$node])) {
            return;
        }

        $visiting[$node] = true;
        $path[] = $node;

        foreach (($graph[$node] ?? []) as $dep) {
            $this->detectCycles(
                $dep,
                $graph,
                $visited,
                $visiting,
                $path,
                $cycles,
            );
        }

        array_pop($path);
        unset($visiting[$node]);
        $visited[$node] = true;
    }
}
