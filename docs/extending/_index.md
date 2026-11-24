---
title: "Extending"
description: "Add your own tools, and teach the MCP server about fieldtypes it does not ship support for"
weight: 50
---

# Extending

Statamic's fieldtype set is open; this package's is not. These guides cover the
extension points that let a site or an addon close that gap.

## Guides

- **[Tools](tools.md)** — Serve your own tools from this server, behind the same tokens, permissions and audit log
- **[Approval channels](approval-channels.md)** — Tell people about waiting approvals by mail, web push and webhooks (Cbox Relay), let them approve on their phone (Cbox ID), or add your own channel
- **[Fieldtypes](fieldtypes.md)** — Describe the wire format of a fieldtype the package does not know, and coerce or reject incoming values for it
