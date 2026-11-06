<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Contracts;

use Cboxdk\StatamicMcp\Actions\Events\ExecutionEvent;

interface LifecycleObserver
{
    public function observe(ExecutionEvent $event): void;
}
