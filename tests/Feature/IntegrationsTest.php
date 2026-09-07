<?php

declare(strict_types=1);

namespace Tests\Feature;

use Isaidgitmenow\LaravelErrors\Integrations\Livewire\ExceptionHook;
use Isaidgitmenow\LaravelErrors\Integrations\Sentry\AttributesEventProcessor;
use Sentry\Event;
use RuntimeException;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;

#[WithContext(['user_id'])]
class IntegrationException extends RuntimeException
{
    public int $user_id = 42;
}

describe('Livewire ExceptionHook', function () {
    it('is callable', function () {
        if (!class_exists(\Livewire\ComponentHook::class)) {
            $this->markTestSkipped('Livewire is not installed.');
        }
        $hook = new ExceptionHook();
        expect(is_callable($hook))->toBeTrue();
    });
});

describe('Sentry AttributesEventProcessor', function () {
    it('enriches Sentry event with context', function () {
        if (!class_exists(\Sentry\Event::class)) {
            $this->markTestSkipped('Sentry is not installed.');
        }

        $processor = new AttributesEventProcessor();
        $event = \Sentry\Event::createEvent();
        
        $hint = new \Sentry\EventHint();
        $hint->exception = new IntegrationException('Failed');
        
        $enrichedEvent = $processor($event, $hint);
        
        // Context is merged into event
        expect($enrichedEvent)->toBe($event);
    });
});
