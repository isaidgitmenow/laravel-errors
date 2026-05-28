# Exception Handling Mechanics

## 🚧 Bypassing the Pipeline: Native Laravel Exceptions

You might be wondering: *"What happens when Laravel throws a `ValidationException` (422) during form validation, an `AuthorizationException` (403) from a Gate, or a `NotFoundHttpException` (404) for a missing page? Will the package intercept them and log them as 500 errors?"*

The answer is **No**. The package has a built-in `pass_through` mechanism defined in `config/errors.php`. This array comes pre-configured with all of Laravel's core HTTP and routing exceptions:

```php
'pass_through' => [
    \Illuminate\Validation\ValidationException::class,
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Auth\Access\AuthorizationException::class,
    \Symfony\Component\HttpKernel\Exception\HttpException::class, // Catches 404s, 405s, 429s, etc.
    \Illuminate\Database\Eloquent\ModelNotFoundException::class,  // Catches User::findOrFail()
    \Illuminate\Session\TokenMismatchException::class,            // Catches CSRF failures
    \Illuminate\Http\Exceptions\HttpResponseException::class,
],
```

When the `ErrorManager` encounters an exception that is an `instanceof` any class listed in this array, it immediately halts its own pipeline and **yields full control back to Laravel's native exception handler**. 

This guarantees that form validation redirects, `$errors` bags, unauthenticated redirects, 403 Forbidden pages, and standard 404 Not Found pages work exactly as they normally do in standard Laravel, without triggering false alarms in your Slack channel or error logs!

### 🔌 Dynamic Pass-Through at Runtime

The `pass_through` config is great for your own application, but it requires editing the published config file. If you are a **package author** and want your internal exceptions to always bypass the pipeline without forcing the user to touch their config, use the static `passThrough()` method:

```php
// In your package's ServiceProvider boot() method:
use Isaidgitmenow\LaravelErrors\ErrorManager;
use Isaidgitmenow\LaravelErrors\Facades\LaravelErrors;

// Via the ErrorManager directly:
ErrorManager::passThrough(MyVendorInternalException::class);

// Or via the Facade:
LaravelErrors::passThrough(MyVendorInternalException::class);
```

This merges your exceptions into the pass-through list at runtime, without requiring the end user to publish and edit `config/errors.php`. The static registry is separate from the config array, so both are always respected.

**Application developers** can also use this for one-off registrations inside `AppServiceProvider::boot()`:

```php
public function boot(): void
{
    // Bypass the pipeline for a third-party exception you cannot decorate
    LaravelErrors::passThrough(\Vendor\Package\SomeInternalException::class);
}
```

---


---

## 🚦 The "Pass Through" Exceptions (Complete List)

By default, the package ships with a pre-configured `pass_through` array in `config/errors.php`. When an exception listed here is thrown, the package **immediately stops its own pipeline** and lets Laravel handle it natively.

Here is the complete list and *why* they are bypassed:

1. **`\Illuminate\Validation\ValidationException::class`**
   - **Why:** Thrown by FormRequests. Bypassing it ensures your `$errors` variable is populated in Blade and that API users receive the standard 422 JSON response.
2. **`\Illuminate\Auth\AuthenticationException::class`**
   - **Why:** Thrown when a guest visits a protected route. Bypassing it ensures the user gets redirected to `/login` (Web) or receives a 401 response (API) natively.
3. **`\Illuminate\Auth\Access\AuthorizationException::class`**
   - **Why:** Thrown when a Gate or Policy fails. Bypassing it ensures Laravel renders a standard 403 Forbidden page instead of logging a 500 Server Error.
4. **`\Symfony\Component\HttpKernel\Exception\HttpException::class`**
   - **Why:** The base class for all HTTP errors (`NotFoundHttpException`, `TooManyRequestsHttpException`, etc.). Bypassing this guarantees that 404s and Rate Limits are handled by Laravel natively.
