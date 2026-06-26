<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Renderers\ApiRenderer;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Support\DataSanitizer;

// Fixture: attribute cannot be placed on anonymous class instantiation expressions
#[HttpCode(403)]
class ForbiddenException extends RuntimeException {}

beforeEach(fn () => ExceptionInspector::flushCache());

describe('ApiRenderer', function () {

    it('returns a JSON response with 500 for unknown exceptions', function () {
        $renderer = new ApiRenderer();
        $request = Request::create('/api/test', 'GET');
        $response = $renderer->render(new RuntimeException('Something failed'), $request);

        expect($response->getStatusCode())->toBe(500);
        $body = json_decode($response->getContent(), true);
        expect($body)->toHaveKey('message');
        expect($body['message'])->toBe('Something failed');
    });

    it('uses HttpCode attribute for the response status', function () {
        ExceptionInspector::flushCache();
        $renderer = new ApiRenderer();
        $request = Request::create('/api/test', 'GET');
        $response = $renderer->render(new ForbiddenException('Forbidden'), $request);

        expect($response->getStatusCode())->toBe(403);
    });

    it('uses the json_formatter closure when provided', function () {
        $formatter = fn (\Throwable $e, Request $r) => ['error' => $e->getMessage(), 'code' => 42];

        $renderer = new ApiRenderer(config: ['json_formatter' => $formatter]);
        $request = Request::create('/api/test', 'GET');
        $response = $renderer->render(new RuntimeException('Custom format'), $request);

        $body = json_decode($response->getContent(), true);
        expect($body['error'])->toBe('Custom format');
        expect($body['code'])->toBe(42);
    });

});

describe('DataSanitizer', function () {

    it('redacts sensitive keys', function () {
        $data = [
            'email'    => 'user@example.com',
            'password' => 'secret123',
            'token'    => 'abc.def.ghi',
        ];

        $sanitized = DataSanitizer::sanitize($data, ['password', 'token']);

        expect($sanitized['email'])->toBe('user@example.com');
        expect($sanitized['password'])->toBe('[REDACTED]');
        expect($sanitized['token'])->toBe('[REDACTED]');
    });

    it('sanitizes nested arrays', function () {
        $data = [
            'user' => [
                'name'     => 'John',
                'password' => 'secret',
            ],
        ];

        $sanitized = DataSanitizer::sanitize($data, ['password']);

        expect($sanitized['user']['name'])->toBe('John');
        expect($sanitized['user']['password'])->toBe('[REDACTED]');
    });

    it('performs case-insensitive key matching', function () {
        $data = ['Password' => 'secret', 'API_TOKEN' => 'abc'];

        $sanitized = DataSanitizer::sanitize($data, ['password', 'token']);

        expect($sanitized['Password'])->toBe('[REDACTED]');
        expect($sanitized['API_TOKEN'])->toBe('[REDACTED]');
    });

    it('converts Closures to a safe string representation', function () {
        $data = [
            'callback' => fn () => 'test',
            'name'     => 'John',
        ];

        $sanitized = DataSanitizer::sanitize($data, []);

        expect($sanitized['callback'])->toBe('[Closure]');
        expect($sanitized['name'])->toBe('John');
    });

    it('converts Stringable objects to their string value', function () {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $data = ['item' => $stringable];
        $sanitized = DataSanitizer::sanitize($data, []);

        expect($sanitized['item'])->toBe('stringable-value');
    });

    it('converts regular objects to their class name', function () {
        $data = ['obj' => new \stdClass()];
        $sanitized = DataSanitizer::sanitize($data, []);

        expect($sanitized['obj'])->toBe('[stdClass]');
    });

    it('converts resources to a safe string representation', function () {
        $handle = fopen('php://memory', 'r');
        $data = ['stream' => $handle];
        $sanitized = DataSanitizer::sanitize($data, []);

        expect($sanitized['stream'])->toBe('[resource]');
        fclose($handle);
    });

});
