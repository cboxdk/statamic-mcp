<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Contracts;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;

interface Authorizer
{
    /** Revalidate the actor, grant and installation profile; throw on denial. */
    public function authorize(ActionDefinition $action, ExecutionContext $context): void;
}
