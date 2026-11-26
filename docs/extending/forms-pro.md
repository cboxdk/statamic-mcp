---
title: "Forms Pro"
description: "Optional reference integration for Automagic Forms, page logic and addresses"
---

# Forms Pro

Install and license `statamic/forms-pro` separately. When the package is installed,
MCP registers its reference integration through `StatamicMcp::integration()`.
The addon does not include Forms Pro's implementation or install it automatically.

## Tools and permissions

`statamic-forms-pro-read` exposes `get` and `page_path`.
`statamic-forms-pro-write` exposes `update`. Both take the existing `form` handle.
The integration has its own `forms-pro:read` and `forms-pro:write` scopes and
consent-bound contracts. Existing tokens do not acquire them automatically.

A Control Panel user may request these scopes. Every call still checks the native
form policy: `view` for reads and `edit` for settings changes. The `forms-pro`
resource policy and the core `forms` resource policy both apply. Neither tool
reads submission data, arbitrary addon settings, connection configuration or
connector credentials.

## Automagic settings

`get` returns the form handle, Automagic settings, page ids and a version.
`update.data` accepts only:

- `automagic_form`: boolean.
- `automagic_form_success_heading`: text or null.
- `automagic_form_success_message`: text or null.
- `automagic_form_show_reset`: boolean.
- `automagic_form_reset_label`: text or null.

Only supplied values change. Pass `expected_version` from `get` to detect a
concurrent settings or definition change. `idempotency_key` and configurable
Control Panel approvals use the same pipeline as other integration actions.
Replayed results obey the current denied-field policy.

The native Forms Pro configuration must also enable Automagic Forms. Changing a
form's toggle does not turn on the feature globally or publish its templates.

## Page logic

Page definitions and `rules` live in the core [Forms 2 definition](../tools/forms.md).
Write them with `statamic-forms-write` using `data.fields.pages`. Rules contain
`destination` (an existing page id) and `conditions` with `field`, `operator`,
`value` and optional `join` (`and` or `or`).

`page_path` takes sample field values in `data` and returns `path`, the page ids
visited by Statamic's native rule evaluator. It saves no submission and runs no
connector. For example, a form may send a particular choice directly from the
first page to the final page. The evaluator's cycle guard is preserved.
Evaluation is refused when a condition references a denied field; otherwise
repeated sample values could reveal the protected condition.

## Address fields

The integration registers the `address` fieldtype's format specification and
sanitizer. Values are objects with `line_1`, `line_2`, `city`, `region`, `postcode`
and `country`. Values must be text or null; unknown keys are refused. Country
uses a valid ISO 3166-1 alpha-2 code, normalized to uppercase. Postcodes stay text
so leading zeros are preserved. Native required-field validation still runs.
