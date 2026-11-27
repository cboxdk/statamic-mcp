---
title: "Authentication"
description: "Scoped API tokens, authentication methods, and permission model for the Statamic MCP Server"
weight: 20
---

# Authentication

The Statamic MCP Server uses scoped API tokens for fine-grained access control over HTTP. Built-in stdio tools use local process authority and require an explicitly selected installation profile; integrations also need a configured token with current capability consent.

## Guides

- **[Token Scopes](token-scopes.md)** — Available scopes and common combinations
- **[Web Authentication](web-authentication.md)** — Bearer tokens, OAuth, and security best practices
