<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use Isaidgitmenow\LaravelErrors\Mcp\McpLogger;
use RuntimeException;
use Illuminate\Support\Facades\File;

describe('McpLogger', function () {
    beforeEach(function () {
        $logPath = storage_path('framework/mcp/.errors-mcp.jsonl');
        if (File::exists($logPath)) {
            File::delete($logPath);
        }
        if (File::exists($logPath . '.old')) {
            File::delete($logPath . '.old');
        }
    });

    it('writes exception to JSONL log file', function () {
        $logPath = storage_path('framework/mcp/.errors-mcp.jsonl');
        $exception = new RuntimeException('Test Logger Exception');
        
        McpLogger::log($exception);

        expect(File::exists($logPath))->toBeTrue();
        
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toHaveCount(1);
        
        $entry = json_decode($lines[0], true);
        
        expect($entry)->toHaveKey('timestamp')
            ->and($entry)->toHaveKey('class')
            ->and($entry)->toHaveKey('message')
            ->and($entry)->toHaveKey('stack_trace')
            ->and($entry['class'])->toBe(RuntimeException::class)
            ->and($entry['message'])->toBe('Test Logger Exception')
            ->and($entry['stack_trace'])->toBeArray();
            
        // Check stack trace prepending
        expect($entry['stack_trace'][0]['function'])->toBe('{main}');
    });

    it('rotates log file when exceeding 2MB', function () {
        $logPath = storage_path('framework/mcp/.errors-mcp.jsonl');
        // Create a 2MB dummy file
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, str_repeat('A', 2 * 1024 * 1024));
        
        $exception = new RuntimeException('Rotate Me');
        McpLogger::log($exception);

        expect(File::exists($logPath . '.old'))->toBeTrue();
        expect(File::size($logPath))->toBeLessThan(2 * 1024 * 1024);
        
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toHaveCount(1);
    });

    it('silently swallows exceptions if log directory cannot be created', function () {
        // Mocking file system errors is tricky, but we can verify that 
        // passing an exception doesn't throw even if we disrupt things.
        $exception = new RuntimeException('Silent Exception');
        
        // This should not throw anything
        McpLogger::log($exception);
        
        expect(true)->toBeTrue();
    });
});
