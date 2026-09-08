# laravel-errors — Analiză @ `121c1b6` (7 septembrie 2026)

**Față de analiza anterioară:** `92e9c42` → `121c1b6`, 10 commit-uri, 93 fișiere, +3.542 / −1.092 linii. Licența schimbată din GPL/proprietary în MIT. Practic, **implementarea planului-master**: 18 din cele 35 de fișiere din plan sunt copiate identic (până la whitespace), 17 diferă doar prin comentarii sau prin mici deviații enumerate mai jos. Restul (comenzi, audit, rendere, teste, config, docs) e scris de autor.

**Metodă:** clone proaspăt, `php -l` pe tot `src/` (curat), diff mecanic plan ↔ repo pe cele 35 de fișiere, citire integrală a fișierelor care nu vin din plan, o trecere adversarială independentă pe teste/docs/wiring, reproduceri PHP pentru afirmațiile noi. Fără `composer install` (Packagist blocat), deci suita nu a fost rulată — dar am verificat ce ar pica și ce e vacuu.

---

## 1. Verdict scurt

Arhitectura din plan e în cod și cele mai grave bug-uri din prima analiză sunt închise: F-01 (`WeakMap`), F-02 (`MessageResolver`), F-05/F-25 (o linie de log, cu stack trace), F-06, F-07 (`props` aruncă la boot), F-08, F-16 (detector pe prefix), F-24, F-26, F-27, F-28, F-29. Familia de wrapper-e, `HandlerSlots`, `AttributeCache`, `Sentry EventProcessor`, hook-ul Livewire — toate prezente, fidele planului.

Dar release-ul, așa cum e acum, **nu funcționează ca întreg**:

1. Comenzile `errors:cache` / `errors:clear` / `errors:doctor` / `errors:list` există ca fișiere, dar **nu sunt înregistrate** — `php artisan errors:cache` nu există, iar `optimizes('errors:cache')` cere o comandă inexistentă.
2. `scan_paths` e **gol** implicit → `AttributeCache` nu găsește nicio clasă → `map()`, `level()`, `dontReport()` nu fac nimic chiar cu flag-urile activate.
3. `HttpException` a **dispărut** din `pass_through` → 404/403/419/405 de framework sunt randate de pachet, iar `WebRenderer` le afișează ca `<h1>404</h1><p></p>` (mesaj gol, nescăpat), înlocuind paginile de eroare ale Laravel pentru **orice** aplicație fără view-uri publicate.
4. `ErrorManager::report()` a fost luat din versiunea planului **dinaintea** corecției din runda 2: `isPassThrough()`/`shouldNotReport()` sunt în afara `try` — o excepție din cache-ul de atribute sare peste tot pipeline-ul și înlocuiește excepția reală.
5. Auditul nu poate funcționa: `AuditReporter` cere `array $sinks` fără binding, `DatabaseAuditSink` inserează un array brut, nu există migrare.

Și cinci bug-uri **în codul din plan** pe care nu le văzusem până acum (§4) — trei dintre ele pot lua jos logarea Laravel a *oricărei* excepții.

---

## 2. Bug-uri noi introduse de implementare

Ordonate după impact. Fiecare cu fișier:linie, cauză, fix.

### 2.1 · Critic

**N-01 · Comenzile noi nu sunt înregistrate.** `src/ErrorsServiceProvider.php:38-42` — `hasCommands(MakeExceptionCommand, MakeDddErrorCommand, ErrorsMcpCommand)`. `CacheErrorsCommand`, `ClearErrorsCacheCommand`, `DoctorCommand`, `ListErrorsCommand` sunt importate (liniile 12-15) dar nu apar. Consecințe: `errors:cache` nu există → producția cu orice flag `integrate_with_laravel` activ aruncă `InvalidConfigurationException` la boot fără nicio cale de a scrie cache-ul; `optimizes(optimize: 'errors:cache', …)` (linia 101) face ca `php artisan optimize` să eșueze cu „command does not exist". **Fix:** adaugă cele patru clase în `hasCommands()`.

