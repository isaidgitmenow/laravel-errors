<?php
// file: src/Support/AttributeValidator.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

/**
 * Validates attribute values at cache time.
 * Returns a list of ValidationIssue(level, class, message).
 */
final class AttributeValidator
{
    /** @param array<class-string, array<string, mixed>> $classes */
    public function validate(array $classes): array
    {
        $issues = [];
        $codes  = [];

        foreach ($classes as $class => $data) {
            // http_code in 400-599
            if (isset($data['http_code'])) {
                $code = (int) $data['http_code'];
                if ($code < 400 || $code >= 600) {
                    $issues[] = ['level' => 'error', 'class' => $class, 'message' => "#[HttpCode({$code})] must be 400–599."];
                }
            }

            // report_to exists in logging.channels
            if (isset($data['report_to'])) {
                $configured = array_keys((array) config('logging.channels', []));
                foreach ($data['report_to'] as $channel) {
                    if (! in_array($channel, $configured, true)) {
                        $issues[] = ['level' => 'warning', 'class' => $class, 'message' => "#[ReportTo] references unknown channel [{$channel}]."];
                    }
                }
            }

            // translated_message.key exists in locale files
            if (isset($data['translated_message']['key'])) {
                $key = $data['translated_message']['key'];
                $locales = (array) config('app.supported_locales', [config('app.locale', 'en')]);
                foreach ($locales as $locale) {
                    $translated = __($key, [], $locale);
                    if ($translated === $key) {
                        $issues[] = ['level' => 'warning', 'class' => $class, 'message' => "#[TranslatedMessage('{$key}')] not found for locale [{$locale}]."];
                    }
                }
            }

            // sensitive only on public properties
            if (! empty($data['sensitive_non_public'])) {
                foreach ($data['sensitive_non_public'] as $prop) {
                    $issues[] = ['level' => 'warning', 'class' => $class, 'message' => "#[Sensitive] on non-public property \${$prop} has no effect (context() can only read public properties)."];
                }
            }

            // log_as PSR-3
            if (isset($data['log_as'])) {
                $valid = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
                if (! in_array($data['log_as'], $valid, true)) {
                    $issues[] = ['level' => 'error', 'class' => $class, 'message' => "#[LogAs('{$data['log_as']}')] is not a valid PSR-3 level."];
                }
            }

            // error_code uniqueness
            if (isset($data['error_code']['code'])) {
                $code = $data['error_code']['code'];
                if (isset($codes[$code])) {
                    $issues[] = ['level' => 'error', 'class' => $class, 'message' => "#[ErrorCode('{$code}')] is also used by {$codes[$code]}."];
                } else {
                    $codes[$code] = $class;
                }
            }

            // rate_limit.by === 'code' without error_code
            if (isset($data['rate_limit']['by']) && $data['rate_limit']['by'] === 'code' && ! isset($data['error_code'])) {
                $issues[] = ['level' => 'warning', 'class' => $class, 'message' => "#[RateLimit(by: 'code')] without #[ErrorCode] will fall back to class-based limiting."];
            }

            // audit + dont_report
            if (isset($data['audit']) && isset($data['dont_report'])) {
                $issues[] = ['level' => 'warning', 'class' => $class, 'message' => "#[Audit] + #[DontReport]: audit will still run, but most reporters won't. Is this intentional?"];
            }
        }

        return $issues;
    }
}
