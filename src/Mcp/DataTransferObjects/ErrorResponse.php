<?php

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

final class ErrorResponse extends BaseResponse
{
    public function __construct(
        string|array $errors,
        ResponseMeta $meta,
        mixed $data = null,
        array $warnings = []
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
}
