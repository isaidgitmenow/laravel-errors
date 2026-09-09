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

    public function addPassThrough(string $exceptionClass): void;

    public function addContext(string $detector, string $renderer): static;

    public function addReporter(string $reporter): static;

    public function bypassConsoleExceptions(bool $on = true): void;
    public function isBypassingConsoleExceptions(): bool;
}
