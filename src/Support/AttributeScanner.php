<?php
// file: src/Support/AttributeScanner.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Symfony\Component\Finder\Finder;

final class AttributeScanner
{
    public function __construct(private readonly AttributeReader $reader) {}

    // @return list<string> căi absolute existente; suportă glob (src/Domain/star/Exceptions)
    public function resolvePaths(array $patterns): array
    {
        // N-05: DDD domain path auto-detection at runtime — config/errors.php nu poate depinde de ordinea încărcării config('ddd.*').
        if (function_exists('config') && ($ddd = config('ddd.domain_path')) !== null) {
            $patterns[] = rtrim((string) $ddd, '/\\') . '/*/Exceptions';
        }

        $paths = [];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $dir) {
                $paths[] = $dir;
            }
        }

        return array_values(array_unique($paths));
    }

    public function latestMtime(array $paths): int
    {
        $max = 0;
        foreach ($this->files($paths) as $file) {
            $max = max($max, $file->getMTime());
        }

        return $max;
    }

    /** @return array<class-string, array<string, mixed>> doar clasele cu cel puțin un atribut al pachetului */
    public function scan(array $paths): array
    {
        $out = [];
        foreach ($this->files($paths) as $file) {
            $class = $this->classFromFile($file->getRealPath());

            if ($class === null || ! class_exists($class) || ! is_subclass_of($class, \Throwable::class)) {
                continue;
            }

            // N-12/P-05: Un atribut invalid pe O clasă nu trebuie să dezactiveze scanarea pentru TOATE.
            // Îl înregistrăm pentru doctor și continuăm.
            try {
                $data = $this->reader->read($class);
            } catch (\Throwable $failure) {
                $this->failures[$class] = $failure->getMessage();
                continue;
            }
            if ($data !== []) {
                $out[$class] = $data;
            }
        }
        ksort($out);

        return $out;
    }

    /** @var array<class-string, string>  clase cu atribute invalide la ultima scanare — raportate de errors:cache/doctor */
    private array $failures = [];

    /** @return array<class-string, string> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return iterable<\SplFileInfo> */
    private function files(array $paths): iterable
    {
        if ($paths === []) {
            return [];
        }

        return (new Finder())->files()->in($paths)->name('*.php')->sortByName()->getIterator();
    }

    /**
     * Extrage FQCN-ul PRIMEI clase declarate (PSR-4: una per fișier) prin PhpToken — fără include, fără regex fragil.
     * Sare peste `new class {}` și `Foo::class`. Întoarce null dacă nu există nicio declarație `class`.
     */
    private function classFromFile(string $path): ?string
    {
        $tokens = \PhpToken::tokenize((string) file_get_contents($path));
        $namespace = '';
        $class = null;

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];

            if ($t->is(T_NAMESPACE)) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace = $tokens[$j]->text;
                        break;
                    }
                    if ($tokens[$j]->text === ';' || $tokens[$j]->text === '{') {
                        break;
                    }
                }
            }

            if ($t->is(T_CLASS) && ! self::precededBy($tokens, $i, [T_NEW, T_DOUBLE_COLON])) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($tokens[$j]->is(T_STRING)) {
                        $class = $tokens[$j]->text;
                        break;
                    }
                }
                break;
            }
        }

        if ($class === null) {
            return null;   // fără clasă, sau doar enum/interface/trait/clasă anonimă
        }

        return $namespace === '' ? $class : $namespace . '\\' . $class;
    }

    /** Tokenul anterior ne-whitespace e unul din $kinds? (`new class {}` și `Foo::class`, chiar cu spații). */
    private static function precededBy(array $tokens, int $i, array $kinds): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            return $tokens[$j]->is($kinds);
        }

        return false;
    }
}
