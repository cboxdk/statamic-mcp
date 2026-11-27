<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals\Cbox;

/** Whether Cbox Relay's client is installed and configured on this site. */
final class CboxRelay
{
    public const string CLIENT = 'Cbox\Relay\Client\Relay';

    public static function available(): bool
    {
        return class_exists(self::CLIENT)
            && is_string(config('relay.key')) && config('relay.key') !== '';
    }

    /** The reference a person's browsers are subscribed under. */
    public static function pushRef(string $userId): string
    {
        return 'statamic-mcp:user:' . $userId;
    }
}