5. **`\Illuminate\Database\Eloquent\ModelNotFoundException::class`**
   - **Why:** Thrown by `User::findOrFail($id)`. Laravel natively converts this to a 404 Not Found. Bypassing it prevents false-positive 500 error logs.
6. **`\Illuminate\Session\TokenMismatchException::class`**
   - **Why:** Thrown when a CSRF token expires. Bypassing it lets Laravel show the standard 419 Page Expired template.
7. **`\Illuminate\Http\Exceptions\HttpResponseException::class`**
   - **Why:** An internal Laravel exception used to abruptly halt execution and return a raw Response object. Must be bypassed.

### Interacting with Pass-Through Exceptions (Injecting Custom Logic)

If you have a strict requirement to log or customize the response of a native Laravel exception, you have full control!

Because you cannot add PHP 8 Attributes directly to native classes (like `ModelNotFoundException`), the most elegant way to handle them is to **catch them and translate them into your own Decorated Exceptions**.

Here is the step-by-step example for all native errors:

#### Step 1: Remove the Exception from `pass_through`
First, open `config/errors.php` and delete the exception you want to intercept (e.g., `ModelNotFoundException`) from the `pass_through` array.

#### Step 2: Create a Decorated Exception
Create a custom exception that extends `Exception` (or the native exception) and add your package attributes:

```php
use Exception;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

#[HttpCode(404)]
#[ReportTo('slack')] // We want Slack to know when a model is not found!
#[TranslatedMessage('The requested resource could not be found.')]
class CustomResourceNotFoundException extends Exception {}
```

#### Step 3: Translate it in `bootstrap/app.php`
Use Laravel's `renderable` hook to catch the native exception and throw your decorated one *before* it hits the package pipeline:

```php
// bootstrap/app.php
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;

->withExceptions(function (Exceptions $exceptions) {
    
    // Example 1: Translating a Model Not Found Error
    $exceptions->renderable(function (ModelNotFoundException $e) {
        throw new CustomResourceNotFoundException('Model missing: ' . $e->getModel());
    });

    // Example 2: Translating a 403 Gate/Policy Error
    $exceptions->renderable(function (AuthorizationException $e) {
        throw new CustomSecurityBreachException('Unauthorized access attempt!');
    });

    // Finally, load the package
    \Isaidgitmenow\LaravelErrors\ErrorHandler::handle($exceptions);
})
```

**Why is this brilliant?** 
Because `ErrorHandler::handle($exceptions)` runs *after* your `renderable` callbacks, the package will immediately catch your newly thrown `CustomResourceNotFoundException`. It will see the `#[ReportTo('slack')]` attribute, send the notification to your Slack channel, evaluate the Context Detectors, and return a perfectly formatted 404 Inertia Modal or API JSON response! 

This pattern works universally for **any** of the 7 pass-through exceptions listed above.

---


---

## 🤷‍♂️ Handling Generic (Un-decorated) Exceptions

What happens if you (or a third-party package) throw a raw exception without any of our custom attributes?

```php
throw new \Exception("Something broke!");
```

