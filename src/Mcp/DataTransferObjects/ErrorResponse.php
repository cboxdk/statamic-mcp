<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

class ErrorResponse extends BaseResponse
{
    /**
     * @param  string|array<int, string>  $errors
     * @param  array<int, string>  $warnings
     * @param  list<string>|null  $validValues
     */
    public function __construct(
        string|array $errors,
        ResponseMeta $meta,
        mixed $data = null,
        array $warnings = [],
        public readonly ErrorCode $code = ErrorCode::Validation,
        public readonly ?string $field = null,
        public readonly ?array $validValues = null,
        public readonly ?string $hint = null,
    ) {
        $errorArray = is_string($errors) ? [$errors] : $errors;

        parent::__construct(
            success: false,
            data: $data,
            meta: $meta,
            errors: $errorArray,
            warnings: $warnings
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'error' => array_filter([
                'code' => $this->code->value,
                'message' => implode('; ', $this->errors),
                'field' => $this->field,
                'valid_values' => $this->validValues,
                'hint' => $this->hint,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }
}
