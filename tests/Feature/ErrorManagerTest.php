<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\Detectors\ApiDetector;
use Isaidgitmenow\LaravelErrors\Detectors\FilamentDetector;
use Isaidgitmenow\LaravelErrors\Detectors\InertiaDetector;
use Isaidgitmenow\LaravelErrors\Detectors\LivewireDetector;
use Isaidgitmenow\LaravelErrors\Detectors\WebDetector;
use Isaidgitmenow\LaravelErrors\ErrorManager;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Reporters\LogReporter;
use Isaidgitmenow\LaravelErrors\Reporters\RateLimitedReporter;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    ExceptionInspector::flushCache();
    ErrorManager::flushPassThrough();
    Cache::flush();
});

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
        expect((new LivewireDetector())->detect(new \RuntimeException(), $request))->toBeTrue();
    });
});

describe('InertiaDetector', function () {
    it('detects Inertia requests via header', function () {
        $request = Request::create('/dashboard', 'GET');
        $request->headers->set('X-Inertia', 'true');
        expect((new InertiaDetector())->detect(new \RuntimeException(), $request))->toBeTrue();
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

        $result = $manager->render(new \RuntimeException(), $request);
        expect($result)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);

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

        expect(fn () => $manager->render(new RuntimeException(), $request))->not->toThrow(\Throwable::class);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Feature: Context Detector Ordering
// ─────────────────────────────────────────────────────────────────────────────

describe('Context Detector Ordering', function () {
    it('evaluates custom (prepended) contexts before config contexts', function () {
        $callOrder = [];

        $firstDetector = new class implements ContextDetectorInterface {
            public array $log = [];
            public function detect(\Throwable $e, Request $request): bool
            {
                $this->log[] = 'first';
                return true;
            }
        };

        $firstRenderer = new class implements ExceptionRendererInterface {
            public function render(\Throwable $e, Request $request): ?\Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response('first', 200);
            }
        };

        $secondDetector = new class implements ContextDetectorInterface {
            public array $log = [];
            public function detect(\Throwable $e, Request $request): bool
            {
                $this->log[] = 'second';
                return true;
            }
        };

        $secondRenderer = new class implements ExceptionRendererInterface {
            public function render(\Throwable $e, Request $request): ?\Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response('second', 200);
            }
        };

        app()->bind($firstDetector::class, fn () => $firstDetector);
        app()->bind($firstRenderer::class, fn () => $firstRenderer);
        app()->bind($secondDetector::class, fn () => $secondDetector);
        app()->bind($secondRenderer::class, fn () => $secondRenderer);

        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'contexts'     => [$secondDetector::class => $secondRenderer::class],
            'reporters'    => [],
        ]);

        $manager->addContext($firstDetector::class, $firstRenderer::class);

        $request  = Request::create('/test', 'GET');
        $response = $manager->render(new RuntimeException(), $request);

        // Second detector was never called — first matched and rendered
        expect($secondDetector->log)->toBeEmpty();
        expect($response->getContent())->toBe('first');
    });

    it('falls through to the next detector when the first one does not match', function () {
        $nonMatchingDetector = new class implements ContextDetectorInterface {
            public function detect(\Throwable $e, Request $request): bool { return false; }
        };

        $nonMatchingRenderer = new class implements ExceptionRendererInterface {
            public function render(\Throwable $e, Request $request): ?\Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response('should_not_appear', 200);
            }
        };

        // A catch-all detector+renderer that always matches and always returns a response
        $catchAllDetector = new class implements ContextDetectorInterface {
            public function detect(\Throwable $e, Request $request): bool { return true; }
        };
        $catchAllRenderer = new class implements ExceptionRendererInterface {
            public function render(\Throwable $e, Request $request): ?\Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response('catch-all', 500);
            }
        };

        app()->bind($nonMatchingDetector::class, fn () => $nonMatchingDetector);
        app()->bind($nonMatchingRenderer::class, fn () => $nonMatchingRenderer);
        app()->bind($catchAllDetector::class, fn () => $catchAllDetector);
        app()->bind($catchAllRenderer::class, fn () => $catchAllRenderer);

        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'contexts'     => [$catchAllDetector::class => $catchAllRenderer::class],
            'reporters'    => [],
        ]);

        $manager->addContext($nonMatchingDetector::class, $nonMatchingRenderer::class);

        $request  = Request::create('/test', 'GET');
        $response = $manager->render(new RuntimeException(), $request);

        // The non-matching detector was skipped; the catch-all responded
        expect($response)->not->toBeNull();
        expect($response->getContent())->toBe('catch-all');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Feature: Dynamic Pass-Through
