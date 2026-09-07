<?php
// file: src/Detectors/ApiDetector.php  (2.0; în 2.2 delegă la HandlerSlots)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Detectors;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Throwable;

/** O singură regulă „e API?", folosită și de shouldRenderJsonWhen() — altfel Laravel și pachetul decid diferit. */
final class ApiDetector implements ContextDetectorInterface
{
    public function detect(Throwable $e, Request $request): bool
    {
        return app(HandlerSlots::class)->shouldRenderJson($request, $e);
    }
}
