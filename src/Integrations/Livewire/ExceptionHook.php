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
