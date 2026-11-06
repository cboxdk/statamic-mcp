<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Contracts;

/** Mandatory v4 capability, including grants whose access token is already gone. */
interface RevokesUserGrants
{
    public function revokeGrantsForUser(string $userId): int;
}
