# laravel-errors — Plan-master unificat (remediere + îmbunătățire)

**Repo:** `isaidgitmenow/laravel-errors` · `origin/main @ 92e9c42`
**Data:** 4 septembrie 2026
**Înlocuiește:** planul de remediere v2 (F-01…F-29) și planul de îmbunătățire v3 (I-01…I-18). Le consolidează pe **zone de lucru din cod**, cu starea finală a fișierelor, nu cu diff-uri per finding. ID-urile F-xx / I-xx sunt păstrate ca referință de trasabilitate.

**Verificare:** cele 35 de fișiere PHP din acest document au fost extrase și trecute prin `php -l` (PHP 8.4.21), înainte și după ultima rundă de corecții. Afirmațiile despre API-urile Laravel 11.27+/12, Livewire 3, sentry-laravel 4.4+ au fost verificate contra surselor în **patru** runde adversariale (planurile v1→v3 + o rundă finală pe acest document, care a corectat 15 puncte de logică în cod și 13 inconsistențe — toate listate în Anexă D). Ce nu s-a putut verifica fără dependențe instalate e marcat explicit cu **⚠ de verificat în workbench**.

---

## Cuprins

- §0 Cum se citește planul
- §1 Fundația: cum rulează `Handler` și cele cinci reguli
- §2 Zonele de lucru (WP-01 … WP-12), fiecare cu fișiere, cod final, teste
- §3 Secvențiere pe release-uri și dependențe
- §4 Configurația finală (`config/errors.php`)
- §5 Compatibilitate, UPGRADE, definiția lui „gata"
- Anexă A — Harta fișierelor (ce se creează / modifică / șterge, pe release)
- Anexă B — Referința atributelor
- Anexă C — Teste de regresie obligatorii
- Anexă D — Ce a fost eliminat din planurile anterioare și de ce

---

## 0. Cum se citește planul

Fiecare **zonă de lucru (WP)** răspunde la: ce fișiere atinge, ce probleme închide (F/I), cum arată codul final, ce teste dovedesc că e corect. Codul e **starea finală** a fișierului pentru release-ul indicat — nu un diff. Unde fișierul e prea lung pentru a fi reprodus integral, e dat scheletul complet cu metodele-cheie și lista exactă a restului.

Etichete de release: **2.0** = remedierea (obligatorie, blocantă), **2.1 / 2.2** = aditive cu excepțiile declarate, **3.0** = schimbări de default. Un WP poate avea livrabile în mai multe release-uri; sunt marcate în text.

Codul presupune namespace-ul `Isaidgitmenow\LaravelErrors` și PHP ^8.4. `use`-urile sunt incluse doar unde clarifică ceva; restul sunt implicite.

---

## 1. Fundația

### 1.1 Cum rulează `Illuminate\Foundation\Exceptions\Handler` (11.27+ / 12)

Tot ce urmează depinde de acest flux. Verificat pe sursă.

```
Handler::report($e)
  $e = mapException($e)                       ← wrapper-ul nostru înlocuiește originalul de aici încolo
  if shouldntReport($e) return                ← ordinea: $e instanceof ShouldntReport → dontReport[] + internalDontReport (instanceof)
                                                 → dontReportCallbacks → throttle() (în rescue, fail-open)
  reportThrowable($e)
    dacă $e are metoda report() → o apelează; dacă nu întoarce false → STOP
    foreach reportCallbacks: if handles($e) && callback($e) === false → STOP  (sare și peste callback-urile următoare!)
    level = Arr::first(levels, fn ($lvl, $type) => $e instanceof $type) ?? error
    logger->{level}($e->getMessage(), buildExceptionContext($e) + ['exception' => $e])

Handler::render($request, $e)
  $e = mapException($e)
  $e->render($request) / Responsable                                    → finalize
  $e = prepareException($e)
  renderViaCallbacks($request, $e)   ← render()-ul nostru; null = continuă  → finalize
  match: HttpResponseException | AuthenticationException | ValidationException | default renderExceptionResponse()
     renderExceptionResponse: HttpExceptionInterface → status + getMessage() VERBATIM (nu maschează!)
                              altfel → 500, mesaj „Server Error" când debug e off
  finalizeRenderedResponse() → respondUsing callback     ← UN SINGUR slot, aplicat pe TOATE căile
```

**Șase consecințe** pe care le respectă fiecare WP:

1. Tot ce Laravel compară cu `instanceof` — `level()`, `dontReport()`, `ShouldntReport`, `report(fn (Foo $e))`, `render(fn (Foo $e))`, metodele `report()/render()/context()` de pe excepție — vede **wrapper-ul** după mapare. De aici **familia fixă de sub-clase** (WP-02) și dezpachetarea prin `origin()` peste tot (WP-01).
2. `false` dintr-un callback de report oprește **tot** ce urmează, inclusiv `Integration::handles` al Sentry. Pachetul întoarce `false` **numai** pentru `#[DontReport]`, unde oprirea e intenția.
3. Laravel **nu maschează** mesajul unui `HttpExceptionInterface`. Wrapper-ul primește mesajul **public** (`MessageResolver`), niciodată `getMessage()`.
4. `respondUsing` și `shouldRenderJsonWhen` sunt câte **un slot**. Pachetul le deține și expune `LaravelErrors::respond()` / `renderJsonWhen()` pentru extindere (WP-02).
5. `mapException()` creează un wrapper **nou** la fiecare apel (o dată în `report`, încă o dată în `render`). Mapper-ul memoizează per original în `WeakMap`.
6. `throttle()` rulează în `shouldntReport()`: când se declanșează, **niciun** reporter nu rulează. E global; se înregistrează doar în producție.

### 1.2 Cele cinci reguli

1. **Atributele sunt API-ul.** Comportamentul se declară pe clasa de excepție; fallback în config. Fără metode magice cerute pe excepții.
2. **Compune cu Laravel, cunoscându-i mecanica** (§1.1). Fiecare reimplementare din trecut a divergut.
3. **Erorile de configurare explodează la boot; erorile de runtime se loghează și se înghit.** `InvalidConfigurationException` din `packageBooted()` / `errors:cache`. În calea fierbinte, `catch (Throwable)` loghează `critical` (rate-limitat 1/min/mesaj) și abia apoi cade pe Laravel. Niciodată `catch (Throwable) {}` gol.
4. **Nimic din excepție nu ajunge la client fără o decizie explicită.** Mesaj: `MessageResolver`. Context: `DataSanitizer` + `#[Sensitive]`. Status: `#[HttpCode]` sau `HttpExceptionInterface`, niciodată `getCode()` implicit. Cod: `#[ErrorCode]`, niciodată numele clasei.
5. **Fiecare API extern are versiunea minimă verificată în sursă.** Decizii: `illuminate/support ^11.27|^12.0|^13.0` (acolo e `ServiceProvider::optimizes()`) + `illuminate/contracts` la fel (`ShouldntReport`), `livewire/livewire ^3.0`, `sentry/sentry-laravel ^4.4`. Nu se folosește `dontReportWhen()` (12-only).

---

## 2. Zonele de lucru

| WP | Zonă | Fișiere principale | Închide | Release |
|---|---|---|---|---|
| WP-01 | Inspecție atribute + cache | `ExceptionInspector`, `Support/AttributeCache`, `Support/AttributeReader`, `Support/AttributeScanner`, `Support/AttributeValidator`, `Attributes/*` | F-01, F-22b, F-26, F-28, F-29, I-02(attr), I-05, I-06, I-08, I-11(attr), I-12(attr), I-17(attr) | 2.0 / 2.1 / 2.2 |
| WP-02 | Integrarea cu `Handler` | `ErrorHandler`, `Exceptions/AttributedHttpException` + familie, `Support/AttributedExceptionMapper`, `Support/HandlerSlots`, `Support/ErrorIdentity`, `Facades/LaravelErrors` | F-05, I-01, I-03, I-11, I-12a | 2.0 / 2.1 / 2.2 / 3.0 |
| WP-03 | Orchestrator | `ErrorManager`, `Contracts/ErrorManagerInterface`, `Events/*` | F-05, F-06, F-18, F-21, F-22a, I-16 | 2.0 / 2.1 / 2.2 |
| WP-04 | Rendere + mesaj + Problem Details | `Support/MessageResolver`, `Support/CallableResolver`, `Support/ProblemDetailsFormatter`, `Renderers/*` | F-02, F-07, F-16(renderer), F-24, I-02, I-09(web/inertia), I-15 | 2.0 / 2.2 / 3.0 |
| WP-05 | Detectori | `Detectors/ApiDetector`, `Detectors/FilamentDetector` | F-16, F-17, I-03d | 2.0 / 2.2 |
| WP-06 | Reporteri + integrări | `Reporters/*`, `Integrations/Sentry/*`, `Integrations/Flare/*`, `Audit/*` | F-12, F-25, F-27, I-07, I-12b, I-17 | 2.0 / 2.2 / 3.0 |
| WP-07 | Sanitizare | `Support/DataSanitizer`, `Support/Masker` | F-08, I-05 | 2.0 / 2.1 |
| WP-08 | Comenzi artisan | `Console/Commands/*`, `Console/Concerns/*` | F-03, F-04, F-13, F-14, I-08(cmd), I-13, I-02(`errors:list`) | 2.0 / 2.1 / 2.2 |
| WP-09 | Livewire / Filament hook | `Integrations/Livewire/ExceptionHook` | I-09a | 3.0 |
| WP-10 | MCP | `Mcp/*` | F-09, F-10, F-11, F-15, F-19, F-20, I-10 | 2.0 / 3.0 |
| WP-11 | Service provider + config | `ErrorsServiceProvider`, `config/errors.php`, `composer.json` | F-01(flush), F-07, F-17, F-24, I-08(optimizes), I-09(register) | toate |
| WP-12 | Teste, fake-uri, CI | `tests/**`, `Testing/*`, `.github/workflows/*`, `workbench/` | F-23, §10.7, I-04, I-18 | toate |

---

### WP-01 · Inspecție atribute + cache

**Fișiere:** `src/ExceptionInspector.php` (rescris), `src/Support/AttributeReader.php` [nou], `src/Support/AttributeCache.php` [nou], `src/Support/AttributeScanner.php` [nou], `src/Support/AttributeValidator.php` [nou], `src/Attributes/{Sensitive,ErrorCode,LogAs,RetryAfter,Audit}.php` [noi], `src/Attributes/{TranslatedMessage,WithContext,RateLimit}.php` (extinse).

**Închide:** F-01 (cache pe `spl_object_id` → `WeakMap`), F-22b (`hasAnyAttribute` fără reflecție dublă), F-26 (`getCode()` ca status → opt-in), F-28 (`trans()` → array), F-29 (`context()` executat de 5×), I-02/I-05/I-06/I-08/I-11/I-12/I-17 (atributele noi și cache-ul).

#### Principiu

O singură sursă de adevăr pentru „ce atribute are clasa X": `AttributeReader` face reflecția și produce un array normalizat; `AttributeScanner` îl apelează pe toate clasele din `scan_paths` și scrie `bootstrap/cache/errors.php`; `AttributeCache` servește din fișier (producție) sau din scanare memoizată pe mtime (non-producție), cu fallback pe `AttributeReader` pentru clase necunoscute (vendor). `ExceptionInspector` nu face reflecție directă — cere de la `AttributeCache`.

#### `src/Support/AttributeReader.php` (2.1)

```php
<?php
// file: src/Support/AttributeReader.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Isaidgitmenow\LaravelErrors\Attributes\Audit;
use Isaidgitmenow\LaravelErrors\Attributes\DontReport;
use Isaidgitmenow\LaravelErrors\Attributes\ErrorCode;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\LogAs;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\RetryAfter;
use Isaidgitmenow\LaravelErrors\Attributes\Sensitive;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Singura clasă care face reflecție pe atributele pachetului.
 * Produce un array normalizat, serializabil (cache-uibil), fără instanțe de atribute
 * — cu excepția RateLimit, care e readonly și mic; se stochează ca array și se reconstruiește.
 */
final class AttributeReader
{
    /**
     * @param class-string $class
     * @return array<string, mixed>  gol dacă clasa nu are niciun atribut al pachetului
     */
    public function read(string $class): array
    {
        $ref  = new ReflectionClass($class);
        $data = [];

        if ($a = $this->first($ref, HttpCode::class)) {
            $data['http_code'] = $a->newInstance()->code;
        }
        if ($this->first($ref, DontReport::class)) {
            $data['dont_report'] = true;
        }
        if ($a = $this->first($ref, ReportTo::class)) {
            $i = $a->newInstance();
            $data['report_to']              = is_array($i->channels) ? $i->channels : [$i->channels];
            $data['report_to_environments'] = $i->environments;
        }
        if ($a = $this->first($ref, TranslatedMessage::class)) {
            $i = $a->newInstance();
            $data['translated_message'] = ['key' => $i->key, 'params' => $i->params, 'choice' => $i->choice];
        }
        if ($a = $this->first($ref, ErrorCode::class)) {
            $i = $a->newInstance();
            $data['error_code'] = ['code' => $i->code, 'type' => $i->type, 'title' => $i->title];
        }
        if ($a = $this->first($ref, LogAs::class)) {
            $data['log_as'] = $a->newInstance()->level;
        }
        if ($a = $this->first($ref, RetryAfter::class)) {
            $data['retry_after'] = $a->newInstance()->seconds;
        }
        if ($a = $this->first($ref, RateLimit::class)) {
            $i = $a->newInstance();
            $data['rate_limit'] = ['max' => $i->max, 'interval' => $i->intervalInMinutes, 'by' => $i->by];
        }
        if ($a = $this->first($ref, Audit::class)) {
            $i = $a->newInstance();
            $data['audit'] = ['retention' => $i->retention, 'category' => $i->category];
        }

        // #[WithContext] pe clasă (proprietăți) și pe metode publice
        if ($a = $this->first($ref, WithContext::class)) {
            $i = $a->newInstance();
            $data['with_context']           = $i->properties;
            $data['with_context_sensitive'] = $i->sensitive;   // ['iban' => 'last4'] pentru array-uri din metode
        }
        $methods = [];
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getAttributes(WithContext::class) !== []) {
                $methods[] = $m->getName();
            }
        }
        if ($methods !== []) {
            $data['with_context_methods'] = $methods;
        }

        // #[Sensitive] pe proprietăți (inclusiv promovate). Cele ne-publice nu pot fi extrase de context() —
        // le raportăm separat ca validatorul (I-13) să avertizeze.
        $sensitive = [];
        $nonPublic = [];
        foreach ($ref->getProperties() as $p) {
            $attrs = $p->getAttributes(Sensitive::class);
            if ($attrs === []) {
                continue;
            }
            if ($p->isPublic()) {
                $sensitive[$p->getName()] = $attrs[0]->newInstance()->mask;
            } else {
                $nonPublic[] = $p->getName();
            }
        }
        if ($sensitive !== []) {
            $data['sensitive'] = $sensitive;
        }
        if ($nonPublic !== []) {
            $data['sensitive_non_public'] = $nonPublic;
        }

        return $data;
    }

    private function first(ReflectionClass $ref, string $attribute): ?\ReflectionAttribute
    {
        $attrs = $ref->getAttributes($attribute);

        return $attrs === [] ? null : $attrs[0];
    }
}
```

#### `src/Support/AttributeCache.php` (2.1)

```php
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
    public const VERSION = '2.1.0';

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
```

**Încălzit la boot.** `ErrorHandler::handle()` rulează în `afterResolving(Handler)`, adică **în timp ce Laravel tratează deja o excepție**. Dacă `AttributeCache::load()` ar arunca abia atunci (producție fără cache), excepția reală ar fi înlocuită cu a noastră. De aceea `packageBooted()` apelează `AttributeCache::all()` când orice flag `integrate_with_laravel` e activ — eroarea de configurare iese la boot, cum cere regula 3.

