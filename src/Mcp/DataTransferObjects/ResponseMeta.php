<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

use JsonSerializable;

final readonly class ResponseMeta implements JsonSerializable
{
    public function __construct(
        public string $tool,
        public string $timestamp,
        public ?string $statamic_version = null,
        public ?string $laravel_version = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'tool' => $this->tool,
            'timestamp' => $this->timestamp,
            'statamic_version' => $this->statamic_version,
            'laravel_version' => $this->laravel_version,
        ], fn (mixed $value): bool => $value !== null);
    }
}