**N-02 · `HttpException` lipsește din `pass_through`.** `config/errors.php:180-187`. `Handler::prepareException()` convertește `ModelNotFoundException` → `NotFoundHttpException`, `AuthorizationException` → `AccessDeniedHttpException`, `TokenMismatchException` → `HttpException(419)` **înainte** de `renderViaCallbacks`; `isPassThrough()` verifică `instanceof` pe excepția aruncată (F-06, corect), deci intrările pentru ModelNotFound/Authorization/TokenMismatch sunt **moarte la randare** — ceea ce protejează e `HttpException::class`, care a fost scos. Rezultat: `abort(404)`, rutele 404/405, 419, `ThrottleRequestsException` → randate de `WebRenderer`, nu de Laravel. **Fix:** readaugă `\Symfony\Component\HttpKernel\Exception\HttpException::class` în `pass_through` (plan §4). Wrapper-ul pachetului nu extinde `HttpException`, deci nu e afectat.

**N-03 · `WebRenderer` înlocuiește paginile Laravel cu HTML brut și nescăpat.** `src/Renderers/WebRenderer.php:36-47`. Verifică doar `errors.{status}` (aplicație) și `laravel-errors::error` (**nu există** — nu e niciun `resources/views/`), apoi cade pe `"<h1>{$status}</h1><p>{$message}</p>"`. Două probleme: (a) paginile default `errors::{status}` ale Laravel (înregistrate de `Handler::registerErrorViewPaths()`) nu sunt consultate → orice aplicație fără view-uri publicate primește `<h1>500</h1>` în loc de pagina Laravel; combinat cu N-02, și 404-urile; (b) `$message` **nu e escapat** — `#[TranslatedMessage(params: [...])]` interpolează proprietăți din excepție, care pot conține input de utilizator → XSS reflectat. **Fix:** `e($message)`; ordinea `errors.{status}` → `errors::{status}` (după `registerErrorViewPaths`, vezi planul C5) → view-ul pachetului (de creat) → fallback minimal escapat; sau `return null` când nu există niciun view și lasă Laravel.

**N-04 · `report()` din versiunea nereparată a planului.** `src/ErrorManager.php:50-62`. `isPassThrough($e)` (→ `origin()` → `AttributeCache::for()` → `load()`) și `shouldNotReport($e)` rulează **în afara** `try`-ului. Un cache stale/absent în producție, un store de cache picat în non-producție (`rememberForever`) sau un atribut invalid pe o clasă necunoscută aruncă din `report()` → Laravel înlocuiește excepția reală cu a noastră, fără log, fără Sentry. Exact regula 3 din plan. **Fix:** versiunea din planul-master actual — `$suppress = false; $ran = []; try { if ($this->isPassThrough($e)) return true; $suppress = ExceptionInspector::shouldNotReport($e); … }`.

### 2.2 · Major

**N-05 · `scan_paths` gol implicit.** `config/errors.php:232-235` — ambele căi sunt comentate. `AttributeCache::classesWith()` întoarce `[]` → `map()`, `dontReport()`, `level()` pe clase nu se înregistrează; `errors:doctor` spune „No exception classes found". Feature-ul central e inert până când utilizatorul ghicește să decomenteze. **Fix:** `app_path('Exceptions')` activ implicit; `AttributeScanner::resolvePaths()` adaugă `config('ddd.domain_path')/*/Exceptions` la runtime (a fost scos din codul planului, `AttributeScanner.php:13-23`).

**N-06 · Auditul nu poate rula.** `src/Reporters/AuditReporter.php:22` cere `array $sinks`; nimic nu îl leagă în container (`grep AuditReporter src/ config/` → doar fișierul lui). Adăugat în `reporters` → `BindingResolutionException` → prins de `report()` → `CriticalLog` → auditul tace. `config('errors.audit.sinks')` nu e citit nicăieri. `DatabaseAuditSink.php:14` inserează `context` (array) direct → eroare PDO „Array to string conversion"; tabela `error_audit_log` nu are migrare; lipsesc `retain_until`, `user_id`, `request_id`. **Fix:** binding în `packageRegistered()` care construiește sink-urile din `audit.sinks`; `json_encode($context)`; migrare publicabilă; câmpurile din planul WP-06.

