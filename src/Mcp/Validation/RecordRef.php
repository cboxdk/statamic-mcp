<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Validation;

/**
 * Identifies the record a finding was raised against.
 *
 * Carried through the validation walk so a finding can name its origin without
 * every method threading three loose strings.
 */
readonly class RecordRef
{
    public function __construct(
        public RecordType $type,
        public string $id,
        public ?string $locale = null,
    ) {}

    /**
     * @return array{record_type: string, record_id: string, locale: string|null}
     */
    public function toArray(): array
    {
        return [
            'record_type' => $this->type->value,
            'record_id' => $this->id,
            'locale' => $this->locale,
        ];
    }
}