**Regula de bump.** `AttributeCache::VERSION` se incrementează la **fiecare** release care adaugă chei în array-ul cache-uit (2.1 → 2.2 pentru `error_code`/`log_as`; 2.2 → 3.0 pentru `retry_after`/`audit`). Consecință la deploy: cache-ul vechi e „stale" → în producție cu flag-uri active **aruncă la boot** până rulezi `errors:cache`. `UPGRADE.md` o spune; `optimize` în pipeline-ul de deploy o rezolvă.

#### `src/Support/AttributeScanner.php` (2.1) — schelet

```php
<?php
// file: src/Support/AttributeScanner.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Symfony\Component\Finder\Finder;

final class AttributeScanner
{
    private const MAX_FILES = 5000;

    public function __construct(private readonly AttributeReader $reader) {}

    /** @return list<string> căi absolute existente; suportă glob (src/Domain/*\/Exceptions) */
    public function resolvePaths(array $patterns): array
    {
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

            $data = $this->reader->read($class);
            if ($data !== []) {
                $out[$class] = $data;
            }
        }
        ksort($out);

        return $out;
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
```

`AttributeValidator` (2.1) primește `array<class-string, array>` și întoarce o listă de `ValidationIssue(level: error|warning, class, message)`. Reguli, adăugate pe release: `http_code` în 400–599 (2.1); `report_to` există în `logging.channels` (2.1); `translated_message.key` există în fiecare locale din `config('app.supported_locales', [app.locale])` (2.1, warning); `sensitive` doar pe proprietăți publice (2.1); `log_as` PSR-3 (2.2); `error_code` unic (2.2, error); `rate_limit.by === 'code'` fără `error_code` (2.2, warning); `audit` + `dont_report` (3.0, warning — intenționat?).

#### `src/ExceptionInspector.php` — stare finală 2.2

```php
<?php
// file: src/ExceptionInspector.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Exceptions\AttributedHttpException;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;
use Isaidgitmenow\LaravelErrors\Support\DataSanitizer;
use Isaidgitmenow\LaravelErrors\Support\Masker;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

/**
 * Citește metadatele unei excepții. Static pentru compatibilitate cu API-ul existent,
 * dar toată reflecția e delegată către AttributeCache (WP-01) și tot cache-ul per obiect
 * e WeakMap (F-01: spl_object_id se reciclează; F-29: context() era executat de 5×).
 */
final class ExceptionInspector
{
    private static ?WeakMap $originCache  = null;
    private static ?WeakMap $contextCache = null;

    // ---------------------------------------------------------------- origin & attributes

    /**
     * Excepția „de interes": dezpachetează wrapper-ul nostru, apoi alege cel mai adânc nod
     * care poartă atributele pachetului; altfel cauza-rădăcină.
     */
    public static function origin(Throwable $e): Throwable
    {
        self::$originCache ??= new WeakMap();

        if (isset(self::$originCache[$e])) {
            return self::$originCache[$e];
        }

        $current = $e instanceof AttributedHttpException ? $e->original() : $e;
        $attributed = null;
        $deepest    = $current;

        while ($current !== null) {
            $deepest = $current;
            if (self::attributes($current) !== []) {
                $attributed = $current;
            }
            $current = $current->getPrevious();
        }

        return self::$originCache[$e] = $attributed ?? $deepest;
    }

    /** @return array<string, mixed> */
    public static function attributes(Throwable $e): array
    {
        return app(AttributeCache::class)->for($e::class);
    }

    public static function hasOwnAttributes(Throwable $e): bool
    {
        return self::attributes($e) !== [];
    }

    // ---------------------------------------------------------------- derived values

    public static function httpCode(Throwable $e): int
    {
        $origin = self::origin($e);
        $attrs  = self::attributes($origin);

        if (isset($attrs['http_code'])) {
            return (int) $attrs['http_code'];
        }

        // Wrapper-ul nostru sau un HttpException real: statusul e de încredere.
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        // F-26: getCode() ca status HTTP e o convenție a APLICAȚIEI, nu a PHP-ului.
        // Opt-in, și doar pe excepția aruncată, nu pe cauza adâncă (SDK-uri terțe pun acolo statusul upstream).
        if (config('errors.http_code_from_exception_code', false)) {
            $code = $e->getCode();
            if (is_int($code) && $code >= 400 && $code < 600) {
                return $code;
            }
        }

        return (int) config('errors.default_status', 500);
    }

    public static function hasHttpCodeAttribute(Throwable $e): bool
    {
        return isset(self::attributes(self::origin($e))['http_code']);
    }

    public static function shouldNotReport(Throwable $e): bool
    {
        return (bool) (self::attributes(self::origin($e))['dont_report'] ?? false);
    }

    public static function errorCode(Throwable $e): ?string
    {
        return self::attributes(self::origin($e))['error_code']['code'] ?? null;
    }

    /** @return array{code: string, type: ?string, title: ?string}|null */
    public static function errorCodeDefinition(Throwable $e): ?array
    {
        return self::attributes(self::origin($e))['error_code'] ?? null;
    }

    /** PSR-3. #[LogAs] explicit → derivat din status → 'error'. */
    public static function logLevel(Throwable $e): string
    {
        $attrs = self::attributes(self::origin($e));

        if (isset($attrs['log_as'])) {
            return $attrs['log_as'];
        }

        if (isset($attrs['http_code'])) {
            $status = (int) $attrs['http_code'];

            return match (true) {
                $status === 429 => 'notice',
                $status === 503 => 'critical',
                $status < 500   => 'warning',
                default         => 'error',
            };
        }

        return 'error';
    }

    public static function retryAfter(Throwable $e): ?int
    {
        return self::attributes(self::origin($e))['retry_after'] ?? null;
    }

    public static function rateLimit(Throwable $e): ?RateLimit
    {
        $rl = self::attributes(self::origin($e))['rate_limit'] ?? null;

        return $rl === null ? null : new RateLimit($rl['max'], $rl['interval'], $rl['by']);
    }

    /** @return array{retention: string, category: string}|null */
    public static function audit(Throwable $e): ?array
    {
        return self::attributes(self::origin($e))['audit'] ?? null;
    }

    /** @return string[]|null  null = nu e restricționat / nu are atributul */
    public static function reportToChannels(Throwable $e): ?array
    {
        $attrs    = self::attributes(self::origin($e));
        $channels = $attrs['report_to'] ?? null;

        if ($channels === null) {
            return null;
        }

        $envs = $attrs['report_to_environments'] ?? [];
        if ($envs !== [] && ! app()->environment($envs)) {
            return null;
        }

        return $channels;
    }

    /** F-28: trans() poate întoarce array pentru o cheie de grup. I-06: parametri din proprietăți. */
    public static function translatedMessage(Throwable $e): ?string
    {
        $origin = self::origin($e);
        $def    = self::attributes($origin)['translated_message'] ?? null;

        if ($def === null) {
            return null;
        }

        $replace = [];
        foreach ($def['params'] ?? [] as $placeholder => $source) {
            if (is_int($placeholder)) {
                $placeholder = $source;
            }
            $replace[$placeholder] = self::paramValue($origin, $source);
        }

        $translated = isset($def['choice'])
            ? trans_choice($def['key'], (int) self::paramValue($origin, $def['choice']), $replace)
            : trans($def['key'], $replace);

        if (! is_string($translated) || $translated === $def['key']) {
            return null;
        }

        return $translated;
    }

    /**
     * Contextul #[WithContext], cu #[Sensitive] aplicat la extragere.
     * Calculat O SINGURĂ DATĂ per obiect (F-29) — metodele #[WithContext] pot fi scumpe.
     *
     * @return array<string, mixed>
     */
    public static function context(Throwable $e): array
    {
        self::$contextCache ??= new WeakMap();

        $origin = self::origin($e);   // cheia e originalul: wrapper-ul (Handler) și originalul (hook, Sentry) → același calcul

        if (isset(self::$contextCache[$origin])) {
            return self::$contextCache[$origin];
        }

        $attrs  = self::attributes($origin);
        $masks  = $attrs['sensitive'] ?? [];
        $ctx    = [];

        foreach ($attrs['with_context'] ?? [] as $property) {
            if (property_exists($origin, $property)) {
                $value = $origin->{$property};
                $ctx[$property] = isset($masks[$property]) ? Masker::mask($value, $masks[$property]) : $value;
            }
        }

        $methodMasks = $attrs['with_context_sensitive'] ?? [];
        foreach ($attrs['with_context_methods'] ?? [] as $method) {
            if (! method_exists($origin, $method)) {
                continue;
            }
            $result = $origin->{$method}();
            if (! is_array($result)) {
                continue;
            }
            foreach ($result as $k => $v) {
                $ctx[$k] = isset($methodMasks[$k]) ? Masker::mask($v, $methodMasks[$k]) : $v;
            }
        }

        return self::$contextCache[$origin] = $ctx;
    }

    /** Contextul trecut și prin lista globală de chei sensibile — forma care iese din pachet. */
    public static function sanitizedContext(Throwable $e): array
    {
        return DataSanitizer::sanitize(self::context($e), (array) config('errors.sanitize', []));
    }

    // ---------------------------------------------------------------- housekeeping

    public static function flushCache(): void
    {
        self::$originCache  = new WeakMap();
        self::$contextCache = new WeakMap();
        app(AttributeCache::class)->flushRuntime();
    }

    private static function paramValue(Throwable $origin, string $source): mixed
    {
        $masks = self::attributes($origin)['sensitive'] ?? [];

        if (property_exists($origin, $source)) {
            $v = $origin->{$source};

            return isset($masks[$source]) ? Masker::mask($v, $masks[$source]) : $v;
        }

        if (method_exists($origin, $source)) {
            return $origin->{$source}();
        }

        return '';
    }
}
```

**Atributele nu se moștenesc.** `AttributeReader` citește atributele clasei concrete, nu ale părinților (comportamentul actual, păstrat). Un `#[HttpCode(402)]` pe o clasă abstractă de bază **nu** se aplică sub-claselor. Documentat în `docs/attributes.md`; extinderea (`getParentClass()` cu merge) e o decizie separată, cu impact pe cache.

**De ce `origin()` dezpachetează explicit wrapper-ul** deși traversarea `getPrevious()` l-ar găsi oricum: wrapper-ul nu are atribute, deci `$attributed` ar fi originalul — corect; dar `$deepest` ar putea fi o cauză și mai adâncă **fără** atribute când originalul nu are nici el (clasă mapată doar prin `translated_message`? nu — atunci are). Dezpachetarea explicită face intenția lizibilă și elimină un caz de gândit.

#### Atributele noi (2.1 / 2.2 / 3.0)

```php
<?php
// file: src/Attributes/Sensitive.php  (2.1)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * TARGET_PROPERTY | TARGET_PARAMETER: pe o proprietate promovată PHP aplică atributul și parametrului;
 * cu TARGET_PROPERTY singur, ReflectionParameter::getAttributes()[0]->newInstance() aruncă Error (PHP 8.4.21).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Sensitive
{
    public const MASKS = ['full', 'last4', 'first_last', 'email', 'hash', 'length'];

    public function __construct(public string $mask = 'full')
    {
        if (! in_array($mask, self::MASKS, true)) {
            throw new \InvalidArgumentException("Unknown mask [{$mask}]. Allowed: " . implode(', ', self::MASKS));
        }
    }
}
```

```php
<?php
// file: src/Attributes/ErrorCode.php  (2.2)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ErrorCode
{
    public function __construct(
        public string $code,            // INSUFFICIENT_FUNDS — stabil, documentat
        public ?string $type = null,    // URI RFC 9457; implicit {type_base_url}/insufficient-funds
        public ?string $title = null,   // implicit: codul umanizat („Insufficient funds"), NU textul statusului
    ) {
        if (! preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $code)) {
            throw new \InvalidArgumentException("Error code [{$code}] must be SCREAMING_SNAKE_CASE, 3–64 chars.");
        }
    }
}
```

```php
<?php
// file: src/Attributes/LogAs.php  (2.2)  — nu „LogLevel": s-ar ciocni cu Psr\Log\LogLevel

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class LogAs
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function __construct(public string $level)
    {
        if (! in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException("Invalid PSR-3 level [{$level}].");
        }
    }
}
```

```php
<?php
// file: src/Attributes/RetryAfter.php  (3.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RetryAfter
{
    public function __construct(public int $seconds)
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('RetryAfter must be at least 1 second.');
        }
    }
}
```

```php
<?php
// file: src/Attributes/Audit.php  (3.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Audit
{
    public function __construct(
        public string $retention = '7 years',   // orice string acceptat de DateInterval / Carbon::add()
        public string $category = 'general',
    ) {
        if (! preg_match('/^[a-z][a-z0-9_\-]{1,63}$/', $category)) {
            throw new \InvalidArgumentException("Audit category [{$category}] must be lowercase kebab/snake, 2–64 chars.");
        }
    }
}
```

Extinderi ale atributelor existente (semnături finale):

```php
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TranslatedMessage
{
    /** @param array<int|string, string> $params  ['amount'] sau ['suma' => 'amount']; valori = proprietăți/metode publice */
    public function __construct(public string $key, public array $params = [], public ?string $choice = null) {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class WithContext
{
    /** @param string[] $properties  @param array<string, string> $sensitive  cheie → mască, pentru array-urile întoarse de metode */
    public function __construct(public array $properties = [], public array $sensitive = []) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RateLimit
{
    public const BY = ['class', 'location', 'message', 'code'];

    public function __construct(public int $max, public int $intervalInMinutes = 5, public string $by = 'location')
    {
        if ($max < 1 || $intervalInMinutes < 1 || ! in_array($by, self::BY, true)) {
            throw new \InvalidArgumentException('Invalid RateLimit configuration.');
        }
    }
}
```

`by` implicit `'location'` în 2.x (comportamentul actual), `'class'` în 3.0 — schimbarea e în constructor + CHANGELOG.

#### Teste WP-01

- **F-01 regresie:** excepție înlănțuită → `origin()`, `unset()`, excepție nouă → `origin()` întoarce noul obiect (pică pe `spl_object_id`).
- Wrapper `AttributedHttpException` și originalul → același `origin()`, același `context()`.
- `context()` cu metodă `#[WithContext]` cu contor → după `report()` + `render()`, contorul e 1 (F-29).
- `translatedMessage()` cu cheie de grup → `null`, fără `TypeError` (F-28); cu `params` → înlocuire; proprietate `#[Sensitive]` apare mascată în mesaj.
- `httpCode()`: `#[HttpCode]` câștigă; `HttpExceptionInterface` pe `$e`; `getCode()` 404 pe cauza adâncă → 500; cu flag `true` și 404 pe excepția aruncată → 404 (F-26).
- `AttributeCache`: fișier cu `version` veche → warning + rescanare/gol; producție fără fișier și `map_http_code => true` → `InvalidConfigurationException`; non-producție → memoizare pe mtime, invalidată după `touch`.
- `AttributeScanner::classFromFile()` pe fișiere cu `namespace X;`, `namespace X {}`, `Foo::class` înainte de `class`, fără clasă, cu `enum`.
- `AttributeValidator`: fiecare regulă pe fixture.

---

### WP-02 · Integrarea cu `Handler`

**Fișiere:** `src/ErrorHandler.php` (rescris), `src/Exceptions/AttributedHttpException.php` + 9 sub-clase [noi], `src/Support/AttributedExceptionMapper.php` [nou], `src/Support/HandlerSlots.php` [nou], `src/Support/ErrorIdentity.php` [nou], `src/Support/RateLimitKey.php` [nou], `src/Facades/LaravelErrors.php` (extins).

**Închide:** F-05 (dublă logare / `#[DontReport]` ineficient — prin contractul `report()` din WP-03 și `dontReport` nativ), I-01 (header prin slot), I-03, I-11 (`level()`/`context()`), I-12a (`throttle()`).

#### Familia de wrapper-e (2.2)

```php
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
```

```php
<?php
// file: src/Exceptions/WarningAttributedHttpException.php  (și analog: Debug, Info, Notice, Error, Critical, Alert, Emergency)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Exceptions;

final class WarningAttributedHttpException extends AttributedHttpException {}
```

```php
<?php
// file: src/Exceptions/SuppressedAttributedHttpException.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/** #[DontReport] pe o clasă mapată: contractul nativ, verificat PRIMUL în Handler::shouldntReport(). */
final class SuppressedAttributedHttpException extends AttributedHttpException implements ShouldntReport {}
```

