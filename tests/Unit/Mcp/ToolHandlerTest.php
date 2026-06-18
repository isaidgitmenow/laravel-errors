<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\ToolHandler;
use Throwable;
use ReflectionClass;

describe('ToolHandler', function () {
    it('returns a list of available tools', function () {
        $handler = new ToolHandler();
        $tools = $handler->list();

        expect($tools)->toBeArray();
        expect($tools)->not->toBeEmpty();
        
        $names = array_column($tools, 'name');
        expect($names)->toContain('generate_error', 'list_exceptions', 'inspect_pipeline', 'get_recent_errors', 'simulate_error', 'search_error_logs', 'get_global_context', 'mock_reporters');
        
        foreach ($tools as $tool) {
            expect($tool)->toHaveKey('name')
                ->and($tool)->toHaveKey('description')
                ->and($tool)->toHaveKey('inputSchema')
                ->and($tool['inputSchema'])->toHaveKey('type')
                ->and($tool['inputSchema'])->toHaveKey('properties');
        }
    });

    it('throws InvalidArgumentException for unknown tool call', function () {
        $handler = new ToolHandler();
        $handler->call('unknown_tool', []);
    })->throws(InvalidArgumentException::class, 'Unknown tool: unknown_tool');

    describe('generate_error tool', function () {
        it('throws exception if name is missing', function () {
            $handler = new ToolHandler();
            $handler->call('generate_error', []);
        })->throws(InvalidArgumentException::class, 'generate_error: "name" argument is required.');

        it('throws exception on path traversal in domain', function () {
            $handler = new ToolHandler();
            $handler->call('generate_error', ['name' => 'BadError', 'domain' => '../Malicious']);
        })->throws(InvalidArgumentException::class, 'generate_error: "domain" must not contain path traversal sequences.');

        it('calls make:error artisan command successfully', function () {
            Artisan::command('make:error {name} {--http=}', function () {
                $this->info('Success!');
                return 0;
            });

            $handler = new ToolHandler();
            $result = $handler->call('generate_error', ['name' => 'CustomError', 'http' => 400]);

            expect($result['success'])->toBeTrue()
                ->and($result['exit_code'])->toBe(0)
                ->and($result['output'])->toContain('Success!');
        });

        it('calls ddd:error artisan command when domain is provided', function () {
            Artisan::command('ddd:error {name} {--http=} {--domain=} {--report=} {--env=}', function () {
                $this->info('DDD Success!');
                return 0;
            });

            $handler = new ToolHandler();
            $result = $handler->call('generate_error', ['name' => 'DddError', 'domain' => 'Billing', 'report' => 'slack', 'env' => 'production']);

            expect($result['success'])->toBeTrue()
                ->and($result['exit_code'])->toBe(0)
                ->and($result['output'])->toContain('DDD Success!');
        });
    });

    describe('simulate_error tool', function () {
        it('throws exception if class is missing', function () {
            $handler = new ToolHandler();
            $handler->call('simulate_error', []);
        })->throws(InvalidArgumentException::class, 'simulate_error: "class" argument is required.');

        it('throws exception if class is not a Throwable subclass', function () {
            $handler = new ToolHandler();
            $handler->call('simulate_error', ['class' => \stdClass::class]);
        })->throws(InvalidArgumentException::class, 'simulate_error: [stdClass] is not a valid Throwable subclass.');

        it('simulates error successfully and rolls back transaction', function () {
            $handler = new ToolHandler();
            // Using RuntimeException as a target class
            $result = $handler->call('simulate_error', [
                'class' => RuntimeException::class,
                'constructor_args' => ['Test simulation error'],
                'context' => [
                    'url' => 'http://test.com/sim',
                    'method' => 'POST',
                    'headers' => ['X-Test' => 'Value']
                ]
            ]);

            expect($result)->toHaveKey('success')
                ->and($result['exception'])->toBe(RuntimeException::class)
                ->and($result['reported'])->toBeTrue();
                
            // Verify that request is restored
            expect(app('request')->url())->not->toBe('http://test.com/sim');
        });
    });

    describe('mock_reporters tool', function () {
        afterEach(function () {
            $lockPath = storage_path('framework/mcp_mock_reporters.lock');
            if (file_exists($lockPath)) {
                @unlink($lockPath);
            }
        });

        it('creates lock file when enabled', function () {
            $lockPath = storage_path('framework/mcp_mock_reporters.lock');
            $handler = new ToolHandler();
            $result = $handler->call('mock_reporters', ['enable' => true]);

            expect($result['mocked'])->toBeTrue()
                ->and($result['lock_file'])->toBe($lockPath);
            expect(file_exists($lockPath))->toBeTrue();
        });

        it('removes lock file when disabled', function () {
            $lockPath = storage_path('framework/mcp_mock_reporters.lock');
            file_put_contents($lockPath, 'test');
            
            $handler = new ToolHandler();
            $result = $handler->call('mock_reporters', ['enable' => false]);

            expect($result['mocked'])->toBeFalse();
            expect(file_exists($lockPath))->toBeFalse();
        });
    });

    describe('get_global_context tool', function () {
        it('returns global context data', function () {
            Context::add('user_id', 12345);
            
            $handler = new ToolHandler();
            $result = $handler->call('get_global_context', []);

            expect($result['available'])->toBeTrue()
                ->and($result['data'])->toHaveKey('user_id')
                ->and($result['data']['user_id'])->toBe(12345);
                
            Context::forget('user_id');
        });
    });

    describe('list_exceptions tool', function () {
        it('returns list of exceptions in app Exceptions path', function () {
            $handler = new ToolHandler();
            $result = $handler->call('list_exceptions', ['page' => 1, 'per_page' => 10]);

            expect($result)->toHaveKey('page')
                ->and($result)->toHaveKey('per_page')
                ->and($result)->toHaveKey('total')
                ->and($result)->toHaveKey('items')
                ->and($result['items'])->toBeArray();
        });
    });

    describe('inspect_pipeline tool', function () {
        it('returns current pipeline setup', function () {
            config(['errors.contexts' => ['DetectorClass' => 'RendererClass']]);
            
            $handler = new ToolHandler();
            $result = $handler->call('inspect_pipeline', []);

            expect($result)->toHaveKey('pipeline')
                ->and($result)->toHaveKey('reporters')
                ->and($result['pipeline'][0]['detector'])->toBe('DetectorClass')
                ->and($result['pipeline'][0]['renderer'])->toBe('RendererClass');
        });
    });
});
