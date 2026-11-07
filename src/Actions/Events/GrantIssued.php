<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

final readonly class GrantIssued extends GrantEvent
{
    protected const NAME = 'grant.issued';
}
