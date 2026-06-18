<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Mcp;

use Isaidgitmenow\LaravelErrors\Mcp\Handlers\PromptHandler;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\ResourceHandler;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\ToolHandler;
use Throwable;

/**
 * Native PHP JSON-RPC 2.0 STDIO server implementing the Model Context Protocol.
 *
 * Design constraints (from mcp.md):
 * - Zero third-party MCP dependencies — pure PHP + Laravel primitives.
 * - Every JSON response MUST be a single flat line (no JSON_PRETTY_PRINT) terminated by \n.
 * - json_validate() used to check payloads before decode.
 * - ob_start() / ob_get_clean() micro-buffer per request to catch rogue output → STDERR.
 * - stream_get_line(STDIN, 1MB, "\n") for robust line reading.
 * - register_shutdown_function() catches fatal errors and emits a JSON-RPC error.
 * - pcntl_async_signals + SIGINT/SIGTERM handlers (degrades gracefully on Windows).
 * - gc_collect_cycles() every 100 requests; app()->forgetInstance() between requests.
 * - DB::reconnect() on PDO disconnects.
 * - set_time_limit(30) during tool execution.
 */
final class McpServer
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'laravel-errors-mcp';
    private const SERVER_VERSION   = '1.0.0';
    private const READ_BUFFER      = 1048576; // 1 MB

    // JSON-RPC 2.0 error codes
    private const ERR_PARSE_ERROR      = -32700;
    private const ERR_INVALID_REQUEST  = -32600;
    private const ERR_METHOD_NOT_FOUND = -32601;
    private const ERR_INVALID_PARAMS   = -32602;
    private const ERR_INTERNAL         = -32603;

    private bool $running = false;
    private int  $requestCount = 0;

    /** @var resource */
    private $outputStream;
    /** @var resource */
    private $inputStream;

    public function __construct(
        private readonly ToolHandler     $toolHandler,
        private readonly ResourceHandler $resourceHandler,
        private readonly PromptHandler   $promptHandler,
        mixed $outputStream = null,
        mixed $inputStream = null,
    ) {
        $this->outputStream = $outputStream ?: STDOUT;
        $this->inputStream  = $inputStream ?: STDIN;
    }

    /**
     * Start the STDIO event loop.
     * Blocks until EOF on STDIN or a signal terminates the process.
     */
    public function run(): void
    {
        $this->harden();
        $this->running = true;

        while ($this->running) {
            // Buffer 1: wrap the blocking STDIN read so any output during the wait
            // (e.g. from Laravel's own boot-time echo) is captured.
            ob_start();
            $line   = stream_get_line($this->inputStream, self::READ_BUFFER, "\n");
            $leaked = ob_get_clean();
            if ($leaked !== '' && $leaked !== false) {
                fwrite(STDERR, "[mcp:leaked-output] " . $leaked . "\n");
            }

            // EOF — client closed the connection.
            if ($line === false) {
                break;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // Buffer 2: Bug 3 fix — wrap dispatch too so rogue echo/print from tool
            // handlers or the packages they call cannot reach STDOUT and break the
            // JSON-RPC stream. handleLine() writes its own json-encoded response via
            // fwrite(STDOUT, ...) which bypasses the output buffer correctly.
            ob_start();
            $this->handleLine($trimmed);
            $leaked = ob_get_clean();
            if ($leaked !== '' && $leaked !== false) {
                fwrite(STDERR, "[mcp:leaked-output] " . $leaked . "\n");
            }

            $this->requestCount++;

            // Memory hygiene every 100 requests.
            if ($this->requestCount % 100 === 0) {
                gc_collect_cycles();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Request Handling
    // -------------------------------------------------------------------------

    /**
     * Parse and dispatch a single JSON-RPC line, then emit the response.
     */
    private function handleLine(string $line): void
    {
        // 1. Validate JSON before decode (php 8.3+).
        if (function_exists('json_validate') && ! json_validate($line)) {
            $this->emitError(null, self::ERR_PARSE_ERROR, 'Parse error: invalid JSON');
            return;
        }

        $payload = json_decode($line, associative: true);

        if (! is_array($payload)) {
            $this->emitError(null, self::ERR_PARSE_ERROR, 'Parse error: invalid JSON');
            return;
        }

        // 2. Validate JSON-RPC 2.0 envelope.
        if (($payload['jsonrpc'] ?? null) !== '2.0') {
            $this->emitError($payload['id'] ?? null, self::ERR_INVALID_REQUEST, 'Invalid Request: jsonrpc must be "2.0"');
            return;
        }

        $id     = $payload['id'] ?? null;
        $method = $payload['method'] ?? null;
        $params = $payload['params'] ?? [];

        if (! is_string($method) || $method === '') {
            $this->emitError($id, self::ERR_INVALID_REQUEST, 'Invalid Request: method is required');
            return;
        }

        // 3. Dispatch.
        try {
            $this->dispatch($id, $method, (array) $params);
        } catch (Throwable $e) {
            $this->emitError($id, self::ERR_INTERNAL, $e->getMessage());
        }
    }

    /**
     * Dispatch the JSON-RPC method to the appropriate handler.
     *
     * @param mixed $id
     * @param array<string, mixed> $params
     */
    private function dispatch(mixed $id, string $method, array $params): void
    {
        // Notifications (no id) are fire-and-forget — no response required.
        $isNotification = $id === null;

        match ($method) {
            'initialize'             => $this->handleInitialize($id, $params),
            'notifications/initialized' => null, // Acknowledge silently
            'ping'                   => $this->emitResult($id, new \stdClass()),
            'tools/list'             => $this->handleToolsList($id),
            'tools/call'             => $this->handleToolsCall($id, $params),
            'resources/list'         => $this->handleResourcesList($id),
            'resources/read'         => $this->handleResourcesRead($id, $params),
            'prompts/list'           => $this->handlePromptsList($id),
            'prompts/get'            => $this->handlePromptsGet($id, $params),
            default                  => $isNotification
                ? null
                : $this->emitError($id, self::ERR_METHOD_NOT_FOUND, "Method not found: {$method}"),
        };
    }

    // -------------------------------------------------------------------------
    // Protocol Handlers
    // -------------------------------------------------------------------------

    /**
     * @param mixed $id
     * @param array<string, mixed> $params
     */
    private function handleInitialize(mixed $id, array $params): void
    {
        $this->emitResult($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo'      => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'capabilities' => [
                'tools'     => ['listChanged' => false],
                'resources' => ['listChanged' => false, 'subscribe' => false],
                'prompts'   => ['listChanged' => false],
            ],
        ]);
    }

    /**
     * @param mixed $id
     */
    private function handleToolsList(mixed $id): void
    {
        $this->emitResult($id, ['tools' => $this->toolHandler->list()]);
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $params
     */
    private function handleToolsCall(mixed $id, array $params): void
    {
        $name      = $params['name'] ?? null;
        $arguments = (array) ($params['arguments'] ?? []);

        if (! is_string($name) || $name === '') {
            $this->emitError($id, self::ERR_INVALID_PARAMS, 'tools/call: "name" parameter is required');
            return;
        }

        try {
            set_time_limit(30);
            $result  = $this->toolHandler->call($name, $arguments);
            $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                $this->emitError($id, self::ERR_INTERNAL, 'Tool result could not be JSON-encoded: ' . json_last_error_msg());
                return;
            }

            $this->emitResult($id, [
                'content' => [
                    ['type' => 'text', 'text' => $encoded],
                ],
                'isError' => false,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->emitError($id, self::ERR_INVALID_PARAMS, $e->getMessage());
        }
    }

    /**
     * @param mixed $id
     */
    private function handleResourcesList(mixed $id): void
    {
        $this->emitResult($id, ['resources' => $this->resourceHandler->list()]);
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $params
     */
    private function handleResourcesRead(mixed $id, array $params): void
    {
        $uri = $params['uri'] ?? null;

        if (! is_string($uri) || $uri === '') {
            $this->emitError($id, self::ERR_INVALID_PARAMS, 'resources/read: "uri" parameter is required');
            return;
        }

        try {
            $content = $this->resourceHandler->read($uri);
            $this->emitResult($id, ['contents' => [$content]]);
        } catch (\InvalidArgumentException $e) {
            $this->emitError($id, self::ERR_INVALID_PARAMS, $e->getMessage());
        }
    }

    /**
     * @param mixed $id
     */
    private function handlePromptsList(mixed $id): void
    {
        $this->emitResult($id, ['prompts' => $this->promptHandler->list()]);
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $params
     */
    private function handlePromptsGet(mixed $id, array $params): void
    {
        $name      = $params['name'] ?? null;
        $arguments = (array) ($params['arguments'] ?? []);

        if (! is_string($name) || $name === '') {
            $this->emitError($id, self::ERR_INVALID_PARAMS, 'prompts/get: "name" parameter is required');
            return;
        }

        try {
            $result = $this->promptHandler->get($name, $arguments);
            $this->emitResult($id, $result);
        } catch (\InvalidArgumentException $e) {
            $this->emitError($id, self::ERR_INVALID_PARAMS, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Emission Helpers
    // -------------------------------------------------------------------------

    /**
     * Emit a JSON-RPC 2.0 success response.
     *
     * @param mixed $id
     * @param mixed $result
     */
    private function emitResult(mixed $id, mixed $result): void
    {
        if ($id === null) {
            // Notifications do not receive responses.
            return;
        }

        $this->emit([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ]);
    }

    /**
     * Emit a JSON-RPC 2.0 error response.
     *
     * @param mixed $id
     */
    private function emitError(mixed $id, int $code, string $message): void
    {
        $this->emit([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ]);
    }

    /**
     * Encode and write a single JSON line to STDOUT.
     * CRITICAL: No JSON_PRETTY_PRINT — STDIO transport requires flat single-line messages.
     *
     * @param array<string, mixed> $payload
     */
    private function emit(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            // Encoding failed — emit a safe internal error instead.
            $json = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error: response encoding failed"}}';
        }

        fwrite($this->outputStream, $json . "\n");
        fflush($this->outputStream);
    }

    // -------------------------------------------------------------------------
    // Daemon Hardening
    // -------------------------------------------------------------------------

    /**
     * Apply all daemon hardening measures described in mcp.md §1.
     */
    private function harden(): void
    {
        // 1. PHP engine pollution: redirect display_errors to STDERR.
        ini_set('display_errors', 'stderr');

        // 2. Intercept non-fatal errors so they never pollute STDOUT.
        set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
            $severity = match ($errno) {
                E_WARNING, E_USER_WARNING     => 'WARNING',
                E_NOTICE, E_USER_NOTICE       => 'NOTICE',
                E_DEPRECATED, E_USER_DEPRECATED => 'DEPRECATED',
                default                       => 'ERROR',
            };
            fwrite(STDERR, "[mcp:{$severity}] {$errstr} in {$errfile}:{$errline}\n");
            return true; // Suppress standard PHP error handler
        });

        // 3. Fatal error death-rattle: emit a JSON-RPC error on shutdown.
        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], strict: true)) {
                $msg  = "[{$error['file']}:{$error['line']}] {$error['message']}";
                $json = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":' . json_encode($msg) . '}}';
                fwrite($this->outputStream, $json . "\n");
                fflush($this->outputStream);
            }
        });

        // 4. Graceful shutdown on SIGINT / SIGTERM (PCNTL, not available on Windows).
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $shutdown = function (): void {
                $this->running = false;
            };
            pcntl_signal(SIGINT, $shutdown);
            pcntl_signal(SIGTERM, $shutdown);
        }
    }
}