**N-07 · `--env` neverificat și stub-ul încă interpolează brut (F-04 pe jumătate).** `src/Console/Commands/MakeExceptionCommand.php:44-46` validează doar `--report`; `BuildsErrorStubs.php:65,71` face `"'" . trim($ch) . "'"` fără `var_export`. Reprodus: `make:error X --report=slack --env="prod')] class Pwn {} #[Y('"` generează PHP arbitrar. **Fix:** `validatedList($this->option('env'), 'environment')` + `var_export($v, true)` în stub, cum cere WP-08. Același lucru în `ddd:error`.

**N-08 · F-13 nefăcut.** `MakeExceptionCommand.php:101` — `str_replace($baseNamespace, '', $namespace)` a rămas. `make:error AppPayments/AppFailed` → `app/Exceptions/Payments/AppFailed.php` cu namespace `App\Exceptions\AppPayments` → clasa nu se autoîncarcă. **Fix:** `($namespace === $base || str_starts_with($namespace, $base . '\\')) ? substr(...) : $namespace`.

**N-09 · Redirect cu status non-3xx în Inertia `flash`.** `src/Renderers/InertiaRenderer.php:58` — `back()->with(...)->setStatusCode($status)`: un 402 pe un `RedirectResponse` e exact comportamentul nedefinit din F-07 v1 (browserul ignoră `Location`). Fallback-ul din `catch` (linia 61) apelează iar `->with()` fără sesiune → a doua eroare → `null` → Laravel HTML. **Fix:** fără `setStatusCode` (302); fallback `new RedirectResponse($request->fullUrl(), 302)` fără `with()`.

**N-10 · `inertia_mode => 'respond'` acceptat, dar neimplementat.** `ErrorsServiceProvider.php:141` îl permite; `InertiaRenderer.php:49` întoarce `null` pentru el; `HandlerSlots` nu are nicio cale Inertia. Cade tăcut pe HTML Laravel. **Fix:** ori implementează (pagina Inertia din `HandlerSlots::onRespond`, WP-04), ori scoate-l din enum până atunci.

**N-11 · `CacheErrorsCommand` scrie cache-ul fără validare.** `src/Console/Commands/CacheErrorsCommand.php:19-27` — nu apelează `AttributeValidator`; `file_put_contents` neverificat. Un `#[HttpCode(200)]` ajunge în cache și în producție. **Fix:** rulează validatorul, `exit 1` fără scriere la `error` (comportamentul din `DoctorCommand`).

**N-12 · `AttributeScanner::scan()` neprotejat.** `src/Support/AttributeScanner.php:48` — `reader->read()` aruncă la primul atribut invalid din **oricare** clasă scanată → `AttributeCache::load()` aruncă (și pe calea `rememberForever`, și pe retry) → `ExceptionInspector::attributes()` aruncă pentru **fiecare** excepție în non-producție; cu N-04, iese din `report()`. **Fix:** `try/catch` per clasă în `scan()`, care înregistrează un issue pentru doctor și continuă.

### 2.3 · Mediu

**N-13 · Ordinea `contexts` nu e cea din plan.** `config/errors.php:167-173` — Filament, Livewire, Inertia, Api, Web. Planul cere `ApiDetector` primul. Detectorul Filament reparat atenuează, dar `docs/01` documentează ordinea veche ca fiind corectă.

**N-14 · `shouldYieldToIgnition()` și `ApiDetector` decid diferit.** `ErrorManager.php:257-264` folosește `ajax()/wantsJson()`; `ApiDetector` folosește `HandlerSlots::shouldRenderJson()` (`expectsJson()` + `api_prefixes`). În debug, `GET /api/x` fără `Accept` e prins de `ApiDetector` (nu e `InteractiveContextDetector`) și apoi cedat lui Ignition → HTML pe rută API. **Fix:** reutilizează `shouldRenderJson()` în yield.

**N-15 · `HandlerSlots::runRespond()` neprotejat.** `HandlerSlots.php:65` — `isPassThrough()` (→ `AttributeCache`) fără `try/catch`; o excepție aici înlocuiește răspunsul deja randat. **Fix:** `try/catch` + `CriticalLog`, `$ours = true` ca default sigur.

