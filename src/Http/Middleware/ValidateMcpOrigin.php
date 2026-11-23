<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Laravel\Mcp\Server\Middleware\ValidateOrigin;

/**
 * laravel/mcp's Origin check, extended with the addon's own allowlist.
 *
 * Since laravel/mcp 1.2, Mcp::web() refuses any request whose Origin is not
 * localhost, app.url or in `mcp.allowed_origins`. Origins an operator listed
 * in `statamic.mcp.web.allowed_origins` were answered 403 before CORS could
 * matter, so every browser client this addon was configured to accept was
 * locked out. The two lists now agree: an origin HandleMcpCors would answer
 * with Access-Control-Allow-Origin also passes here.
 *
 * ServiceProvider::registerWebMcp() swaps it in for ValidateOrigin on this
 * addon's routes only, so other MCP servers in the app keep the library's
 * behaviour.
 */
class ValidateMcpOrigin extends ValidateOrigin
{
    protected function allowed(string $origin): bool
    {
        return parent::allowed($origin) || HandleMcpCors::allowsOrigin($origin);
    }
}
