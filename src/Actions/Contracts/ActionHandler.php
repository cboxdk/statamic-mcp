<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Contracts;

use Cboxdk\StatamicMcp\Actions\ExecutionContext;

interface ActionHandler
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input, ExecutionContext $context): array;
}
