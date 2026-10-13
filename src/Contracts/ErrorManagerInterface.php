<?php
// file: src/Contracts/ErrorManagerInterface.php  (2.2)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

interface ErrorManagerInterface
{
    /** true = lasă Laravel să logheze; false = oprește raportarea (DOAR pentru #[DontReport]). */
    public function report(Throwable $e): bool;

    /** null = cade pe Laravel. */
    public function render(Throwable $e, Request $request): ?Response;

    public function isPassThrough(Throwable $e): bool;

    /** @param class-string<Throwable> $exceptionClass */
    public function addPassThrough(string $exceptionClass): void;

    /**
     * @param class-string<ContextDetectorInterface>   $detector
     * @param class-string<ExceptionRendererInterface> $renderer
     */
    public function addContext(string $detector, string $renderer): static;

    /** @param class-string<ErrorReporterInterface> $reporter */
    public function addReporter(string $reporter): static;
}
