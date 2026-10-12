<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Audit;

use Illuminate\Contracts\Container\Container;
use Isaidgitmenow\LaravelErrors\Contracts\AuditSink;
use Isaidgitmenow\LaravelErrors\Exceptions\InvalidConfigurationException;

/**
 * Construiește sink-urile de audit din errors.audit.
 * `sinks` (listă) are prioritate; altfel `sink` (unul singur). Acceptă alias-urile
 * 'database' / 'log_channel' sau un class-string care implementează AuditSink (OCP).
 */
final class AuditSinkFactory
{
    public function __construct(private readonly Container $app) {}

    /**
     * @param  array<string, mixed>  $audit
     * @return list<AuditSink>
     */
    public function make(array $audit): array
    {
        $names = array_values(array_filter((array) (($audit['sinks'] ?? []) ?: ($audit['sink'] ?? []))));

        return array_map(fn (mixed $name): AuditSink => $this->resolve($name, $audit), $names);
    }

    /** @param array<string, mixed> $audit */
    private function resolve(mixed $name, array $audit): AuditSink
    {
        $sink = match ($name) {
            'database'    => new DatabaseAuditSink((string) ($audit['table'] ?? 'error_audit_log')),
            'log_channel' => new LogChannelAuditSink((string) ($audit['log_channel'] ?? 'audit')),
            default       => is_string($name) && class_exists($name) ? $this->app->make($name) : null,
        };

        if (! $sink instanceof AuditSink) {
            throw new InvalidConfigurationException(
                'errors.audit: sink [' . (is_string($name) ? $name : get_debug_type($name)) . "] must be 'database', 'log_channel' or a class implementing " . AuditSink::class . '.'
            );
        }

        return $sink;
    }
}
