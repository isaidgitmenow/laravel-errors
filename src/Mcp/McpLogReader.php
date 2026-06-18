<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Mcp;

/**
 * Reads structured JSONL entries from the MCP error log file.
 *
 * Responsibilities:
 * - tail(int $limit): return the last N decoded log entries.
 * - search(string $query): stream-search for entries matching a keyword.
 *
 * Both methods are hardened against missing / unreadable files.
 */
final class McpLogReader
{
    private const SEARCH_RESULT_CAP = 20;

    public function __construct(
        private readonly string $logPath,
    ) {}

    /**
     * Return the last $limit decoded log entries (newest last).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tail(int $limit = 10): array
    {
        if (! file_exists($this->logPath) || ! is_readable($this->logPath)) {
            return [];
        }

        // Read all lines efficiently without loading the whole file into one string.
        $lines = $this->readLines();

        // Take only the last $limit non-empty lines.
        $relevant = array_slice(array_filter($lines, static fn (string $l) => trim($l) !== ''), -$limit);

        return array_values(array_filter(
            array_map(static fn (string $line) => json_decode($line, associative: true) ?? null, $relevant),
            static fn ($entry) => $entry !== null,
        ));
    }

    /**
     * Stream the log file and return entries whose JSON contains the query string.
     * Capped at SEARCH_RESULT_CAP to protect context windows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array
    {
        if (! file_exists($this->logPath) || ! is_readable($this->logPath)) {
            return [];
        }

        $results = [];
        $handle  = @fopen($this->logPath, 'r');

        if ($handle === false) {
            return [];
        }

        // Build a JSON-escaped version of the query so that namespaced class names
        // (which contain backslashes) still match the raw JSON line.
        // e.g. query "App\Foo" → jsonQuery "App\\Foo" which matches the stored JSON.
        $jsonQuery = substr((string) json_encode($query), 1, -1);

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                // Fast string-level check (case-insensitive) before JSON decode to minimise
                // allocations. We try both the literal query and the JSON-escaped version so
                // that backslashes in class names are matched correctly.
                $matchesLiteral = stripos($trimmed, $query) !== false;
                $matchesJson    = $jsonQuery !== $query && stripos($trimmed, $jsonQuery) !== false;

                if (! $matchesLiteral && ! $matchesJson) {
                    continue;
                }

                $entry = json_decode($trimmed, associative: true);
                if ($entry !== null) {
                    $results[] = $entry;
                }

                if (count($results) >= self::SEARCH_RESULT_CAP) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $results;
    }

    /**
     * Read all lines from the log file into an array.
     *
     * @return string[]
     */
    private function readLines(): array
    {
        $handle = @fopen($this->logPath, 'r');

        if ($handle === false) {
            return [];
        }

        $lines = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $lines[] = $line;
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }
}
