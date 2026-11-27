<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

final readonly class OperationResult
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data, public bool $replayed) {}
}
