---
title: "SEO Pro"
description: "Optional reference integration for defaults, redirects, reports and sitemap"
---

# SEO Pro

Install and license `statamic/seo-pro` separately (tested with 7.16.0).
Its MCP integration registers only when the package is installed. It uses native
SEO Pro repositories, validation, events and report generation; it does not
expose arbitrary addon settings or connector credentials.

## Tools and grants

`statamic-seo-pro-read` reads defaults, redirects and recorded 404 errors, and
reads existing reports and the sitemap. `statamic-seo-pro-admin` changes defaults
and redirects, deletes redirects/errors and generates reports. The write facet
is named `-admin` because report generation is an admin action.

- `seo-pro:read`: defaults, redirects and errors.
- `seo-pro:write`: update defaults/redirects and create redirects.
- `seo-pro:delete`: additionally required for deletion **and redirect creation**.
- `seo-pro:site-wide`: reports and sitemap. Report generation also needs write.

Existing tokens gain none of these grants automatically. Integration calls over
stdio also require a consent-bound token. Every call checks native Statamic/SEO
Pro permissions as the token's user. Site reads require that user's site access;
section defaults additionally respect entry/term and structure resource policies.

Redirect creation needs delete authority because SEO Pro removes matching 404
errors when saving a new redirect. It checks `delete seo errors` and write access
to the `error:{site}` resource too. Creation, deletion and report generation need
Control Panel approval by default over HTTP. Standard `idempotency_key` support
prevents retries from repeating the operation or report-retention cleanup.

## Defaults and redirects

Use `get` / `update` with `resource_type: site`, `collection` or `taxonomy`.
Site defaults take `site`; section defaults take `handle`. `update.data` contains
only the fields to change. The native blueprint validates them. Site inheritance
is preserved: inherited values participate in validation, but are not copied into
the locale's stored overrides. Setting a section's `enabled` to false preserves
SEO values on its child entries or terms.

`list`, `get`, `create`, `update` and `delete` support `resource_type: redirect`;
errors support only `list`, `get` and `delete`. Errors come from SEO Pro's native
404 tracking, not from an MCP create action. All these calls require `site`,
and record-addressed calls require `id` from `list`. A record in another site is
not returned or changed, even if the id exists there.

Redirect `data` accepts `source`, `destination`, `response_code` (301 or 302),
`enabled` and `description`. Hit counts and timestamps are not editable.
Responses contain `record` and its `version`; updates and deletes accept
`expected_version`. A changed record invalidates an outstanding approval.
Lists return `items`, `total` and `next_cursor`.

The `seo-pro` resource policy uses handles such as `site:en`, `collection:blog`,
`taxonomy:tags`, `redirect:en` and `error:en`. Denied fields apply to results and
write input, including stored idempotent results.

## Reports and sitemap

Use `resource_type: report` with `list_reports`, `get_report` (`id`) or
`generate_report`. Reads never regenerate an old or pending report. Generation
uses SEO Pro's native queue and retention policy, which may delete older reports.
An empty site is refused rather than creating a report that cannot finish.

Use `resource_type: sitemap` with `action: sitemap`. It reads the native sitemap
and supports `limit` / `cursor`, like report listings.

These are site-wide projections, not record-filtered views. They require a super
user, the separate `seo-pro:site-wide` grant, and unrestricted read and field
policies for content and SEO. A stored report can contain previously deleted or
now-hidden content, so checking only today's readable collections is insufficient.

SEO field format descriptions and sanitizers (`seo_pro`, `seo_pro_source` and
`seo_pro_opening_hours`) register through the same integration API. Invalid hours
are refused instead of silently discarding the supplied value.
