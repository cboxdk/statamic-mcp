<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp;

use Cboxdk\StatamicMcp\Integration\Integration;

/** Package-owned registrations, made from a service provider's boot method. */
final class StatamicMcp
{
    public static function integration(string $package): Integration
    {
        return new Integration($package);
    }
}
