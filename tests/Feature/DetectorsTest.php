<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Detectors\FilamentDetector;
use Isaidgitmenow\LaravelErrors\Detectors\InertiaDetector;
use Isaidgitmenow\LaravelErrors\Detectors\LivewireDetector;

describe('Third Party Detectors (with faked classes)', function () {

    describe('FilamentDetector', function () {
        it('detects when an active panel exists', function () {
            \Filament\Facades\Filament::$panel = 'admin';
            $request = Request::create('/admin', 'GET');
            expect((new FilamentDetector())->detect(new \RuntimeException(), $request))->toBeTrue();
        });

        it('returns false when no active panel exists', function () {
            \Filament\Facades\Filament::$panel = null;
            $request = Request::create('/api/users', 'GET');
            expect((new FilamentDetector())->detect(new \RuntimeException(), $request))->toBeFalse();
        });

        it('returns false when getCurrentPanel throws an exception', function () {
            \Filament\Facades\Filament::$panel = 'throw';
            $request = Request::create('/admin', 'GET');
            expect((new FilamentDetector())->detect(new \RuntimeException(), $request))->toBeFalse();
        });
    });

    describe('InertiaDetector', function () {
        it('detects requests with X-Inertia header', function () {
            $request = Request::create('/dashboard', 'GET');
            $request->headers->set('X-Inertia', 'true');
            expect((new InertiaDetector())->detect(new \RuntimeException(), $request))->toBeTrue();
        });

        it('returns false when X-Inertia header is missing', function () {
            $request = Request::create('/dashboard', 'GET');
            expect((new InertiaDetector())->detect(new \RuntimeException(), $request))->toBeFalse();
        });
    });

    describe('LivewireDetector', function () {
        it('detects requests with X-Livewire header', function () {
            $request = Request::create('/livewire/update', 'POST');
            $request->headers->set('X-Livewire', 'true');
            expect((new LivewireDetector())->detect(new \RuntimeException(), $request))->toBeTrue();
        });

        it('returns false when X-Livewire header is missing', function () {
            $request = Request::create('/livewire/update', 'POST');
            expect((new LivewireDetector())->detect(new \RuntimeException(), $request))->toBeFalse();
        });
    });
});