**N-16 · `McpLogger::log($e)` primește wrapper-ul.** `ErrorManager.php:105-113` — după mapare, JSONL-ul MCP loghează `WarningAttributedHttpException` + mesajul public, nu clasa reală + mesajul original; și rulează și pentru `#[DontReport]`. **Fix:** `McpLogger::log(ExceptionInspector::origin($e))`, sau (3.0) listener pe `ExceptionReported`.

**N-17 · `Debugbar`/`Xdebug` etichetează cu clasa wrapper-ului.** `class_basename($e)` în ambele reportere → `WarningAttributedHttpException` în Debugbar. **Fix:** `class_basename(ExceptionInspector::origin($e))`.

**N-18 · `AttributeCache::VERSION = '2.1.0'`** deși array-ul cache-uit conține deja `error_code/log_as/retry_after/audit`. Regula de bump din plan: incrementează.

**N-19 · `composer.json` neactualizat.** `illuminate/contracts ^11.0`, fără `illuminate/support`. `SuppressedAttributedHttpException implements Illuminate\Contracts\Debug\ShouldntReport` — contractul nu există în 11.0–11.x timpuriu → fatal la prima clasă `#[DontReport]` mapată pe acele versiuni. Guard-ul `method_exists('optimizes')` e păstrat (coerent cu `^11.0`), dar înregistrează o comandă inexistentă (N-01). CI păstrează `policy.advisories.block false` (F-23). **Fix:** `illuminate/support ^11.27|^12.0|^13.0` + `illuminate/contracts` la fel; scoate guard-ul.

**N-20 · `ErrorManagerInterface` nu declară `bypassConsoleExceptions()`**, dar `ErrorsMcpCommand.php:49` și `ToolHandler.php:308` îl apelează pe interfață; `ToolHandler::simulateError()` face reflecție pe proprietatea privată `bypassConsoleExceptions` a oricărui obiect rezolvat → se rupe la primul fake/decorator. **Fix:** adaugă metoda în interfață, scoate reflecția.

**N-21 · `hasViews()` / `hasTranslations()` fără `resources/views` și `lang`.** Nu crapă la boot, dar `laravel-errors::error` și `laravel-errors::http.{status}` nu există → `WebRenderer` cade pe HTML brut, `MessageResolver` cade pe `Response::$statusTexts`. Creează-le sau scoate apelurile.

---

## 3. Din lista inițială, ce a rămas deschis

| Finding | Stare | Unde |
|---|---|---|
| F-04 injecție în stub | **pe jumătate** — `--report` validat, `--env` nu; fără `var_export` | N-07 |
| F-09 `tail(0)` | deschis | `McpLogReader.php:39` |
| F-10 JSONL: `json_encode` false → linie goală, `chmod 0666` | deschis | `McpLogger.php:39-44` |
| F-11 paginare nedeterministă | deschis (fără `usort`) | `ToolHandler::listExceptions()` |
| F-13 `str_replace` pe namespace | deschis | N-08 |
| F-15 `get_global_context` nesanitizat | deschis | `ToolHandler::getGlobalContext()` |
| F-19 trunchiere 1 MB, `id: null` | deschis | `McpServer.php:73` |
| F-20 `simulate_error` fără allow-list | deschis | `ToolHandler::simulateError()` |
| F-23 CI advisories | deschis | `run-tests.yml:44` |
| I-04 `LaravelErrors::fake()` | absent (fără `src/Testing/`) | — |
| I-16 `MetricsListener` / cheia `metrics` | absent | — |
| I-09 hook Livewire | prezent, **⚠ netestat** (vezi §5) | — |

Închise și verificate ca identice cu planul: F-01, F-02, F-03 (validare + `assertWithinBase` lexical), F-05, F-06, F-07, F-08, F-12, F-14, F-16 (detector), F-17, F-21, F-22, F-24, F-25, F-26, F-27, F-28, F-29; I-01, I-02 (formatter), I-03 (familie + mapper + `ErrorHandler`), I-05, I-06, I-07, I-11, I-12, I-15, I-17 (parțial — vezi N-06).

---

## 4. Bug-uri în codul din plan, descoperite acum

