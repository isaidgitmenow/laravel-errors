<?php
// file: src/Exceptions/AttributedHttpException.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Wrapper-ul prin care o excepție atribuită devine „HTTP" pentru Laravel.
 *
 * NU extinde Symfony\HttpException: aceea e în Handler::$internalDontReport și ar opri raportarea.
 * Implementează doar interfața, pe care Laravel o verifică pentru status/randare.
 *
 * Mesajul primit e cel PUBLIC (MessageResolver) — Laravel nu maschează HttpExceptionInterface.
 * Originalul rămâne în lanț (previous) și accesibil prin original().
 *
 * Sub-clasele există pentru că Handler compară cu instanceof: level() se înregistrează pe ele o singură
 * dată (I-11), iar SuppressedAttributedHttpException implementează ShouldntReport (#[DontReport]).
 */
abstract class AttributedHttpException extends \RuntimeException implements HttpExceptionInterface
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $publicMessage,
        private readonly Throwable $original,
        private readonly array $headers = [],
    ) {
        parent::__construct($publicMessage, 0, $original);
    }

    public function getStatusCode(): int   { return $this->statusCode; }
    public function getHeaders(): array    { return $this->headers; }
    public function original(): Throwable  { return $this->original; }

    /** Delegare către original, ca hook-urile per-excepție ale Laravel să funcționeze după mapare. */
    public function context(): array
    {
        return method_exists($this->original, 'context') ? (array) $this->original->context() : [];
    }

    public function report(): mixed
    {
        // Handler face container->call([$e,'report']) și se oprește dacă rezultatul !== false.
        // Originalul poate declara report(SomeService $s) → îl apelăm tot prin container, nu direct.
        // Dacă originalul nu are report(), întoarcem false ca să nu oprim nimic.
        return method_exists($this->original, 'report') ? app()->call([$this->original, 'report']) : false;
    }

    public function render(mixed $request): mixed
    {
        return method_exists($this->original, 'render') ? $this->original->render($request) : null;
    }

    /** @return class-string<self> */
    public static function classForLevel(string $psrLevel): string
    {
        return match ($psrLevel) {
            'debug'     => DebugAttributedHttpException::class,
            'info'      => InfoAttributedHttpException::class,
            'notice'    => NoticeAttributedHttpException::class,
            'warning'   => WarningAttributedHttpException::class,
            'critical'  => CriticalAttributedHttpException::class,
            'alert'     => AlertAttributedHttpException::class,
            'emergency' => EmergencyAttributedHttpException::class,
            default     => ErrorAttributedHttpException::class,
        };
    }

    /** @return array<string, class-string<self>>  PSR-3 → clasă, pentru înregistrarea level() */
    public static function levelMap(): array
    {
        return [
            'debug'     => DebugAttributedHttpException::class,
            'info'      => InfoAttributedHttpException::class,
            'notice'    => NoticeAttributedHttpException::class,
            'warning'   => WarningAttributedHttpException::class,
            'error'     => ErrorAttributedHttpException::class,
            'critical'  => CriticalAttributedHttpException::class,
            'alert'     => AlertAttributedHttpException::class,
            'emergency' => EmergencyAttributedHttpException::class,
        ];
    }
}
