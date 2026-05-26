<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Reporters\LogReporter;
use Isaidgitmenow\LaravelErrors\Reporters\RateLimitedReporter;
use Illuminate\Support\Facades\Log;

#[RateLimit(max: 3, intervalInMinutes: 1)]
class RateLimitedTestException extends RuntimeException {}

describe('RateLimitedReporter', function () {

    beforeEach(function () {
        Cache::flush();
        ExceptionInspector::flushCache();
    });

    it('allows reports below the rate limit threshold', function () {
        Log::spy();

        $inner = new LogReporter();
        $reporter = new RateLimitedReporter($inner);
        $e = new RateLimitedTestException('Rate limit test');

        $reporter->report($e);
        $reporter->report($e);
        $reporter->report($e);

        Log::shouldHaveReceived('error')->times(3);
    });

    it('suppresses reports above the rate limit threshold', function () {
        Log::spy();

        $inner = new LogReporter();
        $reporter = new RateLimitedReporter($inner);
        $e = new RateLimitedTestException('Rate limit exceeded test');

        // 3 should pass
        $reporter->report($e);
        $reporter->report($e);
        $reporter->report($e);

        // 4th and 5th should be suppressed
        $reporter->report($e);
        $reporter->report($e);

        Log::shouldHaveReceived('error')->times(3);
    });

    it('passes through when no RateLimit attribute is set', function () {
        Log::spy();

        $inner = new LogReporter();
        $reporter = new RateLimitedReporter($inner);
        $e = new RuntimeException('No rate limit');

        $reporter->report($e);
        $reporter->report($e);
        $reporter->report($e);
        $reporter->report($e);
        $reporter->report($e);

        Log::shouldHaveReceived('error')->times(5);
    });

    it('uses separate cache keys for different exception types', function () {
        Log::spy();

        $inner = new LogReporter();
        $reporter = new RateLimitedReporter($inner);

        $e1 = new RateLimitedTestException('Error type 1');
        $e2 = new RuntimeException('Error type 2 - no rate limit');

        // Exhaust rate limit for e1
        $reporter->report($e1);
        $reporter->report($e1);
        $reporter->report($e1);
        $reporter->report($e1); // suppressed

        // e2 should still pass through
        $reporter->report($e2);

        Log::shouldHaveReceived('error')->times(4); // 3 from e1 + 1 from e2
    });

});

describe('LogReporter', function () {

    it('logs the exception message', function () {
        Log::spy();

        $reporter = new LogReporter();
        $reporter->report(new RuntimeException('Test log message'));

        Log::shouldHaveReceived('error')->once()->with('Test log message', \Mockery::type('array'));
    });

    it('always shouldReport', function () {
        expect((new LogReporter())->shouldReport(new RuntimeException()))->toBeTrue();
    });

});
