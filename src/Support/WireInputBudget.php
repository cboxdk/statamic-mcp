<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support;

/** Check JSON without allocating the decoded object graph. PHP 8.3+ is required. */
final readonly class WireInputBudget
{
    /** @var positive-int */
    private int $maxDepth;

    public function __construct(
        public int $maxBytes = 16777216,
        private int $maxTokens = 40032,
        int $maxDepth = 72,
    ) {
        if ($maxBytes < 1 || $maxBytes >= PHP_INT_MAX || $maxTokens < 1 || $maxDepth < 1) {
            throw new \InvalidArgumentException('Wire input budgets must be positive.');
        }
        $this->maxDepth = $maxDepth;
    }

    public function check(string $json): void
    {
        if (strlen($json) > $this->maxBytes) {
            throw new \LengthException('MCP request exceeds its byte budget.');
        }
        if (! json_validate($json, $this->maxDepth)) {
            throw new \InvalidArgumentException('Invalid JSON or nesting limit exceeded.');
        }
        $tokens = 0;
        $length = strlen($json);
        for ($offset = 0; $offset < $length;) {
            $char = $json[$offset];
            if ($char === '"') {
                $tokens++;
                $offset = $this->afterString($json, $offset + 1);
            } elseif ($char === '{' || $char === '[') {
                $tokens++;
                $offset++;
            } elseif ($char === 't' || $char === 'f' || $char === 'n') {
                $tokens++;
                $offset += $char === 'f' ? 5 : 4;
            } elseif ($char === '-' || ($char >= '0' && $char <= '9')) {
                $tokens++;
                $offset += strspn($json, '0123456789+-.eE', $offset);
            } elseif ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                $offset += strspn($json, " \t\r\n", $offset);
            } else {
                $offset++;
            }
            if ($tokens > $this->maxTokens) {
                throw new \LengthException('MCP request exceeds its token budget.');
            }
        }
    }

    /** Skip string contents in C-sized jumps; never walk a large base64 value byte by byte. */
    private function afterString(string $json, int $offset): int
    {
        while (($quote = strpos($json, '"', $offset)) !== false) {
            $slashes = 0;
            for ($back = $quote - 1; $back >= 0 && $json[$back] === '\\'; $back--) {
                $slashes++;
            }
            $offset = $quote + 1;
            if ($slashes % 2 === 0) {
                return $offset;
            }
        }
        // json_validate() already proved every string is terminated.
        throw new \InvalidArgumentException('Invalid JSON string.');
    }
}
