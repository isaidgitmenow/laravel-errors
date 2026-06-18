<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use InvalidArgumentException;
use RuntimeException;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\ResourceHandler;

describe('ResourceHandler', function () {
    it('returns a list of available resources', function () {
        $handler = new ResourceHandler();
        $resources = $handler->list();

        expect($resources)->toBeArray();
        
        $uris = array_column($resources, 'uri');
        expect($uris)->toContain('errors://environment', 'errors://rules/standards');
        
        // Assert schema compliance
        foreach ($resources as $resource) {
            expect($resource)->toHaveKey('uri')
                ->and($resource)->toHaveKey('name')
                ->and($resource)->toHaveKey('description')
                ->and($resource)->toHaveKey('mimeType');
        }
    });

    it('reads the environment resource correctly', function () {
        $handler = new ResourceHandler();
        $resource = $handler->read('errors://environment');

        expect($resource)->toHaveKey('uri')
            ->and($resource)->toHaveKey('mimeType')
            ->and($resource)->toHaveKey('text')
            ->and($resource['mimeType'])->toBe('application/json');

        $data = json_decode($resource['text'], true);
        expect($data)->toBeArray()
            ->and($data)->toHaveKey('php_version')
            ->and($data)->toHaveKey('laravel_version')
            ->and($data)->toHaveKey('environment');
    });

    it('reads the rules standards resource correctly', function () {
        $handler = new ResourceHandler();
        $resource = $handler->read('errors://rules/standards');

        expect($resource['uri'])->toBe('errors://rules/standards')
            ->and($resource['mimeType'])->toBe('text/markdown')
            ->and($resource['text'])->toContain('# Laravel-Errors Coding Standards')
            ->and($resource['text'])->toContain('SOLID Principles');
    });

    it('reads dynamic documentation correctly', function () {
        $handler = new ResourceHandler();
        // Assuming 01-installation.md exists in the docs folder based on typical structure.
        // We will mock the file_get_contents or directory traversal if needed, 
        // but typically the package has docs/ folder with some markdown.
        $resources = $handler->list();
        $docUris = array_filter(array_column($resources, 'uri'), fn ($uri) => str_starts_with($uri, 'errors://docs/'));
        
        if (count($docUris) > 0) {
            $uri = reset($docUris);
            $resource = $handler->read($uri);
            
            expect($resource['uri'])->toBe($uri)
                ->and($resource['mimeType'])->toBe('text/markdown')
                ->and($resource['text'])->not->toBeEmpty();
        } else {
            // Skip if no docs folder exists during testing
            $this->markTestSkipped('No documentation files found to test dynamic reading.');
        }
    });

    it('throws InvalidArgumentException for unknown resource uri', function () {
        $handler = new ResourceHandler();
        $handler->read('errors://unknown/resource');
    })->throws(InvalidArgumentException::class, 'Unknown resource URI: errors://unknown/resource');

    it('throws InvalidArgumentException for unknown doc slug', function () {
        $handler = new ResourceHandler();
        $handler->read('errors://docs/does-not-exist-12345');
    })->throws(InvalidArgumentException::class, 'Documentation file not found: does-not-exist-12345');
});
