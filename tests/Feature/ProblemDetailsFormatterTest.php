<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Support\ProblemDetailsFormatter;
use RuntimeException;

describe('ProblemDetailsFormatter', function () {
    it('formats exception into problem details structure', function () {
        $e = new RuntimeException('Server Error');
        $request = Request::create('/api/resource', 'GET');
        
        $formatter = new ProblemDetailsFormatter();
        $data = $formatter->format($e, $request, 500, 'Server Error');
        
        expect($data)->toHaveKeys(['title', 'status', 'type', 'instance', 'error_id']);
        expect($data['status'])->toBe(500);
        expect($data['error_id'])->toBeString();
    });
});
