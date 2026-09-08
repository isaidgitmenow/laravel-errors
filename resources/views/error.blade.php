<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status }} | {{ config('app.name', 'Error') }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1a202c; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { text-align: center; max-width: 420px; padding: 2rem; }
        h1 { font-size: 4rem; margin: 0; font-weight: 700; color: #718096; }
        p { font-size: 1.125rem; color: #4a5568; margin: 1rem 0; }
        .error-id { font-size: 0.75rem; color: #a0aec0; margin-top: 1.5rem; }
        a { color: #4299e1; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>{{ $status }}</h1>
        <p>{{ e($message) }}</p>
        @if(!empty($error_id))
            <div class="error-id">Reference: {{ $error_id }}</div>
        @endif
        <p><a href="{{ url('/') }}">← Go Home</a></p>
    </div>
</body>
</html>
