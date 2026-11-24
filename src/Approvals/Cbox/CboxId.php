<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals\Cbox;

/** Whether Cbox ID's client is installed with a client this site may use for CIBA. */
final class CboxId
{
    public const string BACKCHANNEL = 'Cbox\Id\Client\Contracts\BackchannelAuthentication';

    public static function installed(): bool
    {
        return interface_exists(self::BACKCHANNEL);
    }

    public static function available(): bool
    {
        return self::installed()
            && is_string(config('cbox-id-client.client_id')) && config('cbox-id-client.client_id') !== '';
    }
}
