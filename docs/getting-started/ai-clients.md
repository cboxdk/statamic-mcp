---
title: "AI Client Setup"
description: "MCP configurations for Claude Desktop, Claude Code, Cursor, VS Code, ChatGPT, Codex, and Windsurf"
weight: 3
---

# AI Client Setup

Copy-paste configurations for connecting AI assistants to your Statamic MCP endpoint. Replace `your-site.test` with your actual domain and `<your-token>` with the token created in the Control Panel.

**Tools → MCP → Connect** generates these configurations for your site's endpoint.
Choose token authentication, OAuth sign-in, or local stdio. After creating a token,
the dashboard fills it into a copyable client snippet; dismissing it removes the
secret and snippet. Never commit a filled snippet. Merge a server into existing
configuration instead of replacing the whole file.

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

For HTTP with a token:

```bash
claude mcp add --transport http statamic https://your-site.test/mcp/statamic \
  --header 'Authorization: Bearer <your-token>'
```

For OAuth, omit the header, then open `/mcp` in Claude Code and choose Authenticate:

```bash
claude mcp add --transport http statamic https://your-site.test/mcp/statamic
```

Add to `.mcp.json` in your project root or `~/.claude.json` for global access:

```json
{
    "mcpServers": {
        "statamic": {
            "type": "http",
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

Use ChatGPT's **Settings → Apps** custom MCP app flow (Developer Mode and a
supporting plan are required). Enter `https://your-site.test/mcp/statamic`, choose
OAuth, and sign in to the Statamic Control Panel to review the requested access.
OAuth must be enabled (`STATAMIC_MCP_OAUTH_ENABLED=true`) and the endpoint must be
public HTTPS. ChatGPT has no local JSON configuration file for this connection.

## VS Code

Add to `.vscode/mcp.json`. This format uses `servers`, not `mcpServers`:

```json
{
    "servers": {
        "statamic": {
            "type": "http",
            "url": "https://your-site.test/mcp/statamic",
            "headers": {
                "Authorization": "Bearer <your-token>"
            }
        }
    }
}
```

For OAuth, omit `headers`, start the server, and follow VS Code's browser sign-in.
For shared configuration, use VS Code input variables rather than a committed secret.

## Codex

Add to `~/.codex/config.toml` (or `.codex/config.toml` in a trusted project):

```toml
[mcp_servers.statamic]
url = "https://your-site.test/mcp/statamic"
bearer_token_env_var = "STATAMIC_MCP_TOKEN"
```

Set `STATAMIC_MCP_TOKEN` in Codex's environment. The dashboard's one-time filled
snippet instead uses `http_headers = { Authorization = "Bearer <your-token>" }`;
keep that file private. For OAuth, omit both token settings and run:

```bash
codex mcp add statamic --url https://your-site.test/mcp/statamic
codex mcp login statamic
```

## Local stdio

Use a local Statamic project with this addon installed. Run `php artisan mcp:statamic:install`
to choose an explicit local profile, or configure `STATAMIC_MCP_STDIO_PROFILE`
(for example `read_only`). Replace `/path/to/site/artisan` with its absolute path:

```bash
claude mcp add --transport stdio statamic -- php /path/to/site/artisan mcp:start statamic
```

Other local clients use `command: "php"` and
`args: ["/path/to/site/artisan", "mcp:start", "statamic"]` in their server block.
VS Code also requires `type: "stdio"`; Codex uses the same command/args as TOML.
Built-in local tools run as the local operator and do not wait for Control Panel
approvals. Installation profiles and resource policies still apply. Integration
tools additionally need `STATAMIC_MCP_STDIO_TOKEN` with current capability consent
and run as that grant's Statamic user.

## Client read permissions

The server is named `statamic` in these examples. Read-only permission patterns
are `mcp__statamic__*-read` in Claude Code's `permissions.allow` and
`Mcp(statamic:*-read)` in Cursor CLI's `.cursor/cli.json` `permissions.allow`. Keep
write/admin tools subject to confirmation. These client rules do not grant server
scopes; the token, site permissions, installation profile and resource policy
still decide access. Discovery is separate from the `*-read` pattern.

## Windsurf

Add to `~/.codeium/windsurf/mcp_config.json`:

```json
{
    "mcpServers": {
        "statamic": {
            "serverUrl": "https://your-site.test/mcp/statamic",
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
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2026-07-28" \
  -H "Mcp-Method: tools/list" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1,"params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'
```

## Self-Signed Certificates (Laravel Herd / Valet)

If you use **Laravel Herd** or **Laravel Valet** with HTTPS enabled on your local site, Node.js will not trust the self-signed certificate by default. This affects any client that uses `npx mcp-remote` as a bridge (e.g. Claude Desktop Option B) and may also affect other Node.js-based MCP clients.

The fix is to tell Node.js where to find the certificate authority (CA) file by setting the `NODE_EXTRA_CA_CERTS` environment variable in your MCP config.

### Laravel Herd (Windows)

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
            ],
            "env": {
                "NODE_EXTRA_CA_CERTS": "C:\\Users\\<your-username>\\.config\\herd\\config\\valet\\CA\\LaravelValetCASelfSigned.crt"
            }
        }
    }
}
```

### Laravel Herd (macOS)

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
            ],
            "env": {
                "NODE_EXTRA_CA_CERTS": "/Users/<your-username>/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.crt"
            }
        }
    }
}
```

### Laravel Valet (Linux)

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
            ],
            "env": {
                "NODE_EXTRA_CA_CERTS": "/home/<your-username>/.config/valet/CA/LaravelValetCASelfSigned.crt"
            }
        }
    }
}
```

> **Tip:** If you're unsure where the CA certificate is located, look for a file named `LaravelValetCASelfSigned.crt` in your Herd or Valet configuration directory. The `NODE_EXTRA_CA_CERTS` variable works with any self-signed CA — not just Herd/Valet.

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Connection refused | Verify `STATAMIC_MCP_WEB_ENABLED=true` in `.env` and that the app is running |
| 401 Unauthorized | Check that the token is correct and hasn't expired |
| 403 Forbidden | Token scopes don't cover the requested action — update scopes in the CP |
| 404 Not Found | Verify endpoint path matches `STATAMIC_MCP_WEB_PATH` — run `php artisan config:clear` |
| 429 Too Many Requests | Increase `STATAMIC_MCP_RATE_LIMIT_MAX` in `.env` |
| TLS / certificate error | Using Laravel Herd or Valet with HTTPS? See [Self-Signed Certificates](#self-signed-certificates-laravel-herd--valet) above |
| Tools not appearing | Check that the tool domain is enabled in `config/statamic/mcp.php` |
| Timeout | Ensure your site is reachable from the network where the AI client runs |
