<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Strategies;

class SyntaxValidationStrategy implements ValidationStrategy
{
    /**
     * Validate syntax issues.
     */
    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $context
     *
     * @return list<array<string, mixed>>
     */
    public function validate(array $content, array $context = []): array
    {
        $errors = [];
        $template = $content['template'] ?? '';

        // Check for unclosed tags
        $errors = array_merge($errors, $this->checkUnclosedTags($template));

        // Check for malformed tags
        $errors = array_merge($errors, $this->checkMalformedTags($template));

        // Check for nested tag issues
        $errors = array_merge($errors, $this->checkNestedTags($template));

        // Check for quote matching
        $errors = array_merge($errors, $this->checkQuoteMatching($template));

        return $errors;
    }

    /**
     * Get the strategy name.
     */
    public function getName(): string
    {
        return 'syntax_validation';
    }

    /**
     * Check if this strategy applies.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $context
     */
    public function appliesTo(array $content, array $context = []): bool
    {
        return isset($content['template']) && is_string($content['template']);
    }

    /**
     * Check for unclosed tags.
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function checkUnclosedTags(string $template): array
    {
        $errors = [];
        $openTags = [];
        $lines = explode("\n", $template);

        foreach ($lines as $lineNumber => $line) {
            // Check for malformed tags (opening braces without closing braces)
            if (preg_match('/\{\{[^}]*$/', $line)) {
                $errors[] = [
                    'type' => 'syntax_error',
                    'code' => 'unclosed_tag',
                    'message' => 'Unclosed Antlers tag detected - missing closing }}',
                    'line' => $lineNumber + 1,
                    'column' => strpos($line, '{{') + 1,
                    'severity' => 'error',
                ];
            }

            // Find all complete tags in this line
            if (preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $match) {
                    $tagContent = trim($match[0]);
                    $position = $match[1];

                    if (str_starts_with($tagContent, '/')) {
                        // Closing tag
                        $tagName = $this->extractTagName(substr($tagContent, 1));
                        if (! empty($openTags) && end($openTags)['name'] === $tagName) {
                            array_pop($openTags);
                        } else {
                            $errors[] = [
                                'type' => 'syntax_error',
                                'code' => 'unmatched_closing_tag',
                                'message' => "Closing tag '{$tagName}' has no matching opening tag",
                                'line' => $lineNumber + 1,
                                'column' => $position + 1,
                                'severity' => 'error',
                            ];
                        }
                    } else {
                        // Opening tag - check if it's a self-closing tag
                        $tagName = $this->extractTagName($tagContent);
                        if ($this->requiresClosingTag($tagName)) {
                            $openTags[] = [
                                'name' => $tagName,
                                'line' => $lineNumber + 1,
                                'column' => $position + 1,
                            ];
                        }
                    }
                }
            }
        }

        // Check for tags that were never closed
        foreach ($openTags as $openTag) {
            $errors[] = [
                'type' => 'syntax_error',
                'code' => 'unclosed_tag_pair',
                'message' => "Opening tag '{$openTag['name']}' is never closed",
                'line' => $openTag['line'],
                'column' => $openTag['column'],
                'severity' => 'error',
            ];
        }

        return $errors;
    }

    /**
     * Check for malformed tags.
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function checkMalformedTags(string $template): array
    {
        $errors = [];
        $lines = explode("\n", $template);

        foreach ($lines as $lineNumber => $line) {
            // Check for single opening brace
            if (preg_match('/(?<!\{)\{(?!\{)/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                $errors[] = [
                    'type' => 'syntax_error',
                    'code' => 'malformed_tag',
                    'message' => 'Single opening brace found - Antlers tags require double braces {{ }}',
                    'line' => $lineNumber + 1,
                    'column' => $matches[0][1] + 1,
                    'severity' => 'error',
                ];
            }

            // Check for single closing brace
            if (preg_match('/(?<!\})\}(?!\})/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                $errors[] = [
                    'type' => 'syntax_error',
                    'code' => 'malformed_tag',
                    'message' => 'Single closing brace found - Antlers tags require double braces {{ }}',
                    'line' => $lineNumber + 1,
                    'column' => $matches[0][1] + 1,
                    'severity' => 'error',
                ];
            }

            // Check for empty tags
            if (preg_match('/\{\{\s*\}\}/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                $errors[] = [
                    'type' => 'syntax_error',
                    'code' => 'empty_tag',
                    'message' => 'Empty Antlers tag found',
                    'line' => $lineNumber + 1,
                    'column' => $matches[0][1] + 1,
                    'severity' => 'warning',
                ];
            }
        }

        return $errors;
    }

    /**
     * Check for nested tag issues.
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function checkNestedTags(string $template): array
    {
        $errors = [];

        // Find instances of nested braces (which are invalid in Antlers)
        if (preg_match_all('/\{\{[^}]*\{\{/', $template, $matches, PREG_OFFSET_CAPTURE)) {
            $lines = explode("\n", $template);
            $currentPos = 0;

            foreach ($matches[0] as $match) {
                $position = $match[1];
                $lineNumber = $this->getLineNumber($template, $position);
                $columnNumber = $this->getColumnNumber($template, $position);

                $errors[] = [
                    'type' => 'syntax_error',
                    'code' => 'nested_tags',
                    'message' => 'Nested Antlers tags are not allowed',
                    'line' => $lineNumber,
                    'column' => $columnNumber,
                    'severity' => 'error',
                ];
            }
        }

        return $errors;
    }

    /**
     * Check for quote matching issues.
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function checkQuoteMatching(string $template): array
    {
        $errors = [];

        // Find all Antlers tags
        if (preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $template, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $tagContent = $match[0];
                $position = $match[1];

                // Check for unmatched quotes within the tag
                $quoteErrors = $this->checkQuotesInTag($tagContent, $position, $template);
                $errors = array_merge($errors, $quoteErrors);
            }
        }

        return $errors;
    }

    /**
     * Check quotes within a tag.
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function checkQuotesInTag(string $tagContent, int $position, string $template): array
    {
        $errors = [];
        $singleQuoteCount = substr_count($tagContent, "'");
        $doubleQuoteCount = substr_count($tagContent, '"');

        $lineNumber = $this->getLineNumber($template, $position);
        $columnNumber = $this->getColumnNumber($template, $position);

        if ($singleQuoteCount % 2 !== 0) {
            $errors[] = [
                'type' => 'syntax_error',
                'code' => 'unmatched_quotes',
                'message' => 'Unmatched single quotes in Antlers tag',
                'line' => $lineNumber,
                'column' => $columnNumber,
                'severity' => 'error',
            ];
        }

        if ($doubleQuoteCount % 2 !== 0) {
            $errors[] = [
                'type' => 'syntax_error',
                'code' => 'unmatched_quotes',
                'message' => 'Unmatched double quotes in Antlers tag',
                'line' => $lineNumber,
                'column' => $columnNumber,
                'severity' => 'error',
            ];
        }

        return $errors;
    }

    /**
     * Extract tag name from tag content.
     */
    private function extractTagName(string $tagContent): string
    {
        // Remove parameters and modifiers
        $parts = preg_split('/[\s|:]/', $tagContent, 2);

        return trim($parts[0]);
    }

    /**
     * Check if tag requires a closing tag.
     */
    private function requiresClosingTag(string $tagName): bool
    {
        $pairTags = [
            'if', 'unless', 'foreach', 'for', 'while', 'collection', 'taxonomy',
            'entries', 'users', 'assets', 'terms', 'nav', 'form', 'section',
        ];

        return in_array($tagName, $pairTags);
    }

    /**
     * Get line number from character position.
     */
    private function getLineNumber(string $text, int $position): int
    {
        return substr_count(substr($text, 0, $position), "\n") + 1;
    }

    /**
     * Get column number from character position.
     */
    private function getColumnNumber(string $text, int $position): int
    {
        $lineStart = strrpos(substr($text, 0, $position), "\n");

        return $position - ($lineStart === false ? -1 : $lineStart);
    }
}
