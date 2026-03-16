<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

final class SuccessResponse extends BaseResponse
{
    public function __construct(
        mixed $data,
        ResponseMeta $meta,
        array $warnings = []
    ) {
        parent::__construct(
            success: true,
            data: $data,
            meta: $meta,
            errors: [],
            warnings: $warnings
        );
    }
}
