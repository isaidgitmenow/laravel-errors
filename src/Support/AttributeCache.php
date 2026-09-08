<?php
// file: src/Support/AttributeCache.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Isaidgitmenow\LaravelErrors\Exceptions\InvalidConfigurationException;

/**
 * Sursa unică pentru „ce clase au ce atribute".
 *
 * Producție:      bootstrap/cache/errors.php (scris de errors:cache). Lipsa lui + maparea activă = excepție la boot.
 * Non-producție:  scanare la boot, memoizată în cache-ul default pe max(filemtime) al căilor scanate
 *                 (modelul DiscoverEvents din Laravel; nu scriem în bootstrap/cache automat).
 * Orice clasă necunoscută (vendor): reflecție directă prin AttributeReader, memoizată per proces.
 */
final class AttributeCache
{
    public const VERSION = '2.2.0';

    /** @var array<class-string, array<string, mixed>>|null */
    private ?array $classes = null;

    /** @var array<class-string, array<string, mixed>> */
    private array $runtime = [];

    public function __construct(
        private readonly Application $app,
        private readonly AttributeReader $reader,
        private readonly AttributeScanner $scanner,
        private readonly CacheRepository $store,
        private readonly array $config,
    ) {}

    /** @return array<string, mixed> */
    public function for(string $class): array
    {
        $this->load();

        if (isset($this->classes[$class])) {
            return $this->classes[$class];
        }

        // Un atribut invalid pe o clasă necunoscută (vendor) aruncă din newInstance(). Suntem în calea de eroare:
        // logăm o dată și continuăm fără atribute, în loc să înlocuim excepția reală cu a noastră.
        try {
            return $this->runtime[$class] ??= (class_exists($class) ? $this->reader->read($class) : []);
        } catch (\Throwable $failure) {
            CriticalLog::once("laravel-errors: invalid attribute on {$class}", $failure);

            return $this->runtime[$class] = [];
        }
    }

    /**
     * Clasele (din cache/scan, nu vendor) care au CEL PUȚIN una din cheile date.
     *
     * @return list<class-string>
     */
    public function classesWith(string ...$anyOf): array
    {
        $this->load();
        $out = [];

        foreach ($this->classes as $class => $data) {
            foreach ($anyOf as $key) {
                if (array_key_exists($key, $data)) {
                    $out[] = $class;
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array<class-string, array<string, mixed>> */
    public function all(): array
    {
        $this->load();

        return $this->classes;
    }

    public function flushRuntime(): void
    {
        $this->runtime = [];
    }

    private function load(): void
    {
        if ($this->classes !== null) {
            return;
        }

        $file = $this->app->bootstrapPath('cache/errors.php');

        if (is_file($file)) {
            $cached = require $file;

            if (is_array($cached) && ($cached['version'] ?? null) === self::VERSION) {
                $this->classes = $cached['classes'] ?? [];

                return;
            }

            $stale = true;
        }

        if ($this->app->isProduction()) {
            $flags = (array) ($this->config['integrate_with_laravel'] ?? []);
            if (in_array(true, $flags, true)) {
                $why = ($stale ?? false) ? 'has an old version' : 'is missing';
                throw new InvalidConfigurationException(
                    "laravel-errors: integrate_with_laravel is enabled but bootstrap/cache/errors.php {$why}. "
                    . 'Run `php artisan errors:cache` (or `optimize`) during deploy.'
                );
            }

            $this->classes = [];

            return;
        }

        // non-producție: scanare memoizată pe mtime; dacă store-ul e picat, scanăm nememoizat — nu murim în calea de eroare
        $paths = $this->scanner->resolvePaths((array) ($this->config['scan_paths'] ?? []));
        $key   = 'laravel-errors:scan:' . md5(implode('|', $paths) . ':' . $this->scanner->latestMtime($paths));

        try {
            $this->classes = $this->store->rememberForever($key, fn () => $this->scanner->scan($paths));
        } catch (\Throwable $failure) {
            CriticalLog::once('laravel-errors: attribute scan could not be memoized', $failure);
            $this->classes = $this->scanner->scan($paths);
        }
    }
}