// ─────────────────────────────────────────────────────────────────────────────

describe('Dynamic Pass-Through', function () {
    it('bypasses the pipeline for dynamically registered exceptions', function () {
        ErrorManager::passThrough(InvalidArgumentException::class);

        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'contexts'     => [WebDetector::class => \Isaidgitmenow\LaravelErrors\Renderers\WebRenderer::class],
            'reporters'    => [],
        ]);

        $request  = Request::create('/test', 'GET');
        $response = $manager->render(new InvalidArgumentException('Should pass through'), $request);

        expect($response)->toBeNull();
    });

    it('merges config pass_through with dynamically registered ones', function () {
        ErrorManager::passThrough(InvalidArgumentException::class);

        $manager = new ErrorManager(config: [
            'pass_through' => [ValidationException::class],
            'contexts'     => [WebDetector::class => \Isaidgitmenow\LaravelErrors\Renderers\WebRenderer::class],
            'reporters'    => [],
        ]);

        $request = Request::create('/test', 'GET');

        expect($manager->render(new InvalidArgumentException(), $request))->toBeNull();
        expect($manager->render(ValidationException::withMessages(['x' => 'y']), $request))->toBeNull();
    });

    it('does not affect exceptions not in the pass-through list', function () {
        ErrorManager::passThrough(InvalidArgumentException::class);

        // Stub catch-all detector + renderer
        $catchAllDetector = new class implements ContextDetectorInterface {
            public function detect(\Throwable $e, Request $request): bool { return true; }
        };
        $catchAllRenderer = new class implements ExceptionRendererInterface {
            public function render(\Throwable $e, Request $request): ?\Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response('rendered', 500);
            }
        };

        app()->bind($catchAllDetector::class, fn () => $catchAllDetector);
        app()->bind($catchAllRenderer::class, fn () => $catchAllRenderer);

        $manager = new ErrorManager(config: [
            'pass_through' => [],
            'contexts'     => [$catchAllDetector::class => $catchAllRenderer::class],
            'reporters'    => [],
        ]);

        $request  = Request::create('/test', 'GET');
        $response = $manager->render(new RuntimeException('Normal exception'), $request);

        // RuntimeException is NOT in the pass-through list — it gets rendered
        expect($response)->not->toBeNull();
        expect($response->getContent())->toBe('rendered');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Feature: RateLimitedReporter Caching
// ─────────────────────────────────────────────────────────────────────────────

#[RateLimit(max: 2, intervalInMinutes: 1)]
class RateLimitCachingException extends RuntimeException {}

describe('RateLimitedReporter Caching', function () {
    beforeEach(function () {
        Cache::flush();
        ExceptionInspector::flushCache();
    });

    it('caches the hit count and suppresses reports at the threshold', function () {
        Log::spy();

        $reporter = new RateLimitedReporter(new LogReporter());
        $e        = new RateLimitCachingException('cache test');

        $reporter->report($e); // hit 1 → allowed
        $reporter->report($e); // hit 2 → allowed
        $reporter->report($e); // hit 3 → suppressed (max is 2)

        Log::shouldHaveReceived('error')->times(2);
    });

    it('cache keys are isolated per exception class', function () {
        Log::spy();

        $reporter = new RateLimitedReporter(new LogReporter());
        $limited  = new RateLimitCachingException('rate limited');
        $normal   = new RuntimeException('no rate limit');

        // Exhaust the RateLimitCachingException budget
        $reporter->report($limited); // 1 → allowed
        $reporter->report($limited); // 2 → allowed
        $reporter->report($limited); // suppressed

        // RuntimeException has no #[RateLimit] — runs freely
        $reporter->report($normal);
        $reporter->report($normal);

        // 2 from $limited + 2 from $normal
        Log::shouldHaveReceived('error')->times(4);
    });
});
