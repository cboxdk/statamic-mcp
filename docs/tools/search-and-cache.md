---
title: "Search and cache"
description: "Permission-filtered content search and explicit cache maintenance"
---

# Search and cache

## Search the native local index

Call `statamic-system-read` with `action: search`, a configured index `handle`,
and `query`. Optional `site`, `limit` and `cursor` narrow the results.

The call needs `system:read` and Statamic's `access utilities` permission.
Each hit additionally needs its content domain's read scope, resource policy,
native record permission and site permission. Results contain only readable
entries, terms and assets. Counts and pagination apply **after** filtering.
Search does not expose raw indexed fields, snippets or global relevance scores.

If a domain has `denied_fields`, search omits that domain entirely: even a hit's
presence could reveal a protected value. Read the known record through its domain
tool instead. Custom search providers, users and addon records are not exposed.

Only Statamic's built-in local (Comb) driver is supported for reads. Hosted and
custom indexes are refused rather than presenting their first provider page as a
complete result set. A missing index returns `NOT_FOUND`; a read never builds it.

Call `statamic-system-admin` with `action: reindex`, `handle` and optional `site`
to rebuild a configured index. This requires `system:admin`, a super user and
Control Panel approval by default over HTTP. It uses the configured driver,
including hosted drivers. `status: submitted` means the rebuild was requested;
native queues may still be working. MCP content writes dispatch native events,
so configured indexes are updated by Statamic's own listeners.

## Explicit cache operations

These actions use `statamic-system-admin`, `system:admin` and a super user over
HTTP. They support the normal write approval configuration and idempotency key.

- `invalidate_urls`: supply `urls`, a list of absolute URLs inside configured
  sites. The entire list is validated before any page is invalidated. Credentials,
  fragments, wildcards and dot segments are refused. Relative site configuration
  is resolved against `app.url`, never the request's Host header.
- `cache_warm`: `cache_type` is `stache` (default), `static` or `all`. Static
  warming discovers native warmable URLs and queues requests without following
  redirects. It includes pagination when the response advertises it, preserving
  the selected connection and queue for child jobs. `status: queued` is not proof
  that pages have been fetched; a worker must consume the queue.
- `cache_clear`: also accepts `glide`, which invokes Statamic's native Glide
  clear. `all` includes Glide alongside Stache, static, views, app, config and route.

Static warming uses `statamic.static_caching.warm_queue_connection` (falling back
to `queue.default`) and `statamic.static_caching.warm_queue`. Sync, null and unknown
connections are refused. The site operator controls these settings; MCP cannot
change the worker configuration or supply arbitrary fetch URLs.

## Cache behavior after writes

Set `statamic.mcp.cache.static_after_write`, or `STATAMIC_MCP_STATIC_AFTER_WRITE`:

- `full` (effective default): clear static pages after a content write.
- `statamic`: rely on native invalidation rules only. Listing pages and shared
  navigation/global content may remain stale unless your rules cover them.
- `refresh`: clear first, then queue the previously cached URLs. A stopped worker
  leaves a cold cache, not stale pages. This does not discover new pages; use the
  explicit static warmer for that.

When unset, the legacy `clear_static_after_write` switch still selects `full`
(true) or `statamic` (false). With native `rules: all`, an already-empty native
cache does not receive another full flush. For file caching, this also checks the
actual directory: clearing Laravel's URL metadata alone does not remove HTML.

The deferred result is reported in `meta.cache`; refresh dispatch failure is
reported as `static_refresh: failed` without pretending the content write failed.
Stache behavior remains independently configured with `stache_after_write`.
