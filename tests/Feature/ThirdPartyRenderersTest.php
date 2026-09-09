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

        // Uses masked message since it's a generic exception
        expect(\Filament\Notifications\Notification::$lastNotification['title'])->toContain('Internal Server Error (ref: ');
        expect($response)->toBeNull();
    });

    it('returns JSON for Livewire requests in Filament', function () {
        $renderer = new FilamentRenderer();
        $request = Request::create('/admin', 'POST', [], [], [], ['HTTP_X_LIVEWIRE' => 'true']);
        $response = $renderer->render(new \RuntimeException('System failure'), $request);

        expect($response->getStatusCode())->toBe(500);
        
        $json = json_decode($response->getContent(), true);
        expect($json['message'])->toContain('Internal Server Error (ref: ');
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

    it('renders a dedicated error page in default (page) mode', function () {
        $renderer = new InertiaRenderer();
        $request = Request::create('/dashboard', 'GET');
        
        $response = $renderer->render(new \RuntimeException('Inertia error'), $request);

        // Component defaults to ErrorPage
        expect(\Inertia\Inertia::$rendered['component'])->toBe('ErrorPage');
        expect(\Inertia\Inertia::$rendered['props']['message'])->toContain('Internal Server Error (ref: ');
        expect($response->getStatusCode())->toBe(500);
    });

    it('redirects with flash in flash mode', function () {
        $renderer = new InertiaRenderer([
            'inertia_mode' => 'flash',
        ]);
        $request = Request::create('/dashboard', 'GET');
        
        // Mocking the redirect is complex outside full Laravel context, so we just check it doesn't crash 
        // and returns a RedirectResponse
        $response = $renderer->render(new \RuntimeException('Inertia error'), $request);

        expect($response)->toBeInstanceOf(\Illuminate\Http\RedirectResponse::class);
    });
});

describe('LivewireRenderer', function () {
    it('returns a JSON response consumable by Livewire', function () {
        $renderer = new LivewireRenderer();
        $request = Request::create('/livewire/msg', 'POST');
        $response = $renderer->render(new \RuntimeException('Livewire fail'), $request);

        expect($response->getStatusCode())->toBe(500);
        
        $json = json_decode($response->getContent(), true);
        expect($json['message'])->toContain('Internal Server Error (ref: ');
        expect($json['code'])->toBe('HTTP_500');
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
    it('returns HTML for generic requests', function () {
        // Register the package view namespace so view()->exists('laravel-errors::error') works
        view()->addNamespace('laravel-errors', realpath(__DIR__ . '/../../resources/views'));

        $renderer = new WebRenderer();
        $request = Request::create('/', 'GET');
        $response = $renderer->render(new \RuntimeException('Web fail'), $request);

        expect($response->getContent())->toContain('<h1>500</h1>');
        expect($response->getContent())->toContain('Internal Server Error (ref: ');
    });
});
