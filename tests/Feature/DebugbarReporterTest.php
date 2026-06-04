<?php

declare(strict_types=1);

namespace Tests\Feature;

use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use Isaidgitmenow\LaravelErrors\Contracts\BypassesRateLimiting;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Reporters\DebugbarReporter;

#[WithContext(['debug_id'])]
class DebuggableException extends \RuntimeException {
    public int $debug_id = 123;
}

#[WithContext(['debug_id', 'password'])]
class SensitiveDebuggableException extends \RuntimeException {
    public int $debug_id = 456;
    public string $password = 'super-secret';
}

describe('DebugbarReporter', function () {
    beforeEach(function () {
        if (class_exists(\Barryvdh\Debugbar\Facades\Debugbar::class)) {
            \Barryvdh\Debugbar\Facades\Debugbar::flush();
        }
        ExceptionInspector::flushCache();
    });

    it('implements BypassesRateLimiting', function () {
        $reporter = new DebugbarReporter();
        expect($reporter)->toBeInstanceOf(BypassesRateLimiting::class);
    });

    it('shouldReport requires debug mode', function () {
        app()['config']->set('app.debug', false);
        $reporter = new DebugbarReporter();
        expect($reporter->shouldReport(new \RuntimeException()))->toBeFalse();

        app()['config']->set('app.debug', true);
        expect($reporter->shouldReport(new \RuntimeException()))->toBeTrue();
    });

    it('adds throwable to debugbar', function () {
        app()['config']->set('app.debug', true);
        $reporter = new DebugbarReporter();
        $e = new \RuntimeException('Database error');
        
        $reporter->report($e);
        
        expect(\Barryvdh\Debugbar\Facades\Debugbar::$throwables[0])->toBe($e);
    });

    it('adds WithContext data to messages tab', function () {
        app()['config']->set('app.debug', true);
        $reporter = new DebugbarReporter();
        $e = new DebuggableException('Debug error');
        
        $reporter->report($e);
        
        expect(\Barryvdh\Debugbar\Facades\Debugbar::$throwables[0])->toBe($e);
        expect(\Barryvdh\Debugbar\Facades\Debugbar::$messages)->toHaveCount(1);
        expect(\Barryvdh\Debugbar\Facades\Debugbar::$messages[0]['type'])->toBe('error');
        expect(\Barryvdh\Debugbar\Facades\Debugbar::$messages[0]['message'])->toContain('123');
    });

    it('sanitizes sensitive context data before adding to debugbar', function () {
        app()['config']->set('app.debug', true);
        $reporter = new DebugbarReporter(config: ['sanitize' => ['password']]);
        $e = new SensitiveDebuggableException('Sensitive error');

        $reporter->report($e);

        $message = \Barryvdh\Debugbar\Facades\Debugbar::$messages[0]['message'];
        expect($message)->toContain('456');           // debug_id is shown
        expect($message)->toContain('[REDACTED]');      // password is redacted
        expect($message)->not->toContain('super-secret'); // raw password does not appear
    });
});
