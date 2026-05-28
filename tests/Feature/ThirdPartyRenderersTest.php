<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Renderers\FilamentRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\InertiaRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\LivewireRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\WebRenderer;

describe('FilamentRenderer', function () {
    beforeEach(function () {
        \Filament\Notifications\Notification::flush();
    });

    it('uses native Filament notification by default', function () {
        $renderer = new FilamentRenderer();
        $request = Request::create('/admin', 'GET');
        $response = $renderer->render(new \RuntimeException('System failure'), $request);

        expect(\Filament\Notifications\Notification::$lastNotification['body'])->toBe('System failure');
        expect($response->getStatusCode())->toBe(500);
        expect($response->getContent())->toBe('{"message":"System failure"}');
    });

    it('supports a custom filament_handler closure', function () {
        $called = false;
        $renderer = new FilamentRenderer([
            'filament_handler' => function ($e, $req) use (&$called) {
                $called = true;
            }
        ]);
        
        $request = Request::create('/admin', 'GET');
        $renderer->render(new \RuntimeException('fail'), $request);

        expect($called)->toBeTrue();
        // Should not have used native notification because handler took over
        expect(\Filament\Notifications\Notification::$lastNotification)->toBeEmpty();
    });
});

describe('InertiaRenderer', function () {
    beforeEach(function () {
        \Inertia\Inertia::flush();
    });

    it('shares error as prop in default mode', function () {
        $renderer = new InertiaRenderer();
        $request = Request::create('/dashboard', 'GET');
        
        // Note: back()->withInput() requires session to be started, 
        // but we can just test the mock side effects and the response type.
        $response = $renderer->render(new \RuntimeException('Inertia error'), $request);

        expect(\Inertia\Inertia::$shared['error']['message'])->toBe('Inertia error');
        expect($response)->toBeInstanceOf(\Illuminate\Http\RedirectResponse::class);
    });

    it('renders a dedicated error page in redirect mode', function () {
        $renderer = new InertiaRenderer([
            'inertia_mode' => 'redirect',
            'inertia_error_component' => 'CustomErrorPage'
        ]);
        $request = Request::create('/dashboard', 'GET');
        
        $response = $renderer->render(new \RuntimeException('Inertia error'), $request);

        expect(\Inertia\Inertia::$rendered['component'])->toBe('CustomErrorPage');
        expect(\Inertia\Inertia::$rendered['props']['message'])->toBe('Inertia error');
        expect($response->getStatusCode())->toBe(500);
    });
});

describe('LivewireRenderer', function () {
    it('returns a JSON response consumable by Livewire', function () {
        $renderer = new LivewireRenderer();
        $request = Request::create('/livewire/msg', 'POST');
        $response = $renderer->render(new \RuntimeException('Livewire fail'), $request);

        expect($response->getStatusCode())->toBe(500);
        expect($response->getContent())->toBe('{"message":"Livewire fail"}');
    });

    it('supports a custom livewire_handler closure', function () {
        $called = false;
        $renderer = new LivewireRenderer([
            'livewire_handler' => function ($e, $req) use (&$called) {
                $called = true;
            }
        ]);
        $request = Request::create('/livewire/msg', 'POST');
        $renderer->render(new \RuntimeException('fail'), $request);

        expect($called)->toBeTrue();
    });
});

describe('WebRenderer', function () {
    it('returns null to fall through to Laravel default web error pages', function () {
        $renderer = new WebRenderer();
        $request = Request::create('/', 'GET');
        $response = $renderer->render(new \RuntimeException('Web fail'), $request);

        expect($response)->toBeNull();
    });
});
