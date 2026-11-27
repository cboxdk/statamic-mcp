---
title: "Forms"
description: "Forms 2 definitions and site-bound submission access"
---

# Forms

`statamic-forms-read` reads form definitions and stored submissions. `statamic-forms-write` creates and updates definitions, marks finalized submissions as spam and deletes submissions. Both use `form` for the form handle. Requires Statamic 6.44+.

## Permissions

| Actions | Token scopes |
|---|---|
| `list`, `get` | `forms:read` |
| `create`, `update` | `forms:write` |
| `list_submissions`, `get_submission`, `export`, `summary` | `submissions:read` |
| `mark_spam` | `forms:write` and `submissions:read` |
| `delete_submission` | `submissions:delete` |

Every HTTP call also checks Statamic's form permissions. Editing fields uses `editFields`; editing connections uses `editConnections` (both editing and viewing submissions). These checks also apply to optional settings on create: permission to create a titled form alone does not grant access to fields or connectors. Submission calls check the requested site. The installation profile and `tools.forms.resources.read/write` apply as usual. `tools.forms.denied_fields` hides nested field definitions and submission values, including replayed results; definition replacement is refused if it would overwrite a protected field.

## Definitions

`create` requires `data.title`. Both create and update accept `title`, `store` (boolean), `honeypot` (field name or null to reset to `honeypot`), `fields` and `connections`. Unknown settings are refused. Only supplied settings change; `fields` replaces the full definition. Read it first and pass `expected_version` on update.

```json
{
  "action": "create",
  "form": "contact",
  "data": {
    "title": "Contact",
    "fields": {
      "sections": [{
        "display": "Your message",
        "fields": [{"handle": "name", "field": {"type": "short_answer", "display": "Name"}}]
      }]
    }
  }
}
```

This is the stored Forms 2 shape, not the Control Panel's temporary Vue ids or a `forms.*` blueprint. Fieldset imports and references use Statamic's native resolution, including flattened imports. Group fields are validated recursively; uploads inside groups are refused because Statamic cannot render or submit them. Multiple pages require Forms Pro.

Connections use `data.connections: {connector_handle: [configuration, ...]}`. Each supplied handle replaces that connector's connection list; an empty list removes its connections. Native connector validation and processing apply. Configuration is write-only: results show the connector handle and count, never credentials. Approval previews and audit arguments mask the entire connection configuration. Connector failures return a generic validation error rather than provider diagnostics that could contain secrets.

## Submissions

Submission calls use `site` (default: the default site); they never span sites. `get_submission`, `mark_spam` and `delete_submission` also require `id`. An id from another form or site is refused. Legacy submissions with no site belong only to the default site.

`list_submissions`, `export` and `summary` accept `filters.status` (`finalized`, `partial`, `spam`, or `any`) and inclusive `filters.date_from` / `date_to`. The default status is `finalized`. Dates are timestamps with timezones. Arbitrary field filters are refused.

Lists and exports return `{items, total, next_cursor}`. Use `limit` (default 50, max 500) and follow `next_cursor` with the same query. Export is paged JSON, not a server-local file. Summary returns total and status counts for the same filtered site. Hidden fields and internal `_` metadata never appear in submission data.

Reads return an opaque `version`; writes accept `expected_version` and `idempotency_key`. Deletion needs a Control Panel approval over HTTP by default and returns only a receipt (id, form, site, version, deleted). Retrying the same approved or idempotent deletion returns its stored result after checking current authorization. Spam marking refuses partial submissions. MCP does not finalize, unspam or replay connectors.
