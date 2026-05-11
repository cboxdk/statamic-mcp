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

        // Cast to string: Statamic's User->id() returns string under the file
        // users driver but int under the Eloquent driver. The trait's return
        // type contract (and downstream token storage) requires string.
        return $user ? (string) $user->id() : '';
    }
}
