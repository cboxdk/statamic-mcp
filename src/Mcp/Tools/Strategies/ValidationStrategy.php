<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Strategies;

interface ValidationStrategy
{
    /**
     * Validate the given content.
     */
    public function validate(array $content, array $context = []): array;

    /**
     * Get the strategy name.
     */
    public function getName(): string;

    /**
     * Check if this strategy applies to the given content.
     */
    public function appliesTo(array $content, array $context = []): bool;
}
