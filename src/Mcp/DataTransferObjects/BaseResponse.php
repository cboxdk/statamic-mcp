<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

use JsonSerializable;

abstract class BaseResponse implements JsonSerializable
{
    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data,
        public readonly ResponseMeta $meta,
        public readonly array $errors = [],
        public readonly array $warnings = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $response = [
            'success' => $this->success,
            'data' => $this->data,
            'meta' => $this->meta->jsonSerialize(),
        ];

        if (! empty($this->errors)) {
            $response['errors'] = $this->errors;
        }

        if (! empty($this->warnings)) {
            $response['warnings'] = $this->warnings;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
