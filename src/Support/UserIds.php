<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support;

class UserIds
{
    public static function normalize(mixed $userId): string
    {
        return is_scalar($userId) || $userId === null ? (string) $userId : '';
    }
}
