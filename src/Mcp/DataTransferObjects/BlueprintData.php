<?php

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

use JsonSerializable;

final readonly class BlueprintData implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $tabs
     * @param  array<string, mixed>  $sections
     */
    public function __construct(
        public string $handle,
        public string $title,
        public string $namespace,
        public array $fields,
        public ?string $path = null,
        public array $tabs = [],
        public array $sections = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'handle' => $this->handle,
            'title' => $this->title,
            'namespace' => $this->namespace,
            'path' => $this->path,
            'fields' => $this->fields,
            'tabs' => $this->tabs,
            'sections' => $this->sections,
        ];
    }
}
