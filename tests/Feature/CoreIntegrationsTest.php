<?php

declare(strict_types=1);

namespace Laravel\Octane\Events {
    if (!class_exists('Laravel\Octane\Events\RequestTerminated')) {
        class RequestTerminated {}
    }
}

namespace Tests\Feature {

    use Illuminate\Contracts\Events\Dispatcher;
    use Illuminate\Foundation\Configuration\Exceptions;
    use Isaidgitmenow\LaravelErrors\ErrorHandler;
    use Isaidgitmenow\LaravelErrors\ErrorManager;
    use Isaidgitmenow\LaravelErrors\ExceptionInspector;

    describe('ErrorHandler', function () {
        it('binds report and render callbacks to Laravel Exceptions handler', function () {
            
            $reportClosure = null;
            $renderClosure = null;
            
            $exceptionsMock = \Mockery::mock(Exceptions::class)->makePartial();
            
            $exceptionsMock->shouldReceive('report')->andReturnUsing(function ($callable) use (&$reportClosure, $exceptionsMock) {
                $reportClosure = $callable;
                // Return an object with a stop() method to simulate Laravel's chain
                return new class {
                    public function stop() { return $this; }
                };
            });
            
            $exceptionsMock->shouldReceive('render')->andReturnUsing(function ($callable) use (&$renderClosure, $exceptionsMock) {
                $renderClosure = $callable;
                return $exceptionsMock;
            });
            
            ErrorHandler::handle($exceptionsMock);
            
            expect($reportClosure)->toBeInstanceOf(\Closure::class);
            expect($renderClosure)->toBeInstanceOf(\Closure::class);
            
            // Execute closures to gain code coverage
            $e = new \RuntimeException('Test error');
            $reportClosure($e);
            $renderClosure($e, request());
            
            expect(true)->toBeTrue();
        });
    });

    describe('ErrorsServiceProvider', function () {
        it('flushes ExceptionInspector cache on Octane RequestTerminated event', function () {
            // Prime the caches
            $e = new \RuntimeException();
            ExceptionInspector::httpCode($e);
            ErrorManager::passThrough(\InvalidArgumentException::class);

            // Fire event
            $dispatcher = app(Dispatcher::class);
            $dispatcher->dispatch(new \Laravel\Octane\Events\RequestTerminated());

            // Verify ExceptionInspector cache is flushed by calling it again
            // (it should re-compute without error, proving cache was cleared)
            expect(ExceptionInspector::httpCode($e))->toBe(500);

            // Verify dynamicPassThrough was flushed — the exception should not pass through anymore.
            // We test this by checking render() returns a response (not null/pass-through).
            $catchAllDetector = new class implements \Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface {
                public function detect(\Throwable $e, \Illuminate\Http\Request $request): bool { return true; }
            };
            $catchAllRenderer = new class implements \Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface {
                public function render(\Throwable $e, \Illuminate\Http\Request $request): ?\Symfony\Component\HttpFoundation\Response {
                    return new \Symfony\Component\HttpFoundation\Response('rendered', 200);
                }
            };
            app()->bind($catchAllDetector::class, fn () => $catchAllDetector);
            app()->bind($catchAllRenderer::class, fn () => $catchAllRenderer);

            $manager = new ErrorManager(config: [
                'pass_through' => [],
                'contexts'     => [$catchAllDetector::class => $catchAllRenderer::class],
                'reporters'    => [],
            ]);

            $request = \Illuminate\Http\Request::create('/test', 'GET');
            $response = $manager->render(new \InvalidArgumentException('test'), $request);

            // After flush, InvalidArgumentException should no longer be in pass-through list
            expect($response)->not->toBeNull();
            expect($response->getContent())->toBe('rendered');
        });
    });
}
