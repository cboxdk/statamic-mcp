<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Transport;

use Cboxdk\StatamicMcp\Support\WireInputBudget;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Registrar;

/** SDK adapter: customize only this package's local handle, preserving host servers. */
final class BoundedLocalRegistrar extends Registrar
{
    /** @param class-string<Server> $server */
    public static function register(Registrar $registrar, string $handle, string $server): void
    {
        $registrar->localServers[$handle] = static fn (): mixed => self::startServer($server,
            static fn (): BoundedStdioTransport => new BoundedStdioTransport(app(WireInputBudget::class)));
    }
}