The package is smart enough to handle this perfectly! 
1. It defaults to an **HTTP 500** status code.
2. It sends the log to your default logging channel (via Laravel's standard Log facade).
3. Most importantly: **It still uses the Context Renderers!** 

This means if a raw `PDOException` is thrown during a **Livewire** request, the `LivewireRenderer` will still intercept it and return a safe JSON payload instead of a crashing HTML stack trace. Your app's frontend stays resilient even for unexpected, un-decorated errors!

---


---

## 📝 A Note on API Form Requests (`ValidationException`)

If you are building an API and using Laravel's `FormRequest` classes, you might wonder what happens when a user submits invalid data. Does the package log the error? Does it change the 422 JSON response?

The answer is **No, by design.**

As mentioned in the *Bypassing the Pipeline* section, `\Illuminate\Validation\ValidationException::class` is in the `pass_through` array by default.

When validation fails in a Form Request:
1. Laravel throws a `ValidationException`.
2. The `ErrorManager` sees it in the `pass_through` array and ignores it.
3. It is **not** logged to Slack, Flare, or your log files (which is good, you don't want alerts for every typo a user makes).
4. Laravel natively takes over and returns the standard `422 Unprocessable Entity` JSON response with the `$errors` bag.

If you ever *want* to intercept validation errors (for example, to force them into a proprietary JSON format via your `ApiRenderer`), simply remove `ValidationException::class` from the `pass_through` array in `config/errors.php`. However, sticking to Laravel's native 422 structure is highly recommended!

---


---

## 🛡️ Working with Gates & Permissions (`AuthorizationException`)

When you use Laravel's native authorization features (like **Gates** or **Policies**), Laravel automatically throws an `\Illuminate\Auth\Access\AuthorizationException` if the user is not allowed to perform an action.

Because this package is designed to intercept unhandled exceptions, you might wonder: *"Will my 403 Forbidden errors get logged as 500 Server Errors in Slack?"*

**By default, NO!** The package handles this gracefully because `AuthorizationException` is included in the `pass_through` array in `config/errors.php`:

```php
'pass_through' => [
    \Illuminate\Validation\ValidationException::class,
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Auth\Access\AuthorizationException::class, // <-- Ignores Policy/Gate failures!
],
```

### How to use Gates/Policies with this package
You don't need to change anything about how you write your code. Just use Laravel's native authorization methods:

```php
// In a Controller
public function destroy(Post $post)
{
    // If this fails, Laravel throws AuthorizationException.
    // Our package ignores it, and Laravel natively returns a 403 response!
    Gate::authorize('delete', $post);
    
    $post->delete();
}
```

**What if I WANT to log authorization failures?**
If you are building a high-security application (like banking) and you actively *want* to be notified on Slack whenever someone attempts an unauthorized action, you can remove `AuthorizationException::class` from the `pass_through` array.

Then, you can map the native exception to your custom attributes using the package's pipeline, or simply create a custom exception for security breaches:

```php
// Throw a decorated exception for security breaches instead of a standard Gate!
if (Gate::denies('delete', $post)) {
    throw new SecurityBreachException('User attempted to delete a post they do not own!');
}
```

---


---

## 🌍 Translated Error Messages Example

When building user-facing applications, showing a raw backend exception message (e.g., `SQLSTATE[23000]: Integrity constraint violation`) is a terrible user experience. 

The `#[TranslatedMessage]` attribute allows you to bind a Laravel translation key directly to an exception. **All frontend renderers** (API, Livewire, Inertia, Filament) automatically prioritize this translated message over the raw exception message.

### Usage

First, define your translation in Laravel's `lang` directory (e.g., `lang/en/errors.php`):

```php
// lang/en/errors.php
return [
    'checkout' => [
        'out_of_stock' => 'We are sorry, but this item just went out of stock!',
    ],
];
```

Then, attach the attribute to your exception, pointing to that translation key:

```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

#[TranslatedMessage('errors.checkout.out_of_stock')]
class ItemOutOfStockException extends \Exception
{
    public function __construct(string $internalMessage = "Inventory count mismatch in DB")
    {
        // The internal message is what gets logged to Sentry or your log files
        parent::__construct($internalMessage);
    }
}
```

### What Happens Behind the Scenes?

If this exception is thrown during an **API Request**, the `ApiRenderer` will return:
```json
{
    "message": "We are sorry, but this item just went out of stock!",
    "errors": []
}
```

If it's thrown during a **Filament Request**, the `FilamentRenderer` will pop up a red Toast Notification saying:
> "We are sorry, but this item just went out of stock!"

Meanwhile, your `LogReporter` or Sentry will still receive the raw, developer-friendly message: `"Inventory count mismatch in DB"`. 

This completely separates what the **developer** sees from what the **user** sees, keeping your UI clean and your logs informative.

---


---
