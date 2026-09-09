<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Reporters;

use Isaidgitmenow\LaravelErrors\Audit\AuditRecord;
use Isaidgitmenow\LaravelErrors\Contracts\AuditSink;
use Isaidgitmenow\LaravelErrors\Contracts\BypassesRateLimiting;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ReportsIgnoredExceptions;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\CriticalLog;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Throwable;

/**
 * Rulează pentru ORICE excepție cu #[Audit], chiar dacă are și #[DontReport] (ReportsIgnoredExceptions).
 * NU se rate-limitează (BypassesRateLimiting): fiecare occurență are valoare de audit.
 */
final class AuditReporter implements ErrorReporterInterface, BypassesRateLimiting, ReportsIgnoredExceptions
{
    /** @param list<AuditSink> $sinks */
    public function __construct(private readonly array $sinks) {}

    public function shouldReport(Throwable $e): bool
    {
        return ExceptionInspector::audit($e) !== null;
    }

    public function report(Throwable $e): bool
    {
        $audit  = ExceptionInspector::audit($e);
        $origin = ExceptionInspector::origin($e);

        $record = new AuditRecord(
            errorId:        ErrorIdentity::for($e),
            exceptionClass: $origin::class,
            message:        $origin->getMessage(),
            httpStatus:     ExceptionInspector::httpCode($e),
            errorCode:      ExceptionInspector::errorCode($e),
            logLevel:       ExceptionInspector::logLevel($e),
            category:       $audit['category'],
            retention:      $audit['retention'],
            retainUntil:    \Illuminate\Support\Carbon::now()->add($audit['retention'])->toDateTimeImmutable(),
            context:        ExceptionInspector::sanitizedContext($e),
            userId:         auth()->check() ? (string) auth()->id() : null,
            requestId:      request()->header('x-request-id') ?? (request()->route() ? request()->fingerprint() : null),
        );

        foreach ($this->sinks as $sink) {
            try {
                $sink->write($record);
            } catch (Throwable $failure) {
                CriticalLog::once('Audit sink failed: ' . $sink::class, $failure);
            }
        }

        return true;
    }
}
