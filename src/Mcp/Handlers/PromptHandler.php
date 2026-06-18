<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Mcp\Handlers;

/**
 * Handles MCP prompts/list and prompts/get.
 *
 * Prompts are pre-defined AI instruction templates that reference
 * resources and tools to guide the AI through structured workflows.
 */
final class PromptHandler
{
    /**
     * List all available prompt definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return [
            [
                'name'        => 'refactor_to_solid',
                'description' => 'Refactor the active code to comply with SOLID principles, guided by the package coding standards.',
                'arguments'   => [
                    [
                        'name'        => 'code',
                        'description' => 'The PHP code snippet to refactor',
                        'required'    => true,
                    ],
                ],
            ],
            [
                'name'        => 'generate_ddd_error',
                'description' => 'Generate a DDD-style decorated exception class in the correct domain directory.',
                'arguments'   => [
                    [
                        'name'        => 'domain',
                        'description' => 'The domain name (e.g. Invoicing, Payments)',
                        'required'    => true,
                    ],
                    [
                        'name'        => 'class_name',
                        'description' => 'The exception class name (e.g. PaymentFailed)',
                        'required'    => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Return the rendered messages for a given prompt name and arguments.
     *
     * @param  array<string, string> $arguments
     * @return array<string, mixed>
     */
    public function get(string $name, array $arguments): array
    {
        return match ($name) {
            'refactor_to_solid'  => $this->refactorToSolid($arguments),
            'generate_ddd_error' => $this->generateDddError($arguments),
            default              => throw new \InvalidArgumentException("Unknown prompt: {$name}"),
        };
    }

    // -------------------------------------------------------------------------
    // Prompt Builders
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, string> $args
     * @return array<string, mixed>
     */
    private function refactorToSolid(array $args): array
    {
        $code = trim($args['code'] ?? '');

        if ($code === '') {
            throw new \InvalidArgumentException('refactor_to_solid: "code" argument is required.');
        }

        return [
            'description' => 'Refactor code to SOLID principles',
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => implode("\n\n", [
                            '## Task: Refactor to SOLID Principles',
                            '**Step 1:** Read the coding standards resource at `errors://rules/standards` to understand the exact rules for this package.',
                            '**Step 2:** Analyse the provided code for SOLID violations, focusing on:',
                            '- Single Responsibility: Does each class have only one job?',
                            '- Open/Closed: Is the code extendable without modification?',
                            '- Dependency Inversion: Are dependencies injected via interfaces?',
                            '**Step 3:** Produce the refactored code with inline comments explaining each SOLID improvement.',
                            '',
                            '### Code to Refactor',
                            '```php',
                            $code,
                            '```',
                        ]),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string> $args
     * @return array<string, mixed>
     */
    private function generateDddError(array $args): array
    {
        $domain    = trim($args['domain'] ?? '');
        $className = trim($args['class_name'] ?? '');

        if ($domain === '' || $className === '') {
            throw new \InvalidArgumentException('generate_ddd_error: "domain" and "class_name" arguments are required.');
        }

        return [
            'description' => "Generate DDD exception {$domain}:{$className}",
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => implode("\n\n", [
                            '## Task: Generate a DDD-Style Decorated Exception',
                            '**Step 1:** Read `errors://docs/07-ecosystem-and-commands` to understand the DDD exception generation workflow and available attributes.',
                            "**Step 2:** Use the `generate_error` tool with the following parameters:",
                            "- `name`: `{$className}`",
                            "- `domain`: `{$domain}`",
                            '- Choose appropriate `http` code and `report` channels based on the exception\'s semantic meaning.',
                            '**Step 3:** Report back the generated file path and the class attributes applied.',
                        ]),
                    ],
                ],
            ],
        ];
    }
}