Onest: acestea sunt în fișierele pe care le-am livrat eu și pe care autorul le-a copiat. Trei dintre ele pot opri logarea Laravel a *oricărei* excepții, deci merg sus în listă.

**P-01 · Închiderea `context()` din `ErrorHandler` nu are `try/catch`.** `ErrorHandler.php:80-85`. `Handler::exceptionContext()` **nu** protejează `contextCallbacks` (spre deosebire de `context()`). Dacă închiderea aruncă — `AttributeCache::load()` pe un atribut invalid, sau `ExceptionInspector::context()` citind o **proprietate tipizată neinițializată** listată în `#[WithContext]` (reprodus: `Typed property E::$missing must not be accessed before initialization`), sau o metodă `#[WithContext]` care aruncă — **logarea Laravel a oricărei excepții moare** și excepția reală e înlocuită. **Fix:** `try { … } catch (Throwable $f) { CriticalLog::once(...); return []; }` în închidere; în `ExceptionInspector::context()`/`paramValue()`, `(new ReflectionProperty($origin, $property))->isInitialized($origin)` înainte de citire.

**P-02 · `MessageResolver` întoarce `''` pentru `HttpExceptionInterface` fără mesaj.** `MessageResolver.php:29-31` — `abort(404)`, rutele 404, `abort(403)` au mesaj gol; îl întoarcem verbatim. Cu N-02, fiecare 404 de framework devine `"message": ""` / `<h1>404</h1><p></p>`. **Fix:** `$e->getMessage() !== '' ? $e->getMessage() : self::fallback(...)`.

**P-03 · `AttributedHttpException::report()` cu `method_exists`.** `AttributedHttpException.php:47` — un `report()` `protected`/`private` pe original → `app()->call()` aruncă în `Handler::reportThrowable`. **Fix:** `Reflector::isCallable([$this->original, 'report'])`, cum face Laravel.

**P-04 · `HandlerSlots::runRespond()` neprotejat** — N-15 (același cod ca în plan).

**P-05 · `AttributeScanner::scan()` neprotejat** — N-12 (același cod ca în plan; `AttributeCache::for()` are `try/catch`, `scan()` nu).

Le voi corecta și în planul-master.

---

## 5. Testele

Suita **trece** pe codul curent (verificat static — semnături, chei, fake-uri), dar pentru că a fost **tăiată** la ce codul nou satisface, nu extinsă. Din cele 28 de teste de regresie obligatorii din Anexa C a planului, există fragmente pentru 2 (mesaj mascat pe `RuntimeException`, nu pe `QueryException`; Masker). Nu există niciun test pentru: `AttributeCache`/`Scanner`/`Reader`/`Validator`, `ErrorHandler` cu orice flag activ (`map`, `dontReport`, `level`, `throttle`, `shouldRenderJsonWhen`), familia de wrapper-e și mapper-ul, `HandlerSlots` (header, payload, evenimente, `api_prefixes`), contractul `report()` (true/false/pass-through), `MessageResolver` în matrice, F-01/F-06/F-08/F-25/F-27/F-28/F-29 ca regresii, Problem Details prin `ApiRenderer`, comenzi (traversal, injecție, `--http`), MCP hardening, audit, Sentry (testul verifică doar `toBe($event)`).

Teste **vacue sau greșite:**
- `IntegrationsTest.php:25` — `expect(is_callable($hook))->toBeTrue()` pe `ExceptionHook`, care nu are `__invoke` → **pică de îndată ce Livewire e instalat** (acum e skip). Ăsta e tot „testul hook-ului".
- `CoreIntegrationsTest.php:57-99` — „flushes dynamicPassThrough on Octane" — flush-ul din provider nu atinge `dynamicPassThrough`, iar aserțiunea e pe un `new ErrorManager()` proaspăt. Trece vacuu.
- `CoreIntegrationsTest.php:23-55` — mock pe toate metodele `Exceptions` cu `andReturnSelf()`, verifică doar că două închideri există.
- `MissingFeaturesTest.php:60-69` — `'sanitize' => ['api_token']` în array, dar `sanitizedContext()` citește `config('errors.sanitize')` global; trece pentru că lista default conține deja `api_token`.
- `Pest.php` — fake-urile sunt cele vechi: `Filament::$panel` implicit `null` (F-16 invizibil), `Inertia::share` static, `Filament::$panel = 'throw'` scurs între teste.
- `ToolHandlerTest.php:59-82` — suprascrie `make:error`/`ddd:error` cu `Artisan::command()` → validarea MCP → comandă nu e exersată.

