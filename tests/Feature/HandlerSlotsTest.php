<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;

describe('HandlerSlots', function () {
    it('executes respond hooks and modifies the response', function () {
        $slots = app(HandlerSlots::class);
        
        $slots->onRespond(function (Response $response, \Throwable $e, Request $req) {
            $response->headers->set('X-Test-Hook', 'hooked');
            return $response;
        });

        $response = new Response();
        $e = new RuntimeException();
        $request = Request::create('/test');

        $result = $slots->runRespond($response, $e, $request);

        expect($result->headers->get('X-Test-Hook'))->toBe('hooked');
    });

    it('returns the same response if respond hook returns void', function () {
        $slots = app(HandlerSlots::class);
        
        $slots->onRespond(function (Response $response, \Throwable $e, Request $req) {
            $response->headers->set('X-Test-Void', 'voided');
            // no return
        });

        $response = new Response();
        $e = new RuntimeException();
        $request = Request::create('/test');

        $result = $slots->runRespond($response, $e, $request);

        expect($result)->toBe($response);
        expect($result->headers->get('X-Test-Void'))->toBe('voided');
    });

    it('evaluates shouldRenderJson callbacks correctly', function () {
        $slots = app(HandlerSlots::class);
        
        // Default returns null
        $e = new RuntimeException();
        $request = Request::create('/test');
        expect($slots->shouldRenderJson($request, $e))->toBeFalse();

        $slots->onRenderJson(function (Request $req, \Throwable $e) {
            return $req->hasHeader('X-Force-Json');
        });

        $jsonRequest = Request::create('/test', 'GET', [], [], [], ['HTTP_X_FORCE_JSON' => 'true']);
        
        expect($slots->shouldRenderJson($jsonRequest, $e))->toBeTrue();
        expect($slots->shouldRenderJson($request, $e))->toBeFalse(); // Not null because it was explicitly false
    });

    it('clears RENDERED_HEADER from response in runRespond', function () {
        $slots = app(HandlerSlots::class);
        $response = new Response();
        $response->headers->set(HandlerSlots::RENDERED_HEADER, '1');
        
        $e = new RuntimeException();
        $request = Request::create('/test');

        $result = $slots->runRespond($response, $e, $request);
        
        expect($result->headers->has(HandlerSlots::RENDERED_HEADER))->toBeFalse();
    });
});
