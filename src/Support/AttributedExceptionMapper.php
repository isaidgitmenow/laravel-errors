<?php
// file: src/Support/AttributedExceptionMapper.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Illuminate\Contracts\Config\Repository;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Exceptions\AttributedHttpException;
use Isaidgitmenow\LaravelErrors\Exceptions\SuppressedAttributedHttpException;
use Throwable;
use WeakMap;

final class AttributedExceptionMapper
{
    private WeakMap $wrappers;

    public function __construct(private readonly Repository $config)
    {
        $this->wrappers = new WeakMap();
    }

    /**
     * Memoizat per original: Handler::mapException() e apelat de două ori (report + render)
     * și fără memoizare dontReportDuplicates() și error_id s-ar sparge.
     */
    public function wrap(Throwable $e): Throwable
    {
        if ($e instanceof AttributedHttpException || $e instanceof \Illuminate\Contracts\Support\Responsable) {
            return $e;   // Responsable își construiește singur răspunsul; wrapper-ul l-ar ascunde
        }

        if (isset($this->wrappers[$e])) {
            return $this->wrappers[$e];
        }

        $status  = ExceptionInspector::httpCode($e);
        $message = MessageResolver::public($e, $status, (array) $this->config->get('errors', []));
        $headers = [];

        if (($retry = ExceptionInspector::retryAfter($e)) !== null) {
            $headers['Retry-After'] = (string) $retry;
        }

        $suppress = ExceptionInspector::shouldNotReport($e) && ExceptionInspector::audit($e) === null;

        $class = $suppress
            ? SuppressedAttributedHttpException::class
            : AttributedHttpException::classForLevel(ExceptionInspector::logLevel($e));

        return $this->wrappers[$e] = new $class($status, $message, $e, $headers);
    }

    public function flush(): void
    {
        $this->wrappers = new WeakMap();
    }
}