**Notă despre `report()` din wrapper.** `Handler::reportThrowable()` face: `if (Reflector::isCallable([$e, 'report']) && $this->container->call([$e, 'report']) !== false) return;`. Delegăm **prin container** (originalul poate cere dependențe în `report(Foo $foo)`), returnăm exact rezultatul lui; când originalul nu are `report()`, întoarcem `false` ca să nu blocăm nimic. Dacă originalul are `report(): void` (`null`), semantica Laravel e „a tratat" — o păstrăm.

**`Responsable`.** `Handler::render()` verifică `$e instanceof Responsable` pe wrapper. Mapper-ul **nu înfășoară** excepțiile `Responsable` (întoarce `$e` neschimbat) — ele își construiesc singure răspunsul, iar wrapper-ul le-ar fi ascuns.

#### `src/Support/AttributedExceptionMapper.php` (2.2)

```php
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
```

#### `src/Support/ErrorIdentity.php` (2.1)

```php
<?php
// file: src/Support/ErrorIdentity.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Illuminate\Support\Str;
use Isaidgitmenow\LaravelErrors\Exceptions\AttributedHttpException;
use Throwable;
use WeakMap;

final class ErrorIdentity
{
    private static ?WeakMap $ids = null;

    /** Generează întotdeauna; config `error_id.enabled` gate-uiește doar injectarea în răspuns/log/context. */
    public static function for(Throwable $e): string
    {
        self::$ids ??= new WeakMap();

        $key = $e instanceof AttributedHttpException ? $e->original() : $e;

        return self::$ids[$key] ??= (string) Str::ulid();
    }

    public static function flush(): void
    {
        self::$ids = new WeakMap();
    }
}
```

#### `src/Support/RateLimitKey.php` (2.2)

```php
<?php
// file: src/Support/RateLimitKey.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Throwable;

final class RateLimitKey
{
    /** Lucrează pe origin(): pe wrapper, getFile()/getLine() ar fi ale mapper-ului și toate ar împărți un buget. */
    public static function for(Throwable $e, string $by, string $scope = ''): string
    {
        $o = ExceptionInspector::origin($e);

        $raw = match ($by) {
            'class'    => $o::class,
            'message'  => $o::class . ':' . md5($o->getMessage()),
            'code'     => ExceptionInspector::errorCode($e) ?? $o::class,   // fallback la class când nu există #[ErrorCode]
            default    => $o::class . ':' . $o->getFile() . ':' . $o->getLine(),
        };

        return 'laravel-errors:rl:' . md5($scope . '|' . $raw);
    }
}
```

#### `src/Support/HandlerSlots.php` (2.1) — sloturile compuse

```php
<?php
// file: src/Support/HandlerSlots.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\Events\ExceptionRendered;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Handler::respondUsing() și Handler::shouldRenderJsonWhen() sunt câte UN slot: ultimul apel câștigă, tăcut.
 * Pachetul deține sloturile și compune: pipeline-ul intern + închiderile înregistrate de aplicație.
 */
final class HandlerSlots
{
    /** @var list<Closure(Response, Throwable, Request): Response> */
    private array $respond = [];

    /** @var list<Closure(Request, Throwable): ?bool> */
    private array $json = [];

    /** Config prin Repository (citire lazy — F-21: testele fac config()->set() după ce Handler-ul e rezolvat). */
    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
    ) {}

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config->get("errors.{$key}", $default);
    }

    private function dispatch(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $failure) {
            CriticalLog::once('laravel-errors event listener failed', $failure, ['event' => $event::class]);
        }
    }

    public function onRespond(Closure $callback): void
    {
        $this->respond[] = $callback;
    }

    public function onRenderJson(Closure $callback): void
    {
        $this->json[] = $callback;
    }

    /** Marker intern pus de ErrorManager::render() pe răspunsurile proprii, ca să nu emitem ExceptionRendered de două ori. */
    public const RENDERED_HEADER = 'X-Laravel-Errors-Rendered';

    public function runRespond(Response $response, Throwable $e, Request $request): Response
    {
        // Răspunsurile construite de utilizator (HttpResponseException, ValidationException) nu se ating.
        $ours = ! app(ErrorManagerInterface::class)->isPassThrough($e);

        if ($ours && $this->cfg('error_id.enabled', true)) {
            $response = $this->injectErrorId($response, $e);
        }
        if ($ours && ($retry = ExceptionInspector::retryAfter($e)) !== null && ! $response->headers->has('Retry-After')) {
            $response->headers->set('Retry-After', (string) $retry);   // acoperă toate căile de randare, nu doar clasele mapate
        }

        if ($ours && ! $response->headers->has(self::RENDERED_HEADER)) {
            // Laravel a randat (renderele noastre au întors null / n-au fost atinse): emitem noi evenimentul.
            $this->dispatch(new ExceptionRendered(
                exception: ExceptionInspector::origin($e),
                errorId:   ErrorIdentity::for($e),
                response:  $response,
                renderer:  null,
                context:   'laravel',
            ));
        }
        $response->headers->remove(self::RENDERED_HEADER);

        foreach ($this->respond as $cb) {
            $response = $cb($response, $e, $request) ?? $response;
        }

        return $response;
    }

    public function shouldRenderJson(Request $request, Throwable $e): bool
    {
        foreach ($this->json as $cb) {
            $decision = $cb($request, $e);
            if ($decision !== null) {
                return (bool) $decision;   // prima închidere care decide, câștigă
            }
        }

        if ($request->expectsJson()) {
            return true;
        }

        foreach ((array) $this->cfg('api_prefixes', ['api']) as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '' && $request->is($prefix, $prefix . '/*')) {
                return true;
            }
        }

        return false;
    }

    private function injectErrorId(Response $response, Throwable $e): Response
    {
        $id     = ErrorIdentity::for($e);
        $header = (string) $this->cfg('error_id.header', 'X-Error-Id');
        $key    = (string) $this->cfg('error_id.payload_key', 'error_id');

        $response->headers->set($header, $id);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data) && ! array_key_exists($key, $data)) {
                $response->setData($data + [$key => $id]);
            }
        }

        return $response;
    }
}
```

#### `src/ErrorHandler.php` — stare finală 3.0 (în 2.2, blocurile marcate `flags` sunt opt-in)

```php
<?php
// file: src/ErrorHandler.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\Exceptions\AttributedHttpException;
use Isaidgitmenow\LaravelErrors\Renderers\ValidationProblemRenderer;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;
use Isaidgitmenow\LaravelErrors\Support\AttributedExceptionMapper;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Isaidgitmenow\LaravelErrors\Support\RateLimitKey;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Singurul punct de contact cu Handler-ul Laravel. Ordinea și semantica: §1.1.
 *
 *   ->withExceptions(fn (Exceptions $e) => ErrorHandler::handle($e))
 */
final class ErrorHandler
{
    public static function handle(Exceptions $exceptions): void
    {
        $config = (array) config('errors', []);
        $flags  = (array) ($config['integrate_with_laravel'] ?? []);
        $cache  = app(AttributeCache::class);
        $slots  = app(HandlerSlots::class);

        // 1. map() PER CLASĂ pentru orice clasă cu http_code | translated_message | error_code.
        //    Nu o închidere pe Throwable: s-ar înregistra cu cheia Throwable și, fiind prima potrivire,
        //    ar bloca toate map()-urile aplicației.
        if ($flags['map_http_code'] ?? false) {
            $mapper = app(AttributedExceptionMapper::class);
            foreach ($cache->classesWith('http_code', 'translated_message', 'error_code') as $class) {
                $exceptions->map($class, fn (Throwable $e) => $mapper->wrap($e));
            }
        }

        // 2. #[DontReport]. Mapate → SuppressedAttributedHttpException implements ShouldntReport (nativ).
        //    Nemapate → dontReport([...]). Clasele cu #[Audit] sunt EXCLUSE: pipeline-ul nostru trebuie să ruleze.
        if ($flags['dont_report'] ?? false) {
            $mapped = ($flags['map_http_code'] ?? false)
                ? array_flip($cache->classesWith('http_code', 'translated_message', 'error_code'))
                : [];

            $plain = [];
            foreach ($cache->classesWith('dont_report') as $class) {
                $data = $cache->for($class);
                if (isset($data['audit']) || isset($mapped[$class])) {
                    continue;
                }
                $plain[] = $class;
            }
            if ($plain !== []) {
                $exceptions->dontReport($plain);
            }
        }

        // 3. Nivelul de log. Pe familia de wrapper-e (o singură dată, indiferent câte clase există)
        //    și pe clasele nemapate din cache.
        foreach (AttributedHttpException::levelMap() as $psr => $wrapperClass) {
            $exceptions->level($wrapperClass, $psr);
        }
        foreach ($cache->all() as $class => $data) {
            $level = $data['log_as'] ?? self::derivedLevel($data);
            if ($level !== null) {
                $exceptions->level($class, $level);
            }
        }

        // 4. Îmbogățirea liniei de log Laravel — LogReporter e scos din default încă din 2.0.
        $exceptions->context(fn (Throwable $e) => [
            'error_id'         => ErrorIdentity::for($e),
            'error_code'       => ExceptionInspector::errorCode($e),
            'original_message' => ExceptionInspector::origin($e)->getMessage(),   // wrapper-ul are mesajul public
            'error_context'    => ExceptionInspector::sanitizedContext($e),
        ]);

        // 5. throttle() nativ — DOAR în producție: rulează în shouldntReport() și când se declanșează
        //    nu mai rulează NICIUN reporter (nici Debugbar/Xdebug). Fail-open garantat de Laravel (rescue).
        if (app()->isProduction() && ($config['native_throttle'] ?? true)) {
            $exceptions->throttle(function (Throwable $e) {
                $limit = ExceptionInspector::rateLimit($e);

                return $limit === null
                    ? null
                    : Limit::perMinutes($limit->intervalInMinutes, $limit->max)
                        ->by(RateLimitKey::for($e, $limit->by, 'laravel'));
            });
        }

        // 6. Sloturile unice, compuse.
        $exceptions->respond(fn (Response $r, Throwable $e, Request $req) => $slots->runRespond($r, $e, $req));

        if ($flags['json_decision'] ?? false) {
            $exceptions->shouldRenderJsonWhen(fn (Request $req, Throwable $e) => $slots->shouldRenderJson($req, $e));
        }

        // 7. 422 unificat (opt-in, cu Problem Details).
        if (($config['problem_details']['enabled'] ?? false) && ($config['problem_details']['unify_validation'] ?? false)) {
            $exceptions->render(fn (ValidationException $e, Request $req) => app(ValidationProblemRenderer::class)->render($e, $req));
        }

        // 8. Pipeline-ul pachetului. Contractul report(): true = Laravel loghează; false DOAR pentru #[DontReport].
        $exceptions->report(fn (Throwable $e): bool => app(ErrorManagerInterface::class)->report($e));
        $exceptions->render(fn (Throwable $e, Request $req) => app(ErrorManagerInterface::class)->render($e, $req));
    }

    private static function derivedLevel(array $data): ?string
    {
        if (! isset($data['http_code'])) {
            return null;
        }
        $status = (int) $data['http_code'];

        return match (true) {
            $status === 429 => 'notice',
            $status === 503 => 'critical',
            $status < 500   => 'warning',
            default         => 'error',
        };
    }
}
```

**Facade** `LaravelErrors` (extinsă): `respond(Closure)` → `HandlerSlots::onRespond`, `renderJsonWhen(Closure)` → `onRenderJson`, `fake()` (WP-12), `passThrough(string)` → `ErrorManager::addPassThrough`.

#### Teste WP-02

- **Regresia centrală I-03:** `#[HttpCode(402)]` cu `map_http_code` → `Log::assertLogged()` (nu e în `internalDontReport`) **și** `assertReported()`.
- `$exceptions->map(Foo::class, Bar::class)` al aplicației după `handle()` → aplicat.
- Același original → același wrapper în `report()` și `render()`; `dontReportDuplicates()` funcționează.
- Rută API, `debug=false`: clasă mapată → status 402 + mesaj **public**; clasă nemapată → „Server Error".
- Clasă cu doar `#[TranslatedMessage]` → mapată, mesajul tradus apare cu `default_status`.
- `#[DontReport]` mapat → nimic logat; nemapat → nimic logat; `#[DontReport]`+`#[Audit]` → rândul de audit există, nimic în log.
- `level()`: `WARNING` pe clasă mapată **și** nemapată.
- `Integration::handles` înregistrat **după** `handle()` → capturează (contract `report()`).
- `LaravelErrors::respond()` al aplicației rulează după injectarea `error_id`; `errors:doctor` detectează suprascrierea slotului de către un `$exceptions->respond()` direct.
- `throttle()`: neînregistrat în local; în producție, a 11-a excepție `by:'class'` din alt call-site → nelogată; cache picat → logată.
- `RateLimitKey::for()` pe wrapper și pe original → aceeași cheie.

---

### WP-03 · Orchestratorul `ErrorManager`

**Fișiere:** `src/ErrorManager.php` (rescris), `src/Contracts/ErrorManagerInterface.php` [nou], `src/Events/ExceptionReported.php`, `src/Events/ExceptionRendered.php` [noi], `src/Support/CriticalLog.php` [nou].

**Închide:** F-05 (contract `report(): bool`), F-06 (`pass_through` pe excepția aruncată, wrappere enumerate), F-18 (dedupe), F-21 (config lazy prin `Repository`, cu union pentru teste), F-22a (eliminat — nu se cache-uiește lock-ul), I-16 (evenimente), §10.1 (niciun `catch` gol), §10.2 (stare pe instanță, nu statică).

```php
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
}
```

