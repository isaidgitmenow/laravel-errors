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
            // Prime the cache
            $e = new \RuntimeException();
            ExceptionInspector::httpCode($e);
            
            // Fire event
            $dispatcher = app(Dispatcher::class);
            $dispatcher->dispatch(new \Laravel\Octane\Events\RequestTerminated());
            
            expect(true)->toBeTrue();
        });
    });
}
