<?php
// file: src/Support/ProblemDetailsFormatter.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** RFC 9457. title = tipul problemei; textul statusului DOAR când type e about:blank. detail = mesajul public. */
final class ProblemDetailsFormatter
{
    public function __construct(private readonly array $config = []) {}

    /** @return array<string, mixed> */
    public function format(Throwable $e, Request $request, int $status, string $publicMessage): array
    {
        $def   = ExceptionInspector::errorCodeDefinition($e);
        $pd    = (array) ($this->config['problem_details'] ?? []);
        $extra = [];

        if ($def === null) {
            $type  = 'about:blank';
            $title = Response::$statusTexts[$status] ?? 'Error';
            $code  = 'HTTP_' . $status;
        } else {
            $base  = rtrim((string) ($pd['type_base_url'] ?? (config('app.url') . '/errors')), '/');
            $type  = $def['type'] ?? $base . '/' . Str::slug(strtolower($def['code']));
            $title = $def['title'] ?? Str::headline(strtolower($def['code']));
            $code  = $def['code'];
        }

        if ($pd['expose_context'] ?? false) {
            $ctx = ExceptionInspector::sanitizedContext($e);
            if ($ctx !== []) {
                $extra['context'] = $ctx;
            }
        }

        return [
            'type'     => $type,
            'title'    => $title,
            'status'   => $status,
            'detail'   => $publicMessage,
            'instance' => $request->getRequestUri(),
            'code'     => $code,
            'error_id' => ErrorIdentity::for($e),
        ] + $extra;
    }
}