```php
<?php
// file: src/ErrorManager.php  — stare finală 2.2

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Isaidgitmenow\LaravelErrors\Contracts\BypassesRateLimiting;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\Contracts\InteractiveContextDetector;
use Isaidgitmenow\LaravelErrors\Contracts\ReportsIgnoredExceptions;
use Isaidgitmenow\LaravelErrors\Events\ExceptionRendered;
use Isaidgitmenow\LaravelErrors\Events\ExceptionReported;
use Isaidgitmenow\LaravelErrors\Reporters\RateLimitedReporter;
use Isaidgitmenow\LaravelErrors\Support\CriticalLog;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ErrorManager implements ErrorManagerInterface
{
    /** @var array<class-string, class-string> */
    private array $customContexts = [];

    /** @var list<class-string<ErrorReporterInterface>> */
    private array $customReporters = [];

    /** @var array<class-string, true>  dedupe — F-18 */
    private array $dynamicPassThrough = [];

    /** Stare pe instanță (§10.2): singleton-ul e re-creat per worker Octane; nu mai avem statice de golit. */
    private bool $bypassConsoleExceptions = false;

    /** @param array<string, mixed>|Repository $config  union pentru testele care fac `new ErrorManager(config: [...])` (F-21) */
    public function __construct(
        private readonly array|Repository $config = [],
        private readonly ?Dispatcher $events = null,
    ) {}

    // ------------------------------------------------------------------ report

    public function report(Throwable $e): bool
    {
        if ($this->bypassConsoleExceptions) {
            return true;    // procesul MCP: nu rulăm nimic, dar Laravel poate loga în fișier (nu atinge STDOUT)
        }
        if ($this->isPassThrough($e)) {
            return true;    // §4: pass_through ocolește reporterii ȘI renderele pachetului; Laravel decide singur
        }

        $suppress = ExceptionInspector::shouldNotReport($e);
        $ran      = [];

        try {
            if (! $suppress) {
                $this->pushContext($e);
            }

            foreach ($this->reporters() as $reporterClass) {
                $reporter = app($reporterClass);

                if (! $reporter instanceof ErrorReporterInterface) {
                    continue;
                }
                if ($suppress && ! $reporter instanceof ReportsIgnoredExceptions) {
                    continue;
                }

                $reporter = $this->wrapWithRateLimit($reporter, $e);

                if (! $reporter->shouldReport($e)) {
                    continue;
                }

                $ran[]  = $reporterClass;
                $result = $reporter->report($e);

                if ($result === false) {
                    break;
                }
            }

            $this->dispatch(new ExceptionReported(
                exception:        ExceptionInspector::origin($e),
                errorId:          ErrorIdentity::for($e),
                errorCode:        ExceptionInspector::errorCode($e),
                httpStatus:       ExceptionInspector::httpCode($e),
                logLevel:         ExceptionInspector::logLevel($e),
                sanitizedContext: ExceptionInspector::sanitizedContext($e),
                reportersRun:     $ran,
            ));
        } catch (Throwable $failure) {
            // §10.1: niciodată catch gol. Rate-limitat 1/min/mesaj ca să nu inunde log-ul.
            CriticalLog::once('laravel-errors report pipeline failed', $failure, ['for' => $e::class]);
        }

        // #[DontReport] → false: oprim și logarea Laravel și callback-urile următoare (Sentry) — asta e intenția.
        // Orice altceva → true: Laravel loghează O DATĂ, cu level() + context() din ErrorHandler.
        return ! $suppress;
    }

    // ------------------------------------------------------------------ render

    public function render(Throwable $e, Request $request): ?Response
    {
        if ($this->bypassConsoleExceptions) {
            return null;
        }

        try {
            if ($this->isPassThrough($e)) {
                return null;
            }

            [$detector, $rendererClass] = $this->matchContext($e, $request);

            if ($this->shouldYieldToIgnition($detector, $request)) {
                return null;
            }

            if ($rendererClass === null) {
                return null;
            }

            $renderer = app($rendererClass);
            if (! $renderer instanceof ExceptionRendererInterface) {
                return null;
            }

            $response = $renderer->render($e, $request);

            if ($response !== null) {
                $response->headers->set(HandlerSlots::RENDERED_HEADER, '1');   // HandlerSlots îl consumă și îl elimină
                $this->dispatch(new ExceptionRendered(
                    exception: ExceptionInspector::origin($e),
                    errorId:   ErrorIdentity::for($e),
                    response:  $response,
                    renderer:  $rendererClass,
                    context:   $this->contextName($detector),
                ));
            }

            return $response;
        } catch (Throwable $failure) {
            CriticalLog::once('laravel-errors render pipeline failed', $failure, ['for' => $e::class]);

            return null;
        }
    }

    // ------------------------------------------------------------------ pass-through (F-06)

    public function isPassThrough(Throwable $e): bool
    {
        $classes = array_merge((array) $this->cfg('pass_through', []), array_keys($this->dynamicPassThrough));

        // 1. Excepția efectiv aruncată are prioritate. Wrapper-ul nostru NU e Symfony\HttpException, deci nu e prins aici.
        foreach ($classes as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        // 2. Dacă vreun nod din lanț poartă atributele noastre (origin() îl alege), dezvoltatorul a exprimat
        //    o intenție — nu o suprascriem cu cauza adâncă.
        if (ExceptionInspector::hasOwnAttributes(ExceptionInspector::origin($e))) {
            return false;
        }

        // 3. Doar wrapper-ele CUNOSCUTE de framework (ViewException) își transmit decizia din cauza reală.
        $isWrapper = false;
        foreach ((array) $this->cfg('pass_through_chain_wrappers', [\Illuminate\View\ViewException::class]) as $w) {
            if ($e instanceof $w) {
                $isWrapper = true;
                break;
            }
        }
        if (! $isWrapper) {
            return false;
        }

        $origin = ExceptionInspector::origin($e);
        if ($origin === $e) {
            return false;
        }
        foreach ($classes as $class) {
            if ($origin instanceof $class) {
                return true;
            }
        }

        return false;
    }

    public function addPassThrough(string $exceptionClass): void
    {
        $this->dynamicPassThrough[$exceptionClass] = true;
    }

    /** Compat: API static vechi → instanță. */
    public static function passThrough(string $exceptionClass): void
    {
        app(ErrorManagerInterface::class)->addPassThrough($exceptionClass);
    }

    public function addContext(string $detector, string $renderer): static
    {
        $this->customContexts[$detector] = $renderer;

        return $this;
    }

    public function addReporter(string $reporter): static
    {
        $this->customReporters[] = $reporter;

        return $this;
    }

    public function bypassConsoleExceptions(bool $on = true): void
    {
        $this->bypassConsoleExceptions = $on;
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: ?ContextDetectorInterface, 1: ?class-string} */
    private function matchContext(Throwable $e, Request $request): array
    {
        foreach (array_merge($this->customContexts, (array) $this->cfg('contexts', [])) as $detectorClass => $rendererClass) {
            $detector = app($detectorClass);
            if ($detector instanceof ContextDetectorInterface && $detector->detect($e, $request)) {
                return [$detector, $rendererClass];
            }
        }

        return [null, null];
    }

    private function shouldYieldToIgnition(?ContextDetectorInterface $detector, Request $request): bool
    {
        if (! $this->cfg('respect_debug_mode', true) || ! app()->hasDebugModeEnabled()) {
            return false;
        }

        return ! $request->ajax() && ! $request->wantsJson() && ! $detector instanceof InteractiveContextDetector;
    }

    private function pushContext(Throwable $e): void
    {
        // I-01/I-07: push, nu add (un request poate produce mai multe erori); vizibil, nu hidden (altfel nu ajunge nicăieri).
        Context::push('errors', [
            'error_id' => ErrorIdentity::for($e),
            'class'    => ExceptionInspector::origin($e)::class,
            'code'     => ExceptionInspector::errorCode($e),
            'context'  => ExceptionInspector::sanitizedContext($e),
        ]);
    }

    private function wrapWithRateLimit(ErrorReporterInterface $reporter, Throwable $e): ErrorReporterInterface
    {
        if ($reporter instanceof BypassesRateLimiting || ExceptionInspector::rateLimit($e) === null) {
            return $reporter;
        }

        return new RateLimitedReporter($reporter);
    }

    /** @return list<class-string> */
    private function reporters(): array
    {
        // F-22a: NU se cache-uiește pe instanță — singleton-ul trăiește cât procesul MCP, iar lock-ul se schimbă în același proces.
        if (app()->environment('local') && file_exists(storage_path('framework/mcp_mock_reporters.lock'))) {
            return [];
        }

        return array_merge($this->customReporters, (array) $this->cfg('reporters', []));
    }

    private function contextName(?ContextDetectorInterface $detector): string
    {
        return $detector === null ? 'web' : strtolower((string) preg_replace('/Detector$/', '', class_basename($detector)));
    }

    private function dispatch(object $event): void
    {
        try {
            ($this->events ?? app(Dispatcher::class))->dispatch($event);
        } catch (Throwable $failure) {
            CriticalLog::once('laravel-errors event listener failed', $failure, ['event' => $event::class]);
        }
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        if ($this->config instanceof Repository) {
            return $this->config->get("errors.{$key}", $default);
        }

        return $this->config[$key] ?? $default;
    }
}
```

```php
<?php
// file: src/Support/CriticalLog.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Un pachet de error handling care tace când eșuează nu e „self-healing", e invizibil. */
final class CriticalLog
{
    public static function once(string $message, Throwable $failure, array $context = []): void
    {
        try {
            $key = 'laravel-errors:critical:' . md5($message . '|' . $failure::class . '|' . $failure->getMessage());

            if (! Cache::add($key, 1, 60)) {
                return;
            }

            Log::critical("[laravel-errors] {$message}: {$failure->getMessage()}", $context + [
                'exception' => $failure,
            ]);
        } catch (Throwable) {
            // ultimul strat: dacă nici asta nu merge, nu mai avem ce face în siguranță
        }
    }
}
```

Evenimentele (2.1) — `readonly` DTO-uri cu exact câmpurile din apelurile de mai sus. `Listeners/MetricsListener.php` (2.1): ascultă `ExceptionReported`, dezactivat implicit (`errors.metrics => null`), apelează închiderea/class-string-ul invokabil din config prin `CallableResolver` — exemplu de integrare Prometheus/StatsD, fără dependență de o bibliotecă. `ExceptionRendered` e emis din `ErrorManager::render()` pentru renderele proprii **și** din `HandlerSlots::runRespond()` cu `renderer: null`, `context: 'laravel'` când răspunsul n-a trecut prin noi — `HandlerSlots` primește `Dispatcher` în constructor și, ca să nu emită de două ori, sare emisia când răspunsul are deja header-ul intern `X-Laravel-Errors-Rendered` pus de `ErrorManager::render()` (header eliminat tot în `runRespond`, înainte de a ieși).

**Teste WP-03:** `report()` → `true` pentru excepție normală, `false` pentru `#[DontReport]`, `true` cu bypass; reporter care aruncă → `Log::critical` o dată, restul pipeline-ului nu e afectat de a doua eroare identică; `isPassThrough` (F-06): `PlainDomainException(previous: ModelNotFound)` → `false`; `ViewException(previous: NotFoundHttpException)` → `true`; `ViewException(previous: Plain)` → `false`; wrapper `AttributedHttpException` → `false` deși `pass_through` conține `HttpException`; `ExceptionReported` cu context sanitizat; listener care aruncă → `critical`, raportarea continuă; `new ErrorManager(config: [...])` din testele existente rămâne valid.

---

### WP-04 · Rendere, mesaj public, Problem Details

**Fișiere:** `src/Support/MessageResolver.php` [nou], `src/Support/CallableResolver.php` [nou], `src/Support/ProblemDetailsFormatter.php` [nou], `src/Renderers/{Api,Livewire,Filament,Inertia,Web}Renderer.php` (rescrise), `src/Renderers/ValidationProblemRenderer.php` [nou], `resources/views/error.blade.php` [nou], `resources/js/ErrorPage.{vue,tsx}` [noi].

**Închide:** F-02 (mesaj brut în producție — **testat ca intenționat** în `RenderersTest:27`, `ThirdPartyRenderersTest:23,25,58,72,84`; se rescriu), F-07 (Inertia `props`), F-16 (Filament mereu JSON), F-24 (class-string ignorat), I-02, I-09(b,c), I-15.

```php
<?php
// file: src/Support/MessageResolver.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Singurul loc care decide ce mesaj ajunge la client.
 * 'attributed' (implicit): brut doar pentru #[TranslatedMessage] / #[HttpCode] / HttpExceptionInterface real; restul generic.
 * 'always': comportamentul pre-2.0 (NU în producție). 'never': generic chiar și pentru cele atribuite.
 * APP_DEBUG=true: brut întotdeauna.
 */
final class MessageResolver
{
    public static function public(Throwable $e, int $statusCode, array $config = []): string
    {
        if (($translated = ExceptionInspector::translatedMessage($e)) !== null) {
            return $translated;
        }

        // Un HttpExceptionInterface REAL (abort(503, 'Down'), chiar cu previous: PDOException) poartă un mesaj
        // scris deliberat pentru client. Wrapper-ul nostru intră tot aici — mesajul lui e deja public. Idempotent.
        if ($e instanceof HttpExceptionInterface) {
            return $e->getMessage();
        }

        $mode = $config['expose_messages'] ?? 'attributed';

        if ($mode === 'always' || app()->hasDebugModeEnabled()) {
            return ExceptionInspector::origin($e)->getMessage();
        }

        if ($mode === 'attributed' && ExceptionInspector::hasHttpCodeAttribute($e)) {
            return ExceptionInspector::origin($e)->getMessage();
        }

        return self::fallback($statusCode, $e, $config);
    }

    private static function fallback(int $statusCode, Throwable $e, array $config): string
    {
        $prefix = (string) ($config['fallback_message_prefix'] ?? 'errors.http');
        $key    = "{$prefix}.{$statusCode}";
        $params = ($config['error_id']['in_message'] ?? true) ? ['error_id' => ErrorIdentity::for($e)] : [];

        foreach ([$key, "laravel-errors::http.{$statusCode}"] as $candidate) {   // ale aplicației, apoi ale pachetului
            $translated = trans($candidate, $params);
            if (is_string($translated) && $translated !== $candidate) {
                return $translated;
            }
        }

        $text = Response::$statusTexts[$statusCode] ?? 'Server Error';

        return $params === [] ? $text : "{$text} (ref: {$params['error_id']})";
    }
}
```

```php
<?php
// file: src/Support/CallableResolver.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Closure;
use Isaidgitmenow\LaravelErrors\Exceptions\InvalidConfigurationException;

/** F-24: config-ul promitea class-string invokable pentru config:cache; codul accepta doar Closure. */
final class CallableResolver
{
    public static function resolve(mixed $value, string $configKey): ?callable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Closure) {
            return $value;
        }
        if (is_string($value) && class_exists($value)) {
            $instance = app($value);
            if (is_callable($instance)) {
                return $instance;
            }
        }
        if (is_object($value) && is_callable($value)) {
            return $value;
        }

        throw new InvalidConfigurationException("errors.{$configKey} must be a Closure, an invokable class-string, or null.");
    }
}
```

```php
<?php
// file: src/Support/ProblemDetailsFormatter.php  (2.2)

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
    public function __construct(private readonly array $config) {}

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
```

```php
<?php
// file: src/Renderers/ApiRenderer.php  — stare finală 2.2

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\CallableResolver;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\MessageResolver;
use Isaidgitmenow\LaravelErrors\Support\ProblemDetailsFormatter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApiRenderer implements ExceptionRendererInterface
{
    public function __construct(private readonly array $config = []) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        $status  = ExceptionInspector::httpCode($e);
        $message = MessageResolver::public($e, $status, $this->config);

        if (($formatter = CallableResolver::resolve($this->config['json_formatter'] ?? null, 'json_formatter')) !== null) {
            return response()->json($formatter($e, $request), $status);
        }

        if ($this->config['problem_details']['enabled'] ?? false) {
            $payload = app(ProblemDetailsFormatter::class)->format($e, $request, $status, $message);
            if ($this->config['problem_details']['legacy_message'] ?? true) {
                $payload['message'] = $message;
            }

            return response()->json($payload, $status, ['Content-Type' => 'application/problem+json']);
        }

        // Forma legacy — `errors: []` rămâne: clienții fac response.errors.length.
        return response()->json([
            'message'  => $message,
            'errors'   => [],
            'code'     => ExceptionInspector::errorCode($e) ?? 'HTTP_' . $status,
            'error_id' => ErrorIdentity::for($e),
        ], $status);
    }
}
```

**`LivewireRenderer` / `FilamentRenderer` (2.0):** ambele calculează `$status` + `MessageResolver::public()`. Filament: `filament_handler` prin `CallableResolver`; `Notification::send()` în `try/catch` (cere sesiune); **JSON doar pentru** `X-Livewire`/`ajax()`/`wantsJson()`, altfel `null` → pagina HTML (F-16). În 3.0 ambele sunt fallback pentru cazurile pe care hook-ul (WP-09) nu le acoperă.

**`InertiaRenderer` (2.0):** moduri `page` (implicit — `Inertia::render($component, [...])->toResponse($request)->setStatusCode($status)`), `flash` (`back()->with('error', [...])` în `try/catch`, fallback `RedirectResponse(302)`), `redirect` alias pentru `page`. Valoarea veche `props` → `InvalidConfigurationException` **la boot** (WP-11), nu în renderer (self-heal-ul ar înghiți). În 3.0 se adaugă `respond`: pagina Inertia se randează din `HandlerSlots::onRespond` (varianta oficială Inertia), nu prin `$exceptions->respond()` direct.

**`WebRenderer` (3.0):** ordinea: `errors.{status}` al aplicației → `errors::{status}` al Laravel-ului (**după** `Handler::registerErrorViewPaths()`, altfel `view()->exists()` nu-l vede și am înlocui paginile default ale Laravel pentru orice aplicație care nu le-a publicat) → `laravel-errors::error` cu `status`, `message`, `error_id`, `code`, `context` (doar debug). Laravel livrează view-uri doar pentru 401/403/404/419/429/500/503; pentru un 402 view-ul nostru e singurul care arată mesajul tradus. Config `web_renderer.prefer_laravel_views => true`.

