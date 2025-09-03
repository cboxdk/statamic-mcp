<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Validators;

use Cboxdk\StatamicMcp\Mcp\Tools\Strategies\SyntaxValidationStrategy;
use Cboxdk\StatamicMcp\Mcp\Tools\Strategies\TagValidationStrategy;
use Cboxdk\StatamicMcp\Mcp\Tools\Strategies\ValidationStrategy;

class AntlersValidator
{
    /**
     * @var ValidationStrategy[]
     */
    private array $strategies = [];

    public function __construct()
    {
        $this->loadDefaultStrategies();
    }

    /**
     * Add a validation strategy.
     */
    public function addStrategy(ValidationStrategy $strategy): self
    {
        $this->strategies[] = $strategy;

        return $this;
    }

    /**
     * Remove a strategy by name.
     */
    public function removeStrategy(string $strategyName): self
    {
        $this->strategies = array_filter(
            $this->strategies,
            fn (ValidationStrategy $strategy) => $strategy->getName() !== $strategyName
        );

        return $this;
    }

    /**
     * Validate template using all applicable strategies.
     *
     * @param  array<string, mixed>  $context
     *
     * @return array<string, mixed>
     */
    public function validate(string $template, array $context = []): array
    {
        $content = $this->parseTemplate($template);
        $errors = [];
        $warnings = [];

        foreach ($this->strategies as $strategy) {
            if ($strategy->appliesTo($content, $context)) {
                $results = $strategy->validate($content, $context);

                foreach ($results as $result) {
                    if (($result['severity'] ?? 'error') === 'error') {
                        $errors[] = $result;
                    } else {
                        $warnings[] = $result;
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => [
                'total_errors' => count($errors),
                'total_warnings' => count($warnings),
                'strategies_applied' => count(array_filter(
                    $this->strategies,
                    fn (ValidationStrategy $strategy) => $strategy->appliesTo($content, $context)
                )),
            ],
        ];
    }

    /**
     * Load default validation strategies.
     */
    private function loadDefaultStrategies(): void
    {
        $this->strategies = [
            new SyntaxValidationStrategy,
            new TagValidationStrategy,
        ];
    }

    /**
     * Parse template into analyzable content.
     *
     * @return array<string, mixed>
     */
    private function parseTemplate(string $template): array
    {
        return [
            'template' => $template,
            'tags' => $this->extractTags($template),
            'lines' => explode("\n", $template),
        ];
    }

    /**
     * Extract Antlers tags from template.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractTags(string $template): array
    {
        $tags = [];
        $lines = explode("\n", $template);

        foreach ($lines as $lineNumber => $line) {
            // Match Antlers tags: {{ ... }}
            if (preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $match) {
                    $tagContent = trim($match[0]);
                    $position = $match[1];

                    $tag = $this->parseTagContent($tagContent);
                    $tag['line'] = $lineNumber + 1;
                    $tag['column'] = $position + 1;
                    $tag['raw'] = '{{ ' . $tagContent . ' }}';

                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    /**
     * Parse individual tag content.
     *
     * @return array<string, mixed>
     */
    private function parseTagContent(string $content): array
    {
        $tag = [
            'type' => 'unknown',
            'name' => '',
            'params' => [],
            'modifiers' => [],
            'is_closing' => false,
            'is_self_closing' => false,
            'condition' => null,
        ];

        // Check for closing tag
        if (str_starts_with($content, '/')) {
            $tag['is_closing'] = true;
            $content = substr($content, 1);
        }

        // Split by pipes to separate modifiers
        $parts = explode('|', $content);
        $mainPart = trim(array_shift($parts));
        $tag['modifiers'] = array_map('trim', $parts);

        // Parse main part (tag name and parameters)
        if (preg_match('/^(\w+)(?::(\w+))?(.*)$/', $mainPart, $matches)) {
            $tag['name'] = $matches[1];
            if (! empty($matches[2])) {
                $tag['name'] .= ':' . $matches[2];
            }

            // Parse parameters
            $paramString = trim($matches[3]);
            if ($paramString) {
                $tag['params'] = $this->parseParameters($paramString);
            }
        } else {
            $tag['name'] = $mainPart;
        }

        // Determine tag type
        $tag['type'] = $this->determineTagType($tag);

        return $tag;
    }

    /**
     * Parse parameter string.
     *
     * @return array<string, mixed>
     */
    private function parseParameters(string $params): array
    {
        $parameters = [];

        // Match key="value" or key='value' patterns
        if (preg_match_all('/(\w+)=["\']([^"\']*)["\']/', $params, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parameters[$match[1]] = $match[2];
            }
        }

        // Match key=value patterns (without quotes)
        if (preg_match_all('/(\w+)=([^\s]+)/', $params, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (! isset($parameters[$match[1]])) {
                    $parameters[$match[1]] = $match[2];
                }
            }
        }

        // Handle boolean/flag parameters: just "param"
        $words = explode(' ', $params);
        foreach ($words as $word) {
            $word = trim($word);
            if (! empty($word) && ! str_contains($word, '=') && ! isset($parameters[$word])) {
                $parameters[$word] = true; // Flag parameter
            }
        }

        return $parameters;
    }

    /**
     * Determine tag type.
     *
     * @param  array<string, mixed>  $tag
     */
    private function determineTagType(array $tag): string
    {
        $name = $tag['name'];

        // Control structures
        if (in_array($name, ['if', 'unless', 'elseif', 'else'])) {
            return 'conditional';
        }

        if (in_array($name, ['foreach', 'for', 'while'])) {
            return 'loop';
        }

        // Statamic tags
        if (str_contains($name, ':')) {
            return 'namespaced_tag';
        }

        // Variables/fields
        return 'variable';
    }
}
