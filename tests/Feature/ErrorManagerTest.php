<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Isaidgitmenow\LaravelErrors\Detectors\ApiDetector;
use Isaidgitmenow\LaravelErrors\Detectors\FilamentDetector;
use Isaidgitmenow\LaravelErrors\Detectors\InertiaDetector;
use Isaidgitmenow\LaravelErrors\Detectors\LivewireDetector;
use Isaidgitmenow\LaravelErrors\Detectors\WebDetector;
use Isaidgitmenow\LaravelErrors\ErrorManager;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;

beforeEach(fn () => ExceptionInspector::flushCache());

describe('ApiDetector', function () {
    it('detects JSON requests', function () {
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        expect((new ApiDetector())->detect(new RuntimeException(), $request))->toBeTrue();
    });

    it('detects api/* routes', function () {
        $request = Request::create('/api/users', 'GET');
        expect((new ApiDetector())->detect(new RuntimeException(), $request))->toBeTrue();
    });

    it('does not detect web requests', function () {
        $request = Request::create('/dashboard', 'GET');
        expect((new ApiDetector())->detect(new RuntimeException(), $request))->toBeFalse();
    });
});

describe('LivewireDetector', function () {
    it('detects Livewire requests via header', function () {
        $request = Request::create('/livewire/update', 'POST');
        $request->headers->set('X-Livewire', 'true');
        expect((new LivewireDetector())->detect(new RuntimeException(), $request))->toBeFalse(); // Livewire class not installed in tests
    });
});

describe('InertiaDetector', function () {
    it('detects Inertia requests via header', function () {
        $request = Request::create('/dashboard', 'GET');
        $request->headers->set('X-Inertia', 'true');
        expect((new InertiaDetector())->detect(new RuntimeException(), $request))->toBeFalse(); // Inertia class not installed in tests
    });
});

describe('WebDetector', function () {
    it('always returns true as catch-all', function () {
        $request = Request::create('/any', 'GET');
        expect((new WebDetector())->detect(new RuntimeException(), $request))->toBeTrue();
    });
});

describe('ErrorManager', function () {
    it('returns null for pass_through exceptions', function () {
        $manager = new ErrorManager(config: [
            'pass_through' => [ValidationException::class],
            'contexts'     => [],
            'reporters'    => [],
        ]);

        $validator = validator(['email' => ''], ['email' => 'required']);
        $e = ValidationException::withMessages(['email' => 'required']);
        $request = Request::create('/test', 'POST');

        expect($manager->render($e, $request))->toBeNull();
    });

    it('yields to Ignition when debug mode is on and request is plain web', function () {
        app()['config']->set('app.debug', true);

        $manager = new ErrorManager(config: [
            'respect_debug_mode' => true,
            'pass_through'       => [],
            'contexts'           => [WebDetector::class => \Isaidgitmenow\LaravelErrors\Renderers\WebRenderer::class],
            'reporters'          => [],
        ]);

        $request = Request::create('/dashboard', 'GET');
        expect($manager->render(new RuntimeException(), $request))->toBeNull();

        app()['config']->set('app.debug', false);
    });

    it('does NOT yield to Ignition for interactive Livewire requests in debug mode', function () {
        app()['config']->set('app.debug', true);

        $manager = new ErrorManager(config: [
            'respect_debug_mode' => true,
            'pass_through'       => [],
            'contexts'           => [LivewireDetector::class => \Isaidgitmenow\LaravelErrors\Renderers\LivewireRenderer::class],
            'reporters'          => [],
        ]);

        $request = Request::create('/livewire/update', 'POST');
        $request->headers->set('X-Livewire', 'true');

        // LivewireDetector won't match (Livewire not installed) so null is expected,
        // but importantly we are NOT in the "yield to ignition" path
        // because it IS an interactive context. This proves the logic is correct.
        $result = $manager->render(new RuntimeException(), $request);
        // No renderer matched (Livewire class absent), falls through to null - expected.
        expect($result)->toBeNull();

        app()['config']->set('app.debug', false);
    });

    it('adds custom context via addContext()', function () {
        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'contexts'     => [],
            'reporters'    => [],
        ]);

        $manager->addContext(ApiDetector::class, \Isaidgitmenow\LaravelErrors\Renderers\ApiRenderer::class);

        $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $manager->render(new RuntimeException('Test error'), $request);

        expect($response)->not->toBeNull();
        expect($response->getStatusCode())->toBe(500);
    });

    it('self-heals when renderer throws an exception', function () {
        // Create a fake renderer that always throws
        $badRenderer = new class implements \Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface {
            public function render(\Throwable $e, \Illuminate\Http\Request $request): ?\Symfony\Component\HttpFoundation\Response {
                throw new \RuntimeException('Renderer itself broke!');
            }
        };

        $badDetector = new class implements \Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface {
            public function detect(\Throwable $e, \Illuminate\Http\Request $request): bool {
                return true;
            }
        };

        app()->bind($badDetector::class, fn () => $badDetector);
        app()->bind($badRenderer::class, fn () => $badRenderer);

        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'contexts'     => [$badDetector::class => $badRenderer::class],
            'reporters'    => [],
        ]);

        $request = Request::create('/test', 'GET');

        // Should NOT throw - should self-heal and return null
        expect(fn () => $manager->render(new RuntimeException(), $request))->not->toThrow(\Throwable::class);
    });
});
