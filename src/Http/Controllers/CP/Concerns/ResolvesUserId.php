<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP\Concerns;

use Cboxdk\StatamicMcp\Support\UserIds;
use Statamic\Facades\User;

trait ResolvesUserId
{
    /**
     * Resolve the current authenticated user's ID.
     */
    private function resolveUserId(): string
    {
        $user = User::current();

        return $user ? UserIds::normalize($user->id()) : '';
    }
}
