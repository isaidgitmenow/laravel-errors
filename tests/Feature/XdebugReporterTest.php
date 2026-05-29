<?php

declare(strict_types=1);

namespace Tests\Feature;

use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use Isaidgitmenow\LaravelErrors\Contracts\BypassesRateLimiting;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Reporters\XdebugReporter;
use Isaidgitmenow\LaravelErrors\Reporters\XdebugReporterTestSpy;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

#[WithContext(['order_id', 'password'])]
class XdebugContextException extends \RuntimeException
{
    public int $order_id = 99;
    public string $password = 'secret';
}

class XdebugNoContextException extends \RuntimeException {}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('XdebugReporter', function () {
    beforeEach(function () {
        XdebugReporterTestSpy::flush();
        ExceptionInspector::flushCache();
    });

    it('implements ErrorReporterInterface and BypassesRateLimiting', function () {
        $reporter = new XdebugReporter();
        expect($reporter)->toBeInstanceOf(ErrorReporterInterface::class);
        expect($reporter)->toBeInstanceOf(BypassesRateLimiting::class);
    });

    it('shouldReport returns false when debug mode is off', function () {
        app()['config']->set('app.debug', false);
        $reporter = new XdebugReporter(config: ['enrich_xdebug' => true]);
        expect($reporter->shouldReport(new XdebugContextException()))->toBeFalse();
    });

    it('shouldReport returns false when enrich_xdebug is false', function () {
        app()['config']->set('app.debug', true);
        $reporter = new XdebugReporter(config: ['enrich_xdebug' => false]);
        expect($reporter->shouldReport(new XdebugContextException()))->toBeFalse();
    });

    it('shouldReport returns false when exception has no WithContext data', function () {
        app()['config']->set('app.debug', true);
        $reporter = new XdebugReporter(config: ['enrich_xdebug' => true]);
        expect($reporter->shouldReport(new XdebugNoContextException()))->toBeFalse();
    });

    it('shouldReport returns true when all conditions are met', function () {
        app()['config']->set('app.debug', true);
        $reporter = new XdebugReporter(config: ['enrich_xdebug' => true]);
        expect($reporter->shouldReport(new XdebugContextException()))->toBeTrue();
    });

    it('defaults enrich_xdebug to true when config key is absent', function () {
        app()['config']->set('app.debug', true);
        $reporter = new XdebugReporter(config: []);
        expect($reporter->shouldReport(new XdebugContextException()))->toBeTrue();
    });

    it('calls xdebug_notify with sanitized context keyed by exception class name', function () {
        app()['config']->set('app.debug', true);
        $reporter = new XdebugReporter(config: [
            'enrich_xdebug' => true,
            'sanitize'       => ['password'],
        ]);

        $reporter->report(new XdebugContextException());

        expect(XdebugReporterTestSpy::$calls)->toHaveCount(1);

        $payload = XdebugReporterTestSpy::$calls[0];
        expect($payload)->toHaveKey('LaravelErrors Context for XdebugContextException');

        $context = $payload['LaravelErrors Context for XdebugContextException'];
        expect($context)->toHaveKey('order_id');
        expect($context['order_id'])->toBe(99);

        // Password must be redacted
        expect($context)->toHaveKey('password');
        expect($context['password'])->toBe('[REDACTED]');
    });

    it('report returns true to continue the pipeline', function () {
        app()['config']->set('app.debug', true);
        $reporter = new XdebugReporter(config: ['enrich_xdebug' => true]);
        expect($reporter->report(new XdebugContextException()))->toBeTrue();
    });
});
