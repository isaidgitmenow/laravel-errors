<?php

declare(strict_types=1);

namespace {
    use Isaidgitmenow\LaravelErrors\Tests\TestCase;

    uses(TestCase::class)->in(__DIR__);
}

// --- DYNAMIC FAKES FOR THIRD PARTY PACKAGES ---

namespace Barryvdh\Debugbar\Facades {
    if (!class_exists('Barryvdh\Debugbar\Facades\Debugbar')) {
        class Debugbar {
            public static $throwables = [];
            public static $messages = [];
            
            public static function getFacadeRoot() {
                return new self;
            }
            
            public function addThrowable($e) {
                self::$throwables[] = $e;
            }
            
            public function addMessage($message, $type) {
                self::$messages[] = ['message' => $message, 'type' => $type];
            }
            
            public static function flush() {
                self::$throwables = [];
                self::$messages = [];
            }
        }
    }
}

namespace Filament\Facades {
    if (!class_exists('Filament\Facades\Filament')) {
        class Filament {
            public static $panel = null;
            public static function getCurrentPanel() {
                if (self::$panel === 'throw') {
                    throw new \Exception('Panel error');
                }
                return self::$panel;
            }
        }
    }
}

namespace Filament\Notifications {
    if (!class_exists('Filament\Notifications\Notification')) {
        class Notification {
            public static $lastNotification = [];
            public static function make() { return new self; }
            public function title($t) { self::$lastNotification['title'] = $t; return $this; }
            public function body($b) { self::$lastNotification['body'] = $b; return $this; }
            public function danger() { self::$lastNotification['danger'] = true; return $this; }
            public function send() { return $this; }
            public static function flush() { self::$lastNotification = []; }
        }
    }
}

namespace Inertia {
    if (!class_exists('Inertia\Inertia')) {
        class Inertia {
            public static $shared = [];
            public static $rendered = null;
            
            public static function share($data) {
                self::$shared = array_merge(self::$shared, $data);
            }
            
            public static function render($component, $props) {
                self::$rendered = ['component' => $component, 'props' => $props];
                return new class {
                    public function toResponse($req) {
                        return new \Illuminate\Http\Response('inertia');
                    }
                };
            }
            
            public static function flush() {
                self::$shared = [];
                self::$rendered = null;
            }
        }
    }
}

namespace Livewire {
    if (!class_exists('Livewire\Livewire')) {
        class Livewire {}
    }
}

// --- XDEBUG FAKE ---
// Declared in the reporter's own namespace so PHP resolves the unqualified
// xdebug_notify() call there without needing Xdebug installed.
namespace Isaidgitmenow\LaravelErrors\Reporters {
    if (!function_exists('Isaidgitmenow\LaravelErrors\Reporters\xdebug_notify')) {
        /** @param mixed $value */
        function xdebug_notify(mixed $value): void
        {
            XdebugReporterTestSpy::$calls[] = $value;
        }
    }

    if (!class_exists('Isaidgitmenow\LaravelErrors\Reporters\XdebugReporterTestSpy')) {
        /** Collects xdebug_notify() calls for assertions in XdebugReporterTest. */
        class XdebugReporterTestSpy
        {
            /** @var array<int, mixed> */
            public static array $calls = [];

            public static function flush(): void
            {
                self::$calls = [];
            }
        }
    }
}
