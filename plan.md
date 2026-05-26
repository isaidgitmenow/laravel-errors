# Laravel Error Handling Package (Planificare & Arhitectură)

Acest document reprezintă viziunea arhitecturală completă și detaliată pentru pachetul de tratare a erorilor destinat Laravel 13 și PHP 8.4+.

## 1. Arhitectură și Principii SOLID
*   **SRP**: `ContextDetector` doar detectează natura request-ului. `ExceptionRenderer` doar formatează răspunsul. `ExceptionReporter` doar raportează erorile la servicii externe.
*   **OCP**: Pachetul permite adăugarea dinamică de noi Renderere și Detectoare (pentru alte pachete) prin intermediul `config/errors.php`, fără a modifica core-ul pachetului.
*   **LSP**: Toate extensiile respectă interfețele de bază, garantând zero "breakages" la runtime.
*   **ISP**: Interfețele sunt minimale și specifice (`ContextDetectorInterface`, `ExceptionRendererInterface`, `ErrorReporterInterface`).
*   **DIP**: Toată instanțierea și rezolvarea dependențelor se face prin Service Container-ul din Laravel.

## 2. Integrare Simplificată (Laravel 11+)
Pachetul se instalează extrem de ușor în `bootstrap/app.php`:
```php
->withExceptions(function (Exceptions $exceptions) {
    \Isaidgitmenow\LaravelErrors\ErrorHandler::handle($exceptions);
})
```

## 3. Configurare Declarativă și Elegantă (PHP 8.4 Attributes)
În loc să ne bazăm strict pe metode și interfețe boilerplate pe clasele de excepții, dezvoltatorii își vor configura excepțiile folosind Atribute PHP 8, curat și intuitiv:
*   `#[HttpCode(402)]` - Definește codul HTTP returnat.
*   `#[DontReport]` - Oprește trimiterea erorii către trackere externe (Sentry, Flare).
*   `#[ReportTo('slack')]` - Permite rutarea excepției pe un canal de raportare specific.
*   `#[TranslatedMessage('errors.payment_failed')]` - Oferă frontend-ului (Inertia/Livewire/Filament) mesajul tradus, prietenos pentru utilizator.
*   `#[WithContext(['user_id'])]` - Extrage automat parametrii din clasa excepției și îi loghează prin `Laravel Context`.
*   `#[RateLimit(max: 10, intervalInMinutes: 5)]` - Protecție Anti-Spam (evită epuizarea cotelor serviciilor externe când pică baza de date).

## 4. Soluții de Reziliență și Edge-Cases (Bulletproof Mechanics)

### A. Extragerea Atributelor din "Wrapped Exceptions"
Când Laravel ascunde o eroare într-un `QueryException` sau `ViewException`, pachetul folosește clasa internă `ExceptionInspector`. Aceasta coboară recursiv pe firul `$e->getPrevious()` până identifică atributele originale (ex. `#[DontReport]`), astfel nicio regulă nu se pierde.

### B. Integrarea cu Spatie Ignition (Debug Mode)
Când `APP_DEBUG=true`, managerul cedează intenționat controlul (returnând `null` către Laravel) pentru cazurile Web/API, lăsând ecranul nativ "Spatie Ignition" să randeze stack-trace-ul pentru developeri, fără interferențe.

### C. Integrare Nativă cu Laravel Debugbar
Pachetul include un **`DebugbarReporter`**:
*   Excepțiile suprimate cu `#[DontReport]` (care nu ajung în Sentry) vor fi totuși forțate în tab-ul *Exceptions* al Debugbar-ului local.
*   Orice date extrase prin `#[WithContext]` ajung direct în tab-ul *Messages*.
*   *Dependența este complet decuplată* (se verifică prin `class_exists`).

### D. Excepții Native Ignorate (Pass Through)
Excepțiile core Laravel (ex. `ValidationException`, `AuthenticationException`) sunt trecute într-un array `pass_through` în config. Pachetul nu se atinge de ele, lăsând flow-ul formularelor și al login-ului să funcționeze 100% nativ.

### E. Prioritizarea Contextelor (Strategy Order)
`config/errors.php` conține lista de detectoare. Ordinea *top-to-bottom* dictează prioritatea (de ex. `FilamentDetector` rulează obligatoriu înaintea `LivewireDetector`, altfel erorile Filament ar fi preluate greșit de randerul Livewire).

### F. Performanța și Fallback-ul "Self-Healing"
*   **Static Array Caching**: Reflecția atributelor (care consumă memorie) este cached în memorie per request. A doua citire a atributelor unei clase are cost zero.
*   **Invizibil Try-Catch**: Tot sistemul de rutare/randare din pachet este îmbrăcat într-un bloc `try-catch` de siguranță. Dacă formatatorul JSON custom crapă, managerul dă fallback automat la handler-ul default Laravel pentru a nu servi ecrane complet albe (WSOD).

## 5. Etapele de Execuție și Modularizare (Roadmap TDD)
*Pachetul va fi construit și testat în Orchestra Testbench, într-o ordine strictă.*

1.  **Faza 1: Fundația**
    *   Construirea interfețelor (Contracts) și a claselor pentru atributele PHP 8 (`HttpCode`, `DontReport` etc.). 
    *   *Testare*: Verificarea instanțierii și a tipizării.
2.  **Faza 2: Inima Logică (`ExceptionInspector`)**
    *   Construirea motorului care parsează atributele, caching-ul static și rezolvarea recursivității (Wrapped Exceptions).
    *   *Testare*: Testarea pe excepții mockuite și împachetate adânc.
3.  **Faza 3: Orchestratorul (`ErrorManager`)**
    *   Fațada `ErrorHandler`, `ErrorsServiceProvider` și logica Strategy Queue (rularea ordinii detectoarelor).
    *   *Testare*: Verificarea listei `pass_through`, fallback-ul pentru `APP_DEBUG=true` și siguranța try/catch-ului intern.
4.  **Faza 4: Sistemul de Raportare (Reporters)**
    *   Construirea `LogReporter`, `DebugbarReporter` și a sistemului de **Rate Limiting** (cu Laravel Cache).
    *   *Testare*: Mock Laravel Cache pentru verificarea anti-spam-ului (ex: blocarea a peste 10 apeluri/minut).
5.  **Faza 5: Frontend Plugins (Detectors & Renderers)**
    *   Detectoarele și formatatoarele finale (`Web`, `Api`, `Livewire`, `Inertia`, `Filament`).
    *   *Testare*: Simulare de HTTP requests (ex: `X-Inertia` header) pentru verificarea formatului de output corect returnat de Renderere.
