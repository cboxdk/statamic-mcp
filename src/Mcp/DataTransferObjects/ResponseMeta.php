<?php

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

use JsonSerializable;

final readonly class ResponseMeta implements JsonSerializable
{
    public function __construct(
        public string $tool,
        public string $timestamp,
        public string $statamic_version,
        public string $laravel_version,
    ) {}

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return [
            'tool' => $this->tool,
            'timestamp' => $this->timestamp,
            'statamic_version' => $this->statamic_version,
            'laravel_version' => $this->laravel_version,
        ];
    }
}
