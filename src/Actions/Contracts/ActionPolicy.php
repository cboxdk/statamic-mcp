<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Contracts;

use Cboxdk\StatamicMcp\Actions\ExecutionContext;

interface ActionPolicy
{
    /** Authorize the resolved resource and site; throw on denial.
     * @param  array<string, mixed>  $input
     */
    public function authorize(array $input, ExecutionContext $context): void;

    /** Apply current field visibility, including on cached result replay.
     * Must be pure and idempotent; never perform a write.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    public function filterResult(array $input, array $result, ExecutionContext $context): array;
}
