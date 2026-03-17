<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP\Concerns;

use Statamic\Facades\User;

trait ResolvesUserId
{
    /**
     * Resolve the current authenticated user's ID.
     */
    private function resolveUserId(): string
    {
        $user = User::current();

        /** @var string $userId */
        $userId = $user ? $user->id() : '';

        return $userId;
    }
}
