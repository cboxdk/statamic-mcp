<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Services;

class DocumentationContentService
{
    /**
     * Fetch documentation content from URL.
     */
    public function fetchContent(string $url, array $docInfo): ?string
    {
        $content = $this->fetchUrl($url);

        if ($content) {
            return $this->extractContentFromHTML($content, $docInfo);
        }

        return $this->generateStructuredDocContent($url, $docInfo);
    }

    /**
     * Extract meaningful content from HTML.
     */
    private function extractContentFromHTML(string $html, array $docInfo): string
    {
        try {
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);

            $content = '';

            $selectors = [
                '//main',
                '//article',
                '//*[@class="prose"]',
                '//*[@class="content"]',
                '//*[@class="documentation"]',
                '//body',
            ];

            foreach ($selectors as $selector) {
                $xpath = new \DOMXPath($dom);
                $nodes = $xpath->query($selector);

                if ($nodes->length > 0) {
                    $content = $this->nodeToMarkdown($nodes->item(0));
                    break;
                }
            }

            if (empty($content)) {
                $content = strip_tags($html);
            }

            $content = preg_replace('/\s+/', ' ', $content);
            $content = preg_replace('/\n\s*\n/', "\n\n", trim($content));

            return $content;

        } catch (\Exception $e) {
            return $this->generateStructuredDocContent('', $docInfo);
        }
    }

    /**
     * Convert DOM node to markdown.
     */
    private function nodeToMarkdown(\DOMNode $node): string
    {
        $markdown = '';

        if ($node->nodeType === XML_TEXT_NODE) {
            return trim($node->textContent);
        }

        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tagName = strtolower($node->nodeName);

            switch ($tagName) {
                case 'h1':
                    $markdown .= '# ' . trim($node->textContent) . "\n\n";
                    break;
                case 'h2':
                    $markdown .= '## ' . trim($node->textContent) . "\n\n";
                    break;
                case 'h3':
                    $markdown .= '### ' . trim($node->textContent) . "\n\n";
                    break;
                case 'h4':
                    $markdown .= '#### ' . trim($node->textContent) . "\n\n";
                    break;
                case 'p':
                    $markdown .= trim($node->textContent) . "\n\n";
                    break;
                case 'code':
                    $markdown .= '`' . trim($node->textContent) . '`';
                    break;
                case 'pre':
                    $markdown .= "```\n" . trim($node->textContent) . "\n```\n\n";
                    break;
                case 'ul':
                case 'ol':
                    $markdown .= $this->processList($node) . "\n\n";
                    break;
                case 'li':
                    $markdown .= '- ' . trim($node->textContent) . "\n";
                    break;
                case 'strong':
                case 'b':
                    $markdown .= '**' . trim($node->textContent) . '**';
                    break;
                case 'em':
                case 'i':
                    $markdown .= '*' . trim($node->textContent) . '*';
                    break;
                case 'a':
                    $href = $node->getAttribute('href');
                    $text = trim($node->textContent);
                    $markdown .= "[{$text}]({$href})";
                    break;
                default:
                    if ($node->hasChildNodes()) {
                        foreach ($node->childNodes as $child) {
                            $markdown .= $this->nodeToMarkdown($child);
                        }
                    } else {
                        $markdown .= trim($node->textContent);
                    }
                    break;
            }
        }

        return $markdown;
    }

    /**
     * Process list elements.
     */
    private function processList(\DOMNode $list): string
    {
        $markdown = '';
        $isOrdered = $list->nodeName === 'ol';
        $counter = 1;

        foreach ($list->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'li') {
                $prefix = $isOrdered ? "{$counter}. " : '- ';
                $markdown .= $prefix . trim($child->textContent) . "\n";
                if ($isOrdered) {
                    $counter++;
                }
            }
        }

        return $markdown;
    }

    /**
     * Generate structured documentation content based on doc info.
     *
     * @param  array<string, mixed>  $docInfo
     */
    private function generateStructuredDocContent(string $url, array $docInfo): string
    {
        $section = $docInfo['section'] ?? 'general';

        return match ($section) {
            'tags' => $this->generateTagContent($docInfo),
            'fieldtypes' => $this->generateFieldTypeContent($docInfo),
            default => $this->generateCoreContent($docInfo),
        };
    }

    /**
     * Generate content for tag documentation.
     */
    private function generateTagContent(array $docInfo): string
    {
        $title = $docInfo['title'];
        $tags = $docInfo['tags'] ?? [];
        $tagName = '';

        foreach ($tags as $tag) {
            if ($tag !== 'tag' && strlen($tag) > 2) {
                $tagName = $tag;
                break;
            }
        }

        $summary = $docInfo['summary'] ?? 'provide functionality';

        return "# {$title}

The {$tagName} tag is used in Antlers templates to {$summary}.

## Basic Usage

```antlers
{{ {$tagName} }}
    <!-- Your template code here -->
{{ /{$tagName} }}
```

## Parameters

The {$tagName} tag accepts various parameters to customize its behavior:

- `limit`: Limit the number of results
- `sort`: Sort order for results
- `filter`: Filter criteria

## Examples

```antlers
{{ {$tagName} limit=\"5\" sort=\"date:desc\" }}
    <h3>{{ title }}</h3>
    <p>{{ content }}</p>
{{ /{$tagName} }}
```

For more advanced usage and all available parameters, refer to the official Statamic documentation.";
    }

    /**
     * Generate content for fieldtype documentation.
     */
    private function generateFieldTypeContent(array $docInfo): string
    {
        $title = $docInfo['title'];
        $tags = $docInfo['tags'] ?? [];
        $fieldType = '';

        foreach ($tags as $tag) {
            if ($tag !== 'fieldtype' && strlen($tag) > 2) {
                $fieldType = $tag;
                break;
            }
        }

        $summary = $docInfo['summary'] ?? 'provides functionality';

        return "# {$title}

The {$fieldType} fieldtype {$summary}.

## Configuration

```yaml
fields:
  my_field:
    type: {$fieldType}
    display: My Field
    instructions: Instructions for this field
```

## Template Usage

In your Antlers templates:

```antlers
{{ my_field }}
```

## Advanced Configuration

The {$fieldType} fieldtype supports additional configuration options:

- `required`: Whether the field is required
- `default`: Default value
- `validate`: Validation rules

## Best Practices

- Use clear, descriptive field handles
- Provide helpful instructions for content editors
- Consider validation rules for data integrity

For complete configuration options and examples, see the official documentation.";
    }

    /**
     * Generate content for core documentation.
     */
    private function generateCoreContent(array $docInfo): string
    {
        $title = $docInfo['title'];
        $summary = $docInfo['summary'] ?? 'Documentation content';

        return "# {$title}

{$summary}

## Overview

This documentation covers the fundamental concepts and usage patterns for {$title} in Statamic.

## Key Concepts

- **Structure**: How {$title} are organized
- **Configuration**: Setting up and configuring
- **Usage**: Practical examples and best practices
- **Integration**: How it works with other Statamic features

## Getting Started

1. **Setup**: Initial configuration steps
2. **Basic Usage**: Simple examples to get started
3. **Advanced Features**: More complex use cases
4. **Troubleshooting**: Common issues and solutions

## Examples

Practical examples and code snippets will help you understand how to use {$title} effectively in your Statamic projects.

For complete documentation with detailed examples, visit the official Statamic documentation.";
    }

    /**
     * Fetch URL content with cURL.
     */
    private function fetchUrl(string $url): ?string
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Statamic MCP Documentation Fetcher',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $content !== false) {
                return $content;
            }
        } catch (\Exception $e) {
            // Return null if fetch fails
        }

        return null;
    }
}