---

## 6. Documentația vs. codul

README și `docs/` au fost extinse, dar conțin cod care **nu rulează** și afirmații false — periculos, pentru că `AttributeCache::for()` înghite excepțiile din constructorii de atribute și dezactivează tăcut atributele pe clasa respectivă:

- `README.md:17` `#[ReportTo('slack', 'sentry')]` → `TypeError` (al doilea argument e `array`); `:18` `#[RateLimit(maxExceptions: 5, perMinutes: 1)]` → `Unknown named parameter`. Ambele verificate.
- `docs/03:144,199,266`, `docs/05:101`, `docs/06:511` — `throw new #[TranslatedMessage(...)] \Exception()` e **eroare de parsare**; `#[TranslatedMessage('The requested resource…')]` folosește o propoziție ca cheie → `trans()` întoarce cheia → `null`.
- `docs/03:17,249-290` — Inertia `props` documentat ca default (aruncă la boot); `flash` nedocumentat.
- `docs/03:25,34` — „`WebRenderer` returnează `null` și folosește view-urile Blade ale Laravel" — fals (N-03).
- `docs/01:12,18,32,44,56,60,69,71,73,91` — `stop()`, `report(): void`, `hasReporters()`, „`httpCode()` cade pe `getCode()`", `errorCode(): ?array`, `rateLimit(): ?array`, „`CriticalLog` scrie în `errors-critical.log`", „`Masker` traversează recursiv", „`ApiDetector` folosește `wantsJson()`" — toate greșite față de cod.
- `docs/04:8,45`, `docs/01:150-155` — `#[ReportTo]` rutează „automat" prin `LogReporter` — care **nu mai e** în `reporters` default; nicăieri nu se spune că trebuie readăugat.
- `README.md:44` Problem Details „automatic" (opt-in, `false`); `:47` mască `random` (nu există); `:34` „Laravel 11.0+" (vezi N-19); `docs/08:30-34` aserțiuni pe un format care nu e activ implicit și pe un `title` care nu e niciodată numele clasei; `docs/06:365` `ErrorManager::flushPassThrough()` nu există.

---

## 7. Ce aș face, în ordine

**Înainte de orice release (o zi):** N-01 (înregistrează comenzile), N-02 (`HttpException` înapoi în `pass_through`), N-03 (`e($message)` + `errors::{status}` + view-ul pachetului), N-04 (`report()` din planul actual), P-01 (`try/catch` în `context()` + `isInitialized`), P-02 (mesaj gol → fallback), N-05 (`scan_paths` default + DDD la runtime), N-07/N-08 (`--env`, `var_export`, prefix). Toate sunt sub 10 linii fiecare; fără ele, pachetul strică paginile de eroare ale oricărei aplicații care îl instalează.

**Înainte de a promite feature-urile (două zile):** N-06 (audit funcțional sau scos din config/docs), N-09/N-10 (Inertia), N-11/N-12 (validare la cache, scanner tolerant), N-19 (`composer.json`), N-20 (interfața), F-09/F-10/F-19 (MCP), și **testele din Anexa C** — cel puțin cele 8 pentru fixurile critice, pentru că azi nimic nu dovedește că F-01, F-06, F-25, F-27 sunt reparate, iar `IntegrationsTest:25` va pica la prima instalare cu Livewire.

**Documentația:** o trecere „claim → test" pe README și `docs/01`, `03`, `04` — fiecare exemplu de cod din docs rulat prin `php -l` și, pentru atribute, instanțiat o dată. Exemplele greșite din README sunt cele pe care le va copia primul utilizator.

**⚠ Rămân de verificat în workbench** (neschimbat): timing-ul înregistrării `ExceptionHook` față de `ComponentHookRegistry::boot()`; `Filament::getCurrentPanel()` non-null pe orice request în versiunea ta de Filament; comportamentul `errors::{status}` după `registerErrorViewPaths()`.
