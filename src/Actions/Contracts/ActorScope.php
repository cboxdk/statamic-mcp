<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Contracts;

use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Closure;

interface ActorScope
{
    /**
     * Run with the verified actor visible to native policies and events, restoring
     * the caller's authentication context even if the callback throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     *
     * @return T
     */
    public function run(ExecutionContext $context, Closure $callback): mixed;
}
