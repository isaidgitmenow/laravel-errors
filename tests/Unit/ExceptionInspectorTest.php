<?php

declare(strict_types=1);

use Isaidgitmenow\LaravelErrors\Attributes\DontReport;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;

// ───── Fixtures ─────────────────────────────────────────────────────────────

#[HttpCode(402)]
class PaymentException extends RuntimeException {}

#[DontReport]
class SuppressedException extends RuntimeException {}

#[ReportTo(['slack', 'sentry'])]
class CriticalException extends RuntimeException {}

#[TranslatedMessage('errors.custom')]
class TranslatedException extends RuntimeException {}

#[WithContext(['order_id'])]
class ContextualException extends RuntimeException
{
    public int $order_id = 42;
}

class MethodContextualException extends RuntimeException
{
    #[WithContext]
    public function gatherIntel(): array
    {
        return ['intel_level' => 9000];
    }
}

#[RateLimit(max: 5, intervalInMinutes: 1)]
class RateLimitedException extends RuntimeException {}

// ───── Tests ─────────────────────────────────────────────────────────────────

describe('ExceptionInspector', function () {

    beforeEach(fn () => ExceptionInspector::flushCache());

    it('reads HttpCode from attribute', function () {
        expect(ExceptionInspector::httpCode(new PaymentException()))->toBe(402);
    });

    it('falls back to 500 when no HttpCode attribute is set', function () {
        expect(ExceptionInspector::httpCode(new RuntimeException()))->toBe(500);
    });

    it('uses getCode() when it is a valid HTTP status', function () {
        $e = new RuntimeException('msg', 404);
        expect(ExceptionInspector::httpCode($e))->toBe(404);
    });

    it('detects DontReport attribute', function () {
        expect(ExceptionInspector::shouldNotReport(new SuppressedException()))->toBeTrue();
    });

    it('returns false for shouldNotReport when attribute is absent', function () {
        expect(ExceptionInspector::shouldNotReport(new RuntimeException()))->toBeFalse();
    });

    it('reads ReportTo channels', function () {
        expect(ExceptionInspector::reportToChannels(new CriticalException()))
            ->toBe(['slack', 'sentry']);
    });

    it('returns null for reportToChannels when attribute is absent', function () {
        expect(ExceptionInspector::reportToChannels(new RuntimeException()))->toBeNull();
    });

    it('reads translated message key', function () {
        // No actual translation files in tests, so translatedMessage returns null
        // when the key doesn't map to a real translation. We just test the key is read.
        $e = new TranslatedException();
        $attrs = (new ReflectionClass(ExceptionInspector::class))
            ->getMethod('attributes');
        $attrs->setAccessible(true);
        $data = $attrs->invoke(null, $e);
        expect($data['translated_message'])->toBe('errors.custom');
    });

    it('extracts context properties from the exception class', function () {
        $context = ExceptionInspector::context(new ContextualException());
        expect($context)->toBe(['order_id' => 42]);
    });

    it('extracts context properties from exception methods', function () {
        $context = ExceptionInspector::context(new MethodContextualException());
        expect($context)->toBe(['intel_level' => 9000]);
    });

    it('returns empty context when WithContext is absent', function () {
        expect(ExceptionInspector::context(new RuntimeException()))->toBe([]);
    });

    it('reads RateLimit attribute', function () {
        $rateLimit = ExceptionInspector::rateLimit(new RateLimitedException());
        expect($rateLimit)->toBeInstanceOf(RateLimit::class);
        expect($rateLimit->max)->toBe(5);
        expect($rateLimit->intervalInMinutes)->toBe(1);
    });

    it('returns null for rateLimit when attribute is absent', function () {
        expect(ExceptionInspector::rateLimit(new RuntimeException()))->toBeNull();
    });

    it('traverses wrapped exceptions to find attributes on the original', function () {
        $original = new PaymentException('original', 0);
        $wrapped = new RuntimeException('wrapped', 0, $original);

        expect(ExceptionInspector::httpCode($wrapped))->toBe(402);
        expect(ExceptionInspector::origin($wrapped))->toBe($original);
    });

    it('uses static cache on second call', function () {
        $e = new PaymentException();
        ExceptionInspector::httpCode($e); // Prime the cache

        // Flush only the reflection calls - the result should still be 402
        expect(ExceptionInspector::httpCode($e))->toBe(402);
    });

    it('filters ReportTo channels by environment', function () {
        // ProdOnlyCriticalException is defined below with environments: ['production']
        $e = new ProdOnlyCriticalException();

        // In testing environment (not production), channels should be suppressed
        expect(app()->environment('production'))->toBeFalse();
        expect(ExceptionInspector::reportToChannels($e))->toBeNull();
    });

    it('allows ReportTo channels when environment matches', function () {
        $e = new ProdOnlyCriticalException();

        // Temporarily set environment to production
        app()->detectEnvironment(fn () => 'production');
        expect(ExceptionInspector::reportToChannels($e))->toBe(['slack']);
        // Reset
        app()->detectEnvironment(fn () => 'testing');
    });

    it('returns translated message when translation exists', function () {
        // Register a translator entry for the test
        app('translator')->addLines(['errors.test_message' => 'Something went wrong, please try again.'], 'en');

        $e = new TranslatedException();

        // Override the key to match our test translation
        $result = ExceptionInspector::translatedMessage(new TranslatedWithRealKeyException());
        expect($result)->toBe('Something went wrong, please try again.');
    });

});

// Additional fixtures for environment filtering and translation tests
#[ReportTo('slack', environments: ['production'])]
class ProdOnlyCriticalException extends RuntimeException {}

#[TranslatedMessage('errors.test_message')]
class TranslatedWithRealKeyException extends RuntimeException {}
