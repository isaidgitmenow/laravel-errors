# Renderers

## 🎨 Context Detectors & Renderers

The package uses a Strategy Pipeline to automatically identify the request type and return the correct format so your frontend SPAs don't break on a 500 error.

### 🛡️ Filament Panels
- **`FilamentDetector`**: Matches requests by checking if there is an active Filament panel running (`\Filament\Facades\Filament::getCurrentPanel() !== null`). This works flawlessly regardless of your custom routing structure!
- **`FilamentRenderer`**: Executes a native Filament `Notification::make()->send()` to trigger a native error Toast Notification inside the Filament UI without breaking the panel. It then returns a standard JSON response to gracefully conclude the Livewire lifecycle.

### ⚡ Livewire
- **`LivewireDetector`**: Matches requests carrying the `X-Livewire` header. Implements `InteractiveContextDetector`, which tells the `ErrorManager` to keep control even in debug mode (instead of yielding to Ignition).
- **`LivewireRenderer`**: Returns a structured JSON response containing the error message. Livewire parses this natively, preventing full-page HTML crash dumps from destroying Livewire component state.

### ⚛️ Inertia.js (Vue/React/Svelte)
- **`InertiaDetector`**: Matches requests carrying the `X-Inertia` header. Implements `InteractiveContextDetector`, which tells the `ErrorManager` to keep control even in debug mode (instead of yielding to Ignition).
- **`InertiaRenderer`**: Depending on your `inertia_mode` config, it either shares the error globally as an Inertia `prop` and redirects back, or natively renders a dedicated Error Component via `Inertia::render()`. This allows your SPA to handle the error natively without crashing.

### 🔌 API Requests
- **`ApiDetector`**: Matches requests calling `wantsJson()` or paths matching `api/*`.
- **`ApiRenderer`**: Returns a standard JSON payload containing a `message` and `errors` array. The HTTP status code is applied from the `#[HttpCode]` attribute. This structure can be fully customized via a Closure in `config/errors.php`.

### 🌐 Standard Web Requests
- **`WebDetector`**: The fallback detector that always returns `true` if no other context matched.
- **`WebRenderer`**: Defers rendering back to Laravel (returning `null`), which natively renders the standard Blade error pages (e.g., `resources/views/errors/500.blade.php`).

---


---

## 🌐 Web Renderer Example

By default, the `WebRenderer` returns `null`, intentionally yielding control back to Laravel's core rendering engine. This means you can use Laravel's standard Blade error views seamlessly with the `#[HttpCode]` attribute.

For example, if you define a custom exception with a 404 HTTP code:

```php
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;

#[HttpCode(404)]
class ProductNotFoundException extends \Exception {}
```

When this exception is thrown in a standard web request, the package detects the `Web` context, reads the `#[HttpCode(404)]` attribute, and automatically tells Laravel to render the view located at `resources/views/errors/404.blade.php`.

You just need to create the Blade file:

```blade
{{-- resources/views/errors/404.blade.php --}}
@extends('layouts.app')

@section('content')
    <div class="container text-center">
        <h1>404 - Not Found</h1>
        <p>Sorry, we couldn't find the product you're looking for!</p>
    </div>
@endsection
```

---


---

## 📡 API Renderer Example

When an exception is thrown during an API request (detected via `wantsJson()` or an `api/*` route), the `ApiRenderer` automatically takes over and formats the response as standard JSON.

For example, using a custom exception:

```php
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

#[HttpCode(422)]
#[TranslatedMessage('errors.subscription.expired')]
class SubscriptionExpiredException extends \Exception {}
```

If a client makes a JSON request and this exception is thrown, they will receive a clean `422 Unprocessable Entity` response:

```json
{
    "message": "Your subscription has expired. Please renew to continue.",
    "errors": []
}
```

### Customizing the API Response Format

If your frontend expects a different JSON structure (e.g., JSON:API specification), you can completely customize the payload globally by defining a `json_formatter` Closure in `config/errors.php`:

```php
// config/errors.php
use Illuminate\Http\Request;

return [
    // ...
    'json_formatter' => function (\Throwable $e, Request $request) {
        return [
            'success' => false,
            'error_type' => class_basename($e),
            'developer_message' => $e->getMessage(),
            // You can even extract specific attributes
            'meta' => \Isaidgitmenow\LaravelErrors\ExceptionInspector::context($e),
        ];
    },
];
```

---


---

## ⚡ Livewire Renderer Example

A common pain point in Livewire development is that a backend `500 Server Error` often results in a full HTML stack trace being injected directly into your component's DOM, breaking the page entirely.

The `LivewireDetector` intercepts requests containing the `X-Livewire` header. When an exception occurs, the `LivewireRenderer` takes over and formats the error safely so Livewire doesn't crash.

