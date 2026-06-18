<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Mcp\Commands;

use Illuminate\Console\Command;
use Isaidgitmenow\LaravelErrors\ErrorManager;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\PromptHandler;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\ResourceHandler;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\ToolHandler;
use Isaidgitmenow\LaravelErrors\Mcp\McpServer;

/**
 * Artisan command that starts the native MCP STDIO server.
 *
 * Usage (in Claude Desktop config):
 *   {
 *     "command": "php",
 *     "args": ["artisan", "errors:mcp"],
 *     "cwd": "/path/to/your/laravel/app"
 *   }
 *
 * Safety constraints:
 * - Immediately aborts on non-local environments.
 * - Tells ErrorManager to bypass ANSI console rendering to prevent
 *   colorized ANSI text from leaking into the STDIO JSON stream.
 * - Cleans up the mock_reporters lock file on exit so reporters are
 *   never accidentally silenced after the server process ends.
 */
class ErrorsMcpCommand extends Command
{
    protected $signature   = 'errors:mcp';
    protected $description = 'Start the laravel-errors MCP STDIO server (local environment only).';

    public function handle(): int
    {
        // ── Environment lockdown ────────────────────────────────────────────────
        if (! app()->environment('local')) {
            fwrite(STDERR, "[errors:mcp] Aborted: MCP server only runs in the local environment.\n");
            return self::FAILURE;
        }

        // ── Lock-file cleanup on any exit ───────────────────────────────────────
        // The mock_reporters tool writes a lock file to silence external reporters
        // during AI-driven simulations. Register a shutdown function so the file
        // is always removed when this process ends, even on fatal errors or SIGTERM.
        // (SIGKILL is inherently unhandleable and intentionally excluded.)
        $lockPath = storage_path('framework/mcp_mock_reporters.lock');
        register_shutdown_function(static function () use ($lockPath): void {
            @unlink($lockPath);
        });

        // ── ANSI bypass ─────────────────────────────────────────────────────────
        // Prevent Laravel's ConsoleExceptionRenderer from dumping colorized ANSI
        // text to STDOUT, which would instantly break the JSON-RPC protocol.
        ErrorManager::bypassConsoleExceptions();

        // ── Start the server ────────────────────────────────────────────────────
        $server = new McpServer(
            toolHandler:     new ToolHandler(),
            resourceHandler: new ResourceHandler(),
            promptHandler:   new PromptHandler(),
        );

        $server->run();

        // Explicit cleanup for graceful exits (shutdown function also covers this,
        // but being explicit here makes the intent clear to future readers).
        @unlink($lockPath);

        return self::SUCCESS;
    }
}
