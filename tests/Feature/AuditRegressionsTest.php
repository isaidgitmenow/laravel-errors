<?php

declare(strict_types=1);

use Isaidgitmenow\LaravelErrors\Attributes\Sensitive;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use Isaidgitmenow\LaravelErrors\Audit\AuditSinkFactory;
use Isaidgitmenow\LaravelErrors\Audit\DatabaseAuditSink;
use Isaidgitmenow\LaravelErrors\Audit\LogChannelAuditSink;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\Exceptions\AttributedHttpException;
use Isaidgitmenow\LaravelErrors\Exceptions\ErrorAttributedHttpException;
use Isaidgitmenow\LaravelErrors\Exceptions\InvalidConfigurationException;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Reporters\AuditReporter;
use Isaidgitmenow\LaravelErrors\Support\ErrorPayload;

// Fixtures

#[WithContext(['secret', 'visible'])]
class PrivateSensitiveContextException extends RuntimeException
{
    public string $visible = 'ok';

    public function __construct(#[Sensitive] private string $secret = 'top-secret')
    {
        parent::__construct('private sensitive');
    }
}

beforeEach(fn () => ExceptionInspector::flushCache());

describe('Audit regressions', function () {
    it('never exposes non-public properties through WithContext', function () {
        $ctx = ExceptionInspector::context(new PrivateSensitiveContextException());

        expect($ctx)->toBe(['visible' => 'ok'])
            ->and(json_encode($ctx))->not->toContain('top-secret');
    });

    it('derives the log level from the status in one place', function () {
        expect(ExceptionInspector::levelForStatus(429))->toBe('notice')
            ->and(ExceptionInspector::levelForStatus(503))->toBe('critical')
            ->and(ExceptionInspector::levelForStatus(404))->toBe('warning')
            ->and(ExceptionInspector::levelForStatus(500))->toBe('error');
    });

    it('maps every PSR level to its wrapper class via levelMap', function () {
        foreach (AttributedHttpException::levelMap() as $level => $class) {
            expect(AttributedHttpException::classForLevel($level))->toBe($class);
        }
        expect(AttributedHttpException::classForLevel('bogus'))->toBe(ErrorAttributedHttpException::class);
    });

    it('builds the legacy JSON payload with stable key order', function () {
        $payload = ErrorPayload::legacyJson(new RuntimeException('x'), 418, 'Teapot');

        expect(array_keys($payload))->toBe(['message', 'errors', 'code', 'error_id'])
            ->and($payload['code'])->toBe('HTTP_418')
            ->and(array_keys(ErrorPayload::page(new RuntimeException('x'), 418, 'Teapot')))
            ->toBe(['status', 'message', 'code', 'error_id']);
    });

    it('builds audit sinks from config (table, channel, sinks over sink)', function () {
        $factory = app(AuditSinkFactory::class);

        $sinks = $factory->make(['sink' => 'database', 'sinks' => ['database', 'log_channel'], 'table' => 'my_audit', 'log_channel' => 'sec']);
        expect($sinks)->toHaveCount(2)
            ->and($sinks[0])->toBeInstanceOf(DatabaseAuditSink::class)
            ->and($sinks[1])->toBeInstanceOf(LogChannelAuditSink::class);

        $table = (new ReflectionProperty(DatabaseAuditSink::class, 'table'))->getValue($sinks[0]);
        $chan  = (new ReflectionProperty(LogChannelAuditSink::class, 'channel'))->getValue($sinks[1]);
        expect($table)->toBe('my_audit')->and($chan)->toBe('sec');

        expect($factory->make(['sink' => 'database', 'sinks' => []]))->toHaveCount(1);
    });

    it('rejects unknown audit sinks', function () {
        app(AuditSinkFactory::class)->make(['sinks' => ['nope']]);
    })->throws(InvalidConfigurationException::class);

    it('registers AuditReporter when errors.audit.enabled is true', function () {
        config()->set('errors.audit.enabled', true);
        app()->forgetInstance(ErrorManagerInterface::class);

        $reporters = (new ReflectionProperty(\Isaidgitmenow\LaravelErrors\ErrorManager::class, 'customReporters'))
            ->getValue(app(ErrorManagerInterface::class));

        expect($reporters)->toContain(AuditReporter::class);
    });
});
