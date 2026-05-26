<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use Isaidgitmenow\LaravelErrors\Detectors\ApiDetector;
use Isaidgitmenow\LaravelErrors\ErrorManager;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Renderers\ApiRenderer;

// Fixtures

#[WithContext(['user_id', 'order_id'])]
class ContextualManagerException extends RuntimeException
{
    public int $user_id = 99;
    public string $order_id = 'ORD-001';
}

#[WithContext(['api_token'])]
class SensitiveContextException extends RuntimeException
{
    public string $api_token = 'super-secret-token';
}

#[RateLimit(max: 2, intervalInMinutes: 1)]
class RateLimitedManagerException extends RuntimeException {}

beforeEach(fn () => ExceptionInspector::flushCache());

describe('Fix #1: Laravel Context Injection', function () {

    it('injects WithContext data into Laravel Context on report', function () {
        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'reporters'    => [],
            'sanitize'     => [],
        ]);

        $e = new ContextualManagerException('test');
        $manager->report($e);

        $contextData = Context::getHidden('exception_context');
        expect($contextData)->toBe(['user_id' => 99, 'order_id' => 'ORD-001']);
    });

    it('sanitizes sensitive data before injecting into Laravel Context', function () {
        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'reporters'    => [],
            'sanitize'     => ['api_token'],
        ]);

        $e = new SensitiveContextException('test');
        $manager->report($e);

        $contextData = Context::getHidden('exception_context');
        expect($contextData['api_token'])->toBe('[REDACTED]');
    });

    it('does not inject context when WithContext returns no data', function () {
        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'reporters'    => [],
            'sanitize'     => [],
        ]);

        // RuntimeException has no #[WithContext] attribute
        $manager->report(new RuntimeException('no context'));

        // Should not set exception_context key at all
        expect(Context::getHidden('exception_context'))->toBeNull();
    });

});

describe('Fix #2: LaravelErrors Facade', function () {

    it('resolves ErrorManager through facade', function () {
        $manager = \Isaidgitmenow\LaravelErrors\Facades\LaravelErrors::getFacadeRoot();
        expect($manager)->toBeInstanceOf(\Isaidgitmenow\LaravelErrors\ErrorManager::class);
    });

    it('can addContext via facade', function () {
        \Isaidgitmenow\LaravelErrors\Facades\LaravelErrors::addContext(
            ApiDetector::class,
            ApiRenderer::class,
        );

        $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = \Isaidgitmenow\LaravelErrors\Facades\LaravelErrors::render(new RuntimeException('facade test'), $request);

        expect($response)->not->toBeNull();
        expect($response->getStatusCode())->toBe(500);
    });

});

describe('Fix #3: Automatic RateLimitedReporter Wrapping', function () {

    it('applies rate limiting automatically when exception has #[RateLimit]', function () {
        Log::spy();

        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'reporters'    => [\Isaidgitmenow\LaravelErrors\Reporters\LogReporter::class],
            'sanitize'     => [],
        ]);

        $e = new RateLimitedManagerException('Rate limited');

        // First 2 should go through (max: 2)
        $manager->report($e);
        $manager->report($e);
        // Third should be suppressed by RateLimitedReporter
        $manager->report($e);

        Log::shouldHaveReceived('error')->times(2);
    });

    it('does not wrap reporters when no #[RateLimit] attribute is set', function () {
        Log::spy();

        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'reporters'    => [\Isaidgitmenow\LaravelErrors\Reporters\LogReporter::class],
            'sanitize'     => [],
        ]);

        $e = new RuntimeException('No rate limit');

        // All 5 should pass through
        $manager->report($e);
        $manager->report($e);
        $manager->report($e);
        $manager->report($e);
        $manager->report($e);

        Log::shouldHaveReceived('error')->times(5);
    });

});

describe('Fix #4: DataSanitizer in LogReporter', function () {

    it('redacts sensitive WithContext data from log output', function () {
        Log::spy();

        $reporter = new \Isaidgitmenow\LaravelErrors\Reporters\LogReporter(
            config: ['sanitize' => ['api_token']]
        );

        $reporter->report(new SensitiveContextException('Sensitive data test'));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['api_token'] === '[REDACTED]';
            });
    });

});
