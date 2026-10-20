---
title: "Tools"
description: "Serve your own MCP tools from this server, behind the same tokens, permissions and audit log"
weight: 5
---

# Extending tools

A package or a site can add its own tools to this server instead of running a second
MCP server. They are served from the same endpoint, reached with the same tokens, and
recorded in the same audit log as the built-in ones.

## Write a router

An extra tool must extend `Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter`. Anything else is
refused when it is registered, because the router is what applies the checks below.

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Mcp;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('site-health')]
#[Description('Report the health of this site. Actions: get.')]
class SiteHealthRouter extends BaseRouter
{
    protected function getDomain(): string
    {
        return 'system';
    }

    /** @return array<string, string> */
    protected function getActions(): array
    {
        return ['get' => 'Get the health report'];
    }

    /** @return array<string, string> */
    protected function getTypes(): array
    {
        return [];
    }

    /** @return array<int, string> */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        return ['access utilities'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        return ['healthy' => true];
    }
}
```

## Register it

From your service provider's `boot()`:

```php
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;

StatamicMcpServer::registerTool(SiteHealthRouter::class);                   // via search_tools
StatamicMcpServer::registerTool(OtherRouter::class, searchable: false);     // listed at connect time
```

Or, without code, in `config/statamic/mcp.php`:

```php
'extra_tools' => [
    SiteHealthRouter::class,
    ['class' => OtherRouter::class, 'searchable' => false],
],
```

Searchable tools cost a client nothing until it asks for them; they are named in the
server's instructions and found with `search_tools`. List a tool at connect time only
when most sessions will use it. `statamic-system-discover` and `statamic-system-schema`
both report extra tools.

## How it is gated

Exactly like a built-in router, in the same order:

1. **Enabled** — `statamic.mcp.tools.{domain}.enabled`.
2. **Token scope** — `{domain}:read` or `{domain}:write`, by action. A domain this
   package has no scope for (`site-health`, say) requires a full-access `*` token.
3. **Resource policy** — by domain, as configured.
4. **Statamic permission** — whatever `getRequiredPermissions()` returns. If it returns
   nothing, only a super admin may call the tool. Name the permission your tool
   actually needs; `['access cp']` admits every Control Panel user.
5. **Confirmation gate** — by domain and action, as configured.
6. **Audit log** — every call.

The two fail-closed rules matter because a third-party router that forgets to declare
a scope or a permission would otherwise be open to every token and every Control Panel
user.

A write action is any action whose name is one of the write verbs the package knows
(`create`, `update`, `delete`, `publish`, `upload`, …); everything else is checked as a
read.