**`ValidationProblemRenderer` (2.2):** întoarce `null` (→ Laravel: redirect cu erori în sesiune) dacă `! HandlerSlots::shouldRenderJson()`; onorează întâi `$e->response` (`withResponse()`, `FormRequest::failedValidation`); altfel `type: about:blank`, `title` = textul lui `$e->status` (implicit 422 „Unprocessable Content"), `detail: $e->getMessage()`, `code: VALIDATION_FAILED`, `error_id`, `errors: $e->errors()` — structura Laravel, ca extensie RFC. Înregistrat prin `$exceptions->render(fn (ValidationException …))` — `renderViaCallbacks` alege **prima** închidere înregistrată al cărei tip se potrivește, deci e înregistrat **înaintea** callback-ului generic al pachetului.

**Teste WP-04:** `MessageResolver` în matricea `mode × debug × tip excepție` (inclusiv wrapper → mesaj public, `QueryException` → generic cu status text, `getCode()` 404 → „Not Found", nu „Server Error"); Problem Details câmpuri exacte, `about:blank` fără `#[ErrorCode]`, `Content-Type`; class-string invokable apelat, neinvokabil → boot; Filament GET → `null`, cu `X-Livewire` → JSON; Inertia `page`/`flash`/`props` (boot). **Teste existente de rescris:** `RenderersTest:27`, `ThirdPartyRenderersTest:18-26, 23, 25, 50-60, 58, 72, 84` — ca perechi debug on/off.

---

### WP-05 · Detectori

**Fișiere:** `src/Detectors/ApiDetector.php`, `src/Detectors/FilamentDetector.php` (rescrise). **Închide:** F-16 (Filament prinde orice request), F-17 (`api/*` hardcodat, `wantsJson` vs `expectsJson`), I-03d (o singură decizie JSON).

```php
<?php
// file: src/Detectors/ApiDetector.php  (2.0; în 2.2 delegă la HandlerSlots)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Detectors;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Throwable;

/** O singură regulă „e API?", folosită și de shouldRenderJsonWhen() — altfel Laravel și pachetul decid diferit. */
final class ApiDetector implements ContextDetectorInterface
{
    public function detect(Throwable $e, Request $request): bool
    {
        return app(HandlerSlots::class)->shouldRenderJson($request, $e);
    }
}
```

```php
<?php
// file: src/Detectors/FilamentDetector.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Detectors;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Isaidgitmenow\LaravelErrors\Contracts\InteractiveContextDetector;
use Throwable;

/**
 * F-16: în Filament v3 panoul default e „current" pe ORICE request (⚠ de verificat în workbench pe versiunea ta),
 * deci getCurrentPanel() !== null nu spune nimic. Verificăm prefixul panoului pe request.
 */
final class FilamentDetector implements ContextDetectorInterface, InteractiveContextDetector
{
    public function detect(Throwable $e, Request $request): bool
    {
        if (! class_exists(\Filament\Facades\Filament::class)) {
            return false;
        }

        try {
            $panel = \Filament\Facades\Filament::getCurrentPanel();
            if ($panel === null) {
                return false;
            }

            $path = trim((string) $panel->getPath(), '/');

            return $path === ''
                ? $request->hasHeader('X-Livewire')
                : $request->is($path, $path . '/*');
        } catch (Throwable) {
            return false;
        }
    }
}
```

`FilamentDetector` devine `InteractiveContextDetector` (nu era) — altfel `shouldYieldToIgnition()` cedează Ignition-ului pe request-uri Livewire din panou în debug.

**Ordinea în config (2.0):** `ApiDetector` **primul** — un request care cere JSON e API oriunde s-ar afla. Apoi `LivewireDetector`, `FilamentDetector`, `InertiaDetector`, `WebDetector`. Notă: cu Livewire înaintea lui Filament, request-urile Livewire din panou ajung la `LivewireRenderer` (fără `Notification`); dacă vrei notificări, inversează cele două — ambele sunt corecte cu detectorul reparat.

**Teste:** `/api/x` cu `Accept: application/json` într-o aplicație cu panou default → `ApiRenderer`, nu `FilamentRenderer` (**regresia F-16**; cere fake-ul Filament realist din WP-12); `/` cu panou pe `/admin` → nu e Filament; `LaravelErrors::renderJsonWhen()` al aplicației influențează și `ApiDetector`.

---

### WP-06 · Reporteri și integrări

**Fișiere:** `src/Reporters/LogReporter.php`, `src/Reporters/RateLimitedReporter.php` (rescrise), `src/Reporters/AuditReporter.php`, `src/Contracts/AuditSink.php`, `src/Audit/{AuditRecord,DatabaseAuditSink,LogChannelAuditSink}.php`, `src/Integrations/Sentry/AttributesEventProcessor.php`, `src/Integrations/Flare/AttributesContextProvider.php` [noi].

**Închide:** F-12 (canal inexistent — degradează pe emergency logger; avertizăm), F-25 (fără stack trace; chei suprascrise), F-27 (cache picat oprește pipeline-ul), I-07, I-12b, I-17.

```php
<?php
// file: src/Reporters/LogReporter.php  — corectat în 2.0 și, tot în 2.0, scos din `reporters` default (Laravel loghează o dată, cu stack trace). Rămâne pentru #[ReportTo].

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Reporters;

use Illuminate\Support\Facades\Log;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Throwable;

final class LogReporter implements ErrorReporterInterface
{
    public function __construct(private readonly array $config = []) {}

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function report(Throwable $e): bool
    {
        $level   = ExceptionInspector::logLevel($e);
        $message = ExceptionInspector::origin($e)->getMessage();
        $context = [
            'exception'     => ExceptionInspector::origin($e),      // F-25: Throwable → Monolog randează stack trace-ul (originalul, nu wrapper-ul)
            'error_id'      => ErrorIdentity::for($e),
            'error_code'    => ExceptionInspector::errorCode($e),
            'error_context' => ExceptionInspector::sanitizedContext($e),   // sub cheie proprie: nu suprascrie meta
        ];

        $channels = ExceptionInspector::reportToChannels($e);

        if ($channels === null) {
            Log::log($level, $message, $context);

            return true;
        }

        $configured = array_keys((array) config('logging.channels', []));

        foreach ($channels as $channel) {
            if (! in_array($channel, $configured, true)) {
                // F-12: LogManager ar degrada TĂCUT pe emergency logger. Facem zgomot pe canalul default.
                Log::log($level, $message, $context + ['laravel_errors_warning' => "Unknown log channel [{$channel}] in #[ReportTo]"]);
                continue;
            }
            Log::channel($channel)->log($level, $message, $context);
        }

        return true;
    }
}
```

```php
<?php
// file: src/Reporters/RateLimitedReporter.php  (2.0 fail-open; 2.2 RateLimitKey)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Reporters;

use Illuminate\Support\Facades\Cache;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\RateLimitKey;
use Throwable;

/**
 * Rămâne alături de throttle() nativ pentru că e singurul strat cu granularitate PER REPORTER
 * (BypassesRateLimiting pentru Debugbar/Xdebug; AuditReporter nu se limitează niciodată).
 */
final class RateLimitedReporter implements ErrorReporterInterface
{
    public function __construct(private readonly ErrorReporterInterface $inner) {}

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function report(Throwable $e): bool
    {
        $limit = ExceptionInspector::rateLimit($e);
        if ($limit === null) {
            return $this->inner->report($e);
        }

        try {
            $key = RateLimitKey::for($e, $limit->by, $this->inner::class);
            $ttl = now()->addMinutes($limit->intervalInMinutes);

            if (Cache::add($key, 1, $ttl)) {
                return $this->inner->report($e);
            }
            if ((int) Cache::increment($key) > $limit->max) {
                return true;   // suprimat, fără a opri pipeline-ul
            }
        } catch (Throwable) {
            // F-27: cache indisponibil → nu putem limita → NU suprimăm. Fail-open.
        }

        return $this->inner->report($e);
    }
}
```

```php
<?php
// file: src/Integrations/Sentry/AttributesEventProcessor.php  (2.2)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Integrations\Sentry;

use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;

/**
 * NU capturăm: sentry-laravel capturează deja prin Integration::handles(). Un al doilea captureException
 * ar dubla evenimentele în două issue-uri. Decorăm ce capturează Sentry — inclusiv report()-urile manuale.
 * Înregistrat cu Scope::addGlobalEventProcessor() (supraviețuiește resetărilor de scope din Octane/queue).
 */
final class AttributesEventProcessor
{
    public function __invoke(\Sentry\Event $event, ?\Sentry\EventHint $hint): \Sentry\Event
    {
        $e = $hint?->exception;
        if (! $e instanceof \Throwable) {
            return $event;
        }

        $origin = ExceptionInspector::origin($e);       // după mapare, $e e wrapper-ul

        $event->setTag('error_id', ErrorIdentity::for($e));
        $event->setTag('exception_class', $origin::class);   // titlul issue-ului va fi al wrapper-ului; tag-ul păstrează clasa reală

        if (($code = ExceptionInspector::errorCode($e)) !== null) {
            $event->setTag('error_code', $code);
            $event->setFingerprint([$code]);
        }

        $event->setContext('error', ExceptionInspector::sanitizedContext($e));

        // Nivelul doar când e declarat/derivabil din atribute — altfel am suprascrie scope-ul setat de aplicație.
        $attrs = ExceptionInspector::attributes($origin);
        if (isset($attrs['log_as']) || isset($attrs['http_code'])) {
            $event->setLevel(self::severity(ExceptionInspector::logLevel($e)));
        }

        return $event;
    }

    private static function severity(string $psr): \Sentry\Severity
    {
        return match ($psr) {
            'debug'                          => \Sentry\Severity::debug(),
            'info', 'notice'                 => \Sentry\Severity::info(),
            'warning'                        => \Sentry\Severity::warning(),
            'critical', 'alert', 'emergency' => \Sentry\Severity::fatal(),
            default                          => \Sentry\Severity::error(),
        };
    }
}
```

**`AuditReporter` (3.0):** `implements ErrorReporterInterface, ReportsIgnoredExceptions, BypassesRateLimiting`; `shouldReport` = `audit($e) !== null`; scrie `AuditRecord(errorId, code, class, category, userId, requestId, context mascat, occurredAt, retainUntil = now()->add(retention))` în `AuditSink`. Sink-uri: `DatabaseAuditSink` (tabelă `error_audit`, fără `updated_at`, indexuri `error_id`, `user_id`, `occurred_at`, `category`) și `LogChannelAuditSink`. `errors:audit:prune`. **Interacțiune cu `#[DontReport]`:** WP-02 exclude clasele cu `audit` din suprimarea nativă → pipeline-ul rulează doar reporterii `ReportsIgnoredExceptions` → `report()` întoarce `false`.

**Flare:** `AttributesContextProvider` — `Flare::context('error', …)` + `Flare::group('error_code', …)`; 60 de linii, același model.

**Teste WP-06:** `LogReporter` → `context['exception'] instanceof Throwable`, `#[WithContext(['exception'])]` ajunge în `error_context.exception`; canal inexistent → linie pe default cu `laravel_errors_warning`; `RateLimitedReporter` cu `Cache::shouldReceive('add')->andThrow()` → `inner->report()` apelat; Sentry real: **un** eveniment, tag-uri, fingerprint, `Severity`; `AuditReporter` cu `#[DontReport]` → rând scris, nimic în log.

---

### WP-07 · Sanitizare

**Fișiere:** `src/Support/DataSanitizer.php` (rescris), `src/Support/Masker.php` [nou]. **Închide:** F-08 (recursivitate nelimitată → fatal neprins), I-05 (mascare pe valoare).

```php
<?php
// file: src/Support/Masker.php  (2.1)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

final class Masker
{
    public const REDACTED = '[REDACTED]';

    public static function mask(mixed $value, string $mask): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return self::REDACTED;   // array/obiect marcat sensibil: nu încercăm să fim deștepți
        }

        $s = (string) $value;

        return match ($mask) {
            'last4'      => strlen($s) > 4 ? str_repeat('*', 4) . substr($s, -4) : str_repeat('*', strlen($s)),
            'first_last' => strlen($s) > 2 ? $s[0] . str_repeat('*', 3) . $s[-1] : str_repeat('*', strlen($s)),
            'email'      => self::email($s),
            // HMAC, nu sha256 simplu: IBAN/CNP/PAN au spații mici cu checksum → brute-force-abile cu un dicționar.
            'hash'       => 'hmac:' . substr(hash_hmac('sha256', $s, (string) config('app.key')), 0, 12),
            'length'     => '[REDACTED:' . strlen($s) . ']',
            default      => self::REDACTED,
        };
    }

    private static function email(string $s): string
    {
        $at = strrpos($s, '@');
        if ($at === false || $at === 0) {
            return self::REDACTED;
        }

        return $s[0] . '***' . substr($s, $at);
    }
}
```

```php
<?php
// file: src/Support/DataSanitizer.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

final class DataSanitizer
{
    private const MAX_DEPTH  = 8;
    private const MAX_NODES  = 2000;
    private const MAX_STRING = 8192;

    /** @param array<array-key, mixed> $data  @param string[] $sensitiveKeys */
    public static function sanitize(array $data, array $sensitiveKeys = []): array
    {
        $budget = self::MAX_NODES;

        return self::walk($data, $sensitiveKeys, 0, $budget);
    }

    private static function walk(array $data, array $keys, int $depth, int &$budget): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['__truncated' => '[max-depth-' . self::MAX_DEPTH . ']'];   // F-08: array auto-referențiat → fatal neprins
        }

        $out = [];
        foreach ($data as $k => $v) {
            if (--$budget <= 0) {
                $out['__truncated'] = '[max-nodes-' . self::MAX_NODES . ']';
                break;
            }

            $out[$k] = match (true) {
                self::isSensitive((string) $k, $keys)  => Masker::REDACTED,
                is_array($v)                          => self::walk($v, $keys, $depth + 1, $budget),
                $v instanceof \Closure                => '[Closure]',
                is_resource($v) || str_starts_with(gettype($v), 'resource') => '[resource]',
                is_object($v)                         => self::stringify($v),
                is_string($v) && strlen($v) > self::MAX_STRING => substr($v, 0, self::MAX_STRING) . '…[truncated]',
                default                               => $v,
            };
        }

        return $out;
    }

    private static function stringify(object $v): string
    {
        if (! $v instanceof \Stringable) {
            return '[' . $v::class . ']';
        }
        try {
            $s = (string) $v;   // __toString() poate arunca (model neîncărcat)
        } catch (\Throwable) {
            return '[' . $v::class . ']';
        }

        return strlen($s) > self::MAX_STRING ? substr($s, 0, self::MAX_STRING) . '…[truncated]' : $s;
    }

    private static function isSensitive(string $key, array $sensitiveKeys): bool
    {
        $key = strtolower($key);
        foreach ($sensitiveKeys as $s) {
            if (str_contains($key, strtolower((string) $s))) {
                return true;
            }
        }

        return false;
    }
}
```

**Teste:** array auto-referențiat → întoarce cu `[max-depth-8]` (F-08, pica fatal înainte); 5000 chei → `[max-nodes-2000]`; `Stringable` care aruncă → clasa; fiecare mască pe edge-cases; `hash` diferă între două `app.key`.

---

### WP-08 · Comenzi artisan

**Fișiere:** `src/Console/Concerns/ValidatesErrorInput.php` [nou], `src/Console/Concerns/BuildsErrorStubs.php` (rescris), `src/Console/Commands/{MakeExceptionCommand,MakeDddErrorCommand}.php` (rescrise), `src/Console/Commands/{CacheErrorsCommand,ClearErrorsCacheCommand,DoctorCommand,ListErrorsCommand}.php` [noi], `src/Support/{DetectorProbe,HandlerSlotInspector}.php` [noi].

**Închide:** F-03 (path traversal — **reprodus**: `name=../../../../tmp/pwned` → `/tmp/pwned.php`), F-04 (injecție PHP prin `--report` — **reprodus**), F-13 (`str_replace` pe namespace), F-14 (`--http=200` → dezactivare tăcută), I-08 (cache), I-13 (doctor).

```php
<?php
// file: src/Console/Concerns/ValidatesErrorInput.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Concerns;

/**
 * TOATĂ validarea rulează la începutul lui handle(), înainte de orice atingere a filesystem-ului.
 * (v1 al planului valida din buildStub(), DUPĂ ensureDirectoryExists — crea directoare și abia apoi refuza.)
 */
trait ValidatesErrorInput
{
    private const SEGMENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';
    private const LIST_ITEM = '/^[A-Za-z0-9_\-.]+$/';

    /** Acceptă Payments/PaymentFailed și Payments\PaymentFailed; refuză orice altceva (inclusiv `..`). */
    private function validatedClassName(string $raw): string
    {
        $name = trim(str_replace('/', '\\', trim($raw)), '\\');
        if (! preg_match(self::CLASS_NAME, $name)) {
            throw new \InvalidArgumentException("Invalid exception name [{$raw}]. Use letters, digits, underscores and \\ or / as namespace separator.");
        }

        return $name;
    }

    /** Un singur identificator: domeniul DDD și clasa DDD (stub-ul emite `class {{ class }}` — nu poate conține `\`). */
    private function validatedSegment(string $raw, string $label): string
    {
        $v = trim($raw);
        if (! preg_match(self::SEGMENT, $v)) {
            throw new \InvalidArgumentException("Invalid {$label} [{$raw}]. Use a single PHP identifier.");
        }

        return $v;
    }

    private function validatedHttp(mixed $raw): int
    {
        if (! is_numeric($raw)) {
            throw new \InvalidArgumentException("The --http option must be numeric, got [{$raw}].");
        }
        $http = (int) $raw;
        if ($http !== 500 && ($http < 400 || $http > 599)) {
            throw new \InvalidArgumentException("The --http option must be 400–599, got [{$http}]. (#[HttpCode] would throw at runtime and silently disable the package for this class.)");
        }

        return $http;
    }

    /** @return list<string> */
    private function validatedList(string $raw, string $label): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $items = array_values(array_filter(array_map('trim', explode(',', $raw))));
        foreach ($items as $item) {
            if (! preg_match(self::LIST_ITEM, $item)) {
                throw new \InvalidArgumentException("Invalid {$label} [{$item}]. Allowed: letters, digits, _ - .");
            }
        }

        return $items;
    }

    /** Normalizare lexicală (fără filesystem) + containment. Apelată ÎNAINTE de ensureDirectoryExists(). */
    private function assertWithinBase(string $target, string $base): void
    {
        $normalize = static function (string $p): string {
            $p = str_replace('\\', '/', $p);
            $drive = '';
            if (preg_match('/^([A-Za-z]:)(\/.*)?$/', $p, $m)) {
                $drive = $m[1];
                $p = $m[2] ?? '/';
            }
            $out = [];
            foreach (explode('/', $p) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    array_pop($out);
                    continue;
                }
                $out[] = $seg;
            }

            return $drive . '/' . implode('/', $out);
        };

        $b = rtrim($normalize(realpath($base) ?: $base), '/') . '/';
        if (! str_starts_with($normalize($target), $b)) {
            throw new \InvalidArgumentException('Refusing to write outside of the application directory.');
        }
    }
}
```

`BuildsErrorStubs::buildAttributes(int $http, array $channels, array $envs)` primește listele **validate** și emite cu `var_export($v, true)` — output-ul e identic cu string-ul așteptat de `MakeExceptionCommandTest` (`['slack', 'sentry'], environments: ['prod', 'staging']`), deci testul rămâne verde.

`MakeExceptionCommand::handle()`:

```php
public function handle(): int
{
    try {
        $name     = $this->validatedClassName((string) $this->argument('name'));
        $http     = $this->validatedHttp($this->option('http'));
        $channels = $this->validatedList((string) $this->option('report'), 'report channel');
        $envs     = $this->validatedList((string) $this->option('env'), 'environment');

        [$namespace, $class] = $this->resolveNamespaceAndClass($name);
        $target = $this->resolveTargetPath($namespace, $class);
        $this->assertWithinBase($target, app_path());
    } catch (\InvalidArgumentException $e) {
        $this->components->error($e->getMessage());

        return self::FAILURE;
    }

    if ($this->files->exists($target)) { /* … */ }

    $this->files->ensureDirectoryExists(dirname($target));    // ABIA ACUM atingem discul
    $this->files->put($target, $this->buildStub($namespace, $class, $http, $channels, $envs));
    // …
}

private function resolveTargetPath(string $namespace, string $class): string
{
    $base = rtrim($this->laravel->getNamespace(), '\\');
    // F-13: substr pe prefix, nu str_replace (App\Exceptions\AppPayments devenea Exceptions/Payments)
    $relative = ($namespace === $base || str_starts_with($namespace, $base . '\\')) ? substr($namespace, strlen($base)) : $namespace;

    return app_path(ltrim(str_replace('\\', DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $class . '.php');
}
```

`MakeDddErrorCommand`: `validatedSegment($domain, 'domain')`, `validatedSegment($class, 'class name')`, `assertWithinBase($target, base_path())`. Tool-ul MCP `generate_error` (WP-10) aplică aceleași validări înainte de `Artisan::call`.

**`errors:cache` / `errors:clear` (2.1):** `AttributeScanner::scan()` → `AttributeValidator` → dacă zero erori, scrie `bootstrap/cache/errors.php` cu `['version' => AttributeCache::VERSION, 'classes' => …]`; altfel exit 1 fără scriere.

**`errors:doctor` (2.1):** secțiuni Config (validare chei + valori, `CallableResolver` pe formatter/handlere, `inertia_mode` ∈ `page|flash|respond` + alias `redirect`), Handler slots (`HandlerSlotInspector` prin reflecție pe `Handler::$finalizeResponseCallback` / `$shouldRenderJsonWhenCallback` — avertizează dacă nu sunt ale pachetului), Detectors (`DetectorProbe`: request-uri sintetice — `GET /api/ping Accept: json`, `POST /livewire/update X-Livewire`, `GET /admin/x`, `GET /` — și ce detector le prinde), Attributes (`AttributeValidator`), Cache (mtime vs. fișiere), Integrations (versiuni + ce cale e activă). `exit 1` la orice `error`.

**Teste WP-08:** `make:error "../../../tmp/x"` → exit 1, **niciun director creat** (aserțiune `is_dir`); `ddd:error "Foo\\Bar" --domain=X` → exit 1; `--report="a')] class X{} //"` → exit 1, fără fișier; `--http=200|abc|999` → exit 1; `make:error "AppPayments/AppFailed"` → `app/Exceptions/AppPayments/AppFailed.php` cu namespace-ul corect (F-13); fiecare fișier generat trece `php -l` (aserțiune `toBeValidPhp`); `errors:cache` refuză să scrie cu erori; `errors:doctor` pică pe fiecare regulă.

---

### WP-09 · Livewire și Filament prin hook-ul `exception` (3.0)

**Fișiere:** `src/Integrations/Livewire/ExceptionHook.php` [nou], `src/ErrorsServiceProvider.php` (înregistrare în `register()`), `resources/js/laravel-errors.js` [nou — listener de referință]. **Închide:** I-09(a), I-14.

**De ce hook, nu renderer.** Livewire tratează orice non-2xx ca eșec de request → modal cu JSON brut. Iar în momentul în care rulează callback-ul de render al Laravel, `Livewire::current()` e deja `null` (`HandleComponents::update()` face pop în `finally`). Singurul punct în care putem influența răspunsul Livewire e hook-ul `exception`, pe care Livewire îl declanșează din `Wrapped::__call()` (acțiuni) **și** din `SupportLifecycleHooks::callHook()` (mount/hydrate/render…). `$stopPropagation()` are sens doar în acțiuni.

```php
<?php
// file: src/Integrations/Livewire/ExceptionHook.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Integrations\Livewire;

use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\MessageResolver;
use Livewire\ComponentHook;
use Throwable;

final class ExceptionHook extends ComponentHook
{
    private bool $inAction = false;

    /** Faza „acțiune": Livewire apelează call() înaintea metodei și închiderea returnată după (și după o excepție oprită). */
    public function call($method, $params, $returnEarly)
    {
        $this->inAction = true;

        return fn () => $this->inAction = false;
    }

    public function exception(Throwable $e, callable $stopPropagation): void
    {
        $inAction       = $this->inAction;
        $this->inAction = false;                     // reset independent de închiderea de după acțiune

        if (config('errors.livewire_mode', 'hook') !== 'hook') {
            return;
        }
        if (app(ErrorManagerInterface::class)->isPassThrough($e)) {
            return;                                  // ValidationException etc. → Livewire le tratează nativ
        }
        if (config('errors.respect_debug_mode', true) && app()->hasDebugModeEnabled()) {
            return;                                  // dezvoltatorul vrea Ignition în modal
        }
        if (! $inAction) {
            return;                                  // mount/hydrate/render: lăsăm să propage → Handler raportează + fallback JSON
        }

        report($e);                                  // DOAR aici oprim propagarea → nu mai ajunge la Handler → raportăm explicit

        $status  = ExceptionInspector::httpCode($e);
        $message = MessageResolver::public($e, $status, (array) config('errors', []));

        $this->component->dispatch('laravel-errors:error',
            status:  $status,
            message: $message,
            errorId: ErrorIdentity::for($e),
            code:    ExceptionInspector::errorCode($e),
        );

        if ($this->isFilamentPanel()) {
            // dehydrate rulează normal → Filament emite notificationsSent → notificarea apare în acest round-trip
            \Filament\Notifications\Notification::make()->title($message)->danger()->send();
        }

        $stopPropagation();
    }

    private function isFilamentPanel(): bool
    {
        // Panoul e setat de middleware-ul panoului pe ruta livewire/update scoped — acoperă pagini, widget-uri, relation managers.
        return class_exists(\Filament\Facades\Filament::class)
            && \Filament\Facades\Filament::getCurrentPanel() !== null;
    }
}
```

**Înregistrare — din `register()`, nu `boot()`.** `ComponentHookRegistry::boot()` inițializează hook-urile prezente la momentul `boot()`-ului Livewire; un hook adăugat din `packageBooted()` poate ajunge după și nu mai fi inițializat. **⚠ de verificat în workbench:** că hook-ul se declanșează pentru o acțiune care aruncă — asta e testul de timing.

```php
// ErrorsServiceProvider::register()
if (class_exists(\Livewire\ComponentHookRegistry::class)) {
    \Livewire\ComponentHookRegistry::register(\Isaidgitmenow\LaravelErrors\Integrations\Livewire\ExceptionHook::class);
}
```

**Limitări, documentate:** excepțiile din `mount()`/`hydrate()`/`render()` sunt raportate dar nu interceptate (fallback `LivewireRenderer` JSON). `report($e)` e obligatoriu în hook. `livewire_mode => 'json'` = comportamentul actual.

**Frontend de referință** (`resources/js/laravel-errors.js`, publicabil): `Livewire.on('laravel-errors:error', ({status, message, errorId, code}) => …)` → toast/banner cu buton „Copiază codul de referință".

**Teste** (Livewire + Filament reale): acțiune care aruncă `#[HttpCode(402)] #[TranslatedMessage]` → 200, `effects.dispatches` conține evenimentul cu mesajul tradus, `Log::assertLogged()`; `mount()` care aruncă → raportat, răspuns JSON de fallback; panou Filament → notificare în `effects`; `ValidationException` → netratată de noi; `livewire_mode => 'json'` → azi.

---

### WP-10 · MCP

**Fișiere (2.0):** `src/Mcp/McpLogReader.php`, `src/Mcp/McpLogger.php`, `src/Mcp/McpServer.php`, `src/Mcp/Handlers/ToolHandler.php`. **Fișiere (3.0):** pachet nou `isaidgitmenow/laravel-errors-mcp`.

**Închide (2.0):** F-09, F-10, F-11, F-15, F-19, F-20. **Închide (3.0):** I-10, §10.4.

**Corecții 2.0, punctual:**

- `McpLogReader::tail()`: `$limit = max(1, min($limit, 500))` (F-09 — `array_slice($x, -0)` întoarce tot).
- `McpLogger::log()`: `json_encode(..., JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)`, verificare `false` cu intrare minimă de rezervă, `mb_substr($message, 0, 4096)`, `chmod` la `config('errors.mcp.log_file_mode', 0664)` (nu `0640`: în Docker/Sail containerul scrie ca `www-data`, Claude Desktop citește ca utilizatorul host), `mkdir` la `0775`, redactarea argumentelor CLI `--*token|secret|password|key*=` (F-10).
- `ToolHandler::listExceptions()`: `usort` pe `class` înainte de `array_slice` (F-11). Framing-ul de securitate „autoload forțat" e eliminat — e tooling local; mitigarea reală e WP-08.
- `ToolHandler::getGlobalContext()`: `DataSanitizer::sanitize(Context::all())` + `Context::allHidden()` (F-15).
- `ToolHandler::generateError()`: validează `name` cu același regex ca WP-08 (`^[A-Za-z_][A-Za-z0-9_]*(?:[\\/][A-Za-z_][A-Za-z0-9_]*)*$`) și `domain` ca segment.
- `ToolHandler::simulateError()`: allow-list de namespace-uri cu `''` (fără namespace — `RuntimeException`), `Illuminate\`, `Symfony\Component\HttpKernel\Exception\`, `App\`, domeniul DDD; **`ToolHandlerTest:92-111` rămâne verde**; mock-ul reporterilor implicit (`mock_reporters: true` în schema tool-ului), fără cache pe lock (F-20, F-22a).
- `McpServer`: detecția trunchierii cu `strlen($line) === READ_BUFFER` (nu `>= READ_BUFFER - 1` — `stream_get_line` întoarce exact `length` când trunchiază; verificat) și consumul restului liniei; erorile din **dispatch**-ul unei notificări valide se înghit (JSON-RPC 2.0 §5 cere răspuns cu `id: null` pentru parse error / invalid request — acelea rămân); `array_key_exists('id', $payload)` distinge `id: null` de absent (F-19).

**3.0 — pachet separat.** `ErrorManager` nu mai știe de MCP: `bypassConsoleExceptions` devine un listener pe `ExceptionReported` care ignoră; `McpLogger` ascultă `ExceptionReported` (are deja `sanitizedContext`, `errorId`); lock-file-ul dispare (`simulate_error` construiește un `ErrorManager` cu `reporters => [RecordingReporter::class]` prin container). Tool-uri/resurse noi: `explain_error(error_id)` (JSONL + `storage/logs/laravel*.log`, clasa, atributele din `AttributeCache`, trace filtrat), `errors://recent` (resursă), `errors://codes`, `list_exceptions` din cache fără autoload.

**Teste:** `tail(0|-5|10000)`; mesaj cu `"\xB1\x31"` → JSON valid; permisiuni `0664`; paginare consistentă; `simulate_error(RuntimeException::class)` verde, `\Vendor\Foo` refuzat; linie de 1 MB → eroare + stream resincronizat; notificare cu metodă necunoscută → fără output.

---

### WP-11 · Service provider și config

**Fișiere:** `src/ErrorsServiceProvider.php` (rescris), `config/errors.php` (§4), `composer.json`, `src/Exceptions/InvalidConfigurationException.php` [nou].

```php
<?php
// file: src/ErrorsServiceProvider.php  — stare finală 3.0

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Isaidgitmenow\LaravelErrors\Console\Commands\CacheErrorsCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\ClearErrorsCacheCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\DoctorCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\ListErrorsCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\MakeDddErrorCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\MakeExceptionCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\PruneAuditCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\UpgradeConfigCommand;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\Exceptions\InvalidConfigurationException;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;
use Isaidgitmenow\LaravelErrors\Support\AttributedExceptionMapper;
use Isaidgitmenow\LaravelErrors\Support\CallableResolver;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ErrorsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-errors')
            ->hasConfigFile('errors')
            ->hasViews()
            ->hasTranslations()
            ->hasCommands(
                MakeExceptionCommand::class,
                MakeDddErrorCommand::class,
                CacheErrorsCommand::class,
                ClearErrorsCacheCommand::class,
                DoctorCommand::class,
                ListErrorsCommand::class,
                PruneAuditCommand::class,      // 3.0
                UpgradeConfigCommand::class,   // 3.0
            );
    }

    public function register(): void
    {
        parent::register();

        // WP-09: hook-ul Livewire trebuie să existe ÎNAINTE ca Livewire să-și booteze registry-ul.
        // Registry-ul e static: în teste, register() rulează per instanță de aplicație → guard static contra dublării.
        static $hookRegistered = false;
        if (! $hookRegistered && class_exists(\Livewire\ComponentHookRegistry::class)) {
            \Livewire\ComponentHookRegistry::register(Integrations\Livewire\ExceptionHook::class);
            $hookRegistered = true;
        }
    }

    public function packageRegistered(): void
    {
        $config = fn ($app): array => (array) $app['config']->get('errors', []);

        $this->app->singleton(ErrorManagerInterface::class, fn ($app) => new ErrorManager(
            config: $app[Repository::class],
            events: $app[Dispatcher::class],
        ));
        $this->app->alias(ErrorManagerInterface::class, ErrorManager::class);

        $this->app->singleton(AttributeCache::class, fn ($app) => new AttributeCache(
            app:     $app,
            reader:  $app->make(Support\AttributeReader::class),
            scanner: $app->make(Support\AttributeScanner::class),
            store:   $app['cache']->store(),
            config:  $config($app),
        ));
        $this->app->singleton(HandlerSlots::class, fn ($app) => new HandlerSlots($app[Repository::class], $app[Dispatcher::class]));
        $this->app->singleton(AttributedExceptionMapper::class, fn ($app) => new AttributedExceptionMapper($app[Repository::class]));

        // bind(), nu singleton(): se re-rezolvă la fiecare utilizare, deci config-ul e citit proaspăt (F-21) fără Repository.
        foreach ([
            Renderers\ApiRenderer::class, Renderers\LivewireRenderer::class, Renderers\FilamentRenderer::class,
            Renderers\InertiaRenderer::class, Renderers\WebRenderer::class, Renderers\ValidationProblemRenderer::class,
            Reporters\LogReporter::class, Reporters\DebugbarReporter::class, Reporters\XdebugReporter::class,
            Support\ProblemDetailsFormatter::class,
        ] as $class) {
            $this->app->bind($class, fn ($app) => new $class($config($app)));
        }
    }

    public function packageBooted(): void
    {
        $this->validateConfiguration();

        // Încălzire: o eroare de cache (lipsă/stale în producție) trebuie să iasă AICI, nu în afterResolving(Handler),
        // unde ar înlocui excepția reală pe care Laravel o tratează.
        if (in_array(true, (array) $this->app['config']->get('errors.integrate_with_laravel', []), true)) {
            $this->app->make(AttributeCache::class)->all();
        }

        $this->optimizes(optimize: 'errors:cache', clear: 'errors:clear', key: 'errors');   // illuminate/support ≥ 11.27, cerut de composer.json

        $events = $this->app->make(Dispatcher::class);

        // Golire per unitate de lucru. WeakMap-urile se golesc singure, dar cache-ul runtime al claselor vendor
        // și wrapper-ele memoizate merită golite pe granițe de request/job.
        $flush = static function (): void {
            ExceptionInspector::flushCache();
            ErrorIdentity::flush();
            app(AttributedExceptionMapper::class)->flush();
        };
        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            $events->listen(\Laravel\Octane\Events\RequestTerminated::class, $flush);
        }
        $events->listen([JobProcessed::class, JobExceptionOccurred::class], $flush);   // Worker nu emite JobProcessed când job-ul aruncă

        if (class_exists(\Sentry\SentrySdk::class) && $this->app->bound(\Sentry\State\HubInterface::class)) {
            \Sentry\State\Scope::addGlobalEventProcessor($this->app->make(Integrations\Sentry\AttributesEventProcessor::class));
        }
    }

    /** §1.2 regula 3: erorile de configurare explodează la boot, nu tac în producție. */
    private function validateConfiguration(): void
    {
        $c = (array) $this->app['config']->get('errors', []);

        $enum = static function (string $key, mixed $value, array $allowed): void {
            if (! in_array($value, $allowed, true)) {
                throw new InvalidConfigurationException(
                    "errors.{$key} must be one of [" . implode(', ', $allowed) . "], got [" . var_export($value, true) . '].'
                );
            }
        };

        if (($c['inertia_mode'] ?? 'page') === 'props') {
            throw new InvalidConfigurationException(
                "errors.inertia_mode 'props' was removed in 2.0: Inertia::share() does not survive a redirect, so the error was silently lost. Use 'page', 'flash' or 'respond'."
            );
        }
        $enum('inertia_mode', $c['inertia_mode'] ?? 'page', ['page', 'flash', 'redirect', 'respond']);
        $enum('expose_messages', $c['expose_messages'] ?? 'attributed', ['attributed', 'always', 'never']);
        $enum('livewire_mode', $c['livewire_mode'] ?? 'hook', ['hook', 'json']);

        foreach (['json_formatter', 'livewire_handler', 'filament_handler'] as $key) {
            CallableResolver::resolve($c[$key] ?? null, $key);   // aruncă dacă e class-string neinvokabil
        }

        foreach ((array) ($c['contexts'] ?? []) as $detector => $renderer) {
            if (! class_exists($detector) || ! class_exists($renderer)) {
                throw new InvalidConfigurationException("errors.contexts: [{$detector} => {$renderer}] references a missing class.");
            }
        }
        foreach ((array) ($c['reporters'] ?? []) as $reporter) {
            if (! class_exists($reporter)) {
                throw new InvalidConfigurationException("errors.reporters: [{$reporter}] does not exist.");
            }
        }
    }
}
```

`composer.json`: `"illuminate/support": "^11.27|^12.0|^13.0"` (aici e `optimizes()`), `"illuminate/contracts": "^11.27|^12.0|^13.0"`, `suggest`: `livewire/livewire ^3.0`, `filament/filament ^3.0`, `inertiajs/inertia-laravel ^1.0|^2.0`, `sentry/sentry-laravel ^4.4`, `spatie/laravel-flare`. CI: eliminarea `policy.advisories.block false` **după** `composer audit` și rezolvarea advisory-ului existent (F-23).

---

### WP-12 · Teste, fake-uri, CI

**Fișiere:** `tests/Pest.php` (fake-uri realiste), `tests/Regression/*` [noi], `tests/Feature/Queue/*`, `tests/Feature/Octane/*` [noi], `src/Testing/ErrorManagerFake.php`, `src/Testing/Concerns/InteractsWithErrors.php` [noi], `.github/workflows/run-tests.yml`, `workbench/` [nou].

**Fake-urile din `tests/Pest.php` — de ce sunt problema #1.** Toate testele „Filament/Inertia/Livewire/Debugbar" rulează contra unor clase-schelet definite manual. Fake-ul `Filament::getCurrentPanel()` întoarce `null` → F-16 invizibil; fake-ul `Inertia::share()` reține props-urile static → F-07 invizibil (testul aserta bug-ul). **2.0:** fake-urile devin realiste (panou cu `getPath()` implicit; `Notification::send()` cere sesiune; `Inertia::share()` per request). **2.0, în paralel:** job CI cu pachetele **reale** (`composer require livewire/livewire filament/filament inertiajs/inertia-laravel sentry/sentry-laravel --dev` în matrice separată; `if (!class_exists)` din `Pest.php` dezactivează fake-urile automat).

**`LaravelErrors::fake()` (2.2):** `ErrorManagerFake implements ErrorManagerInterface` — **înregistrează** tot, **nu raportează**, **randează** (forțând `respect_debug_mode => false`, altfel `shouldYieldToIgnition()` întoarce `null` sub `APP_DEBUG=true`). Instalat cu `LaravelErrors::swap($fake)`. Aserțiuni: `assertReported(class, ?callback)`, `assertNotReported`, `assertReportedTimes`, `assertNothingReported`, `assertRendered(class, ?status, ?renderer)`, `assertNotRendered`, `assertPassedThrough`, `assertContextReported(class, keys)`, `assertContextSanitized(class, key)`, `assertRateLimited`, `assertAudited(class, ?category)` (3.0). Mesajele de eșec listează ce **a fost** raportat.

**Matricea CI:** PHP 8.4/8.5 × Laravel **11.27** (minimul, nu `^11`) / 12 / 13 × {fără integrări, cu integrări reale}; job `APP_DEBUG=false`; job `CACHE_STORE=file`; job `queue:work --once`; `composer audit` blocant; PHPStan la 6 după 2.0, 8 după 3.0.

**Teste de regresie obligatorii:** Anexă C. **Teste existente care aserțează bug-uri și se rescriu conștient:** `RenderersTest:27` (F-02), `ThirdPartyRenderersTest:18-26` (F-16), `:23,25,58,72,84` (F-02), `:50-60` (F-07), `ExceptionInspectorTest` pe `getCode()` (F-26 — cu flag). **Rămân verzi deliberat:** `ToolHandlerTest:92-111`, `ErrorManagerTest`/`MissingFeaturesTest`/`CoreIntegrationsTest` (`new ErrorManager(config: [...])`), `MakeExceptionCommandTest` (string-ul `var_export`).

---

## 3. Secvențiere și dependențe

```
2.0  REMEDIERE (blocantă)
     WP-01 ExceptionInspector WeakMap + F-26/F-28/F-29 (fără AttributeCache încă — reflecție directă cache-uită pe FQCN)
     WP-03 ErrorManager: contract report(), isPassThrough F-06, CriticalLog, config union
     WP-04 MessageResolver, CallableResolver, rendere (F-02, F-07, F-16, F-24)
     WP-05 detectori (F-16, F-17) + ordinea contexts
     WP-06 LogReporter F-25/F-12, RateLimitedReporter F-27
     WP-07 DataSanitizer F-08
     WP-08 validare comenzi (F-03, F-04, F-13, F-14)
     WP-10 fixuri MCP (F-09, F-10, F-11, F-15, F-19, F-20)
     WP-11 validare la boot, flush queue, composer ^11.27, CI advisory
     WP-12 fake-uri realiste, job cu pachete reale, teste de regresie
     ErrorHandler: report() întoarce bool (true / false DOAR pe #[DontReport]). LogReporter IESE din `reporters` default încă din 2.0:
                   linia Laravel are stack trace (a lui nu avea, F-25), deci o singură linie fără să scurtcircuităm Sentry.
                   Cine folosește #[ReportTo] îl adaugă explicit înapoi (documentat în UPGRADE). Nivelul e `error` până la level() din 2.2.
       ↓
2.1  FUNDAȚIE (aditiv)             WP-01 AttributeReader/Scanner/Validator/Cache · WP-02 ErrorIdentity, HandlerSlots (respond + json)
                                   WP-07 Masker + #[Sensitive] · WP-01 TranslatedMessage params · WP-08 errors:cache/clear/doctor
                                   WP-03 Events + MetricsListener · WP-12 teste queue/Octane
       ↓
2.2  COMPUNERE (un breaking declarat: Context)
                                   WP-01 #[ErrorCode], #[LogAs], RateLimit(by) · WP-04 Problem Details + 422 · WP-12 fake()
                                   WP-02 familie wrapper + mapper + ErrorHandler complet (flags OFF implicit) + level()/context()/throttle()
                                   WP-06 Sentry processor, Flare, RateLimitKey
                                   WP-03 Context::push('errors') vizibil                                    ← BREAKING (cheie + vizibilitate)
       ↓
3.0  DEFAULT-URI                   WP-02 flags ON · WP-01 #[RetryAfter], #[Audit], RateLimit by:'class' · WP-09 hook Livewire/Filament
                                   WP-04 WebRenderer, Inertia 'respond' · WP-06 AuditReporter + sink-uri · WP-10 pachet MCP separat
```

**Dependențe dure:** AttributeCache (2.1) → orice `map()/dontReport()/level()` pe clase (2.2). `#[Sensitive]` (2.1) → context vizibil (2.2). `#[ErrorCode]` (2.2) → `by:'code'`, 422 unificat, `errors://codes`. Familia de wrapper-e (2.2) → `#[RetryAfter]` (3.0). Evenimente (2.1) → MCP separat, Audit (3.0). Fake-uri realiste (2.0) → regresia F-16 testabilă.

---

## 4. Configurația finală (`config/errors.php`, 3.0)

```php
<?php
// file: config/errors.php

declare(strict_types=1);

use Isaidgitmenow\LaravelErrors\Detectors\{ApiDetector, FilamentDetector, InertiaDetector, LivewireDetector, WebDetector};
use Isaidgitmenow\LaravelErrors\Renderers\{ApiRenderer, FilamentRenderer, InertiaRenderer, LivewireRenderer, WebRenderer};
use Isaidgitmenow\LaravelErrors\Reporters\{DebugbarReporter, XdebugReporter};

return [

    // Compunerea cu Handler-ul Laravel (WP-02). 3.0: toate true. 2.2: false (opt-in).
    'integrate_with_laravel' => [
        'map_http_code' => true,   // #[HttpCode]/#[TranslatedMessage]/#[ErrorCode] → wrapper HttpExceptionInterface, per clasă
        'dont_report'   => true,   // #[DontReport] → ShouldntReport (mapate) / dontReport() (nemapate); #[Audit] exclus
        'json_decision' => true,   // shouldRenderJsonWhen() din api_prefixes + LaravelErrors::renderJsonWhen()
    ],

    'respect_debug_mode' => true,
    'enrich_xdebug'      => true,
    'native_throttle'    => true,          // $exceptions->throttle() — DOAR în producție (global, oprește toți reporterii)

    // Ce ajunge la client
    'expose_messages'         => 'attributed',   // attributed | always (NU în producție) | never
    'fallback_message_prefix' => 'errors.http',  // errors.http.500, errors.http.404 … cu :error_id
    'default_status'          => 500,            // clase mapate fără #[HttpCode]
    'http_code_from_exception_code' => false,    // getCode() ca status: opt-in, doar pe excepția aruncată

    'error_id' => ['enabled' => true, 'header' => 'X-Error-Id', 'payload_key' => 'error_id', 'in_message' => true],

    'problem_details' => [
        'enabled'          => false,                      // decizie deschisă pentru default în 3.0
        'type_base_url'    => env('APP_URL') . '/errors',
        'expose_context'   => false,
        'legacy_message'   => true,
        'unify_validation' => false,                      // 422 → problem+json cu `errors`
    ],

    // Pipeline
    'contexts' => [
        ApiDetector::class      => ApiRenderer::class,      // primul: cine cere JSON e API oriunde
        LivewireDetector::class => LivewireRenderer::class,
        FilamentDetector::class => FilamentRenderer::class,  // inversează cu Livewire dacă vrei Notification pe fallback
        InertiaDetector::class  => InertiaRenderer::class,
        WebDetector::class      => WebRenderer::class,
    ],
    'api_prefixes' => ['api'],

    // Ocolește reporterii ȘI renderele/hook-urile pachetului. Decis pe excepția aruncată (F-06).
    'pass_through' => [
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,   // wrapper-ul nostru NU e HttpException
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Http\Exceptions\HttpResponseException::class,
    ],
    'pass_through_chain_wrappers' => [\Illuminate\View\ViewException::class],

    // Reporteri proprii. Laravel loghează singur (level() + context()); LogReporter e disponibil, deprecat.
    'reporters' => [
        XdebugReporter::class,
        DebugbarReporter::class,
        // \Isaidgitmenow\LaravelErrors\Reporters\AuditReporter::class,   // 3.0, opt-in
    ],

    // Potrivire pe substring, deci fără token-uri scurte: 'pan' ar redacta company_id, participant, expand_*.
    'sanitize' => ['password', 'password_confirmation', 'current_password', 'api_key', 'api_token', 'token', 'secret',
                   'authorization', 'credit_card', 'card_number', 'cvv', 'iban'],

    'json_formatter'   => null,   // Closure | class-string invokable | null
    'livewire_handler' => null,
    'filament_handler' => null,
    'livewire_mode'    => 'hook',  // hook | json
    'inertia_mode'     => 'page',  // page | flash | respond  ('redirect' alias pentru page; 'props' aruncă la boot)
    'inertia_error_component' => 'ErrorPage',

    'scan_paths' => [
        app_path('Exceptions'),
        base_path('src/Domain') . '/*/Exceptions',   // AttributeScanner adaugă singur config('ddd.domain_path') la runtime — nu depindem de ordinea încărcării config-urilor
    ],

    'audit' => ['sink' => 'database', 'table' => 'error_audit', 'log_channel' => 'audit'],

    // 2.x: aici. 3.0: se mută în config-ul pachetului laravel-errors-mcp. App\ și domeniul DDD se adaugă automat la runtime.
    'mcp' => ['log_file_mode' => 0664, 'log_dir_mode' => 0775,
              'simulate_namespaces' => ['', 'Illuminate\\', 'Symfony\\Component\\HttpKernel\\Exception\\']],
];
```

---

## 5. Compatibilitate, UPGRADE, „gata"

**Politică:** 2.0 = remediere (schimbă comportament acolo unde comportamentul era bug: mesaje mascate, `#[DontReport]` funcțional, **o singură linie de log** cu `LogReporter` scos din default, Inertia `props` aruncă la boot). 2.1 aditiv. 2.2 aditiv **cu o excepție declarată** (`Context` cheie nouă + vizibil). 3.0 default-uri + split MCP. `UPGRADE.md` per major, `errors:upgrade-config` care rescrie config-ul publicat.

| Comportament | 2.0 | 2.2 | 3.0 | Opt-out 3.0 |
|---|---|---|---|---|
| Mesaj brut la client | doar atribuite/debug | idem | idem | `expose_messages => 'always'` |
| `#[DontReport]` | oprește Laravel (report() false) | idem | nativ (`ShouldntReport`/`dontReport`) | `dont_report => false` |
| Linii de log per excepție | **1** (Laravel; `LogReporter` scos din default) | 1 (+ `level()`/`context()`) | 1 | `reporters += LogReporter` (2 linii) |
| Mapare `#[HttpCode]` → wrapper | — | opt-in | **on** | `map_http_code => false` |
| Decizia JSON | `ApiDetector` propriu | opt-in slot | **on** | `json_decision => false` |
| Livewire/Filament | JSON | JSON | **hook** | `livewire_mode => 'json'` |
| Inertia | `page` | `page` | `page` (+`respond`) | — |
| `RateLimit.by` | `location` | `location` | **`class`** | `by: 'location'` |
| MCP | în pachet | în pachet | **separat** | `composer require --dev …-mcp` |
| Callback-urile **aplicației** cheiate pe clasa originală (`render(fn (Foo $e))`, `report(fn (Foo $e))`, `level(Foo::class)`, `dontReport([Foo::class])`, Sentry `ignore_exceptions`) | se potrivesc | se potrivesc (flags off) | **nu se mai potrivesc** pentru clasele mapate | `map_http_code => false`, sau cheiază pe `AttributedHttpException::class` + `->original()` |

**Breaking neevident în 3.0 (C1).** Închiderile din config (`json_formatter`, `livewire_handler`, `filament_handler`) primesc **originalul** (pachetul dezpachetează înainte de apel), dar callback-urile înregistrate direct în `withExceptions()` primesc wrapper-ul. `errors:doctor` inspectează prin reflecție `Handler::$renderCallbacks / $reportCallbacks / $levels / $dontReport` și avertizează pentru fiecare tip care e o clasă mapată.

**Ordinea în `withExceptions()` (C2).** `renderViaCallbacks` = prima închidere înregistrată al cărei tip se potrivește. Callback-ul generic al pachetului (`Throwable`) ar umbri orice `render()` al aplicației înregistrat **după** `ErrorHandler::handle()`. Regula documentată: **aplicația își înregistrează `render()`-urile înainte de `ErrorHandler::handle()`**; pentru `report()`, ordinea nu contează în 3.0 (suprimarea e nativă), iar în 2.x `#[DontReport]` (`report() → false`) nu poate opri un `Integration::handles` înregistrat **înaintea** noastră — documentat ca limitare 2.x.

**Definiția lui „gata"** (per release): (1) atribut nou = validare + regulă `AttributeValidator` + intrare doctor + docs + exemplu în stub; (2) cheie de config nouă = validată la boot + documentată + în `upgrade-config`; (3) integrare third-party = job CI cu pachetul real; (4) comportament observabil = aserțiune în `fake()`; (5) `errors:doctor` verde pe `workbench/`; (6) README „claim → test"; (7) **fiecare compunere cu un mecanism nativ are un test că mecanismul aplicației continuă să funcționeze** (`map()`-ul aplicației, `Integration::handles` o singură dată **și când e după noi**, `ValidationException` la Livewire nativ, `LaravelErrors::respond()` al aplicației); (8) CI pe versiunea **minimă** din `composer.json`; (9) testele queue/Octane trec.

---

## Anexă A — Harta fișierelor

**Șterse:** `src/Reporters/LogReporter.php` din `reporters` default (**2.0**; fișierul rămâne pentru `#[ReportTo]`) · `src/Mcp/**` mutat în pachet separat (3.0).

**2.0 — modificate:** `ExceptionInspector.php` · `ErrorManager.php` · `ErrorHandler.php` · `Renderers/{Api,Livewire,Filament,Inertia}Renderer.php` · `Detectors/{Api,Filament}Detector.php` · `Reporters/{Log,RateLimited}Reporter.php` · `Support/DataSanitizer.php` · `Console/Commands/{MakeException,MakeDddError}Command.php` · `Console/Concerns/BuildsErrorStubs.php` · `Mcp/{McpLogReader,McpLogger,McpServer}.php` · `Mcp/Handlers/ToolHandler.php` · `ErrorsServiceProvider.php` · `config/errors.php` · `composer.json` · `.github/workflows/run-tests.yml` · `tests/Pest.php` · `tests/Feature/{Renderers,ThirdPartyRenderers}Test.php` · `tests/Unit/ExceptionInspectorTest.php`
**2.0 — noi:** `Support/MessageResolver.php` · `Support/CallableResolver.php` · `Support/CriticalLog.php` · `Console/Concerns/ValidatesErrorInput.php` · `Exceptions/InvalidConfigurationException.php` · `tests/Regression/*` (Anexă C)

**2.1 — noi:** `Support/{AttributeReader,AttributeScanner,AttributeValidator,AttributeCache}.php` · `Support/{ErrorIdentity,HandlerSlots,Masker}.php` · `Attributes/Sensitive.php` · `Events/{ExceptionReported,ExceptionRendered}.php` · `Listeners/MetricsListener.php` · `Console/Commands/{CacheErrors,ClearErrorsCache,Doctor}Command.php` · `Support/{DetectorProbe,HandlerSlotInspector}.php` · `tests/Feature/{Queue,Octane}/*` · `docs/{attributes,events}.md`
**2.1 — modificate:** `Attributes/{TranslatedMessage,WithContext}.php` · `ExceptionInspector.php` (prin cache) · `ErrorHandler.php` (slot respond) · `Facades/LaravelErrors.php`

**2.2 — noi:** `Attributes/{ErrorCode,LogAs}.php` · `Exceptions/AttributedHttpException.php` + 9 sub-clase · `Support/{AttributedExceptionMapper,RateLimitKey,ProblemDetailsFormatter}.php` · `Renderers/ValidationProblemRenderer.php` · `Console/Commands/ListErrorsCommand.php` · `Contracts/ErrorManagerInterface.php` · `Testing/ErrorManagerFake.php` · `Testing/Concerns/InteractsWithErrors.php` · `Integrations/Sentry/AttributesEventProcessor.php` · `Integrations/Flare/AttributesContextProvider.php` · `docs/{testing,integrations}.md`
**2.2 — modificate:** `Attributes/RateLimit.php` · `ErrorHandler.php` (complet) · `ErrorManager.php` (`Context::push`) · `Renderers/ApiRenderer.php` · `Reporters/RateLimitedReporter.php` · `config/errors.php`

**3.0 — noi:** `Attributes/{RetryAfter,Audit}.php` · `Integrations/Livewire/ExceptionHook.php` · `resources/views/error.blade.php` · `resources/js/{ErrorPage.vue,ErrorPage.tsx,laravel-errors.js}` · `Reporters/AuditReporter.php` · `Contracts/AuditSink.php` · `Audit/{AuditRecord,DatabaseAuditSink,LogChannelAuditSink}.php` · `database/migrations/…_create_error_audit_table.php` · `Console/Commands/{PruneAudit,UpgradeConfig}Command.php` · `UPGRADE.md` · `workbench/` · pachet `laravel-errors-mcp` (`Tools/ExplainErrorTool.php`, `Resources/{RecentErrors,ErrorCodes}Resource.php`)
**3.0 — modificate:** `config/errors.php` (default-uri) · `Renderers/{Web,Inertia}Renderer.php` · `ErrorsServiceProvider.php` (register hook) · `Attributes/RateLimit.php` (default `class`)

---

## Anexă B — Atributele (3.0)

| Atribut | Țintă | Parametri | Efect |
|---|---|---|---|
| `#[HttpCode(int)]` | clasă | 400–599 | status; wrapper `HttpExceptionInterface` prin `map()` |
| `#[ErrorCode(code, ?type, ?title)]` | clasă | SCREAMING_SNAKE 3–64 | `code` în răspuns, `type`/`title` Problem Details, fingerprint Sentry, cheie `by:'code'` |
| `#[DontReport]` | clasă | — | `SuppressedAttributedHttpException` (mapate) / `dontReport()` (nemapate); `report()` → `false` |
| `#[ReportTo(channels, environments)]` | clasă | canale `logging.channels` | rutare `LogReporter` (deprecat) |
| `#[LogAs(level)]` | clasă | PSR-3 | `level()` pe familie / pe clasă; `Severity` Sentry |
| `#[RetryAfter(seconds)]` | clasă | ≥ 1 | header prin wrapper (3.0) |
| `#[RateLimit(max, intervalInMinutes, by)]` | clasă | `class\|location\|message\|code` | `throttle()` nativ (producție) + `RateLimitedReporter` |
| `#[TranslatedMessage(key, params, choice)]` | clasă | proprietăți/metode | mesaj public; mapat chiar fără `#[HttpCode]` |
| `#[WithContext(properties, sensitive)]` | clasă, metodă | — | context, o singură extragere per obiect |
| `#[Sensitive(mask)]` | proprietate, parametru | `full\|last4\|first_last\|email\|hash\|length` | mascare la extragere; `hash` = HMAC |
| `#[Audit(retention, category)]` | clasă | interval, slug | `AuditReporter`; exclus din suprimarea nativă |

---

## Anexă C — Teste de regresie obligatorii (pică pe codul actual, trec după)

| Test | Ce dovedește |
|---|---|
| `OriginCacheIdentityTest` | F-01: excepție nouă după `unset()` nu primește originul alteia |
| `ProductionMessageLeakTest` | F-02: `QueryException`, `debug=false`, API → „Internal Server Error", nu SQL |
| `PathTraversalTest` | F-03: `make:error ../../x` → exit 1, niciun director creat |
| `StubInjectionTest` | F-04: `--report="a')]…"` → exit 1; fișierele generate trec `php -l` |
| `DontReportStopsLoggingTest` | F-05: `#[DontReport]` → `Log::assertNothingLogged()` |
| `PassThroughChainTest` | F-06: `Plain(previous: ModelNotFound)` → randat de noi; `ViewException(previous: NotFound)` → pass-through |
| `InertiaModeTest` | F-07: `page` randează cu status; `flash` pune în sesiune; `props` aruncă la boot |
| `SanitizerRecursionTest` | F-08: array auto-referențiat → întoarce cu marker |
| `FilamentHijacksApiTest` | F-16: `/api/x` + `Accept: json` cu panou default → `ApiRenderer` |
| `LogReporterStackTraceTest` | F-25: `context['exception'] instanceof Throwable` |
| `UpstreamStatusLeakTest` | F-26: `Domain(previous: Guzzle 401)` → 500 |
| `RateLimiterFailOpenTest` | F-27: `Cache::add` aruncă → raportat |
| `ContextOnceTest` | F-29: metoda `#[WithContext]` rulează o dată per excepție |
| `MappedExceptionStillReportedTest` | I-03: wrapper-ul nu e în `internalDontReport` — `Log::assertLogged` + `assertReported` |
| `AppMapStillRunsTest` | I-03: `map(Foo, Bar)` al aplicației după noi → aplicat |
| `LevelOnMappedClassTest` | I-11: `WARNING` pe linia Laravel pentru clasă mapată și nemapată |
| `SentryAfterUsCapturesOnceTest` | I-07/I-11: `Integration::handles` după `handle()` → exact un eveniment |
| `RespondSlotComposesTest` | I-01: `LaravelErrors::respond()` al aplicației rulează după `X-Error-Id` |
| `ErrorIdSameOnWrapperTest` | I-01: wrapper și original → același ID; report + render → același ID |
| `LivewireHookFiresTest` | I-09: acțiune care aruncă → 200 + `dispatches` (⚠ verifică timing-ul înregistrării) |

---

## Anexă D — Ce a fost eliminat din planurile anterioare și de ce

| Eliminat | Motiv |
|---|---|
| `AttributedHttpException extends Symfony\HttpException` | `HttpException` e în `internalDontReport` → raportarea s-ar fi oprit complet |
| `map(fn (Throwable $e) => …)` global | cheia `Throwable` e prima potrivire → bloca `map()`-urile aplicației |
| `stopIgnoring(AttributedHttpException::class)` | no-op: compară class-string-uri, nu `instanceof` |
| `Exceptions::dontReportWhen()` | există doar în 12.x; familia de wrapper-e îl face inutil |
| `SentryReporter` / `FlareReporter` care capturează | captură dublă față de `Integration::handles`; două issue-uri per eroare |
| `Scope::setLevel('warning')` cu string | cere `\Sentry\Severity` |
| `#[LogLevel]` | coliziune cu `Psr\Log\LogLevel` |
| `Livewire::current()->dispatch()` din renderer | e `null` la momentul randării (pop în `finally`) |
| Cache pe `file_exists(lock)` în `ErrorManager` | singleton în procesul MCP → stale → simulările raportau real |
| `chmod 0640` pe JSONL | Docker/Sail: containerul scrie ca alt UID, Claude Desktop nu mai putea citi |
| `method_exists($this, 'optimizes')` guard | înlocuit de `^11.27` în `composer.json` |
| `default_report_fallback` | înlocuit de contractul `report(): bool` (true / false doar pe `#[DontReport]`) |
| `Context::add('error_id')` | suprascrie la a doua excepție din același request → `push('errors')` |
| `#[Sensitive]` cu `TARGET_PROPERTY` singur | `newInstance()` prin `ReflectionParameter` aruncă pe proprietăți promovate |
| `hash` = sha256 simplu | brute-force-abil pe IBAN/CNP/PAN → HMAC cu `app.key` |
| „Masking-ul Laravel e gratuit după `map()`" | Laravel **nu** maschează `HttpExceptionInterface`; mapper-ul e punctul de masking |
| „`Retry-After` gratuit pe 429/503" | `HttpException` de bază nu setează header-e; vine din `#[RetryAfter]` |
| `errors: []` scos din forma legacy | breaking pentru clienții care fac `response.errors.length` |
| Regex „declaresClass" ca mitigare de securitate în `list_exceptions` | nu împiedica execuția; mitigarea reală e validarea din WP-08 |
| `report($e)` în hook înaintea verificării fazei | pentru mount/render excepția propaga la Handler → dublă raportare |
| `$this->original->report()` apelat direct în wrapper | Laravel apelează prin container; `report(Foo $f)` ar fi dat `ArgumentCountError` |
| `AttributeCache::load()` abia la prima excepție | în producție ar fi înlocuit excepția reală cu `InvalidConfigurationException` — încălzit la boot |
| `'pan'` în `sanitize` | substring: redacta `company_id`, `participant`, `expand_*` |
| `Sentry setLevel()` necondiționat | suprascria nivelul setat de aplicație pe capturile ei manuale |
