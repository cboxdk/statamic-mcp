<?php

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

use JsonSerializable;

final readonly class CollectionData implements JsonSerializable
{
    /**
     * @param  array<int, string>  $blueprints
     * @param  array<int, string>  $sites
     * @param  array<string, mixed>  $structure
     */
    public function __construct(
        public string $handle,
        public string $title,
        public array $blueprints = [],
        public array $sites = [],
        public ?string $default_blueprint = null,
        public ?string $path = null,
        public array $structure = [],
        public int $entries_count = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'handle' => $this->handle,
            'title' => $this->title,
            'blueprints' => $this->blueprints,
            'sites' => $this->sites,
            'default_blueprint' => $this->default_blueprint,
            'path' => $this->path,
            'structure' => $this->structure,
            'entries_count' => $this->entries_count,
        ];
    }
}
