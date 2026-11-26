<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

use JsonSerializable;

readonly class ResponseMeta implements JsonSerializable
{
    public function __construct(
        public string $tool,
        public string $timestamp,
    ) {}

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return [
            'tool' => $this->tool,
            'timestamp' => $this->timestamp,
        ];
    }
}
