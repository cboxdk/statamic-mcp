---
title: "Quick Start"
description: "Connect an AI assistant to your Statamic site via MCP in under 2 minutes"
weight: 2
---

# Quick Start

Connect an AI assistant to your Statamic site in three steps.

## 1. Enable the Web Endpoint

Add to your `.env`:

```env
STATAMIC_MCP_WEB_ENABLED=true
STATAMIC_MCP_WEB_PATH="/mcp/statamic"
```

Your MCP endpoint is now available at `https://your-site.test/mcp/statamic`.

## 2. Create an API Token

1. Log in to the Statamic Control Panel
2. Go to **Tools > MCP**
3. Open the **Tokens** tab
4. Click **Create Token**
5. Give it a name (e.g. "Claude Desktop") and select scopes
6. Copy the token immediately — it is only shown once

For read-only exploration, select only the `:read` scopes. Use `*` (full access) only for local development.

## 3. Configure Your AI Client

Add the MCP server to your client's configuration. Replace `your-site.test` with your actual domain and paste the token from step 2.

### Claude Desktop

File: `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS)

```json
{
    "mcpServers": {
        "statamic": {
            "url": "https://your-site.test/mcp/statamic",
            "headers": {
                "Authorization": "Bearer <your-token>"
            }
        }
    }
}
```

### Cursor

File: `.cursor/mcp.json` in your project root

```json
{
    "mcpServers": {
        "statamic": {
            "url": "https://your-site.test/mcp/statamic",
            "headers": {
                "Authorization": "Bearer <your-token>"
            }
        }
    }
}
```

See [AI Client Setup](ai-clients.md) for ChatGPT, Windsurf, Claude Code, and generic clients.

## Test It

Ask your AI assistant:

> "List all collections in my Statamic site."

or

> "Show me all available blueprints."

If the connection works, the assistant will call the MCP tools and return structured data from your Statamic installation.

## CLI Access (No Token Required)

For local development without a web endpoint, use the CLI transport directly:

```bash
php artisan mcp:serve
```

CLI access bypasses token authentication entirely. This is the default mode and requires no configuration.

## What's Next

- **[AI Client Setup](ai-clients.md)** — Full config examples for every supported client
- **[Token Scopes](../authentication/token-scopes.md)** — Understand what each scope grants
- **[Tool Reference](../tools/overview.md)** — See what your AI assistant can do
- **[Configuration](../configuration/reference.md)** — Fine-tune rate limits, audit logging, and more
