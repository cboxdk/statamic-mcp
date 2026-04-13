---
title: "AI Client Setup"
description: "Copy-paste MCP configurations for Claude Desktop, Claude Code, Cursor, ChatGPT, Windsurf, and generic clients"
weight: 3
---

# AI Client Setup

Copy-paste configurations for connecting AI assistants to your Statamic MCP endpoint. Replace `your-site.test` with your actual domain and `<your-token>` with the token created in the Control Panel.

## Claude Desktop

### Option A: OAuth Connectors (recommended)

1. Open **Claude Desktop** and go to **Settings → Connectors**.
2. Click **Add custom connector**.
3. Enter a name (e.g. "Statamic") and your MCP server URL: `https://your-site.test/mcp/statamic`
4. Click **Add** — Claude will start the OAuth flow automatically.
5. Approve access in your Statamic Control Panel.

Your server must be publicly accessible (use [ngrok](https://ngrok.com/) for local development).

### Option B: Config file with mcp-remote bridge

Claude Desktop's config file only supports stdio transport. Use `mcp-remote` as a bridge to connect to the streamable HTTP endpoint.

Requires [Node.js](https://nodejs.org/) installed.

Config file location:
- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
    "mcpServers": {
        "statamic": {
            "command": "npx",
            "args": [
                "mcp-remote",
                "https://your-site.test/mcp/statamic",
                "--header",
                "Authorization: Bearer <your-token>"
            ]
        }
    }
}
```

Restart Claude Desktop after editing the config file.

> **Note:** The `url`-based config format does not work in Claude Desktop — it is only supported by Claude Code and other CLI-based clients.

## Claude Code

Add to `.mcp.json` in your project root or `~/.claude.json` for global access:

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

## Cursor

Add to `.cursor/mcp.json` in your project root:

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

## ChatGPT

Add to your ChatGPT MCP configuration:

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

## Windsurf

Add to `.windsurf/mcp.json` in your project root or via the Command Palette:

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

## Generic / Other MCP Clients

Any MCP-compatible client can connect using the streamable HTTP transport:

```json
{
    "mcpServers": {
        "statamic": {
            "url": "https://your-site.test/mcp/statamic",
            "transport": "streamable-http",
            "headers": {
                "Authorization": "Bearer <your-token>",
                "Accept": "application/json"
            }
        }
    }
}
```

You can also test the endpoint directly with curl:

```bash
curl -X POST https://your-site.test/mcp/statamic \
  -H "Authorization: Bearer <your-token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Connection refused | Verify `STATAMIC_MCP_WEB_ENABLED=true` in `.env` and that the app is running |
| 401 Unauthorized | Check that the token is correct and hasn't expired |
| 403 Forbidden | Token scopes don't cover the requested action — update scopes in the CP |
| 404 Not Found | Verify endpoint path matches `STATAMIC_MCP_WEB_PATH` — run `php artisan config:clear` |
| 429 Too Many Requests | Increase `STATAMIC_MCP_RATE_LIMIT_MAX` in `.env` |
| Tools not appearing | Check that the tool domain is enabled in `config/statamic/mcp.php` |
| Timeout | Ensure your site is reachable from the network where the AI client runs |
