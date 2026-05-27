# Integrare Laravel-DDD pentru `ddd:error`

Scopul este crearea unei comenzi Artisan `ddd:error` în pachetul nostru (`isaidgitmenow/laravel-errors`), permițând dezvoltatorilor să genereze excepții decorate direct în modulele lor de domeniu (ex: `src/Domain/Invoicing/Exceptions`).

## Arhitectura Finală

1. **Comandă Dedicată:** Vom folosi `ddd:error` (mapată pe o clasă nouă `MakeDddErrorCommand`), evitând alterarea comenzii noastre de bază `make:error` sau suprascrierea comenzilor din `laravel-ddd`.
2. **Dependență Sigură (Runtime):** Comanda va verifica existența pachetului `tey/laravel-ddd`. Dacă lipsește, se va opri grațios cu un mesaj prietenos, fără a cauza erori fatale.
3. **Rezolvarea Căilor (Decuplată):** Vom citi setările native `config('ddd.domain_path')` și `config('ddd.domain_namespace')` furnizate de pachetul `laravel-ddd`. Dacă acestea nu există (fallback), vom folosi standardul: `src/Domain` și `Domain`.
4. **Generare Stub:** Noile excepții DDD vor extinde `\Exception` în mod implicit, folosind același stub cu suport pentru atributele noastre (`#[HttpCode]`, `#[ReportTo]`, etc.).

## Schimbări Propuse

### 1. Crearea `MakeDddErrorCommand`
#### [NEW] `src/Console/Commands/MakeDddErrorCommand.php`
- Extinde `Illuminate\Console\Command`.
- **Semnătură:** `ddd:error {name} {--domain=} {--http=500} {--report=} {--env=}`
- Va suporta sintaxa shorthand (specifică DDD): `ddd:error Invoicing:PaymentFailed`
- Va implementa o logică custom pentru `resolveNamespaceAndClass` și `resolveTargetPath` care respectă configurația `ddd.php`.

### 2. Înregistrarea Comenzii
#### [MODIFY] `src/ErrorsServiceProvider.php`
- În metoda `boot()`, adăugăm `MakeDddErrorCommand::class` alături de `MakeExceptionCommand::class`.

### 3. Actualizarea Documentației
#### [MODIFY] `README.md`
- O scurtă secțiune "Domain Driven Design (DDD) Support" ce explică integrarea și rularea `php artisan ddd:error`.

## Plan de Verificare

### Testare Automată
- **[NEW]** `tests/Feature/MakeDddErrorCommandTest.php`
  - Assert: Comanda aruncă eroare elegantă dacă simulăm absența pachetului `laravel-ddd`.
  - Assert: Comanda respectă config-ul `ddd.domain_path` mock-uit și plasează fișierul generat corect.

### Testare Manuală
- Vom rula comanda folosind `--domain=Invoicing` și `Invoicing:PaymentFailed` în consolă pentru a confirma funcționarea completă.
