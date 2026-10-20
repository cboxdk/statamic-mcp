<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support;

/**
 * Whether the current call arrived over the authenticated web MCP endpoint
 * rather than the trusted local stdio server.
 *
 * The web decision must not rest on the process SAPI: an HTTP runtime whose
 * workers report a console SAPI (Octane, Swoole, RoadRunner) would otherwise
 * let an authenticated web request be treated as the trusted local operator,
 * skipping token scope, Statamic permissions and the confirmation gate. The
 * authoritative signal is the `mcp_web_request` attribute that
 * AuthenticateForMcp sets on every request it handles — a server-side marker a
 * client cannot clear. `force_web_mode` and the legacy `X-MCP-Remote` header
 * remain honoured as additional web signals.
 *
 * The stdio server runs no HTTP middleware, so the attribute is absent there
 * and `isCli()` falls back to the console SAPI.
 */
final class McpContext
{
    public const WEB_ATTRIBUTE = 'mcp_web_request';

    public static function isWebRequest(): bool
    {
        if ((bool) config('statamic.mcp.security.force_web_mode', false)) {
            return true;
        }

        $request = request();

        return $request->attributes->get(self::WEB_ATTRIBUTE) === true
            || $request->hasHeader('X-MCP-Remote');
    }

    public static function isCli(): bool
    {
        return ! self::isWebRequest() && app()->runningInConsole();
    }
}
