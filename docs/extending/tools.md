---
title: "Tools"
description: "Serve your own MCP tools from this server, behind the same tokens, permissions and audit log"
weight: 5
---

# Extending tools

A package or a site can add its own tools to this server instead of running a second
MCP server. They are served from the same endpoint, reached with the same tokens, and
recorded in the same audit log as the built-in ones.

## Register an integration

Use `StatamicMcp::integration()` for a package with its own domain and scopes.
Register during your service provider's `boot()` (or Statamic's `booted` callback),
before the registries freeze:

```php
use Cboxdk\StatamicMcp\StatamicMcp;

StatamicMcp::integration('vendor/notes')
    ->scope('notes:read', 'Read notes', ['view notes'])
    ->scope('notes:write', 'Edit notes', ['edit notes'])
    ->router(NotesRouter::class, version: 1, approvals: ['replace'])
    ->observer(MetricsObserver::class);
```

The package must register those Statamic permissions itself. Every permission
listed for a scope is required; an empty list means super administrators only.
Each scope belongs to one package. Integrations cannot replace a built-in domain,
another router or another integration's scope.

`NotesRouter` extends `BaseRouter`, just like the core routers. It declares its
domain, input schema, resource types, actions and handler. Each action declares
its arguments and adds its owned scopes and **success-data schema**, without the
standard response envelope:

```php
RouterAction::edit('update', 'Edit a note.', needs: ['handle', 'data'], optional: ['site'])
    ->withContract(['notes:write'], [
        'type' => 'object',
        'properties' => ['title' => ['type' => 'string']],
        'additionalProperties' => false,
    ]);
```

Use `needs: []` for an action taking no arguments. Declare argument types in
`defineSchema()` and include `parent::defineSchema($schema)`. Inputs are validated
before the handler. Successful results are filtered by `denied_fields` and
validated against the action's output schema before completion is recorded.
Fields an operator may hide must not be required by that schema. A bad result
after a saved write is a failed operation with committed effects, never permission
to repeat the write.

The server presents the router as `-read` and `-write` (or `-admin`) tools. It
supplies the annotations, approval and idempotency arguments, response envelope,
replay flags and bounded successful write receipts. Implement
`discoveryKeywords()` to make the router discoverable by intent.

### Authorize the exact resource

Integration routers must implement `authorizeAction(array $arguments): void`.
Resolve the named resource, verify its container and site, and check its native
policy with `Statamic\Facades\User::current()`. Throw
`Illuminate\Auth\Access\AuthorizationException` if access is refused. The method
must not write. A scope alone does not authorize every record.

Both HTTP and stdio calls run as the grant's Statamic user, restoring the previous
identity afterwards. Authorization runs before approvals and replay; grant,
identity and resource authorization run again immediately before each
`persistStatamic()` write. Always persist through that helper, not a direct
`save()`. A read action cannot use it to write. Installed PHP is trusted code;
this API does not sandbox an addon that bypasses the helper.

The configured resource allowlists and denied fields use the integration's
domain. Override `resolveResourceHandle()` when the default argument precedence
does not identify your resource. Lists must authorize and filter each item before
returning it; one list-level check cannot authorize its records.

### Consent is tied to the contract

New scopes and actions require the owner to approve them through the token picker
or OAuth consent. Old tokens never gain them through a matching scope string or
full-access preset. Consent records the registered scopes and action contracts.
Changing the router's input schema, action declaration, output schema, approval
defaults or explicit version invalidates affected consent. Increment `version`
when handler semantics change without a declaration change. Re-authorize the
client after deploying such changes; do not rewrite stored grants to match.

Approvals bind the exact call and contract. Idempotency binds the user and stable
grant identity, including stdio. Credential rotation under that grant can replay
the result; another user's identical key is a different operation.

### Declare deferred effects

```php
use Cboxdk\StatamicMcp\Integration\Effects;

$this->persistStatamic(fn () => $record->save(), new Effects(
    stache: true,
    static: false,
    glide: false,
    staticUrls: ['https://example.com/notes'],
    searchIndexes: ['notes'],
));
```

Effects drain after the call finishes, also if it throws after saving. Stache uses
the configured repair mode. Static URLs are invalidated, never fetched. A named
search index is rebuilt for all its configured locales; this is a full rebuild,
so request it only when native Statamic events do not maintain that index.
`glide: true` clears generated images. By default, `Effects` requests Stache and
full static invalidation. Existing `persistStatamic($write, ['stache', 'static'])`
calls remain supported. Deferred cache failures are reported server-side and do
not undo a saved write.

The same integration builder has `fieldtype($handle, $spec, $sanitizer)` for the
[fieldtype contract](fieldtypes.md), and `observer($class)` for
[lifecycle events](lifecycle-events.md). Integration fieldtype ownership is unique
and frozen at boot. Observers cannot veto a call.

## Compatibility: register an extra router

An extra tool must extend `Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter`. Anything else is
refused when it is registered, because the router is what applies the checks below.

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Mcp;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
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

    protected function actions(): array
    {
        return [RouterAction::read('get', 'Get the health report')];
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

`registerTool()` and `extra_tools` are compatibility adapters to the integration
registry. They do not register custom scopes or grant consent. Use them for an
existing router in a built-in domain, or move to `integration()` for a new domain.
Every tool is listed by default. When `catalog.searchable` is enabled, searchable
tools are reached through `search_tools`; `searchable: false` keeps a compatibility
tool in the initial catalog. `statamic-discover` reports both kinds.

## Be recommended by discovery

`statamic-discover` matches a client's intent against keywords and recommends the
tools that score. Name the words that should send a client to your tool:

```php
/** @return list<string> */
public function discoveryKeywords(): array
{
    return ['health', 'uptime', 'site status'];
}
```

Each keyword found in the lower-cased intent adds a point, the same scoring the built-in
routers use, and recommendations are ranked by points (a tie keeps the built-in router
first). The recommendation's reason is the first sentence of your tool's description;
override `discoveryReason()` to say something else. A tool that declares no keywords is
never recommended, and one switched off with `statamic.mcp.tools.{domain}.enabled` is
left out like it is from the catalog. A keyword counts once however often it is listed,
and a tool whose `discoveryKeywords()` or `discoveryReason()` throws is left out rather
than failing discovery.

## How it is gated

Exactly like a built-in router, in the same order:

1. **Enabled** — `statamic.mcp.tools.{domain}.enabled`.
2. **Token scope** — by the action's declared effect: `{domain}:read` for a read, the
   domain's write scope (`{domain}:admin` where there is one, else `{domain}:write`) for
   anything else, plus `{domain}:publish` or `{domain}:delete` for a publish or delete
   effect in a domain that has them. A domain this package has no scope for
   (`site-health`, say) is refused for every token.
3. **Resource policy** — by domain, as configured.
4. **Statamic permission** — whatever `getRequiredPermissions()` returns. If it returns
   nothing, only a super admin may call the tool. Name the permission your tool
   actually needs; `['access cp']` admits every Control Panel user.
5. **Approval gate** — by domain and action, as configured: the call waits for the person it acts as to approve it in the Control Panel.
6. **Audit log** — every call.

The two fail-closed rules matter because a third-party router that forgets to declare
a scope or a permission would otherwise be open to every token and every Control Panel
user.

Each action is declared in `actions()` with its effect: `RouterAction::read()`,
`edit()`, `publish()`, `delete()` or `admin()`. The effect, not the action's name, decides
whether the call writes, which scopes it needs and what the approval shows. An action that
is not declared is refused before anything else runs, and so is a `resource_type` the
action's declaration does not list.
