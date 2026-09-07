<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;

describe('ErrorsMcpCommand', function () {
    it('aborts on non-local environments', function () {
        app()->detectEnvironment(fn () => 'production');
        
        $result = Artisan::call('errors:mcp');
        
        expect($result)->toBe(\Illuminate\Console\Command::FAILURE);
        
        // Reset environment
        app()->detectEnvironment(fn () => 'testing');
    });

});
