<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use InvalidArgumentException;
use Isaidgitmenow\LaravelErrors\Mcp\Handlers\PromptHandler;

describe('PromptHandler', function () {
    it('returns a list of available prompts', function () {
        $handler = new PromptHandler();
        $prompts = $handler->list();

        expect($prompts)->toBeArray();
        expect($prompts)->not->toBeEmpty();
        
        $names = array_column($prompts, 'name');
        expect($names)->toContain('refactor_to_solid', 'generate_ddd_error');
        
        // Assert schema compliance
        foreach ($prompts as $prompt) {
            expect($prompt)->toHaveKey('name')
                ->and($prompt)->toHaveKey('description')
                ->and($prompt)->toHaveKey('arguments')
                ->and($prompt['arguments'])->toBeArray();
        }
    });

    it('returns refactor_to_solid prompt correctly', function () {
        $handler = new PromptHandler();
        $response = $handler->get('refactor_to_solid', ['code' => 'echo "bad";']);

        expect($response)->toHaveKey('description')
            ->and($response)->toHaveKey('messages')
            ->and($response['messages'])->toBeArray()
            ->and($response['messages'][0]['role'])->toBe('user')
            ->and($response['messages'][0]['content']['type'])->toBe('text')
            ->and($response['messages'][0]['content']['text'])->toContain('echo "bad";')
            ->and($response['messages'][0]['content']['text'])->toContain('SOLID');
    });

    it('fails refactor_to_solid when code argument is missing', function () {
        $handler = new PromptHandler();
        $handler->get('refactor_to_solid', []);
    })->throws(InvalidArgumentException::class, 'refactor_to_solid: "code" argument is required');

    it('returns generate_ddd_error prompt correctly', function () {
        $handler = new PromptHandler();
        $response = $handler->get('generate_ddd_error', ['domain' => 'Billing', 'class_name' => 'InvoiceFailed']);

        expect($response)->toHaveKey('description')
            ->and($response)->toHaveKey('messages')
            ->and($response['messages'])->toBeArray()
            ->and($response['messages'][0]['content']['text'])->toContain('InvoiceFailed')
            ->and($response['messages'][0]['content']['text'])->toContain('Billing');
    });

    it('fails generate_ddd_error when required arguments are missing', function () {
        $handler = new PromptHandler();
        $handler->get('generate_ddd_error', ['domain' => 'Billing']);
    })->throws(InvalidArgumentException::class, 'generate_ddd_error: "domain" and "class_name" arguments are required');

    it('throws InvalidArgumentException for unknown prompt', function () {
        $handler = new PromptHandler();
        $handler->get('unknown_prompt', []);
    })->throws(InvalidArgumentException::class, 'Unknown prompt: unknown_prompt');
});
