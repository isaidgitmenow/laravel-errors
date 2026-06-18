<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use Isaidgitmenow\LaravelErrors\Mcp\McpServer;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\ToolHandler;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\ResourceHandler;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\PromptHandler;
use ReflectionClass;
use ReflectionMethod;

describe('McpServer', function () {
    $invokeHandleLine = function (McpServer $server, string $line) {
        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('handleLine');
        $method->setAccessible(true);
        $method->invoke($server, $line);
    };

    beforeEach(function () {
        $this->outputStream = fopen('php://memory', 'w+');
        $this->inputStream = fopen('php://memory', 'r+');
    });

    afterEach(function () {
        fclose($this->outputStream);
        fclose($this->inputStream);
    });

    it('emits parse error on invalid JSON', function () use ($invokeHandleLine) {
        $server = new McpServer(new ToolHandler(), new ResourceHandler(), new PromptHandler(), $this->outputStream, $this->inputStream);
        $invokeHandleLine($server, '{invalid json');

        rewind($this->outputStream);
        $output = stream_get_contents($this->outputStream);
        
        $decoded = json_decode(trim($output), true);
        expect($decoded)->toHaveKey('error')
            ->and($decoded['error']['code'])->toBe(-32700)
            ->and($decoded['error']['message'])->toContain('Parse error');
    });

    it('emits invalid request if jsonrpc is missing', function () use ($invokeHandleLine) {
        $server = new McpServer(new ToolHandler(), new ResourceHandler(), new PromptHandler(), $this->outputStream, $this->inputStream);
        $invokeHandleLine($server, json_encode(['method' => 'ping', 'id' => 1]));

        rewind($this->outputStream);
        $output = stream_get_contents($this->outputStream);
        
        $decoded = json_decode(trim($output), true);
        expect($decoded)->toHaveKey('error')
            ->and($decoded['error']['code'])->toBe(-32600)
            ->and($decoded['error']['message'])->toContain('jsonrpc must be "2.0"');
    });

    it('emits invalid request if method is missing', function () use ($invokeHandleLine) {
        $server = new McpServer(new ToolHandler(), new ResourceHandler(), new PromptHandler(), $this->outputStream, $this->inputStream);
        $invokeHandleLine($server, json_encode(['jsonrpc' => '2.0', 'id' => 1]));

        rewind($this->outputStream);
        $output = stream_get_contents($this->outputStream);
        
        $decoded = json_decode(trim($output), true);
        expect($decoded)->toHaveKey('error')
            ->and($decoded['error']['code'])->toBe(-32600)
            ->and($decoded['error']['message'])->toContain('method is required');
    });

    it('handles initialize method correctly', function () use ($invokeHandleLine) {
        $server = new McpServer(new ToolHandler(), new ResourceHandler(), new PromptHandler(), $this->outputStream, $this->inputStream);
        $invokeHandleLine($server, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]));

        rewind($this->outputStream);
        $output = stream_get_contents($this->outputStream);
        
        $decoded = json_decode(trim($output), true);
        expect($decoded)->toHaveKey('result')
            ->and($decoded['result'])->toHaveKey('protocolVersion')
            ->and($decoded['result'])->toHaveKey('serverInfo')
            ->and($decoded['result']['serverInfo']['name'])->toBe('laravel-errors-mcp');
    });

    it('handles tools/list method correctly', function () use ($invokeHandleLine) {
        $server = new McpServer(new ToolHandler(), new ResourceHandler(), new PromptHandler(), $this->outputStream, $this->inputStream);
        $invokeHandleLine($server, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]));

        rewind($this->outputStream);
        $output = stream_get_contents($this->outputStream);
        
        $decoded = json_decode(trim($output), true);
        expect($decoded)->toHaveKey('result')
            ->and($decoded['result'])->toHaveKey('tools')
            ->and($decoded['result']['tools'])->toBeArray();
    });

    it('handles ping method correctly', function () use ($invokeHandleLine) {
        $server = new McpServer(new ToolHandler(), new ResourceHandler(), new PromptHandler(), $this->outputStream, $this->inputStream);
        $invokeHandleLine($server, json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping', 'params' => []]));

        rewind($this->outputStream);
        $output = stream_get_contents($this->outputStream);
        
        $decoded = json_decode(trim($output), true);
        expect($decoded)->toHaveKey('result')
            ->and($decoded['result'])->toBeArray()->toBeEmpty();
    });

    it('handles method not found', function () use ($invokeHandleLine) {
        $server = new McpServer(new ToolHandler(), new ResourceHandler(), new PromptHandler(), $this->outputStream, $this->inputStream);
        $invokeHandleLine($server, json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'unknown/method', 'params' => []]));

        rewind($this->outputStream);
        $output = stream_get_contents($this->outputStream);
        
        $decoded = json_decode(trim($output), true);
        expect($decoded)->toHaveKey('error')
            ->and($decoded['error']['code'])->toBe(-32601)
            ->and($decoded['error']['message'])->toContain('Method not found');
    });

    it('handles notifications silently', function () use ($invokeHandleLine) {
        $server = new McpServer(new ToolHandler(), new ResourceHandler(), new PromptHandler(), $this->outputStream, $this->inputStream);
        // No ID means notification
        $invokeHandleLine($server, json_encode(['jsonrpc' => '2.0', 'method' => 'unknown/notification', 'params' => []]));

        rewind($this->outputStream);
        $output = stream_get_contents($this->outputStream);
        
        expect(trim($output))->toBe('');
    });
});
