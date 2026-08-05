<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Validation;

/**
 * One problem found in stored content.
 *
 * Severity is derived from the type rather than passed in, so a finding cannot
 * be built with a severity that contradicts what it describes.
 */
readonly class Finding
{
    public function __construct(
        public FindingType $type,
        public RecordRef $record,
        public string $message,
        public ?string $fieldPath = null,
    ) {}

    public function severity(): Severity
    {
        return $this->type->severity();
    }

    /**
     * Serialize for the MCP response. This is the only place a finding becomes
     * an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity()->value,
            'type' => $this->type->value,
            ...$this->record->toArray(),
            'field_path' => $this->fieldPath,
            'message' => $this->message,
        ];
    }
}
