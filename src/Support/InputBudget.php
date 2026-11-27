<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support;

/** Bounds traversal before field processing. Transport limits must also bound parsing. */
final class InputBudget
{
    public function __construct(
        private readonly int $maxBytes = 16777216,
        private readonly int $maxValues = 20000,
        private readonly int $maxDepth = 64,
    ) {
        if ($maxBytes < 1 || $maxValues < 1 || $maxDepth < 1) {
            throw new \InvalidArgumentException('Input budgets must be positive.');
        }
    }

    /** @param array<string, mixed> $arguments */
    public function check(array $arguments): void
    {
        $bytes = 0;
        $values = 0;
        $this->visit($arguments, 0, $bytes, $values);
    }

    private function visit(mixed $value, int $depth, int &$bytes, int &$values): void
    {
        if ($depth > $this->maxDepth || ++$values > $this->maxValues) {
            throw new \InvalidArgumentException('Tool input exceeds nesting or value-count limits.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $bytes += strlen((string) $key) + 4;
                if (is_string($key) && str_contains($key, "\x00")) {
                    throw new \InvalidArgumentException('Tool input contains invalid null bytes.');
                }
                $this->checkBytes($bytes);
                $this->visit($child, $depth + 1, $bytes, $values);
            }
        } elseif (is_string($value)) {
            $bytes += strlen($value) + 2;
            if (str_contains($value, "\x00")) {
                throw new \InvalidArgumentException('Tool input contains invalid null bytes.');
            }
        } elseif (is_scalar($value) || $value === null) {
            $bytes += 32;
            if (is_float($value) && ! is_finite($value)) {
                throw new \InvalidArgumentException('Tool input contains a non-finite number.');
            }
        } else {
            throw new \InvalidArgumentException('Tool input must contain JSON values only.');
        }
        $this->checkBytes($bytes);
    }

    private function checkBytes(int $bytes): void
    {
        if ($bytes > $this->maxBytes) {
            throw new \InvalidArgumentException('Tool input exceeds its size budget.');
        }
    }
}
