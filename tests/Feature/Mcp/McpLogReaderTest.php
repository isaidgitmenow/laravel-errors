<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use Isaidgitmenow\LaravelErrors\Mcp\McpLogReader;
use Isaidgitmenow\LaravelErrors\Mcp\McpLogger;
use Illuminate\Support\Facades\File;
use RuntimeException;

describe('McpLogReader', function () {
    beforeEach(function () {
        $logPath = storage_path('framework/mcp/.errors-mcp.jsonl');
        if (File::exists($logPath)) {
            File::delete($logPath);
        }
    });

    it('returns empty array when log file does not exist', function () {
        $logPath = storage_path('framework/mcp/.errors-mcp.jsonl');
        $reader = new McpLogReader($logPath);
        
        expect($reader->tail())->toBeArray()->toBeEmpty();
        expect($reader->search('test'))->toBeArray()->toBeEmpty();
    });

    it('reads the tail of the log file', function () {
        $logPath = storage_path('framework/mcp/.errors-mcp.jsonl');
        for ($i = 1; $i <= 15; $i++) {
            McpLogger::log(new RuntimeException("Error {$i}"));
        }

        $reader = new McpLogReader($logPath);
        $tail = $reader->tail(10);

        expect($tail)->toBeArray()->toHaveCount(10);
        
        // Latest entry should be at the end of the array, or wait... 
        // readLines appends to array, array_slice takes from end.
        // Array slice keeps order, so the last element is the newest.
        expect($tail[9]['message'])->toBe('Error 15');
        expect($tail[0]['message'])->toBe('Error 6');
    });

    it('searches for literals and JSON escaped strings', function () {
        $logPath = storage_path('framework/mcp/.errors-mcp.jsonl');
        McpLogger::log(new RuntimeException('Standard Error'));
        McpLogger::log(new \LogicException('App\Exceptions\SpecificError'));

        $reader = new McpLogReader($logPath);
        
        $results1 = $reader->search('Standard');
        expect($results1)->toHaveCount(1);
        expect($results1[0]['message'])->toBe('Standard Error');

        $results2 = $reader->search('App\Exceptions\SpecificError');
        expect($results2)->toHaveCount(1);
        expect($results2[0]['message'])->toBe('App\Exceptions\SpecificError');
        
        $results3 = $reader->search('LogicException');
        expect($results3)->toHaveCount(1);
        expect($results3[0]['class'])->toBe(\LogicException::class);
    });
    
    it('caps search results to 20', function () {
        $logPath = storage_path('framework/mcp/.errors-mcp.jsonl');
        for ($i = 1; $i <= 25; $i++) {
            McpLogger::log(new RuntimeException("Cap Me"));
        }

        $reader = new McpLogReader($logPath);
        $results = $reader->search('Cap Me');

        expect($results)->toHaveCount(20);
    });
});
