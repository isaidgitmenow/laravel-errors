<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Isaidgitmenow\LaravelErrors\Attributes\Audit;
use Isaidgitmenow\LaravelErrors\Events\ExceptionReported;
use Isaidgitmenow\LaravelErrors\Reporters\AuditReporter;
use RuntimeException;

#[Audit(retention: '30d', category: 'billing')]
class AuditableException extends RuntimeException {}

beforeEach(fn () => \Isaidgitmenow\LaravelErrors\ExceptionInspector::flushCache());

describe('AuditReporter', function () {
    it('always returns true to shouldReport', function () {
        $reporter = new AuditReporter([]);
        expect($reporter->shouldReport(new AuditableException()))->toBeTrue();
    });

    it('bypasses rate limiting', function () {
        $reporter = new AuditReporter([]);
        expect($reporter instanceof \Isaidgitmenow\LaravelErrors\Contracts\BypassesRateLimiting)->toBeTrue();
    });

    it('reports ignored exceptions', function () {
        $reporter = new AuditReporter([]);
        expect($reporter instanceof \Isaidgitmenow\LaravelErrors\Contracts\ReportsIgnoredExceptions)->toBeTrue();
    });

    it('writes to audit sinks when an exception has the Audit attribute', function () {
        $sink = \Mockery::mock(\Isaidgitmenow\LaravelErrors\Contracts\AuditSink::class);
        $sink->shouldReceive('write')->withArgs(function ($record) {
            return $record->category === 'billing' &&
                   $record->retention === '30d' &&
                   $record->message === 'Payment failed';
        })->once();

        $reporter = new AuditReporter([$sink]);
        $e = new AuditableException('Payment failed');
        $reporter->report($e);
    });

    it('returns true from report method when successful', function () {
        $sink = \Mockery::mock(\Isaidgitmenow\LaravelErrors\Contracts\AuditSink::class);
        $sink->shouldReceive('write')->once();
        
        $reporter = new AuditReporter([$sink]);
        $e = new AuditableException('Test');
        
        expect($reporter->report($e))->toBeTrue();
    });

    it('does not write to sink if exception lacks Audit attribute', function () {
        $sink = \Mockery::mock(\Isaidgitmenow\LaravelErrors\Contracts\AuditSink::class);
        $sink->shouldReceive('write')->never();

        $reporter = new AuditReporter([$sink]);
        $e = new RuntimeException('Test');
        
        expect($reporter->shouldReport($e))->toBeFalse();
    });
});