### Basic Usage

You don't need to change anything in your components. If you throw a decorated exception inside a Livewire method:

```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Livewire\Component;

class CheckoutForm extends Component
{
    public function processPayment()
    {
        // ... payment fails
        
        // This exception is caught by the LivewireRenderer
        throw new #[TranslatedMessage('checkout.insufficient_funds')] \Exception();
    }
}
```

The package catches the error and returns a clean JSON response containing the message, preserving the interactive state of the rest of the page.

### Customizing the Livewire Handler

You might want to trigger a frontend notification (like a Toast or a SweetAlert) when an error occurs during a Livewire request, rather than just returning a response. You can configure a global closure in `config/errors.php` using the `livewire_handler` key:

```php
// config/errors.php
use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;

return [
    // ...
    'livewire_handler' => function (\Throwable $e, Request $request) {
        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();
        
        // Flash the error to the session so a global Toast component can display it
        session()->flash('error', $message);
        
        // Or interact with Livewire's internal response (if needed)
    },
];
```

---


---

## 🛡️ Filament Renderer Example

In a Filament Admin panel, an unhandled exception usually results in an ugly modal containing a full Ignition stack trace, or worse, a broken UI state. 

The `FilamentDetector` automatically intercepts requests made inside your Filament paths. The `FilamentRenderer` then dynamically uses Filament's native Notification system to display the error, keeping your admin panel beautiful and interactive.

### Basic Usage

You can throw a decorated exception directly inside a Filament Action, Resource, or Page:

```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Filament\Actions\Action;

class CreateInvoiceAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->action(function () {
            // ... invoice creation fails
            
            throw new #[TranslatedMessage('invoices.creation_failed')] \RuntimeException();
        });
    }
}
```

The `FilamentRenderer` catches this exception and natively executes:
```php
\Filament\Notifications\Notification::make()
    ->title(__('Error'))
    ->body('The translated invoice creation failed message')
    ->danger()
    ->send();
```
The user simply sees a red Toast Notification in the corner of their screen!

### Customizing the Filament Handler

If you want to customize how the notification looks, or if you want to perform other actions when an error occurs in Filament, you can define a `filament_handler` in `config/errors.php`:

```php
// config/errors.php
use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Filament\Notifications\Notification;

return [
    // ...
    'filament_handler' => function (\Throwable $e, Request $request) {
        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();
        
        Notification::make()
            ->title('Oops! Something went wrong.')
            ->body($message)
            ->warning() // Make it a warning instead of danger
            ->duration(10000) // Stay on screen longer
            ->send();
    },
];
```

---


---

## ⚛️ Inertia.js Renderer Example

Inertia.js applications (Vue, React, Svelte) expect a specific JSON payload to update their client-side router. A raw Laravel 500 HTML error page will break an Inertia request and force a hard reload or show a blank modal.

The `InertiaDetector` catches requests containing the `X-Inertia` header. The `InertiaRenderer` then handles the exception using one of two configurable modes in `config/errors.php`: `'props'` (default) or `'redirect'`.

### Mode 1: Props (Default)

In this mode, the renderer catches the exception, shares the error globally using `Inertia::share()`, and redirects the user back to the same page (`back()->withInput()`). This allows you to show an inline error without losing the user's form data.

*(Note: If the route lacks session middleware—such as in a stateless API context—the renderer will safely return a clean RedirectResponse rather than crashing.)*

```php
// config/errors.php
'inertia_mode' => 'props',
```

If you throw an exception:
```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

throw new #[TranslatedMessage('cart.item_out_of_stock')] \Exception();
```

You can then catch this globally in your Vue/React layout by reading the shared `error` prop:

```vue
<!-- Layout.vue -->
<template>
  <div v-if="$page.props.error" class="bg-red-500 text-white p-4">
    {{ $page.props.error.status }} - {{ $page.props.error.message }}
  </div>
  <slot />
</template>
```

### Mode 2: Redirect to Error Page

If you prefer to completely redirect the user to a dedicated Error component (like an isolated 500 or 404 page in your frontend), change the mode to `redirect`.

```php
// config/errors.php
'inertia_mode' => 'redirect',
'inertia_error_component' => 'ErrorPage', // The name of your Vue/React component
```

Now, when an exception is thrown, the package executes `Inertia::render('ErrorPage')` and passes the `status` and `message` as props to that component.

```vue
<!-- Pages/ErrorPage.vue -->
<script setup>
defineProps({
  status: Number,
  message: String,
})
</script>

<template>
  <div class="error-container">
    <h1>Error {{ status }}</h1>
    <p>{{ message }}</p>
  </div>
</template>
```

---


---
